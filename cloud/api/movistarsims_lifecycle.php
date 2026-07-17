<?php
// api/movistarsims_lifecycle.php
// Cambia el ciclo de vida ("estado") de una SIM Movistar en Kite Platform.
// Se usa desde la pestania "Estado" del modal Consultar de la SIM.
//
// Consume:
//   POST api/movistarsims_lifecycle.php
//   Body JSON: {"id": <movistarsims.id>, "target": "ACTIVATED"|"DEACTIVATED"}
//
// Flujo:
//   1. Chequea permiso `plataformas.movistar.sims.editar`.
//   2. Lee el ICC de la fila `movistarsims.id`.
//   3. Llama a Kite (mTLS) para pedir el cambio de lifeCycleStatus.
//   4. Devuelve el JSON crudo de Kite (o {} si Kite responde 204).
//
// El estado local (`movistarsims.estado`) NO se actualiza aca — el cambio
// en Kite suele ser asincronico (Kite devuelve un requestId y el cambio se
// refleja en el getSubscriptions varios segundos despues). La proxima
// corrida del sync trae el estado real.
//
// Respuesta:
//   200 {ok:true, data:{icc, target, kite: <respuesta cruda>}}
//   400 target invalido / id faltante
//   404 SIM no encontrada
//   500 error de Kite u otro

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/lib/auth_check.php';
require_once __DIR__ . '/lib/movistarsims_kite.php';

header('Content-Type: application/json; charset=utf-8');

requireAuth();

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
if ($method !== 'POST') jsonError('Metodo no soportado', 405);

try {
    requirePermission('plataformas.movistar.sims.editar');

    $in     = readJsonBody();
    $id     = (int)($in['id'] ?? 0);
    $target = strtoupper(trim((string)($in['target'] ?? '')));

    if ($id <= 0)      jsonError('Falta id', 400);
    // Whitelist estricta: solo activar/desactivar por ahora. Si en el futuro
    // se necesita RETIRED, TEST_READY, etc., agregarlos aca.
    if (!in_array($target, ['ACTIVATED', 'DEACTIVATED'], true)) {
        jsonError('target invalido (esperado ACTIVATED o DEACTIVATED)', 400);
    }

    $pdo  = db();
    $stmt = $pdo->prepare('SELECT icc FROM movistarsims WHERE id = :id');
    $stmt->execute([':id' => $id]);
    $icc = (string) $stmt->fetchColumn();
    if ($icc === '') jsonError('SIM no encontrada o sin ICC', 404);

    $cfg  = kiteConfig();
    $resp = kiteChangeLifeCycle($cfg, $icc, $target);

    jsonOk([
        'icc'    => $icc,
        'target' => $target,
        'kite'   => $resp,
    ]);
} catch (Throwable $e) {
    jsonError($e->getMessage(), 500);
}
