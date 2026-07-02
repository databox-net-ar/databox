<?php
// api/awscuentas_facturas.php
// Consulta el estado de facturacion de una cuenta AWS combinando dos fuentes:
//   - BCM Recommended Actions: acciones tipo PAYMENTS_DUE / PAYMENTS_PAST_DUE
//     -> unica fuente publica que dice "cuanto adeudas ahora" via API key,
//     sin depender del plan de soporte (a diferencia de AWS Health).
//   - AWS Invoicing: lista de facturas emitidas en los ultimos N meses.
//
// GET api/awscuentas_facturas.php?id=N[&months=6]
//
// Respuesta:
//   { ok: true, data: {
//       account_id, nombre,
//       payments:  { ok, actions: [...], error }
//       invoicing: { ok, count, invoices: [...], error }
//     } }
//
// Nunca falla si una API no responde: cada seccion lleva su propio flag/error.

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/lib/auth_check.php';
require_once __DIR__ . '/lib/bcm.php';
require_once __DIR__ . '/lib/invoicing.php';
require_once __DIR__ . '/lib/sucesos.php';

header('Content-Type: application/json; charset=utf-8');

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') {
    jsonError('Metodo no soportado', 405);
}

requireAuth();

$id     = isset($_GET['id'])     ? (int)$_GET['id']     : 0;
$months = isset($_GET['months']) ? (int)$_GET['months'] : 6;
if ($months < 1)  $months = 1;
if ($months > 12) $months = 12;

if ($id <= 0) jsonError('Falta id de cuenta', 400);

$pdo  = db();
$stmt = $pdo->prepare('SELECT id, nombre, numero, accesskey, secreto FROM awscuentas WHERE id = :id');
$stmt->execute([':id' => $id]);
$cuenta = $stmt->fetch();

if (!$cuenta)                     jsonError('Cuenta AWS no encontrada', 404);
if (empty($cuenta['numero']))     jsonError('La cuenta no tiene numero configurado', 400);
if (empty($cuenta['accesskey']))  jsonError('La cuenta no tiene Access Key configurado', 400);
if (empty($cuenta['secreto']))    jsonError('La cuenta no tiene Secret Key configurado', 400);

$respuesta = [
    'account_id' => $cuenta['numero'],
    'nombre'     => $cuenta['nombre'],
    'payments'   => null,
    'invoicing'  => null,
];

// --- 1. BCM Recommended Actions: deuda vencida / por vencer ---
try {
    $p = bcm_payments_due($cuenta['accesskey'], $cuenta['secreto']);
    $respuesta['payments'] = ['ok' => true, 'actions' => $p['actions']];
} catch (Throwable $e) {
    $respuesta['payments'] = ['ok' => false, 'actions' => [], 'error' => $e->getMessage()];
    registrarSuceso($pdo, 'awscuentas', 'error',
        "BCM fallo (cuenta #{$id}): " . $e->getMessage());
}

// --- 2. AWS Invoicing: facturas emitidas ---
try {
    $inv = invoicing_list_account($cuenta['accesskey'], $cuenta['secreto'], $cuenta['numero'], $months);
    $inv['ok'] = true;
    $respuesta['invoicing'] = $inv;
} catch (Throwable $e) {
    $respuesta['invoicing'] = ['ok' => false, 'count' => 0, 'invoices' => [], 'error' => $e->getMessage()];
    registrarSuceso($pdo, 'awscuentas', 'error',
        "Invoicing fallo (cuenta #{$id}): " . $e->getMessage());
}

// --- 3. Reconciliacion: intentar identificar cuales facturas componen la deuda ---
//
// AWS no expone "invoice unpaid" como campo. La estrategia (misma que usa
// openclaw) es tomar el total adeudado que reporta BCM como fuente de verdad
// y buscar un subset de las facturas emitidas cuya suma sea exactamente ese
// total (tolerancia 1 centavo por errores de redondeo). Si hay match exacto,
// esas son las facturas probablemente impagas. Si no, solo se informa el total.
$respuesta['match'] = ['method' => 'subset-sum vs total PAYMENTS_DUE/PAST_DUE', 'matches' => []];

if ($respuesta['payments']['ok'] && $respuesta['invoicing']['ok']) {
    $deudaByCurrency = [];
    foreach ($respuesta['payments']['actions'] as $a) {
        if ($a['amount'] === null || !$a['currency']) continue;
        $c = $a['currency'];
        $deudaByCurrency[$c] = ($deudaByCurrency[$c] ?? 0.0) + (float)$a['amount'];
    }
    foreach ($deudaByCurrency as $currency => $total) {
        $ids = match_invoices_subset($respuesta['invoicing']['invoices'], $total, $currency);
        if ($ids !== null) {
            $respuesta['match']['matches'][] = [
                'currency'    => $currency,
                'total'       => $total,
                'invoice_ids' => $ids,
            ];
        }
    }
}

