<?php
/**
 * cloud/api/lib/datacount_comprobantes_webhook.php
 *
 * Canal unico para notificar via webhook a `datacount_comprobantes.webhook_url`
 * cuando el comprobante logra autorizarse contra AFIP. Compartida por:
 *
 *   - `api/v4/datacount/comprobantes.php`  (ingesta v4, autorizacion sincronica)
 *   - `cloud/jobs/datacount_comprobantes_notificar.php`  (cron de retry)
 *
 * Espeja el patron de la lib de autorizacion (datacount_comprobantes_autorizar):
 * un solo lugar arma el payload, hace el POST y actualiza `webhook_estado`, para
 * que no puedan divergir el disparo online (v4) y el de retry (cron).
 *
 * Best-effort: cualquier fallo (timeout, HTTP != 2xx, cURL error) NO propaga
 * excepciones. El comprobante ya esta autorizado -- el webhook es solo
 * notificacion. En caso de fallo `webhook_estado` queda en 'pendiente' y el
 * cron lo reintenta en el proximo tick.
 *
 * Se registra suceso 'info' en OK y 'alerta' en fallo para trazabilidad.
 */

declare(strict_types=1);

const DCC_WEBHOOK_TIMEOUT_S = 8;
const DCC_WEBHOOK_CONNECT_S = 3;

// User-Agent -- identifica al proyecto en los logs del receptor.
const DCC_WEBHOOK_UA = 'databox/datacount-comprobantes-webhook';

/**
 * Reconstruye el payload que se envia al webhook a partir del comprobante en
 * BD. Mismo shape que el `data` de la response 201 del endpoint v4, para que
 * el receptor no tenga que distinguir la fuente (online vs cron).
 *
 * Devuelve null si el comprobante no existe.
 */
function dccWebhookBuildPayload(PDO $pdo, int $id): ?array {
    $st = $pdo->prepare("
        SELECT id, uuid, talonario, proyecto, empresa, tipo, punto, serie,
               fiscal, caenro, caevto, caeres, emision, vencimiento, concepto,
               asociado, contrato, cliente, razon, condicion, cuit, domicilio,
               correo, celular, neto, iva, total, observaciones, comentarios,
               medio, registrado, autorizado, estado, webhook_url, webhook_estado
          FROM datacount_comprobantes
         WHERE id = :id
         LIMIT 1
    ");
    $st->execute([':id' => $id]);
    $c = $st->fetch();
    if (!$c) return null;

    // El shape refleja el `$out` que arma el endpoint v4 al autorizar OK.
    // `serie` y `cbte_nro` traen el mismo valor (columna `serie` de la tabla,
    // que se setea con el CbteNro que devuelve AFIP).
    $cbteNro = $c['serie'] !== null ? (int)$c['serie'] : null;
    return [
        'id'             => (int)$c['id'],
        'uuid'           => $c['uuid'],
        'talonario'      => $c['talonario'] !== null ? (int)$c['talonario'] : null,
        'proyecto'       => $c['proyecto']  !== null ? (int)$c['proyecto']  : null,
        'empresa'        => $c['empresa']   !== null ? (int)$c['empresa']   : null,
        'tipo'           => $c['tipo'],
        'punto'          => $c['punto']     !== null ? (int)$c['punto']     : null,
        'fiscal'         => $c['fiscal'],
        'concepto'       => $c['concepto']  !== null ? (int)$c['concepto']  : null,
        'emision'        => $c['emision'],
        'vencimiento'    => $c['vencimiento'],
        'neto'           => $c['neto'],
        'iva'            => $c['iva'],
        'total'          => $c['total'],
        'estado'         => $c['estado'],
        'registrado'     => $c['registrado'],
        'webhook_url'    => $c['webhook_url'],
        'webhook_estado' => $c['webhook_estado'],
        'cae'            => $c['caenro'],
        'cae_vto'        => $c['caevto'],
        'cbte_nro'       => $cbteNro,
        'serie'          => $cbteNro,
        'autorizado'     => $c['autorizado'],
        'caeres'         => $c['caeres'],
    ];
}

/**
 * POST JSON al webhook + persiste `webhook_estado` con el resultado.
 *
 * `origen` se usa para etiquetar el suceso (ej. 'v4/datacount.comprobantes' o
 * 'cron/datacount_comprobantes_notificar') para poder diferenciar en el visor
 * si el disparo vino del alta online o del cron de retry.
 *
 * Devuelve: 'completado' | 'pendiente'.
 */
function dccWebhookDisparar(PDO $pdo, int $id, string $url, array $payload, string $origen): string {
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => json_encode($payload, JSON_UNESCAPED_UNICODE),
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => DCC_WEBHOOK_TIMEOUT_S,
        CURLOPT_CONNECTTIMEOUT => DCC_WEBHOOK_CONNECT_S,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_MAXREDIRS      => 3,
        CURLOPT_HTTPHEADER     => [
            'Content-Type: application/json; charset=utf-8',
            'Accept: application/json',
            'User-Agent: ' . DCC_WEBHOOK_UA,
        ],
    ]);
    $raw    = curl_exec($ch);
    $errStr = curl_error($ch);
    $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    $ok          = ($raw !== false) && $status >= 200 && $status < 300;
    $nuevoEstado = $ok ? 'completado' : 'pendiente';

    try {
        $upd = $pdo->prepare('UPDATE datacount_comprobantes SET webhook_estado = :s WHERE id = :id');
        $upd->execute([':s' => $nuevoEstado, ':id' => $id]);
    } catch (Throwable $_) { /* no romper el flujo */ }

    try {
        if ($ok) {
            registrarSuceso($pdo, $origen, 'info',
                "cbte#{$id} webhook OK (HTTP {$status}) -> {$url}");
        } else {
            $motivo = $raw === false
                ? ('cURL: ' . ($errStr !== '' ? $errStr : 'sin detalle'))
                : "HTTP {$status}";
            registrarSuceso($pdo, $origen, 'alerta',
                "cbte#{$id} webhook fallo ({$motivo}) -> {$url}");
        }
    } catch (Throwable $_) { /* no romper el flujo */ }

    return $nuevoEstado;
}
