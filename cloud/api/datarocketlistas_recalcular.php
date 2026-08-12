<?php
// api/datarocketlistas_recalcular.php
// Recalcula la columna denormalizada `datarocket_listas.suscriptos` de TODAS
// las listas contando las filas de la tabla puente
// `datarocket_contactos_listas` para cada lista. Es el "reset" oficial del
// contador cuando queda desactualizado (por ejemplo, si otra via alteró
// suscripciones sin actualizar el denormalizado).
//
//   POST api/datarocketlistas_recalcular.php
//   Body: (vacio)
//   Respuesta: {ok:true, data:{recalculadas:N, duracion_ms:M}}
//
// Permisos: `datarocket.listas.editar` — quien puede editar una lista puede
// pedir el recalculo global. Idempotente (correr N veces deja el mismo
// estado final).

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/lib/auth_check.php';

header('Content-Type: application/json; charset=utf-8');

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
if ($method !== 'POST') jsonError('Metodo no soportado', 405);

try {
    requirePermission('datarocket.listas.editar');

    $t0  = microtime(true);
    $pdo = db();

    // UPDATE en un solo statement — la subquery correlacionada recorre la
    // tabla puente. Aplica a TODAS las filas de `datarocket_listas` (incluso
    // las que hoy tienen NULL) para que el ABM muestre 0 en las listas
    // vacias en lugar del guion "—".
    $sql = "
        UPDATE datarocket_listas dl
        SET dl.suscriptos = (
            SELECT COUNT(*)
              FROM datarocket_contactos_listas dcl
             WHERE dcl.lista_id = dl.id
        )
    ";
    $recalculadas = (int) $pdo->exec($sql);

    jsonOk([
        'recalculadas' => $recalculadas,
        'duracion_ms'  => (int) round((microtime(true) - $t0) * 1000),
    ]);
} catch (Throwable $e) {
    jsonError($e->getMessage(), 500);
}
