<?php
// api/clarosims_sync.php
// Recibe el inventario de SIMs Claro desde el agente externo `openclaw`, que
// se encarga de loguearse en https://iotgestion.claro.com.ar/ (el portal esta
// detras de un WAF con fingerprint dinamico que corta cualquier scraper HTTP
// puro, ver notas en el commit). Hace UPSERT sobre la tabla `clarosims` por
// ICCID (UNIQUE KEY uk_clarosims_icc). Idempotente.
//
// Consume:
//   POST api/clarosims_sync.php   (body JSON con el formato exportado por openclaw)
//
// Auth:
//   - Header `Authorization: Bearer <CLARO_IOT_SYNC_TOKEN>` (path openclaw), o
//   - Sesion de panel con permiso `plataformas.claro.sims.sincronizar`
//     (path UI, si a futuro se dispara desde el admin).
//
// Body esperado (resumen — ver ejemplo real en el ticket):
//   {
//     "exportedAt": "2026-07-17T17:58:54.342Z",
//     "accounts": [{
//       "lines": [{
//         "iccid":          "8954312212097818037",   // -> icc  (clave UPSERT)
//         "cellularNumber": "2646176179",            // -> linea
//         "msisdn":         "5492646176179",         // -> msisdn
//         "status":         "ACTIVO"                 // -> estado (normalizado)
//       }, ...]
//     }, ...]
//   }
//
// Campos NO tocados por el sync (se preservan valores editados a mano en el
// ABM): nombre, imei, limite_datos, estado_gprs, estado_lte. openclaw no los
// provee y sobreescribirlos con NULL borraria trabajo del operador.
//
// Respuesta:
//   200 {ok:true, data:{fetched, insertados, actualizados, sin_icc, cuentas,
//                       duracion_ms, ultima_sync, exportedAt}}
//   400 body invalido / sin cuentas
//   401/403 sin auth valida
//   500 error de DB u otros

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/lib/auth_check.php';

header('Content-Type: application/json; charset=utf-8');

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
if ($method !== 'POST') jsonError('Metodo no soportado', 405);

try {
    authorizeClaroSync();

    $t0      = microtime(true);
    $payload = readJsonBody();
    $stats   = importClaroSims(db(), $payload);
    $stats['duracion_ms'] = (int) round((microtime(true) - $t0) * 1000);
    $stats['exportedAt']  = isset($payload['exportedAt']) ? (string) $payload['exportedAt'] : null;

    jsonOk($stats);
} catch (Throwable $e) {
    jsonError($e->getMessage(), 500);
}

// ----------------------------------------------------------------------------
// Autorizacion: bearer token (openclaw) o sesion de panel con permiso.
// ----------------------------------------------------------------------------

function authorizeClaroSync(): void {
    // Apache/PHP-FPM en este stack NO propaga Authorization a $_SERVER, pero
    // getallheaders() si la ve. Chequeamos ambas y tambien REDIRECT_HTTP_AUTHORIZATION
    // (fallback cuando el header pasa por mod_rewrite).
    $auth = trim((string)($_SERVER['HTTP_AUTHORIZATION']
                       ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION']
                       ?? ''));
    if ($auth === '' && function_exists('getallheaders')) {
        foreach (getallheaders() as $k => $v) {
            if (strcasecmp($k, 'Authorization') === 0) { $auth = trim((string)$v); break; }
        }
    }
    $token = stripos($auth, 'Bearer ') === 0 ? trim(substr($auth, 7)) : '';

    $expected = trim((string)(getenv('CLARO_IOT_SYNC_TOKEN') ?: ''));

    // Path openclaw: bearer token match. Solo aceptamos si el token esta
    // configurado en el .env (evita bypass accidental si alguien manda un
    // Bearer vacio contra una env sin CLARO_IOT_SYNC_TOKEN definido).
    if ($token !== '' && $expected !== '' && hash_equals($expected, $token)) {
        return;
    }

    // Path UI: sesion + permiso.
    requirePermission('plataformas.claro.sims.sincronizar');
}

// ----------------------------------------------------------------------------
// Import: recorre accounts[].lines[] y UPSERT-ea en clarosims por icc.
// ----------------------------------------------------------------------------

function importClaroSims(PDO $pdo, array $payload): array {
    $accounts = $payload['accounts'] ?? null;
    if (!is_array($accounts) || !$accounts) {
        jsonError('Payload sin cuentas (accounts[]).', 400);
    }

    $fetched      = 0;
    $insertados   = 0;
    $actualizados = 0;
    $sinIcc       = 0;

    $lookup = $pdo->prepare("SELECT id FROM clarosims WHERE icc = :icc");
    // UPSERT: solo los campos que openclaw provee. `nombre`, `imei`,
    // `limite_datos`, `estado_gprs` y `estado_lte` quedan intactos en el
    // UPDATE para no pisar ediciones manuales del ABM.
    $upsert = $pdo->prepare("
        INSERT INTO clarosims
            (linea, icc, estado, msisdn, actualizado)
        VALUES
            (:linea, :icc, :estado, :msisdn, NOW())
        ON DUPLICATE KEY UPDATE
            linea       = VALUES(linea),
            estado      = VALUES(estado),
            msisdn      = VALUES(msisdn),
            actualizado = VALUES(actualizado)
    ");

    foreach ($accounts as $acc) {
        $lines = $acc['lines'] ?? null;
        if (!is_array($lines)) continue;

        foreach ($lines as $ln) {
            $p = mapClaroLine($ln);
            if ($p[':icc'] === null) { $sinIcc++; continue; }

            $lookup->execute([':icc' => $p[':icc']]);
            $existente = (bool) $lookup->fetchColumn();

            $upsert->execute($p);
            if ($existente) $actualizados++; else $insertados++;
            $fetched++;
        }
    }

    return [
        'fetched'      => $fetched,
        'insertados'   => $insertados,
        'actualizados' => $actualizados,
        'sin_icc'      => $sinIcc,
        'cuentas'      => count($accounts),
        'ultima_sync'  => date('Y-m-d H:i:s'),
    ];
}

/**
 * Traduce una linea del JSON de openclaw a los :params del UPSERT.
 * Normaliza `status` a la forma que espera la stats query de clarosims.php
 * (`activada` / `activa` / `active`): "ACTIVO" -> "Activada".
 */
function mapClaroLine(array $ln): array {
    $icc    = trim((string)($ln['iccid']          ?? ''));
    $linea  = trim((string)($ln['cellularNumber'] ?? ''));
    $msisdn = trim((string)($ln['msisdn']         ?? ''));
    $status = trim((string)($ln['status']         ?? ''));

    return [
        ':icc'    => $icc    !== '' ? mb_substr($icc,    0, 25) : null,
        ':linea'  => $linea  !== '' ? mb_substr($linea,  0, 30) : null,
        ':msisdn' => $msisdn !== '' ? mb_substr($msisdn, 0, 30) : null,
        ':estado' => $status !== '' ? mb_substr(normalizeClaroStatus($status), 0, 40) : null,
    ];
}

function normalizeClaroStatus(string $raw): string {
    return match (strtoupper($raw)) {
        'ACTIVO'         => 'Activada',
        'DESACTIVADO'    => 'Desactivada',
        'RETIRADO'       => 'Retirada',
        'SUSPENDIDO'     => 'Suspendida',
        'PRESUSPENDIDO'  => 'Presuspendida',
        'TEST'           => 'Test',
        'INVENTARIO'     => 'Inventario',
        'NO DISPONIBLE'  => 'No disponible',
        default          => ucfirst(strtolower($raw)),
    };
}
