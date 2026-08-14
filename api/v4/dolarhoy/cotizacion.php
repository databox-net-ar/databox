<?php
// api/v4/dolarhoy/cotizacion.php
// Microservicio de consulta de la cotizacion del dolar guardada en el ABM
// cloud > Plataformas > DolarHoy > Cotizaciones.
//
//   GET /v4/dolarhoy/cotizacion                 -> ultima cotizacion cargada
//   GET /v4/dolarhoy/cotizacion?fecha=YYYY-MM-DD -> cotizacion de esa fecha
//
// Auth: Bearer con apikey de la tabla `aplicaciones` (mismo esquema que el
// resto del stack v4 -- ver /v4/arca, /v4/evolution, /v4/telegram).
//
// Tabla fuente: `dolarhoy_cotizaciones` (schema en db/schema.sql).
// Es solo lectura -- el alta/edicion de cotizaciones vive en el ABM cloud
// (cloud/api/dolarhoycotizaciones.php).

declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');

require_once dirname(__DIR__, 3) . '/env.php';
require_once dirname(__DIR__, 3) . '/cloud/api/db.php';

// ---------------------------------------------------------------------------
// Auth
// ---------------------------------------------------------------------------
// Apache no siempre propaga Authorization a $_SERVER (depende de mod_rewrite
// y CGIPassAuth). Chequeamos $_SERVER, REDIRECT_HTTP_AUTHORIZATION y como
// ultimo recurso getallheaders().
function readBearer(): string {
    $auth = trim((string)($_SERVER['HTTP_AUTHORIZATION']
                       ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION']
                       ?? ''));
    if ($auth === '' && function_exists('getallheaders')) {
        foreach (getallheaders() as $k => $v) {
            if (strcasecmp($k, 'Authorization') === 0) { $auth = trim((string)$v); break; }
        }
    }
    return stripos($auth, 'Bearer ') === 0 ? trim(substr($auth, 7)) : '';
}

function requireApp(): array {
    $token = readBearer();
    if ($token === '') jsonError('Bearer token ausente', 401);

    $pdo = db();
    $st  = $pdo->prepare("SELECT id, nombre, habilitada FROM aplicaciones WHERE apikey = :k LIMIT 1");
    $st->execute([':k' => $token]);
    $app = $st->fetch();
    if (!$app)                              jsonError('API key desconocida', 401);
    if ((string)$app['habilitada'] !== '1') jsonError('Aplicacion deshabilitada', 401);

    // Contador de uso -- best effort, un fallo aca no debe tumbar el request.
    try {
        $pdo->prepare("UPDATE aplicaciones SET usos = COALESCE(usos,0)+1 WHERE id = :id")
            ->execute([':id' => (int)$app['id']]);
    } catch (Throwable) { /* ignore */ }

    return $app;
}

// ---------------------------------------------------------------------------
// Ruteo
// ---------------------------------------------------------------------------

try {
    requireApp();
    $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
    if ($method !== 'GET') jsonError('Metodo no soportado', 405);

    $fecha = trim((string)($_GET['fecha'] ?? ''));
    if ($fecha !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $fecha)) {
        jsonError('Formato de fecha invalido (esperado YYYY-MM-DD)', 400);
    }

    $pdo = db();
    if ($fecha !== '') {
        $st = $pdo->prepare(
            "SELECT id, fecha, compra, venta
               FROM dolarhoy_cotizaciones
              WHERE fecha = :fecha
              ORDER BY id DESC
              LIMIT 1"
        );
        $st->execute([':fecha' => $fecha]);
    } else {
        $st = $pdo->query(
            "SELECT id, fecha, compra, venta
               FROM dolarhoy_cotizaciones
              ORDER BY fecha DESC, id DESC
              LIMIT 1"
        );
    }
    $row = $st->fetch();
    if (!$row) jsonError('Cotizacion no encontrada', 404);

    jsonOk([
        'id'     => (int)$row['id'],
        'fecha'  => $row['fecha'],
        'compra' => $row['compra'] !== null ? (float)$row['compra'] : null,
        'venta'  => $row['venta']  !== null ? (float)$row['venta']  : null,
    ]);
} catch (Throwable $e) {
    jsonError($e->getMessage(), 500);
}
