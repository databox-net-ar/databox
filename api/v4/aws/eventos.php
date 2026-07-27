<?php
// api/v4/aws/eventos.php
// Webhook receptor de notificaciones AWS SNS con eventos SES (Delivery /
// Bounce / Complaint / Reject / Open / Click / Send / DeliveryDelay /
// RenderingFailure). Este endpoint es publico (SNS no manda credenciales)
// pero valida la firma del sobre SNS antes de procesar.
//
//   POST /v4/aws/eventos   (body JSON del sobre SNS)
//
// Flujo:
//   1) Parsear el sobre JSON de SNS.
//   2) Validar firma (host del cert es *.amazonaws.com, openssl_verify).
//   3) Si Type=SubscriptionConfirmation -> GET al SubscribeURL para
//      confirmar la suscripcion (Amazon deja subscripta la URL al hacer eso).
//   4) Si Type=Notification:
//      - Deduplicar por SNS MessageId contra aws_eventos.sns_message_id.
//      - Extraer eventType, subtipo, destino, MessageId SES del payload
//        SES anidado (SNS.Message es un string JSON).
//      - Insertar en aws_eventos con el raw completo.
//      - Mapear eventType -> aws_mensajes.resultado y actualizar si el
//        estado nuevo pisa al viejo segun la precedencia definida abajo.
//   5) Responder 200 lo antes posible (SNS reintenta ante 5xx).
//
// Precedencia de aws_mensajes.resultado (mayor gana; solo se pisa hacia
// arriba):
//   1 = entregado   (Delivery)
//   2 = abierto     (Open — refina la entrega)
//   3 = spam        (Complaint — mas grave)
//   4 = rebotado    (Bounce — terminal)
//   5 = rechazado   (Reject — SES ni siquiera intento enviar)
//
// La primera entrega (Delivery) suele llegar antes que Open; despues Open
// puede llegar N veces (solo la primera nos importa). Bounce/Complaint son
// terminales; Reject es pre-envio y suele venir sin previa Delivery.

header('Content-Type: application/json; charset=utf-8');

require_once dirname(__DIR__, 3) . '/env.php';
require_once dirname(__DIR__, 3) . '/cloud/api/db.php';
require_once dirname(__DIR__, 3) . '/cloud/api/lib/sucesos.php';

const AWS_EVT_ORIGEN = 'v4/aws/eventos';

// Precedencia y mapa tipo->resultado. Deben declararse antes del bloque `try`
// porque los `const` top-level en PHP no se hoistean: se procesan en el orden
// lexical. Si quedan debajo del dispatcher, actualizar_resultado_mensaje()
// revienta con "Undefined constant" (bug ya visto y arreglado en awsmensajes.php).
//
// Precedencia: mayor pisa a menor. NULL siempre se sobreescribe.
// Semantica: los estados positivos de engagement (abierto/cliqueado) suben
// desde entregado; los estados negativos terminales (spam/rebotado/rechazado)
// pisan cualquier estado positivo — un click seguido de complaint tiene que
// quedar en 'spam', no en 'cliqueado'.
const AWS_EVT_RESULTADO_PRECEDENCIA = [
    'entregado' => 1,
    'abierto'   => 2,
    'cliqueado' => 3,
    'spam'      => 4,
    'rebotado'  => 5,
    'rechazado' => 6,
];
const AWS_EVT_TIPO_A_RESULTADO = [
    'delivery'  => 'entregado',
    'open'      => 'abierto',
    'click'     => 'cliqueado',   // engagement mas fuerte que open
    'complaint' => 'spam',
    'bounce'    => 'rebotado',
    'reject'    => 'rechazado',
];

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'error' => 'Metodo no soportado']);
    exit;
}

