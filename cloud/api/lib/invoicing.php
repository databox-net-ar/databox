<?php
/**
 * Wrapper de la AWS Invoicing API (invoicing-2024-12-01).
 * Documenta https://docs.aws.amazon.com/aws-cost-management/latest/APIReference/API_Operations_AWS_Invoicing.html
 *
 * Solo cubre ListInvoiceSummaries (facturas emitidas para una cuenta). El
 * servicio no expone un flag "pagada / impaga"; para eso hay que mirar
 * DueDate vs today en la UI.
 *
 * Region: la API Invoicing solo esta en us-east-1 (billing global).
 */

require_once __DIR__ . '/awssig.php';

const INVOICING_REGION  = 'us-east-1';
const INVOICING_SERVICE = 'invoicing';
// El servicio Invoicing solo esta en el dominio dualstack .api.aws (dnsSuffix
// nuevo de botocore endpointrules). En invoicing.us-east-1.amazonaws.com el
// mismo target devuelve UnknownOperationException.
const INVOICING_HOST    = 'invoicing.us-east-1.api.aws';

/**
 * Lista las invoice summaries de una AWS account.
 *
 * @param string $accessKey Access Key ID de la cuenta.
 * @param string $secretKey Secret Access Key.
 * @param string $accountId Numero de la cuenta AWS (12 digitos).
 * @param int    $months    Cuantos meses hacia atras traer (default 12).
 * @return array Lista normalizada de invoices + metadata.
 */
function invoicing_list_account(string $accessKey, string $secretKey, string $accountId, int $months = 12): array {
    if ($months < 1)  $months = 1;
    if ($months > 60) $months = 60;

    // La API filtra por BillingPeriod (mes/anio), no por rango libre.
    // Iteramos los ultimos $months meses y agregamos los resultados.
    $now       = time();
    $summaries = [];
    for ($i = 0; $i < $months; $i++) {
        $ts    = strtotime('-' . $i . ' months', $now);
        $month = (int)date('n', $ts);
        $year  = (int)date('Y', $ts);

        $payload = [
            'Selector' => [
                'ResourceType' => 'ACCOUNT_ID',
                'Value'        => $accountId,
            ],
            'Filter' => [
                'BillingPeriod' => ['Month' => $month, 'Year' => $year],
            ],
            'MaxResults' => 20,
        ];

        $r = aws_json_rpc(
            $accessKey, $secretKey,
            INVOICING_REGION, INVOICING_SERVICE, INVOICING_HOST,
            'Invoicing.ListInvoiceSummaries',
            $payload,
            '1.0'
        );

        if ($r['status'] < 200 || $r['status'] >= 300) {
            $awsErr  = $r['decoded']['message'] ?? $r['decoded']['Message'] ?? $r['body'];
            $awsType = $r['decoded']['__type']  ?? $r['decoded']['code']    ?? ('HTTP ' . $r['status']);
            throw new RuntimeException('AWS Invoicing error (' . $awsType . '): ' . $awsErr);
        }
        foreach (($r['decoded']['InvoiceSummaries'] ?? []) as $s) {
            $summaries[] = $s;
        }
    }

    $start = strtotime('-' . ($months - 1) . ' months', $now);
    $end   = $now;
    $invoices = [];
    foreach ($summaries as $s) {
        $paymentAmount = $s['PaymentCurrencyAmount'] ?? $s['BaseCurrencyAmount'] ?? [];
        $invoices[] = [
            'invoice_id'      => $s['InvoiceId']            ?? null,
            'commercial_id'   => $s['CommercialInvoiceId']  ?? null,
            'bill_type'       => $s['BillType']             ?? null,
            'invoice_type'    => $s['InvoiceType']          ?? null,
            'issued_date'     => isset($s['IssuedDate']) ? invoicing_epoch_to_iso($s['IssuedDate']) : null,
            'due_date'        => isset($s['DueDate'])    ? invoicing_epoch_to_iso($s['DueDate'])    : null,
            'billing_period'  => isset($s['BillingPeriod'])
                ? sprintf('%04d-%02d', $s['BillingPeriod']['Year'] ?? 0, $s['BillingPeriod']['Month'] ?? 0)
                : null,
            'total'           => $paymentAmount['TotalAmount']   ?? null,
            'currency'        => $paymentAmount['CurrencyCode']  ?? null,
        ];
    }

    return [
        'account_id' => $accountId,
        'range'      => [
            'start' => date('Y-m-d', $start),
            'end'   => date('Y-m-d', $end),
        ],
        'count'    => count($invoices),
        'invoices' => $invoices,
    ];
}

/**
 * La API devuelve fechas como epoch en segundos (numero). Las convertimos
 * a ISO 8601 (YYYY-MM-DD) para que el front las muestre en formato local.
 */
function invoicing_epoch_to_iso($epoch): ?string {
    if (!is_numeric($epoch)) return null;
    // Si viene en milisegundos (>10 digitos), lo bajamos a segundos.
    $s = (int)$epoch;
    if ($s > 20000000000) $s = (int)($s / 1000);
    return date('Y-m-d', $s);
}
