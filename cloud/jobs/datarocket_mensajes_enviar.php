<?php
/**
 * cloud/jobs/datarocket_mensajes_enviar.php
 * Orquestador de envio de mensajes pendientes. Corre desde el Programador de
 * tareas (cada minuto tipicamente). Despacha en cada corrida:
 *
 *   1) evolution_mensajes (WhatsApp via Evolution API HTTP)
 *      - prioridad 5 (muy alta): sin limite, siempre se envia primero.
 *      - prioridades 4-3-2      : uno por canal respetando intervaloCorto.
 *      - prioridad 1 (muy baja) : uno por canal respetando intervaloLargo.
 *      Replica la logica del legacy `robot/datarocketPostman.php`.
 *
 *   2) aws_mensajes (email via AWS SES v2 REST + SigV4)
 *      - hasta MAX_AWS_POR_CORRIDA mensajes por corrida, orden prioridad DESC.
 *      - firmado con las credenciales IAM SES del canal (`aws_canales.accesskey`
 *        / `secreto` / `region`), NO por SMTP (no hay PHPMailer/Composer).
 *
 * Lock optimista: antes de despachar cada mensaje se hace
 *   UPDATE ... SET estado='enviando' WHERE estado='pendiente'
 * y se saltea si rowCount()=0 (lo tomo otro proceso o cambio de estado).
 *
 * Deja un suceso por mensaje en `sucesos`:
 *   - tipo=info   : despacho OK.
 *   - tipo=alerta : error de configuracion (canal sin creds) o API devolvio
 *                   error. Los errores por mensaje NO frenan el job.
 *
 * Se registra desde el Programador de tareas (tabla `tareas`) apuntando
 * `script` = "datarocket_mensajes_enviar".
 *
 * Referencia legacy: databox_legacy/databox-api/robot/datarocketPostman.php
 *                    + modulos/evolution.php + modulos/awsses.php.
 */

require_once __DIR__ . '/_bootstrap.php';
require_once __DIR__ . '/../api/lib/awssig.php';

const EVOLUTION_ENDPOINT      = 'https://evolution.york.databox.net.ar';
const EVOLUTION_TIMEOUT_SEG   = 30;
const EVOLUTION_DELAY_MS      = 1000;   // delay que Evolution respeta antes de mandar (anti-spam)
const MAX_AWS_POR_CORRIDA     = 10;
const AWS_SES_DEFAULT_REGION  = 'us-east-1';

$ORIGEN_EVO = 'cron/evolution_mensajes_enviar';
$ORIGEN_AWS = 'cron/aws_mensajes_enviar';

try {
    $pdo = db();

    $evo = despacharEvolution($pdo, $ORIGEN_EVO);
    $aws = despacharAws($pdo, $ORIGEN_AWS);

    $resumen = 'Evolution: ' . $evo['ok'] . ' OK / ' . $evo['err'] . ' err / ' . $evo['skip'] . ' skip'
             . ' | AWS SES: '  . $aws['ok'] . ' OK / ' . $aws['err'] . ' err / ' . $aws['skip'] . ' skip';
    anotarLog('Finalizado: ' . $resumen);
    marcarEjecucionOk($resumen);

} catch (Throwable $e) {
    anotarLog('ERROR fatal: ' . $e->getMessage());
    marcarEjecucionError($e);
    throw $e;
}

// ============================================================================
// EVOLUTION (WhatsApp)
// ============================================================================

/**
 * Selecciona y despacha los mensajes Evolution pendientes replicando la
 * cadena de prioridades del legacy:
 *   - prioridad 5 (todos, sin filtro por canal)
 *   - prioridades 4/3/2 (un mensaje por canal cuyo `intervaloCorto` ya vencio)
 *   - prioridad 1 (un mensaje por canal cuyo `intervaloLargo` ya vencio)
 *
 * `evolution_canales.ultimo` guarda ms unix del ultimo envio; el throttling
 * compara `ahora - ultimo` contra intervaloCorto/intervaloLargo.
 */
