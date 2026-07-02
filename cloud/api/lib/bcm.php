<?php
/**
 * Wrapper de la AWS Billing & Cost Management Recommended Actions API.
 *
 * Este es el servicio que reporta "hay deuda vencida" con montos concretos,
 * accesible via API key + secret (a diferencia de AWS Health, que requiere
 * plan de soporte Business+).
 *
 * Endpoint dualstack (.api.aws), no el clasico .amazonaws.com.
 *
 * Action: ListRecommendedActions
 * Filter: types PAYMENTS_DUE + PAYMENTS_PAST_DUE
 * Response fields por accion: severity, context.amount, context.currency,
 * nextSteps, lastUpdatedTimeStamp.
 */

require_once __DIR__ . '/awssig.php';

const BCM_REGION  = 'us-east-1';
const BCM_SERVICE = 'bcm-recommended-actions';
const BCM_HOST    = 'bcm-recommended-actions.us-east-1.api.aws';
const BCM_TARGET  = 'AWSBillingAndCostManagementRecommendedActions.ListRecommendedActions';

/**
 * Devuelve las recommended actions de tipo PAYMENTS_DUE / PAYMENTS_PAST_DUE.
 * Cada accion incluye monto adeudado, moneda y severidad.
 *
 * @return array { actions: array<array> }
 */
function bcm_payments_due(string $accessKey, string $secretKey): array {
    $payload = [
        'filter' => [
            'actions' => [[
                'key'         => 'TYPE',
                'matchOption' => 'EQUALS',
                'values'      => ['PAYMENTS_DUE', 'PAYMENTS_PAST_DUE'],
            ]],
        ],
        'maxResults' => 50,
    ];

    $r = aws_json_rpc(
        $accessKey, $secretKey,
        BCM_REGION, BCM_SERVICE, BCM_HOST,
        BCM_TARGET,
        $payload,
        '1.0'
    );

    if ($r['status'] < 200 || $r['status'] >= 300) {
        $awsErr  = $r['decoded']['message'] ?? $r['decoded']['Message'] ?? $r['body'];
        $awsType = $r['decoded']['__type']  ?? ('HTTP ' . $r['status']);
        throw new RuntimeException('AWS BCM error (' . $awsType . '): ' . $awsErr);
    }

    $raw = $r['decoded']['recommendedActions'] ?? $r['decoded']['RecommendedActions'] ?? [];
    $out = [];
    foreach ($raw as $a) {
        // Los nombres de campos pueden venir en camelCase (protocolo json) o PascalCase.
        $ctx = $a['context']    ?? $a['Context']    ?? [];
        $out[] = [
            'action_id'      => $a['actionId']              ?? $a['ActionId']              ?? null,
            'type'           => $a['type']                  ?? $a['Type']                  ?? bcm_extract_type($a),
            'severity'       => $a['severity']              ?? $a['Severity']              ?? null,
            'amount'         => $ctx['amount']              ?? $ctx['Amount']              ?? null,
            'currency'       => $ctx['currency']            ?? $ctx['Currency']            ?? null,
            'next_steps'     => $a['nextSteps']             ?? $a['NextSteps']             ?? null,
            'last_updated'   => $a['lastUpdatedTimeStamp']  ?? $a['LastUpdatedTimeStamp']  ?? null,
            'raw_context'    => $ctx,
        ];
    }
    return ['actions' => $out];
}

/**
 * Si el objeto viene con un array de "actions" en vez del type plano.
 */
function bcm_extract_type(array $a): ?string {
    if (isset($a['actions'][0]['values'][0])) return $a['actions'][0]['values'][0];
    return null;
}