// --- 4. Cachear resultado en awscuentas ---
// facturas_cantidad = cantidad de facturas que matchean con la deuda BCM
// (subset-sum). facturas_total = total adeudado segun BCM en la moneda
// primaria. Semantica: "facturas pendientes de pago" segun AWS.
//
// Solo cacheamos si BCM anduvo para no pisar valores buenos por errores
// transitorios. Cuentas sin deuda quedan con cantidad=0, total=0.
if ($respuesta['payments']['ok']) {
    $totalesPorMoneda = [];
    foreach ($respuesta['payments']['actions'] as $a) {
        if ($a['amount'] === null || !$a['currency']) continue;
        $c = $a['currency'];
        $totalesPorMoneda[$c] = ($totalesPorMoneda[$c] ?? 0.0) + (float)$a['amount'];
    }
    if ($totalesPorMoneda) {
        arsort($totalesPorMoneda);
        $primaryCurrency = array_key_first($totalesPorMoneda);
        $primaryAmount   = $totalesPorMoneda[$primaryCurrency];
    } else {
        $primaryCurrency = 'USD';
        $primaryAmount   = 0.0;
    }
    $facturasCantidad = 0;
    foreach ($respuesta['match']['matches'] as $m) {
        if ($m['currency'] === $primaryCurrency) {
            $facturasCantidad += count($m['invoice_ids']);
        }
    }
    $upd = $pdo->prepare('UPDATE awscuentas SET
        facturas_cantidad    = :c,
        facturas_total       = :t,
        facturas_moneda      = :m,
        facturas_actualizado = NOW(),
        facturas_json        = :j
        WHERE id = :id');
    $upd->execute([
        ':c'  => $facturasCantidad,
        ':t'  => $primaryAmount,
        ':m'  => $primaryCurrency,
        ':j'  => json_encode($respuesta, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        ':id' => $id,
    ]);
}

$msgLog = sprintf(
    'Consulta billing cuenta #%d (%s): payments=%s, invoicing=%s, matches=%d',
    $id, $cuenta['nombre'],
    $respuesta['payments']['ok']  ? (count($respuesta['payments']['actions']) . ' acciones') : 'ERROR',
    $respuesta['invoicing']['ok'] ? ($respuesta['invoicing']['count']         . ' facturas') : 'ERROR',
    count($respuesta['match']['matches'])
);
registrarSuceso($pdo, 'awscuentas', 'info', $msgLog);

jsonOk($respuesta);

// ---------------------------------------------------------------------------
// Reconciliacion por subset-sum
// ---------------------------------------------------------------------------

/**
 * Busca el subset de facturas cuya suma total sea exactamente $targetAmount
 * (comparado en centavos para evitar problemas de precision float).
 *
 * Prefiere subsets mas chicos (empezamos por tamano 1 y crecemos). Si hay
 * mas de una combinacion posible del mismo tamano, devuelve la primera
 * encontrada (que suele ser la de las facturas mas recientes porque las
 * emitidas mas recientes se ordenan primero en la lista).
 *
 * @return array<string>|null Lista de invoice_ids matcheados, o null si no
 *   hubo match exacto en ninguna combinacion.
 */
function match_invoices_subset(array $invoices, float $targetAmount, string $currency): ?array {
    $candidates = [];
    foreach ($invoices as $inv) {
        if (($inv['currency'] ?? '') !== $currency)   continue;
        if (!isset($inv['total']) || $inv['total'] === null || $inv['invoice_id'] === null) continue;
        $amount = (float)$inv['total'];
        if ($amount <= 0) continue;
        $candidates[] = [
            'id'    => (string)$inv['invoice_id'],
            'cents' => (int)round($amount * 100),
        ];
    }
    $targetCents = (int)round($targetAmount * 100);
    if ($targetCents <= 0 || !$candidates) return null;

    // Facturas mas recientes primero (ya vienen ordenadas por el wrapper, pero
    // por si acaso ordenamos por cents desc para que subsets pequenos matcheen
    // con las facturas mas grandes que suelen ser las mas recientes).
    $n = count($candidates);
    for ($size = 1; $size <= $n; $size++) {
        $found = subset_of_size($candidates, $size, $targetCents, 0, [], 0);
        if ($found !== null) return $found;
    }
    return null;
}

/**
 * Backtracking: encuentra un subset de tamano exacto $size cuya suma en
 * centavos sea exactamente $targetCents.
 */
function subset_of_size(array $cands, int $size, int $targetCents, int $start, array $picked, int $sumSoFar): ?array {
    if (count($picked) === $size) {
        return $sumSoFar === $targetCents ? array_column($picked, 'id') : null;
    }
    $n = count($cands);
    $need = $size - count($picked);
    for ($i = $start; $i <= $n - $need; $i++) {
        $newSum = $sumSoFar + $cands[$i]['cents'];
        if ($newSum > $targetCents) continue;
        $newPicked = $picked;
        $newPicked[] = $cands[$i];
        $result = subset_of_size($cands, $size, $targetCents, $i + 1, $newPicked, $newSum);
        if ($result !== null) return $result;
    }
    return null;
}
