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
require_once dirname(__DIR__, 3) . '/cloud/api/lib/apikey_auth.php';

// ---------------------------------------------------------------------------
// Auth
// ---------------------------------------------------------------------------
// Puerta unica del stack: requireAppApikey() vive en
// cloud/api/lib/apikey_auth.php. Lee `Authorization: Bearer <apikey>`, valida
// contra la tabla `aplicaciones`, rechaza con 401 (token ausente / apikey
// desconocida / aplicacion deshabilitada) e incrementa `aplicaciones.usos`.
//
// Hasta la consolidacion cada microservicio arrastraba su propia copia de este
// bloque (8 variantes con nombres prefijados). Si hace falta tocar la auth
// -- scope por aplicacion, rate limit, rotacion de apikey -- se toca la lib.

// ---------------------------------------------------------------------------
// Ruteo
// ---------------------------------------------------------------------------

try {
    requireAppApikey();
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