try {
    $raw = file_get_contents('php://input') ?: '';
    if ($raw === '') {
        registrarSuceso(db(), AWS_EVT_ORIGEN, 'alerta', 'POST con body vacio');
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => 'Body vacio']);
        exit;
    }
    $sobre = json_decode($raw, true);
    if (!is_array($sobre) || empty($sobre['Type'])) {
        registrarSuceso(db(), AWS_EVT_ORIGEN, 'alerta', 'Body no es un sobre SNS valido: ' . substr($raw, 0, 500));
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => 'Body no es un sobre SNS']);
        exit;
    }

    $tipoSobre = (string)($sobre['Type'] ?? '');
    $snsId     = (string)($sobre['MessageId'] ?? '');

    if (!sns_verificar_firma($sobre)) {
        registrarSuceso(db(), AWS_EVT_ORIGEN, 'alerta',
            "Firma SNS invalida — tipo='{$tipoSobre}' msgId='{$snsId}'");
        http_response_code(403);
        echo json_encode(['ok' => false, 'error' => 'Firma SNS invalida']);
        exit;
    }

    if ($tipoSobre === 'SubscriptionConfirmation' || $tipoSobre === 'UnsubscribeConfirmation') {
        $subscribeUrl = (string)($sobre['SubscribeURL'] ?? '');
        $confirmado   = false;
        $errFetch     = '';
        if ($subscribeUrl !== '') {
            [$confirmado, $errFetch] = fetch_subscribe_url($subscribeUrl);
        }
        registrarSuceso(db(), AWS_EVT_ORIGEN, $confirmado ? 'info' : 'alerta',
            "{$tipoSobre} recibida (topic=" . (string)($sobre['TopicArn'] ?? '?') . ") — "
            . ($confirmado ? "SubscribeURL OK" : "SubscribeURL FALLO: {$errFetch}"));
        echo json_encode(['ok' => $confirmado, 'confirmado' => $confirmado, 'error' => $errFetch ?: null]);
        exit;
    }

    if ($tipoSobre !== 'Notification') {
        // Tipo desconocido — 200 igual para que SNS no reintente.
        registrarSuceso(db(), AWS_EVT_ORIGEN, 'info', "Tipo desconocido ignorado: {$tipoSobre}");
        echo json_encode(['ok' => true, 'ignorado' => $tipoSobre]);
        exit;
    }

    procesar_notificacion(db(), $sobre, $raw);
    echo json_encode(['ok' => true]);
    exit;

} catch (Throwable $e) {
    // Log del error y 200 igual — no queremos que SNS reintente por bugs
    // nuestros. Los errores quedan en el log de PHP + tabla sucesos.
    error_log('aws/eventos.php: ' . $e->getMessage());
    try {
        registrarSuceso(db(), AWS_EVT_ORIGEN, 'error',
            'Excepcion procesando webhook: ' . $e->getMessage());
    } catch (Throwable $_) { /* si ni sucesos anda, ya es pescado */ }
    echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
    exit;
}

// Descarga el SubscribeURL usando cURL (mas confiable que file_get_contents
// — allow_url_fopen puede estar off, y cURL da un error message decente
// para logear). Devuelve [bool ok, string errorMsg].
function fetch_subscribe_url(string $url): array {
    if (!function_exists('curl_init')) {
        // Fallback a file_get_contents con logging del ultimo error.
        $ok = @file_get_contents($url) !== false;
        return [$ok, $ok ? '' : (error_get_last()['message'] ?? 'file_get_contents fallo')];
    }
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_TIMEOUT        => 10,
        CURLOPT_USERAGENT      => 'databox-sns-webhook/1.0',
    ]);
    $body = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err  = curl_error($ch);
    // curl_close() esta deprecada desde PHP 8 — el handle se libera solo al
    // salir de scope.
    if ($body === false) return [false, "cURL: {$err}"];
    if ($code < 200 || $code >= 300) return [false, "HTTP {$code}: " . substr((string)$body, 0, 200)];
    return [true, ''];
}

// ---------------------------------------------------------------------------
// Procesamiento de la notificacion
// ---------------------------------------------------------------------------

