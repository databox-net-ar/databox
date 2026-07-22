<?php
/**
 * api/lib/awscuentas_billing.php
 * Nucleo compartido entre el endpoint api/awscuentas_facturas.php y el job
 * cloud/jobs/awscuentas_actualizar_facturas.php. Consulta a AWS el estado
 * de facturacion (BCM Recommended Actions + Invoicing), intenta reconciliar
 * la deuda contra las facturas emitidas via subset-sum, y cachea el
 * resultado en la fila de `aws_cuentas`.
 *
 * NO escribe en `sucesos`: cada caller decide como loguear el resultado
 * (el endpoint hoy escribe una linea de resumen; el job escribe una por
 * cuenta).
 */

require_once __DIR__ . '/bcm.php';
require_once __DIR__ . '/invoicing.php';

/**
 * Consulta BCM + Invoicing para una cuenta AWS, arma la reconciliacion y
 * actualiza el cache en `aws_cuentas`. Asume que la cuenta ya fue validada
 * (tiene numero, accesskey y secreto).
 *
 * @param array $cuenta Fila de aws_cuentas con id, nombre, numero, accesskey, secreto.
 * @return array{
 *   ok: bool,
 *   errores: array<string>,
 *   account_id: string,
 *   nombre: string,
 *   payments: array,
 *   invoicing: array,
 *   match: array,
 *   summary: string
 * }
 */
function actualizarBillingCuenta(PDO $pdo, array $cuenta, int $months = 6): array {
    if ($months < 1)  $months = 1;
    if ($months > 12) $months = 12;

    $id = (int) $cuenta['id'];
    $out = [
        'ok'         => true,
        'errores'    => [],
        'account_id' => $cuenta['numero'],
        'nombre'     => $cuenta['nombre'],
        'payments'   => null,
        'invoicing'  => null,
        'match'      => ['method' => 'subset-sum vs total PAYMENTS_DUE/PAST_DUE', 'matches' => []],
        'summary'    => '',
    ];

    // --- 1. BCM Recommended Actions: deuda vencida / por vencer ---
    try {
        $p = bcm_payments_due($cuenta['accesskey'], $cuenta['secreto']);
        $out['payments'] = ['ok' => true, 'actions' => $p['actions']];
    } catch (Throwable $e) {
        $out['ok']         = false;
        $out['errores'][]  = 'BCM: ' . $e->getMessage();
        $out['payments']   = ['ok' => false, 'actions' => [], 'error' => $e->getMessage()];
    }

    // --- 2. AWS Invoicing: facturas emitidas ---
    try {
        $inv = invoicing_list_account($cuenta['accesskey'], $cuenta['secreto'], $cuenta['numero'], $months);
        $inv['ok'] = true;
        $out['invoicing'] = $inv;
    } catch (Throwable $e) {
        $out['ok']         = false;
        $out['errores'][]  = 'Invoicing: ' . $e->getMessage();
        $out['invoicing']  = ['ok' => false, 'count' => 0, 'invoices' => [], 'error' => $e->getMessage()];
    }

    // --- 3. Reconciliacion: subset-sum de facturas contra el total adeudado ---
    if ($out['payments']['ok'] && $out['invoicing']['ok']) {
        $deudaByCurrency = [];
        foreach ($out['payments']['actions'] as $a) {
            if ($a['amount'] === null || !$a['currency']) continue;
            $c = $a['currency'];
            $deudaByCurrency[$c] = ($deudaByCurrency[$c] ?? 0.0) + (float)$a['amount'];
        }
        foreach ($deudaByCurrency as $currency => $total) {
            $ids = match_invoices_subset($out['invoicing']['invoices'], $total, $currency);
            if ($ids !== null) {
                $out['match']['matches'][] = [
                    'currency'    => $currency,
                    'total'       => $total,
                    'invoice_ids' => $ids,
                ];
            }
        }
    }

    // --- 4. Cachear en aws_cuentas (solo si BCM anduvo) ---
    if ($out['payments']['ok']) {
        $totalesPorMoneda = [];
        foreach ($out['payments']['actions'] as $a) {
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
        foreach ($out['match']['matches'] as $m) {
            if ($m['currency'] === $primaryCurrency) {
                $facturasCantidad += count($m['invoice_ids']);
            }
        }
        $upd = $pdo->prepare('UPDATE aws_cuentas SET
            facturas_cantidad    = :c,
            facturas_total       = :t,
            facturas_moneda      = :m,
            facturas_actualizado = NOW(),
            facturas_json        = :j,
            actualizada          = NOW()
            WHERE id = :id');
        $upd->execute([
            ':c'  => $facturasCantidad,
            ':t'  => $primaryAmount,
            ':m'  => $primaryCurrency,
            ':j'  => json_encode($out, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            ':id' => $id,
        ]);
    }

    $out['summary'] = sprintf(
        'cuenta #%d (%s): payments=%s, invoicing=%s, matches=%d',
        $id, $cuenta['nombre'],
        $out['payments']['ok']  ? (count($out['payments']['actions']) . ' acciones') : 'ERROR',
        $out['invoicing']['ok'] ? ($out['invoicing']['count']         . ' facturas') : 'ERROR',
        count($out['match']['matches'])
    );

    return $out;
}

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
