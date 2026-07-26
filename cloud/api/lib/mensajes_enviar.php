<?php
/**
 * cloud/api/lib/mensajes_enviar.php
 * Envio individual de mensajes — fuente unica de verdad del despacho.
 *
 * Consumida por:
 *   - Los jobs del Programador de tareas (cloud/jobs/evolution_mensajes_enviar.php
 *     y cloud/jobs/aws_mensajes_enviar.php) para el batch de cron.
 *   - Los endpoints POST de envio manual desde el ABM
 *     (cloud/api/evolutionmensajes_enviar.php y cloud/api/awsmensajes_enviar.php).
 *
 * De esta forma toda la logica de envio (lock optimista, validaciones,
 * transporte, persistencia del resultado, registro de suceso, veto, etc.)
 * vive en un solo lugar. Los jobs solo aportan seleccion + throttling; los
 * endpoints solo aportan auth + serializacion JSON.
 *
 * Contrato de retorno de las dos funciones publicas:
 *   ['ok'=>true, 'destino'=>string, 'canal_nombre'=>string, 'formato'=>string]
 *       cuando el envio fue aceptado por el destino (SMTP 250 o Evolution 2xx sin error).
 *   ['ok'=>false, 'skip'=>true, 'motivo'=>'ya_no_pendiente']
 *       cuando el lock optimista fallo (otra corrida ya lo tomo o cambio de estado).
 *   ['ok'=>false, 'error'=>string, 'detalle'=>?string, 'destino'=>?string, 'canal_nombre'=>?string]
 *       cuando hubo error real (config, red, API, ...). El detalle es el error
 *       ya persistido en `mensajes.error`.
 *
 * NO llama a `anotarLog()` — esa funcion solo existe en CLI (jobs). La info
 * util para el log del job (o para el JSON del endpoint) sale via el array
 * de retorno. Si el llamador quiere loguear, que use el array.
 */

require_once __DIR__ . '/sucesos.php';

const EVOLUTION_ENDPOINT    = 'https://evolution.york.databox.net.ar';
const EVOLUTION_TIMEOUT_SEG = 30;
const EVOLUTION_DELAY_MS    = 1000;   // delay que Evolution respeta antes de mandar (anti-spam)
const SMTP_PORT_DEFAULT     = 587;
const SMTP_TIMEOUT_SEG      = 30;

// ============================================================================
// PUBLICO: EVOLUTION (WhatsApp)
// ============================================================================

/**
 * Toma un evolution_mensajes por id, aplica el lock optimista, llama a
 * Evolution API y persiste el resultado. Actualiza `evolution_canales.ultimo /
 * enviados / acumulados` si el envio fue exitoso — asi el throttling del cron
 * cuenta tambien los envios manuales.
 */