function procesar_notificacion(PDO $pdo, array $sobre, string $rawSobre): void {
    $snsMessageId = (string)($sobre['MessageId'] ?? '');

    // Deduplicacion: si ya guardamos este SNS MessageId, no lo re-procesamos.
    // Aprovechamos el UNIQUE KEY uk_sns_message_id (ver migracion 2400).
    if ($snsMessageId !== '') {
        $st = $pdo->prepare('SELECT id FROM aws_eventos WHERE sns_message_id = :m LIMIT 1');
        $st->execute([':m' => $snsMessageId]);
        if ($st->fetchColumn()) return;
    }

    // El SES event viene como string JSON dentro de sobre.Message.
    $sesMsg = @json_decode((string)($sobre['Message'] ?? ''), true);
    if (!is_array($sesMsg)) {
        // Guardar igual para debug — el ABM lo va a mostrar como raw crudo.
        insertar_evento($pdo, [
            'uuid'           => null,
            'sns_message_id' => $snsMessageId ?: null,
            'tipo'           => 'invalido',
            'subtipo'        => null,
            'destino'        => null,
            'raw'            => $rawSobre,
        ]);
        return;
    }

    // La estructura tipica del SES event tiene:
    //   { "eventType": "Bounce", "mail": { "messageId": "...", "destination": [...] },
    //     "bounce": { "bounceType": "Permanent", "bouncedRecipients": [...] } }
    // Otras posibles: delivery, complaint, reject, open, click, send, deliveryDelay,
    // renderingFailure. Algunos payloads antiguos usan "notificationType" en vez
    // de "eventType".
    $tipo = strtolower((string)($sesMsg['eventType'] ?? $sesMsg['notificationType'] ?? 'desconocido'));

    // Extraer subtipo y destino segun el tipo.
    $subtipo = null;
    $destino = null;
    if ($tipo === 'bounce' && isset($sesMsg['bounce'])) {
        $subtipo = (string)($sesMsg['bounce']['bounceType'] ?? '');
        $destino = (string)($sesMsg['bounce']['bouncedRecipients'][0]['emailAddress'] ?? '');
    } elseif ($tipo === 'complaint' && isset($sesMsg['complaint'])) {
        $subtipo = (string)($sesMsg['complaint']['complaintFeedbackType'] ?? '');
        $destino = (string)($sesMsg['complaint']['complainedRecipients'][0]['emailAddress'] ?? '');
    } elseif ($tipo === 'delivery' && isset($sesMsg['delivery'])) {
        $destino = (string)($sesMsg['delivery']['recipients'][0] ?? '');
    } elseif ($tipo === 'reject' && isset($sesMsg['reject'])) {
        $subtipo = (string)($sesMsg['reject']['reason'] ?? '');
    }

    // Fallback: destino desde mail.destination si no lo trajo el sub-nodo.
    if (!$destino && isset($sesMsg['mail']['destination'][0])) {
        $destino = (string)$sesMsg['mail']['destination'][0];
    }

    $uuid = (string)($sesMsg['mail']['messageId'] ?? '');

    insertar_evento($pdo, [
        'uuid'           => $uuid ?: null,
        'sns_message_id' => $snsMessageId ?: null,
        'tipo'           => $tipo,
        'subtipo'        => $subtipo ?: null,
        'destino'        => $destino ?: null,
        'raw'            => (string)($sobre['Message'] ?? $rawSobre),
    ]);

    // Actualizar aws_mensajes.resultado si aplica.
    if ($uuid !== '') {
        actualizar_resultado_mensaje($pdo, $uuid, $tipo);
    }
}

