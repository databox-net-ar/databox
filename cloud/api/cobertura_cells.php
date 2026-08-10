<?php
// api/cobertura_cells.php
// Proxy read-only sobre la API publica de OpenCellID
// (https://opencellid.org/cell/getInArea) para las vistas
// Movistar > Zona de cobertura y Claro > Zona de cobertura del SPA.
//
// Lo que resuelve el proxy:
//   1. CORS — OpenCellID no envia headers CORS abiertos, un fetch() directo
//      desde el browser rebota.
//   2. Token — el token de Unwired Labs vive server-side en la tabla
//      `parametros` (variable `opencellid.api_token`); NO viaja al SPA.
//   3. Autorizacion — chequea que el usuario tenga al menos uno de los dos
//      permisos de cobertura antes de gastar cuota contra la API externa.
//
// Contrato:
//   GET api/cobertura_cells.php?bbox=lat1,lon1,lat2,lon2&mcc=722&mnc=7&limit=1000
//     - bbox   (obligatorio) — 4 numeros: latMin, lonMin, latMax, lonMax
//     - mcc    (default 722 = Argentina)
//     - mnc    (opcional)   — filtra por operadora (Movistar=7, Claro=310/320,
//                             Personal=34). Si se omite, devuelve todas.
//     - limit  (default 1000, tope 1000 = maximo permitido por OpenCellID)
//   Respuesta {ok:true, data:{cells:[...], count:N}} u {ok:false, error:'...'}.

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/lib/auth_check.php';

requireAuth();
header('Content-Type: application/json; charset=utf-8');

try {
    // Basta con tener el permiso de al menos una de las 2 vistas: el proxy
    // no distingue operador — el filtrado por MNC lo hace el frontend.
    if (!(hasPermission('plataformas.movistar.cobertura.consultar')
       || hasPermission('plataformas.claro.cobertura.consultar'))) {
        jsonError('Permiso denegado', 403);
    }

    $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
    if ($method !== 'GET') jsonError('Metodo no soportado', 405);

    $bbox  = trim((string)($_GET['bbox']  ?? ''));
    $mcc   = (int)($_GET['mcc'] ?? 722);
    $mnc   = isset($_GET['mnc']) && $_GET['mnc'] !== '' ? (int)$_GET['mnc'] : 0;
    $limit = (int)($_GET['limit'] ?? 1000);
    if ($limit < 1 || $limit > 1000) $limit = 1000;

    // Formato bbox: 4 numeros con signo separados por coma. Se valida con
    // regex antes de propagar hacia el backend externo — si algo no matchea
    // devolvemos 400 sin gastar cuota contra OpenCellID.
    if ($bbox === '' || !preg_match('/^-?\d+(\.\d+)?,-?\d+(\.\d+)?,-?\d+(\.\d+)?,-?\d+(\.\d+)?$/', $bbox)) {
        jsonError('Parametro bbox invalido. Formato esperado: latMin,lonMin,latMax,lonMax', 400);
    }

    $pdo  = db();
    $stmt = $pdo->prepare('SELECT valor FROM parametros WHERE variable = :v LIMIT 1');
    $stmt->execute([':v' => 'opencellid.api_token']);
    $token = trim((string)$stmt->fetchColumn());
    if ($token === '') {
        jsonError('Falta configurar el parametro `opencellid.api_token` en Herramientas > Parametros.', 500);
    }

    $params = [
        'key'    => $token,
        'BBOX'   => $bbox,
        'mcc'    => $mcc,
        'format' => 'json',
        'limit'  => $limit,
    ];
    if ($mnc > 0) $params['mnc'] = $mnc;

    $url = 'https://opencellid.org/cell/getInArea?' . http_build_query($params);
    $ch  = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CONNECTTIMEOUT => 5,
        CURLOPT_TIMEOUT        => 20,
        CURLOPT_USERAGENT      => 'databox-cloud/1.0 (+cobertura_cells.php)',
    ]);
    $body = curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $cerr = curl_error($ch);
    curl_close($ch);

    if ($body === false || $body === '') {
        jsonError('Sin respuesta de OpenCellID' . ($cerr !== '' ? ": {$cerr}" : ''), 502);
    }
    if ($code < 200 || $code >= 300) {
        jsonError("OpenCellID devolvio HTTP {$code}: " . substr((string)$body, 0, 300), 502);
    }

    $data = json_decode((string)$body, true);
    if (!is_array($data)) {
        jsonError('Respuesta invalida de OpenCellID (no es JSON)', 502);
    }

    // La API a veces devuelve un objeto de error con clave `err` o mensaje
    // en texto plano — normalizamos a {ok:false} en ese caso.
    if (isset($data['err']) || isset($data['error'])) {
        $msg = (string)($data['err'] ?? $data['error'] ?? 'error desconocido');
        jsonError("OpenCellID: {$msg}", 502);
    }

    jsonOk([
        'cells' => $data['cells'] ?? [],
        'count' => (int)($data['count'] ?? count($data['cells'] ?? [])),
    ]);
} catch (Throwable $e) {
    jsonError($e->getMessage(), 500);
}