function evolutionMensajeEnviarPorId(PDO $pdo, int $id, string $origen): array {
    // -- 1) Lock optimista --------------------------------------------------
    $lock = $pdo->prepare("
        UPDATE evolution_mensajes
           SET estado = 'enviando'
         WHERE id = :id AND estado IN ('pendiente', 'error')
    ");
    $lock->execute([':id' => $id]);
    if ($lock->rowCount() === 0) {
        return ['ok' => false, 'skip' => true, 'motivo' => 'ya_no_pendiente'];
    }

    // -- 2) Fetch datos del mensaje + canal ---------------------------------
    $st = $pdo->prepare("
        SELECT m.id, m.canal_id, m.destino, m.asunto, m.cuerpo, m.formato,
               m.adjunto, m.encolado,
               c.slug AS canal_slug, c.token AS canal_token,
               c.prefijo AS canal_prefijo, c.nombre AS canal_nombre
          FROM evolution_mensajes m
     LEFT JOIN evolution_canales  c ON c.id = m.canal_id
         WHERE m.id = :id
    ");
    $st->execute([':id' => $id]);
    $m = $st->fetch();
    if (!$m) {
        registrarSuceso($pdo, $origen, 'alerta', "Evolution mensaje #{$id} desaparecio antes del envio");
        return ['ok' => false, 'error' => 'mensaje_no_encontrado'];
    }

    $canalNombre = (string)($m['canal_nombre'] ?? '');
    $destinoRaw  = (string)($m['destino'] ?? '');

    // -- 3) Validaciones ----------------------------------------------------
    $faltantes = [];
    if (empty($m['canal_slug']))  $faltantes[] = 'slug';
    if (empty($m['canal_token'])) $faltantes[] = 'token';
    if ($faltantes) {
        $mensaje = 'Canal sin configuracion (falta: ' . implode(', ', $faltantes) . ')';
        marcarEvolutionError($pdo, $id, $mensaje);
        registrarSuceso($pdo, $origen, 'alerta', "Evolution mensaje #{$id}: {$mensaje}");
        return ['ok' => false, 'error' => $mensaje, 'canal_nombre' => $canalNombre, 'destino' => $destinoRaw];
    }

    // -- 4) Normalizacion + preparacion del cuerpo --------------------------
    $destino = normalizarDestinoEvolution($destinoRaw, (string)$m['canal_prefijo']);
    $cuerpo  = (string) $m['cuerpo'];
    if (!empty($m['asunto'])) {
        $cuerpo = '*' . $m['asunto'] . '*' . PHP_EOL . $cuerpo;
    }
    $formato = $m['formato'] ?: 'texto';
    $adjunto = (string) ($m['adjunto'] ?? '');

    // -- 5) HTTP a Evolution ------------------------------------------------
    try {
        $resp = evolutionApiEnviar((string)$m['canal_slug'], (string)$m['canal_token'],
                                   $destino, $cuerpo, $formato, $adjunto);
    } catch (Throwable $e) {
        $err = 'cURL: ' . $e->getMessage();
        marcarEvolutionError($pdo, $id, $err);
        registrarSuceso($pdo, $origen, 'alerta', "Evolution mensaje #{$id}: {$err}");
        return ['ok' => false, 'error' => $err, 'destino' => $destino, 'canal_nombre' => $canalNombre];
    }

    $body    = (string) $resp['body'];
    $decoded = $resp['decoded'];
    // Evolution responde 200/201 pero puede incluir {"error": "..."} si algo fallo logico.
    $tieneErrorApi = is_array($decoded) && isset($decoded['error'])
                     && $decoded['error'] !== '' && $decoded['error'] !== null;

    // -- 6) Persistir resultado --------------------------------------------
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
        // Actualiza throttle del canal: el cron y el envio manual comparten
        // el mismo contador para evitar rafagas.
        tocarEvolutionCanal($pdo, (int)$m['canal_id']);
        registrarSuceso($pdo, $origen, 'info', "Evolution mensaje #{$id} enviado a {$destino}");
        return ['ok' => true, 'destino' => $destino, 'canal_nombre' => $canalNombre, 'formato' => $formato];
    }

    $errTxt = 'HTTP ' . $resp['status'] . ': ' . substr($body, 0, 800);
    marcarEvolutionError($pdo, $id, $errTxt);
    registrarSuceso($pdo, $origen, 'alerta', "Evolution mensaje #{$id}: {$errTxt}");
    // Veto: si Evolution nos dice que el numero no existe en WhatsApp.
    if (stripos($body, 'exists') !== false) {
        vetarEvolutionContacto($pdo, $destino, $errTxt);
    }
    return ['ok' => false, 'error' => $errTxt, 'destino' => $destino, 'canal_nombre' => $canalNombre];
}

// ============================================================================
// PUBLICO: AWS SES (email via SMTP con STARTTLS + AUTH LOGIN)
// ============================================================================

/**
 * Toma un aws_mensajes por id, aplica el lock optimista, abre una conexion
 * SMTP contra el canal AWS SES y persiste el resultado. Usa las credenciales
 * SMTP de `aws_canales.servidor / usuario / contrasena` (mismas que usaba el
 * legacy con PHPMailer).
 */
function awsMensajeEnviarPorId(PDO $pdo, int $id, string $origen): array {
    // -- 1) Lock optimista --------------------------------------------------
    $lock = $pdo->prepare("
        UPDATE aws_mensajes
           SET estado = 'enviando'
         WHERE id = :id AND estado IN ('pendiente', 'error')
    ");
    $lock->execute([':id' => $id]);
    if ($lock->rowCount() === 0) {
        return ['ok' => false, 'skip' => true, 'motivo' => 'ya_no_pendiente'];
    }

    // -- 2) Fetch datos del mensaje + canal ---------------------------------
    $st = $pdo->prepare("
        SELECT m.id, m.canal_id, m.remitente, m.remite, m.destino, m.asunto,
               m.cuerpo, m.formato, m.adjunto, m.encolado,
               c.servidor, c.usuario, c.contrasena, c.correo AS canal_correo,
               c.nombre AS canal_nombre
          FROM aws_mensajes m
     LEFT JOIN aws_canales  c ON c.id = m.canal_id
         WHERE m.id = :id
    ");
    $st->execute([':id' => $id]);
    $m = $st->fetch();
    if (!$m) {
        registrarSuceso($pdo, $origen, 'alerta', "AWS mensaje #{$id} desaparecio antes del envio");
        return ['ok' => false, 'error' => 'mensaje_no_encontrado'];
    }

    $canalNombre = (string)($m['canal_nombre'] ?? '');
    $destinoRaw  = (string)($m['destino'] ?? '');

    // -- 3) Validaciones ----------------------------------------------------
    $faltantes = [];
    if (empty($m['servidor']))   $faltantes[] = 'servidor';
    if (empty($m['usuario']))    $faltantes[] = 'usuario';
    if (empty($m['contrasena'])) $faltantes[] = 'contrasena';
    if ($faltantes) {
        $mensaje = 'Canal sin credenciales SMTP (falta: ' . implode(', ', $faltantes) . ')';
        marcarAwsError($pdo, $id, $mensaje);
        registrarSuceso($pdo, $origen, 'alerta', "AWS mensaje #{$id}: {$mensaje}");
        return ['ok' => false, 'error' => $mensaje, 'canal_nombre' => $canalNombre, 'destino' => $destinoRaw];
    }

    // From: preferimos `remite` del mensaje; si esta vacio, usamos `correo` del canal.
    $remite = trim((string)($m['remite'] ?? '')) ?: trim((string)($m['canal_correo'] ?? ''));
    if ($remite === '') {
        $mensaje = 'Sin From: ni remite en el mensaje ni correo en el canal';
        marcarAwsError($pdo, $id, $mensaje);
        registrarSuceso($pdo, $origen, 'alerta', "AWS mensaje #{$id}: {$mensaje}");
        return ['ok' => false, 'error' => $mensaje, 'canal_nombre' => $canalNombre, 'destino' => $destinoRaw];
    }
    $fromHeader = $remite;
    if (!empty($m['remitente'])) {
        $fromHeader = mimeEncodeHeader((string)$m['remitente']) . ' <' . $remite . '>';
    }

    // Destinos: separados por coma en la BD.
    $to = array_values(array_filter(array_map('trim', explode(',', $destinoRaw))));
    if (!$to) {
        $mensaje = 'Destino vacio';
        marcarAwsError($pdo, $id, $mensaje);
        registrarSuceso($pdo, $origen, 'alerta', "AWS mensaje #{$id}: {$mensaje}");
        return ['ok' => false, 'error' => $mensaje, 'canal_nombre' => $canalNombre, 'destino' => $destinoRaw];
    }

    // -- 4) Armado MIME + envio SMTP ---------------------------------------
    $asunto  = (string)($m['asunto'] ?? '');
    $cuerpo  = (string)($m['cuerpo'] ?? '');
    $formato = (string)($m['formato'] ?? 'html');   // 'html' | 'texto'
    $adjunto = trim((string)($m['adjunto'] ?? ''));
    $rfc822  = construirMime($fromHeader, $to, $asunto, $cuerpo, $formato, $adjunto);

    try {
        smtpEnviar(
            (string)$m['servidor'],
            SMTP_PORT_DEFAULT,
            (string)$m['usuario'],
            (string)$m['contrasena'],
            $remite,
            $to,
            $rfc822
        );
    } catch (Throwable $e) {
        $err = 'SMTP: ' . $e->getMessage();
        marcarAwsError($pdo, $id, $err);
        registrarSuceso($pdo, $origen, 'alerta', "AWS mensaje #{$id}: {$err}");
        return ['ok' => false, 'error' => $err, 'destino' => $destinoRaw, 'canal_nombre' => $canalNombre];
    }

    // -- 5) Persistir resultado --------------------------------------------
    $upd = $pdo->prepare("
        UPDATE aws_mensajes
           SET estado  = 'enviado',
               error   = NULL,
               enviado = NOW(),
               demora  = TIMESTAMPDIFF(SECOND, COALESCE(encolado, fecha, NOW()), NOW())
         WHERE id = :id
    ");
    $upd->execute([':id' => $id]);
    registrarSuceso($pdo, $origen, 'info', "AWS mensaje #{$id} enviado a " . implode(', ', $to));
    return ['ok' => true, 'destino' => implode(', ', $to), 'canal_nombre' => $canalNombre, 'formato' => $formato];
}

// ============================================================================
// PRIVADO: helpers Evolution
// ============================================================================

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

function tocarEvolutionCanal(PDO $pdo, int $canal): void {
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
function evolutionApiEnviar(
    string $slug, string $token, string $destino, string $cuerpo,
    string $formato, string $adjunto
): array {
    switch ($formato) {
        case 'imagen':
            $url = EVOLUTION_ENDPOINT . '/message/sendMedia/' . $slug;
            $payload = [
                'number'    => $destino,
                'media'     => $adjunto,
                'caption'   => $cuerpo,
                'mediatype' => 'image',
                'fileName'  => 'imagen.jpg',
                'delay'     => EVOLUTION_DELAY_MS,
            ];
            break;
        case 'video':
            $url = EVOLUTION_ENDPOINT . '/message/sendMedia/' . $slug;
            $payload = [
                'number'    => $destino,
                'media'     => $adjunto,
                'caption'   => $cuerpo,
                'mediatype' => 'video',
                'fileName'  => 'video.mp4',
                'delay'     => EVOLUTION_DELAY_MS,
            ];
            break;
        case 'audio':
            $url = EVOLUTION_ENDPOINT . '/message/sendWhatsAppAudio/' . $slug;
            $payload = [
                'number' => $destino,
                'audio'  => $adjunto,
                'delay'  => EVOLUTION_DELAY_MS,
            ];
            break;
        case 'ubicacion':
            [$lat, $lon] = array_pad(explode(',', $adjunto, 2), 2, '');
            $url = EVOLUTION_ENDPOINT . '/message/sendLocation/' . $slug;
            $payload = [
                'number'    => $destino,
                'name'      => $cuerpo !== '' ? $cuerpo : 'Ubicacion compartida',
                'address'   => '',
                'latitude'  => (float) $lat,
                'longitude' => (float) $lon,
                'delay'     => EVOLUTION_DELAY_MS,
            ];
            break;
        case 'texto':
        default:
            $url = EVOLUTION_ENDPOINT . '/message/sendText/' . $slug;
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
    $status  = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $decoded = json_decode((string)$body, true);
    return [
        'status'  => $status,
        'body'    => (string) $body,
        'decoded' => is_array($decoded) ? $decoded : null,
    ];
}

// ============================================================================
// PRIVADO: helpers AWS SES (SMTP + MIME)
// ============================================================================

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
 * acentos en nombres del remitente / asunto.
 */
function mimeEncodeHeader(string $texto): string {
    if (preg_match('/[\x80-\xff]/', $texto) === 0) return $texto;
    return '=?UTF-8?B?' . base64_encode($texto) . '?=';
}

/**
 * Arma el mensaje RFC 5322 completo (headers + body). Si `adjunto` esta vacio,
 * genera un body simple (text/plain o text/html). Si trae adjunto, arma un
 * multipart/mixed con el cuerpo + un adjunto bajado por HTTP o del filesystem.
 */
function construirMime(
    string $from, array $to, string $asunto, string $cuerpo,
    string $formato, string $adjunto
): string {
    $eol = "\r\n";
    $mimeType = $formato === 'html' ? 'text/html' : 'text/plain';

    $lineas = [];
    $lineas[] = 'From: '    . $from;
    $lineas[] = 'To: '      . implode(', ', $to);
    $lineas[] = 'Subject: ' . mimeEncodeHeader($asunto);
    $lineas[] = 'Date: '    . date('r');
    $lineas[] = 'MIME-Version: 1.0';

    if ($adjunto === '') {
        $lineas[] = 'Content-Type: ' . $mimeType . '; charset=UTF-8';
        $lineas[] = 'Content-Transfer-Encoding: base64';
        $lineas[] = '';
        $lineas[] = chunk_split(base64_encode($cuerpo), 76, $eol);
        return implode($eol, $lineas);
    }

    $boundary = 'boundary_' . bin2hex(random_bytes(12));
    $lineas[] = 'Content-Type: multipart/mixed; boundary="' . $boundary . '"';
    $lineas[] = '';

    // Parte 1: cuerpo
    $lineas[] = '--' . $boundary;
    $lineas[] = 'Content-Type: ' . $mimeType . '; charset=UTF-8';
    $lineas[] = 'Content-Transfer-Encoding: base64';
    $lineas[] = '';
    $lineas[] = chunk_split(base64_encode($cuerpo), 76, $eol);

    // Parte 2: adjunto
    $contenido = @file_get_contents($adjunto);
    if ($contenido === false) {
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

/**
 * Envia un mensaje ya armado (headers + body, "\r\n" entre lineas) via SMTP
 * con STARTTLS y AUTH LOGIN. Tira RuntimeException si algo falla.
 */
function smtpEnviar(
    string $host, int $port, string $usuario, string $contrasena,
    string $mailFrom, array $rcptTo, string $rfc822
): void {
    $errno  = 0;
    $errstr = '';
    // stream_socket_client tira warning si falla; usamos @ y validamos manualmente.
    $sock = @stream_socket_client(
        'tcp://' . $host . ':' . $port,
        $errno, $errstr, SMTP_TIMEOUT_SEG, STREAM_CLIENT_CONNECT
    );
    if ($sock === false) {
        throw new RuntimeException("No se pudo conectar a {$host}:{$port} - [{$errno}] {$errstr}");
    }
    stream_set_timeout($sock, SMTP_TIMEOUT_SEG);

    try {
        smtpEsperar($sock, 220);
        smtpCmd($sock, 'EHLO ' . gethostname(), 250);
        smtpCmd($sock, 'STARTTLS', 220);
        if (!@stream_socket_enable_crypto($sock, true, STREAM_CRYPTO_METHOD_TLS_CLIENT)) {
            throw new RuntimeException('Fallo el handshake TLS con ' . $host);
        }
        smtpCmd($sock, 'EHLO ' . gethostname(), 250);   // EHLO post-TLS obligatorio
        smtpCmd($sock, 'AUTH LOGIN', 334);
        smtpCmd($sock, base64_encode($usuario), 334);
        smtpCmd($sock, base64_encode($contrasena), 235);
        smtpCmd($sock, 'MAIL FROM:<' . $mailFrom . '>', 250);
        foreach ($rcptTo as $r) {
            smtpCmd($sock, 'RCPT TO:<' . $r . '>', 250);
        }
        smtpCmd($sock, 'DATA', 354);
        // Escapa lineas que empiezan con "." (RFC 5321 §4.5.2).
        $body = preg_replace('/^\./m', '..', $rfc822);
        fwrite($sock, $body . "\r\n.\r\n");
        smtpEsperar($sock, 250);
        @fwrite($sock, "QUIT\r\n");  // best-effort
    } finally {
        @fclose($sock);
    }
}

function smtpCmd($sock, string $cmd, int $codigoEsperado): string {
    fwrite($sock, $cmd . "\r\n");
    return smtpEsperar($sock, $codigoEsperado);
}

/**
 * Lee la respuesta SMTP (multiline: cada linea empieza con NNN- y la ultima
 * con NNN espacio). Tira RuntimeException si el codigo != esperado.
 */
function smtpEsperar($sock, int $codigoEsperado): string {
    $todo = '';
    while (true) {
        $linea = fgets($sock, 8192);
        if ($linea === false) {
            $meta = stream_get_meta_data($sock);
            if (!empty($meta['timed_out'])) {
                throw new RuntimeException('Timeout leyendo respuesta SMTP');
            }
            throw new RuntimeException('SMTP cerro la conexion inesperadamente');
        }
        $todo .= $linea;
        if (strlen($linea) >= 4 && $linea[3] === ' ') break;
    }
    $codigo = (int) substr($todo, 0, 3);
    if ($codigo !== $codigoEsperado) {
        throw new RuntimeException("Esperado {$codigoEsperado}, recibido: " . trim($todo));
    }
    return $todo;
}