function insertar_evento(PDO $pdo, array $ev): void {
    $st = $pdo->prepare("
        INSERT IGNORE INTO aws_eventos
            (uuid, sns_message_id, tipo, subtipo, destino, raw, recibido)
        VALUES
            (:uuid, :sns, :tipo, :subtipo, :destino, :raw, :recibido)
    ");
    $st->execute([
        ':uuid'     => $ev['uuid'],
        ':sns'      => $ev['sns_message_id'],
        ':tipo'     => $ev['tipo'],
        ':subtipo'  => $ev['subtipo'],
        ':destino'  => $ev['destino'],
        ':raw'      => $ev['raw'],
        ':recibido' => (new DateTime('now', new DateTimeZone('America/Argentina/Buenos_Aires')))
                       ->format('Y-m-d H:i:s'),
    ]);
}

// (Constantes AWS_EVT_RESULTADO_PRECEDENCIA / AWS_EVT_TIPO_A_RESULTADO
// declaradas al inicio del archivo — const top-level no se hoistea.)

function actualizar_resultado_mensaje(PDO $pdo, string $uuid, string $tipo): void {
    $nuevo = AWS_EVT_TIPO_A_RESULTADO[$tipo] ?? null;
    if ($nuevo === null) return;   // send/click/deliveryDelay/etc. -> no cambian resultado

    $st = $pdo->prepare('SELECT resultado FROM aws_mensajes WHERE uuid = :u LIMIT 1');
    $st->execute([':u' => $uuid]);
    $actual = $st->fetchColumn();
    if ($actual === false) return;   // no hay mensaje con ese uuid (evento huerfano)

    $precActual = AWS_EVT_RESULTADO_PRECEDENCIA[$actual] ?? 0;
    $precNuevo  = AWS_EVT_RESULTADO_PRECEDENCIA[$nuevo]  ?? 0;
    if ($precNuevo <= $precActual) return;   // no downgrade

    $upd = $pdo->prepare('UPDATE aws_mensajes SET resultado = :r WHERE uuid = :u');
    $upd->execute([':r' => $nuevo, ':u' => $uuid]);
}

// ---------------------------------------------------------------------------
// Verificacion de firma SNS (Signature Version 1)
// https://docs.aws.amazon.com/sns/latest/dg/sns-verify-signature-of-message.html
// ---------------------------------------------------------------------------

function sns_verificar_firma(array $sobre): bool {
    $tipoSobre = (string)($sobre['Type'] ?? '');
    $version   = (string)($sobre['SignatureVersion'] ?? '');
    $signature = (string)($sobre['Signature'] ?? '');
    $certUrl   = (string)($sobre['SigningCertURL'] ?? '');
    // AWS SNS soporta SignatureVersion 1 (SHA1, historica) y 2 (SHA256,
    // default para topics creados post-2022). Aceptamos ambas.
    if (!in_array($version, ['1', '2'], true) || $signature === '' || $certUrl === '') return false;

    // Anti-SSRF: el cert URL debe ser un host amazonaws.com sobre https.
    $partes = parse_url($certUrl);
    if (!$partes || ($partes['scheme'] ?? '') !== 'https') return false;
    $host = strtolower((string)($partes['host'] ?? ''));
    if (!preg_match('/^sns\.[a-z0-9\-]+\.amazonaws\.com$/', $host)) return false;

    // String-to-sign segun tipo. Los campos y su orden estan documentados
    // por AWS y NO se pueden cambiar (orden alfabetico, saltar campos que
    // no estan presentes).
    $campos = ($tipoSobre === 'Notification')
        ? ['Message', 'MessageId', 'Subject', 'Timestamp', 'TopicArn', 'Type']
        : ['Message', 'MessageId', 'SubscribeURL', 'Timestamp', 'Token', 'TopicArn', 'Type'];
    $strToSign = '';
    foreach ($campos as $c) {
        if (!array_key_exists($c, $sobre)) continue;   // Subject es opcional
        $strToSign .= $c . "\n" . (string)$sobre[$c] . "\n";
    }

    $cert = sns_fetch_cert($certUrl);
    if ($cert === null) return false;
    $pub = openssl_pkey_get_public($cert);
    if ($pub === false) return false;

    $sigBin = base64_decode($signature, true);
    if ($sigBin === false) return false;

    $alg = $version === '2' ? OPENSSL_ALGO_SHA256 : OPENSSL_ALGO_SHA1;
    $ok  = openssl_verify($strToSign, $sigBin, $pub, $alg);
    return $ok === 1;
}

// Cache filesystem simple del cert (comparte cache entre requests del proc,
// y persistente entre reboots via /tmp). Amazon rota certs raramente asi
// que 7 dias de cache es seguro.
function sns_fetch_cert(string $url): ?string {
    $cacheFile = sys_get_temp_dir() . '/sns_cert_' . md5($url) . '.pem';
    if (is_file($cacheFile) && (time() - filemtime($cacheFile)) < 7 * 86400) {
        return @file_get_contents($cacheFile) ?: null;
    }
    $ctx = stream_context_create(['http' => ['timeout' => 5]]);
    $cert = @file_get_contents($url, false, $ctx);
    if (!$cert) return null;
    @file_put_contents($cacheFile, $cert);
    return $cert;
}
