<?php
/**
 * Wrapper de la AWS Health API (health-2016-08-04) para consultar el estado
 * de facturacion de una cuenta AWS via IAM API credentials.
 *
 * Es lo que openclaw usa detras: `DescribeEvents(services=["BILLING"])`
 * devuelve eventos como AWS_BILLING_PAYMENTS_PAST_DUE / _PAYMENT_DECLINED /
 * _EXCESSIVE_ACTIVITY. `DescribeEventDetails` trae la descripcion completa
 * con el monto adeudado (texto libre, no un campo estructurado).
 *
 * IMPORTANTE: La AWS Health API requiere plan de soporte
 * Business / Enterprise On-Ramp / Enterprise. Sin eso devuelve
 * SubscriptionRequiredException.
 *
 * Region: los endpoints regionales estan, pero el servicio activo global
 * vive en us-east-1 y ese es el que se recomienda para consultas simples.
 */

require_once __DIR__ . '/awssig.php';

const HEALTH_REGION       = 'us-east-1';
const HEALTH_SERVICE      = 'health';
const HEALTH_HOST         = 'health.us-east-1.amazonaws.com';
const HEALTH_TARGET_PREFIX = 'AWSHealth_20160804';

/**
 * Devuelve los eventos abiertos/proximos de BILLING para la cuenta cuyas
 * credenciales pasamos. Cada evento se enriquece con `latestDescription` que
 * suele contener el monto adeudado.
 *
 * @return array {
 *   support_ok: bool,           // false si el plan de soporte no permite Health
 *   events: array<array>,       // eventos normalizados
 * }
 */
function health_billing_events(string $accessKey, string $secretKey): array {
    $payload = [
        'filter' => [
            'services'         => ['BILLING'],
            'eventStatusCodes' => ['open', 'upcoming'],
        ],
        'maxResults' => 20,
    ];

    try {
        $r = aws_json_rpc(
            $accessKey, $secretKey,
            HEALTH_REGION, HEALTH_SERVICE, HEALTH_HOST,
            HEALTH_TARGET_PREFIX . '.DescribeEvents',
            $payload,
            '1.1'
        );
    } catch (RuntimeException $e) {
        throw new RuntimeException('AWS Health: ' . $e->getMessage());
    }

    if ($r['status'] < 200 || $r['status'] >= 300) {
        $awsErr  = $r['decoded']['message'] ?? $r['decoded']['Message'] ?? $r['body'];
        $awsType = $r['decoded']['__type']  ?? ('HTTP ' . $r['status']);
        // Sin plan de soporte alto, AWS Health responde SubscriptionRequiredException.
        if (stripos($awsType, 'SubscriptionRequired') !== false) {
            return ['support_ok' => false, 'events' => []];
        }
        throw new RuntimeException('AWS Health error (' . $awsType . '): ' . $awsErr);
    }

    $rawEvents = $r['decoded']['events'] ?? [];
    if (!$rawEvents) return ['support_ok' => true, 'events' => []];

    // Trae la descripcion detallada de cada evento (contiene el monto).
    $eventArns = array_column($rawEvents, 'arn');
    $details   = health_event_details($accessKey, $secretKey, $eventArns);

    $events = [];
    foreach ($rawEvents as $ev) {
        $arn  = $ev['arn'] ?? '';
        $desc = $details[$arn] ?? null;
        $events[] = [
            'arn'                => $arn,
            'event_type_code'    => $ev['eventTypeCode']    ?? null,
            'event_type_category' => $ev['eventTypeCategory'] ?? null,
            'status_code'        => $ev['statusCode']       ?? null,
            'service'            => $ev['service']          ?? null,
            'region'             => $ev['region']           ?? null,
            'start_time'         => isset($ev['startTime']) ? date('c', (int)$ev['startTime']) : null,
            'end_time'           => isset($ev['endTime'])   ? date('c', (int)$ev['endTime'])   : null,
            'last_updated'       => isset($ev['lastUpdatedTime']) ? date('c', (int)$ev['lastUpdatedTime']) : null,
            'description'        => $desc,
            'monto_estimado'     => $desc ? health_extract_amount($desc) : null,
        ];
    }
    return ['support_ok' => true, 'events' => $events];
}

/**
 * Batch de DescribeEventDetails. Devuelve un mapa arn -> latestDescription.
 */
function health_event_details(string $accessKey, string $secretKey, array $eventArns): array {
    $out = [];
    if (!$eventArns) return $out;

    $payload = ['eventArns' => array_values(array_unique($eventArns))];
    $r = aws_json_rpc(
        $accessKey, $secretKey,
        HEALTH_REGION, HEALTH_SERVICE, HEALTH_HOST,
        HEALTH_TARGET_PREFIX . '.DescribeEventDetails',
        $payload,
        '1.1'
    );

    if ($r['status'] < 200 || $r['status'] >= 300) return $out;

    foreach (($r['decoded']['successfulSet'] ?? []) as $item) {
        $arn  = $item['event']['arn'] ?? null;
        $desc = $item['eventDescription']['latestDescription'] ?? null;
        if ($arn && $desc) $out[$arn] = $desc;
    }
    return $out;
}

/**
 * Heuristica para sacar el monto y la moneda de la descripcion del evento.
 * Los eventos de billing suelen incluir textos como "USD 50.16" o "$50.16 USD".
 * Devuelve la primera coincidencia o null.
 */
function health_extract_amount(string $desc): ?array {
    // "USD 1,234.56"  |  "USD 1234.56"  |  "$ 1234.56"
    if (preg_match('/(USD|EUR|ARS|CAD|GBP|BRL|MXN|JPY|AUD)\s*\$?\s*([\d,]+\.\d{2})/i', $desc, $m)) {
        return ['currency' => strtoupper($m[1]), 'amount' => str_replace(',', '', $m[2])];
    }
    if (preg_match('/\$\s*([\d,]+\.\d{2})\s*(USD|EUR|ARS|CAD|GBP|BRL|MXN|JPY|AUD)/i', $desc, $m)) {
        return ['currency' => strtoupper($m[2]), 'amount' => str_replace(',', '', $m[1])];
    }
    return null;
}