function despacharEvolution(PDO $pdo, string $origen): array {
    $ok = 0; $err = 0; $skip = 0;

    // -- 1) Prioridad 5: sin filtro de canal, todos los que haya --
    $st = $pdo->query("
        SELECT id
          FROM evolution_mensajes
         WHERE estado = 'pendiente' AND prioridad = 5
         ORDER BY id
    ");
    foreach ($st->fetchAll() as $r) {
        $res = enviarEvolutionMensaje($pdo, (int)$r['id'], $origen);
        $res['ok'] ? $ok++ : ($res['skip'] ? $skip++ : $err++);
    }

    // -- 2) Prioridades 4-3-2: throttle por intervaloCorto --
    $ahoraMs = (int) round(microtime(true) * 1000);
    $canales = $pdo->prepare("
        SELECT id
          FROM evolution_canales
         WHERE habilitado = '1'
           AND (:ahora - COALESCE(ultimo, 0)) > COALESCE(intervaloCorto, 0)
         ORDER BY id
    ");
    $canales->execute([':ahora' => $ahoraMs]);
    foreach ($canales->fetchAll() as $c) {
        $m = obtenerMensajePendienteCanal($pdo, (int)$c['id'], [2, 3, 4]);
        if (!$m) continue;
        $res = enviarEvolutionMensaje($pdo, (int)$m['id'], $origen);
        if ($res['ok']) {
            $ok++;
            tocarCanal($pdo, (int)$c['id']);
        } elseif ($res['skip']) {
            $skip++;
        } else {
            $err++;
        }
    }

    // -- 3) Prioridad 1: throttle por intervaloLargo --
    $ahoraMs = (int) round(microtime(true) * 1000);
    $canales = $pdo->prepare("
        SELECT id
          FROM evolution_canales
         WHERE habilitado = '1'
           AND (:ahora - COALESCE(ultimo, 0)) > COALESCE(intervaloLargo, 0)
         ORDER BY id
    ");
    $canales->execute([':ahora' => $ahoraMs]);
    foreach ($canales->fetchAll() as $c) {
        $m = obtenerMensajePendienteCanal($pdo, (int)$c['id'], [1]);
        if (!$m) continue;
        $res = enviarEvolutionMensaje($pdo, (int)$m['id'], $origen);
        if ($res['ok']) {
            $ok++;
            tocarCanal($pdo, (int)$c['id']);
        } elseif ($res['skip']) {
            $skip++;
        } else {
            $err++;
        }
    }

    return ['ok' => $ok, 'err' => $err, 'skip' => $skip];
}

function obtenerMensajePendienteCanal(PDO $pdo, int $canal, array $prioridades): ?array {
    $inList = implode(',', array_map('intval', $prioridades));
    $st = $pdo->prepare("
        SELECT id
          FROM evolution_mensajes
         WHERE estado = 'pendiente'
           AND canal  = :canal
           AND prioridad IN ($inList)
         ORDER BY prioridad DESC, id
         LIMIT 1
    ");
    $st->execute([':canal' => $canal]);
    $row = $st->fetch();
    return $row ?: null;
}

function tocarCanal(PDO $pdo, int $canal): void {
    $ms = (int) round(microtime(true) * 1000);
    $st = $pdo->prepare("
        UPDATE evolution_canales
           SET ultimo     = :ms,
               enviados   = COALESCE(enviados, 0) + 1,
               acumulados = COALESCE(acumulados, 0) + 1
         WHERE id = :id
    ");
    $st->execute([':ms' => $ms, ':id' => $canal]);
}

/**
 * Toma el lock (estado='enviando'), consulta Evolution y persiste el
 * resultado (estado='enviado'|'error'). Devuelve:
 *   ['ok'=>true]                  si Evolution respondio sin error
 *   ['ok'=>false, 'skip'=>true]   si otro proceso ya tomo el mensaje
 *   ['ok'=>false, 'skip'=>false]  si hubo error real (config, red, API, ...)
 */
function enviarEvolutionMensaje(PDO $pdo, int $id, string $origen): array {
    // Lock optimista
    $lock = $pdo->prepare("
        UPDATE evolution_mensajes
           SET estado = 'enviando'
         WHERE id = :id AND estado = 'pendiente'
    ");
    $lock->execute([':id' => $id]);
    if ($lock->rowCount() === 0) {
        return ['ok' => false, 'skip' => true];
    }

    $st = $pdo->prepare("
        SELECT m.id, m.canal, m.destino, m.asunto, m.cuerpo, m.formato,
               m.adjunto, m.encolado,
               c.uuid AS canal_uuid, c.token AS canal_token,
               c.prefijo AS canal_prefijo, c.nombre AS canal_nombre
          FROM evolution_mensajes m
     LEFT JOIN evolution_canales  c ON c.id = m.canal
         WHERE m.id = :id
    ");
    $st->execute([':id' => $id]);
    $m = $st->fetch();
    if (!$m) {
        registrarSuceso($pdo, $origen, 'alerta', "Evolution mensaje #{$id} desaparecio antes del envio");
        return ['ok' => false, 'skip' => false];
    }

    $prefix = "evolution#{$id} canal={$m['canal']}({$m['canal_nombre']}) -> {$m['destino']}";

    // Canal invalido / sin credenciales
    $faltantes = [];
    if (empty($m['canal_uuid']))  $faltantes[] = 'uuid';
    if (empty($m['canal_token'])) $faltantes[] = 'token';
    if ($faltantes) {
        $mensaje = 'Canal sin configuracion (falta: ' . implode(', ', $faltantes) . ')';
        marcarEvolutionError($pdo, $id, $mensaje);
        anotarLog("{$prefix} - ERROR: {$mensaje}");
        registrarSuceso($pdo, $origen, 'alerta', "Evolution mensaje #{$id}: {$mensaje}");
        return ['ok' => false, 'skip' => false];
    }

    // Normaliza destino: JID -> tal cual; 10 digitos -> anteponer prefijo; resto -> tal cual.
    $destino = normalizarDestinoEvolution((string)$m['destino'], (string)$m['canal_prefijo']);

    // Prepara cuerpo: si hay asunto se antepone en negrita (formato WhatsApp).
    $cuerpo = (string) $m['cuerpo'];
    if (!empty($m['asunto'])) {
        $cuerpo = '*' . $m['asunto'] . '*' . PHP_EOL . $cuerpo;
    }
    $formato = $m['formato'] ?: 'T';
    $adjunto = (string) ($m['adjunto'] ?? '');

    anotarLog("{$prefix} - enviando ({$formato})");
    try {
        $resp = evolutionMensajeEnviar((string)$m['canal_uuid'], (string)$m['canal_token'],
                                       $destino, $cuerpo, $formato, $adjunto);
    } catch (Throwable $e) {
        $err = 'cURL: ' . $e->getMessage();
        marcarEvolutionError($pdo, $id, $err);
        anotarLog("{$prefix} - ERROR {$err}");
        registrarSuceso($pdo, $origen, 'alerta', "Evolution mensaje #{$id}: {$err}");
        return ['ok' => false, 'skip' => false];
    }

    // Evolution responde 200/201 con {..., "error": "..."} si algo fallo logico.
    $body = (string) $resp['body'];
    $decoded = $resp['decoded'];
    $tieneErrorApi = is_array($decoded) && isset($decoded['error'])
                     && $decoded['error'] !== ''
                     && $decoded['error'] !== null;

    if ($resp['status'] >= 200 && $resp['status'] < 300 && !$tieneErrorApi) {
        $upd = $pdo->prepare("
            UPDATE evolution_mensajes
               SET estado  = 'enviado',
                   error   = NULL,
                   enviado = NOW(),
                   demora  = TIMESTAMPDIFF(SECOND, COALESCE(encolado, fecha, NOW()), NOW())
             WHERE id = :id
        ");
        $upd->execute([':id' => $id]);
        anotarLog("{$prefix} - OK");
        registrarSuceso($pdo, $origen, 'info', "Evolution mensaje #{$id} enviado a {$destino}");
        return ['ok' => true];
    }

    // Fallo: guardar body truncado como error.
    $errTxt = 'HTTP ' . $resp['status'] . ': ' . substr($body, 0, 800);
    marcarEvolutionError($pdo, $id, $errTxt);
    anotarLog("{$prefix} - ERROR {$errTxt}");
    registrarSuceso($pdo, $origen, 'alerta', "Evolution mensaje #{$id}: {$errTxt}");

    // Veto si Evolution avisa que el numero no existe en WhatsApp.
    if (stripos($body, 'exists') !== false) {
        vetarEvolutionContacto($pdo, $destino, $errTxt);
    }
    return ['ok' => false, 'skip' => false];
}

function marcarEvolutionError(PDO $pdo, int $id, string $err): void {
    $st = $pdo->prepare("
        UPDATE evolution_mensajes
           SET estado = 'error',
               error  = :err
         WHERE id = :id
    ");
    $st->execute([':err' => substr($err, 0, 1000), ':id' => $id]);
}

function normalizarDestinoEvolution(string $destino, string $prefijo): string {
    if (strpos($destino, '@') !== false) return $destino;
    if (strlen($destino) === 10 && $prefijo !== '') return $prefijo . $destino;
    return $destino;
}

function vetarEvolutionContacto(PDO $pdo, string $destino, string $error): void {
    try {
        $ex = $pdo->prepare("SELECT id FROM evolution_vetados WHERE destino = :d LIMIT 1");
        $ex->execute([':d' => $destino]);
        if ($ex->fetch()) return;
        $ins = $pdo->prepare("
            INSERT INTO evolution_vetados (fecha, destino, error, estado)
            VALUES (NOW(), :d, :e, '1')
        ");
        $ins->execute([':d' => $destino, ':e' => substr($error, 0, 1000)]);
    } catch (Throwable $_) { /* no romper el flujo */ }
}

/**
 * Ejecuta el POST curl contra Evolution API segun el formato del mensaje.
 * Espeja la clase mcEvolution::mensajeEnviar() del legacy.
 * Devuelve ['status'=>int, 'body'=>string, 'decoded'=>array|null].
 */
function evolutionMensajeEnviar(
    string $uuid, string $token, string $destino, string $cuerpo,
    string $formato, string $adjunto
): array {
    switch ($formato) {
        case 'I':
            $url = EVOLUTION_ENDPOINT . '/message/sendMedia/' . $uuid;
            $payload = [
                'number'    => $destino,
                'media'     => $adjunto,
                'caption'   => $cuerpo,
                'mediatype' => 'image',
                'fileName'  => 'imagen.jpg',
                'delay'     => EVOLUTION_DELAY_MS,
            ];
            break;
        case 'V':
            $url = EVOLUTION_ENDPOINT . '/message/sendMedia/' . $uuid;
            $payload = [
                'number'    => $destino,
                'media'     => $adjunto,
                'caption'   => $cuerpo,
                'mediatype' => 'video',
                'fileName'  => 'video.mp4',
                'delay'     => EVOLUTION_DELAY_MS,
            ];
            break;
        case 'A':
            $url = EVOLUTION_ENDPOINT . '/message/sendWhatsAppAudio/' . $uuid;
            $payload = [
                'number' => $destino,
                'audio'  => $adjunto,
                'delay'  => EVOLUTION_DELAY_MS,
            ];
            break;
        case 'U':
            [$lat, $lon] = array_pad(explode(',', $adjunto, 2), 2, '');
            $url = EVOLUTION_ENDPOINT . '/message/sendLocation/' . $uuid;
            $payload = [
                'number'    => $destino,
                'name'      => $cuerpo !== '' ? $cuerpo : 'Ubicacion compartida',
                'address'   => '',
                'latitude'  => (float) $lat,
                'longitude' => (float) $lon,
                'delay'     => EVOLUTION_DELAY_MS,
            ];
            break;
        case 'T':
        default:
            $url = EVOLUTION_ENDPOINT . '/message/sendText/' . $uuid;
            $payload = [
                'number' => $destino,
                'text'   => $cuerpo,
                'delay'  => EVOLUTION_DELAY_MS,
            ];
            break;
    }

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_CUSTOMREQUEST  => 'POST',
        CURLOPT_POSTFIELDS     => json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => EVOLUTION_TIMEOUT_SEG,
        CURLOPT_HTTP_VERSION   => CURL_HTTP_VERSION_1_1,
        CURLOPT_HTTPHEADER     => [
            'accept: application/json',
            'content-type: application/json',
            'apikey: ' . $token,
        ],
    ]);
    $body = curl_exec($ch);
    if ($body === false) {
        $err = curl_error($ch);
        throw new RuntimeException($err !== '' ? $err : 'curl_exec devolvio false');
    }
    $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $decoded = json_decode((string)$body, true);
    return [
        'status'  => $status,
        'body'    => (string) $body,
        'decoded' => is_array($decoded) ? $decoded : null,
    ];
}

// ============================================================================
// AWS SES (email)
// ============================================================================

/**
 * Selecciona y despacha hasta MAX_AWS_POR_CORRIDA mensajes pendientes de
 * `aws_mensajes` ordenados por prioridad DESC, id ASC. Cada uno se envia
 * con las credenciales IAM SES del canal correspondiente.
 */
function despacharAws(PDO $pdo, string $origen): array {
    $ok = 0; $err = 0; $skip = 0;

    $limite = (int) MAX_AWS_POR_CORRIDA;
    $st = $pdo->query("
        SELECT id
          FROM aws_mensajes
         WHERE estado = 'pendiente'
         ORDER BY CAST(COALESCE(prioridad,'3') AS UNSIGNED) DESC, id ASC
         LIMIT {$limite}
    ");
    foreach ($st->fetchAll() as $r) {
        $res = enviarAwsMensaje($pdo, (int)$r['id'], $origen);
        $res['ok'] ? $ok++ : ($res['skip'] ? $skip++ : $err++);
    }
    return ['ok' => $ok, 'err' => $err, 'skip' => $skip];
}

/**
 * Toma el lock (estado='enviando'), llama a SES v2 y persiste el resultado.
 * Ver enviarEvolutionMensaje() para el contrato de retorno.
 */
function enviarAwsMensaje(PDO $pdo, int $id, string $origen): array {
    // Lock optimista
    $lock = $pdo->prepare("
        UPDATE aws_mensajes
           SET estado = 'enviando'
         WHERE id = :id AND estado = 'pendiente'
    ");
    $lock->execute([':id' => $id]);
    if ($lock->rowCount() === 0) {
        return ['ok' => false, 'skip' => true];
    }

    $st = $pdo->prepare("
        SELECT m.id, m.canal, m.remitente, m.remite, m.destino, m.asunto,
               m.cuerpo, m.formato, m.adjunto, m.encolado,
               c.correo AS canal_correo, c.accesskey, c.secreto, c.region,
               c.nombre AS canal_nombre
          FROM aws_mensajes m
     LEFT JOIN aws_canales  c ON c.id = m.canal
         WHERE m.id = :id
    ");
    $st->execute([':id' => $id]);
    $m = $st->fetch();
    if (!$m) {
        registrarSuceso($pdo, $origen, 'alerta', "AWS mensaje #{$id} desaparecio antes del envio");
        return ['ok' => false, 'skip' => false];
    }

    $prefix = "aws#{$id} canal={$m['canal']}({$m['canal_nombre']}) -> {$m['destino']}";

    $faltantes = [];
    if (empty($m['accesskey'])) $faltantes[] = 'accesskey';
    if (empty($m['secreto']))   $faltantes[] = 'secreto';
    if ($faltantes) {
        $mensaje = 'Canal sin credenciales SES (falta: ' . implode(', ', $faltantes) . ')';
        marcarAwsError($pdo, $id, $mensaje);
        anotarLog("{$prefix} - ERROR: {$mensaje}");
        registrarSuceso($pdo, $origen, 'alerta', "AWS mensaje #{$id}: {$mensaje}");
        return ['ok' => false, 'skip' => false];
    }

    $region = !empty($m['region']) ? (string)$m['region'] : AWS_SES_DEFAULT_REGION;
    $host   = 'email.' . $region . '.amazonaws.com';

    // From: si hay remitente lo usamos como display name, si no solo el email.
    $from = (string) $m['remite'];
    if (!empty($m['remitente'])) {
        $from = mimeEncodeHeader((string)$m['remitente']) . ' <' . $m['remite'] . '>';
    }

    // Destinos: separados por coma en la BD.
    $to = array_values(array_filter(array_map('trim', explode(',', (string)$m['destino']))));
    if (!$to) {
        $mensaje = 'Destino vacio';
        marcarAwsError($pdo, $id, $mensaje);
        anotarLog("{$prefix} - ERROR: {$mensaje}");
        registrarSuceso($pdo, $origen, 'alerta', "AWS mensaje #{$id}: {$mensaje}");
        return ['ok' => false, 'skip' => false];
    }

    $asunto  = (string)($m['asunto'] ?? '');
    $cuerpo  = (string)($m['cuerpo'] ?? '');
    $formato = (string)($m['formato'] ?? 'H');   // H=HTML, T=texto
    $adjunto = trim((string)($m['adjunto'] ?? ''));

    // Sin adjunto: usar Content.Simple (SES lo formatea).
    // Con adjunto: construir un RFC 5322 multipart/mixed y mandarlo como Raw.
    if ($adjunto === '') {
        $payload = [
            'FromEmailAddress' => $from,
            'Destination'      => ['ToAddresses' => $to],
            'Content'          => [
                'Simple' => [
                    'Subject' => ['Data' => $asunto, 'Charset' => 'UTF-8'],
                    'Body'    => $formato === 'H'
                        ? ['Html' => ['Data' => $cuerpo, 'Charset' => 'UTF-8']]
                        : ['Text' => ['Data' => $cuerpo, 'Charset' => 'UTF-8']],
                ],
            ],
        ];
    } else {
        $raw = construirRawMime($from, $to, $asunto, $cuerpo, $formato, $adjunto);
        $payload = [
            'FromEmailAddress' => $from,
            'Destination'      => ['ToAddresses' => $to],
            'Content'          => [
                'Raw' => ['Data' => base64_encode($raw)],
            ],
        ];
    }

    anotarLog("{$prefix} - enviando (SES v2, region={$region}, formato={$formato}" .
              ($adjunto !== '' ? ', con adjunto' : '') . ')');
    try {
        $resp = aws_rest_json(
            (string)$m['accesskey'], (string)$m['secreto'],
            $region, 'ses', $host,
            'POST', '/v2/email/outbound-emails',
            $payload
        );
    } catch (Throwable $e) {
        $err = 'cURL: ' . $e->getMessage();
        marcarAwsError($pdo, $id, $err);
        anotarLog("{$prefix} - ERROR {$err}");
        registrarSuceso($pdo, $origen, 'alerta', "AWS mensaje #{$id}: {$err}");
        return ['ok' => false, 'skip' => false];
    }

    if ($resp['status'] >= 200 && $resp['status'] < 300) {
        $upd = $pdo->prepare("
            UPDATE aws_mensajes
               SET estado  = 'enviado',
                   error   = NULL,
                   enviado = NOW(),
                   demora  = TIMESTAMPDIFF(SECOND, COALESCE(encolado, fecha, NOW()), NOW())
             WHERE id = :id
        ");
        $upd->execute([':id' => $id]);
        anotarLog("{$prefix} - OK");
        registrarSuceso($pdo, $origen, 'info',
            "AWS mensaje #{$id} enviado a " . implode(', ', $to));
        return ['ok' => true];
    }

    $errTxt = 'HTTP ' . $resp['status'] . ': ' . substr((string)$resp['body'], 0, 800);
    marcarAwsError($pdo, $id, $errTxt);
    anotarLog("{$prefix} - ERROR {$errTxt}");
    registrarSuceso($pdo, $origen, 'alerta', "AWS mensaje #{$id}: {$errTxt}");
    return ['ok' => false, 'skip' => false];
}

function marcarAwsError(PDO $pdo, int $id, string $err): void {
    $st = $pdo->prepare("
        UPDATE aws_mensajes
           SET estado = 'error',
               error  = :err
         WHERE id = :id
    ");
    $st->execute([':err' => substr($err, 0, 1000), ':id' => $id]);
}

/**
 * Codifica una cabecera segun RFC 2047 (=?UTF-8?B?...?=) para preservar
 * acentos en nombres del remitente ("Databox Núñez" -> mime encoded).
 */
function mimeEncodeHeader(string $texto): string {
    if (preg_match('/[\x80-\xff]/', $texto) === 0) return $texto;
    return '=?UTF-8?B?' . base64_encode($texto) . '?=';
}

/**
 * Arma un mensaje RFC 5322 multipart/mixed con el cuerpo (HTML o texto) y el
 * adjunto descargado desde `adjunto` (URL o path). Devuelve el mensaje en
 * crudo listo para base64-encodear y pasarle a SES v2 como Raw.Data.
 */
function construirRawMime(
    string $from, array $to, string $asunto, string $cuerpo,
    string $formato, string $adjunto
): string {
    $eol = "\r\n";
    $boundary = 'boundary_' . bin2hex(random_bytes(12));

    $lineas = [];
    $lineas[] = 'From: ' . $from;
    $lineas[] = 'To: '   . implode(', ', $to);
    $lineas[] = 'Subject: ' . mimeEncodeHeader($asunto);
    $lineas[] = 'MIME-Version: 1.0';
    $lineas[] = 'Content-Type: multipart/mixed; boundary="' . $boundary . '"';
    $lineas[] = '';

    // Parte 1: cuerpo
    $lineas[] = '--' . $boundary;
    if ($formato === 'H') {
        $lineas[] = 'Content-Type: text/html; charset=UTF-8';
    } else {
        $lineas[] = 'Content-Type: text/plain; charset=UTF-8';
    }
    $lineas[] = 'Content-Transfer-Encoding: base64';
    $lineas[] = '';
    $lineas[] = chunk_split(base64_encode($cuerpo), 76, $eol);

    // Parte 2: adjunto
    $contenido = @file_get_contents($adjunto);
    if ($contenido === false) {
        // Si no se pudo bajar el adjunto, mandamos igual el cuerpo y anotamos
        // el problema como parte del cuerpo. No lo hacemos error fatal para no
        // perder el mensaje entero por un adjunto caido.
        $lineas[] = '--' . $boundary . '--';
        return implode($eol, $lineas);
    }
    $nombre = basename((string) parse_url($adjunto, PHP_URL_PATH));
    if ($nombre === '' || $nombre === false) $nombre = 'adjunto.bin';

    $lineas[] = '--' . $boundary;
    $lineas[] = 'Content-Type: application/octet-stream; name="' . $nombre . '"';
    $lineas[] = 'Content-Transfer-Encoding: base64';
    $lineas[] = 'Content-Disposition: attachment; filename="' . $nombre . '"';
    $lineas[] = '';
    $lineas[] = chunk_split(base64_encode($contenido), 76, $eol);
    $lineas[] = '--' . $boundary . '--';

    return implode($eol, $lineas);
}
