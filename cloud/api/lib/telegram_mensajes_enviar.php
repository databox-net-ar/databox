<?php
/**
 * cloud/api/lib/telegram_mensajes_enviar.php
 * Despacho individual de mensajes Telegram (MTProto) -- fuente unica de
 * verdad del envio. Consumida por:
 *   - Cron job cloud/jobs/telegram_mensajes_enviar.php (batch por lotes)
 *   - Endpoint POST cloud/api/telegrammensajes_enviar.php (envio manual
 *     desde el ABM > menu contextual > Enviar ahora)
 *
 * Toda la logica MTProto vive en el microservicio /v4/telegram/mensajes.
 * Este lib actua como CLIENTE HTTP del microservicio: toma la fila
 * pendiente, aplica el lock optimista, arma el body {canal_slug, destinatario,
 * mensaje}, hace el POST y persiste el resultado.
 *
 * Mirror estructural de cloud/api/lib/mensajes_enviar.php pero especifico
 * para telegram.
 *
 * Contrato de retorno (idem mensajes_enviar.php):
 *   ['ok'=>true, 'destino'=>string, 'canal_nombre'=>string, 'message_id'=>?int]
 *   ['ok'=>false, 'skip'=>true, 'motivo'=>'ya_no_pendiente']
 *   ['ok'=>false, 'error'=>string, 'destino'=>?string, 'canal_nombre'=>?string]
 */

require_once __DIR__ . '/sucesos.php';

// URL de loopback al microservicio v4 (mismo container, port 8114 = vhost
// api.databox.net.ar). Se resuelve localmente y no depende de DNS/SSL.
const TG_V4_ENDPOINT      = 'http://localhost:8114/v4/telegram/mensajes';
const TG_V4_TIMEOUT_SEG   = 60;   // MadelineProto puede tardar ~15s en cold start
const TG_V4_CONNECT_SEG   = 5;

/**
 * Toma un telegram_mensajes por id, aplica el lock optimista, llama al
 * microservicio MTProto y persiste el resultado.
 */
function telegramMensajeEnviarPorId(PDO $pdo, int $id, string $origen): array {
    // -- 1) Lock optimista --------------------------------------------------
    $lock = $pdo->prepare("
        UPDATE telegram_mensajes
           SET estado = 'enviando'
         WHERE id = :id AND estado IN ('pendiente', 'error')
    ");
    $lock->execute([':id' => $id]);
    if ($lock->rowCount() === 0) {
        return ['ok' => false, 'skip' => true, 'motivo' => 'ya_no_pendiente'];
    }

    // -- 2) Fetch datos del mensaje + canal ---------------------------------
    $st = $pdo->prepare("
        SELECT m.id, m.canal_id, m.destino, m.asunto, m.cuerpo, m.encolado,
               c.slug AS canal_slug, c.nombre AS canal_nombre,
               c.telefono AS canal_telefono, c.habilitado AS canal_habilitado
          FROM telegram_mensajes m
     LEFT JOIN telegram_canales  c ON c.id = m.canal_id
         WHERE m.id = :id
    ");
    $st->execute([':id' => $id]);
    $m = $st->fetch();
    if (!$m) {
        registrarSuceso($pdo, $origen, 'alerta', "Telegram mensaje #{$id} desaparecio antes del envio");
        return ['ok' => false, 'error' => 'mensaje_no_encontrado'];
    }

    $canalNombre = (string)($m['canal_nombre'] ?? '');
    $destinoRaw  = (string)($m['destino'] ?? '');

    // -- 3) Validaciones ----------------------------------------------------
    $faltantes = [];
    if (empty($m['canal_slug']))     $faltantes[] = 'canal';
    if (empty($m['canal_telefono'])) $faltantes[] = 'canal.telefono';
    if ($destinoRaw === '')          $faltantes[] = 'destino';
    if (empty($m['cuerpo']))         $faltantes[] = 'cuerpo';
    if ($faltantes) {
        $mensaje = 'Configuracion incompleta (falta: ' . implode(', ', $faltantes) . ')';
        marcarTelegramError($pdo, $id, $mensaje);
        registrarSuceso($pdo, $origen, 'alerta', "Telegram mensaje #{$id}: {$mensaje}");
        return ['ok' => false, 'error' => $mensaje, 'canal_nombre' => $canalNombre, 'destino' => $destinoRaw];
    }
    if (((string)$m['canal_habilitado']) === '0') {
        $mensaje = "Canal '{$m['canal_slug']}' esta deshabilitado";
        marcarTelegramError($pdo, $id, $mensaje);
        registrarSuceso($pdo, $origen, 'alerta', "Telegram mensaje #{$id}: {$mensaje}");
        return ['ok' => false, 'error' => $mensaje, 'canal_nombre' => $canalNombre, 'destino' => $destinoRaw];
    }

    // -- 4) API key para el POST al microservicio v4 ------------------------
    // Buscamos una `aplicaciones` habilitada de uso interno. Preferimos
    // "Kernel" (convencion del grupo para llamados internos). Si no existe,
    // agarramos cualquier habilitada -- el objetivo es no bloquear el
    // envio por un problema de config del catalogo.
    $apikey = telegramWorkerApiKey($pdo);
    if ($apikey === null) {
        $mensaje = 'No hay ninguna aplicacion habilitada con apikey para el loopback';
        marcarTelegramError($pdo, $id, $mensaje);
        registrarSuceso($pdo, $origen, 'error', "Telegram mensaje #{$id}: {$mensaje}");
        return ['ok' => false, 'error' => $mensaje, 'canal_nombre' => $canalNombre, 'destino' => $destinoRaw];
    }

    // -- 5) Armado del body -------------------------------------------------
    // Si viene asunto, se antepone en negrita al cuerpo (mismo patron
    // que evolution/aws). Telegram soporta markdown -- el microservicio v4
    // podria formatearlo pero por ahora enviamos texto plano.
    $mensajeTxt = (string) $m['cuerpo'];
    if (!empty($m['asunto'])) {
        $mensajeTxt = '*' . $m['asunto'] . '*' . PHP_EOL . PHP_EOL . $mensajeTxt;
    }

    // `mensaje_id` le dice al microservicio que esta fila YA existe y que la
    // persistencia la maneja este lib (pasos 1 y 7). Sin el, el v4 abre su
    // propia fila en `telegram_mensajes` y cada mensaje del ABM aparece
    // duplicado en el listado.
    $body = [
        'canal_slug'   => (string) $m['canal_slug'],
        'destinatario' => $destinoRaw,
        'mensaje'      => $mensajeTxt,
        'mensaje_id'   => $id,
    ];

    // -- 6) POST al microservicio v4 ----------------------------------------
    try {
        $resp = telegramV4Post($apikey, $body);
    } catch (Throwable $e) {
        $err = 'cURL: ' . $e->getMessage();
        marcarTelegramError($pdo, $id, $err);
        registrarSuceso($pdo, $origen, 'alerta', "Telegram mensaje #{$id}: {$err}");
        return ['ok' => false, 'error' => $err, 'destino' => $destinoRaw, 'canal_nombre' => $canalNombre];
    }

    $decoded = $resp['decoded'];
    $ok      = $resp['status'] >= 200 && $resp['status'] < 300
               && is_array($decoded) && !empty($decoded['ok']);

    // -- 7) Persistir resultado --------------------------------------------
    if ($ok) {
        $messageId = isset($decoded['data']['message_id']) ? (int)$decoded['data']['message_id'] : null;
        $upd = $pdo->prepare("
            UPDATE telegram_mensajes
               SET estado  = 'enviado',
                   error   = NULL,
                   enviado = NOW(),
                   demora  = TIMESTAMPDIFF(SECOND, COALESCE(encolado, fecha, NOW()), NOW())
             WHERE id = :id
        ");
        $upd->execute([':id' => $id]);
        // Latido pasivo del canal: cada envio OK refresca online/latido gratis.
        // Cubre el "canal esta vivo" sin necesidad de correr el cron activo.
        marcarCanalTelegramOnline($pdo, (int)$m['canal_id']);
        registrarSuceso($pdo, $origen, 'info', "Telegram mensaje #{$id} enviado a {$destinoRaw} via '{$m['canal_slug']}'");
        return [
            'ok'           => true,
            'destino'      => $destinoRaw,
            'canal_nombre' => $canalNombre,
            'message_id'   => $messageId,
        ];
    }

    $errApi = is_array($decoded) && isset($decoded['error']) ? (string)$decoded['error'] : '';
    $errTxt = 'HTTP ' . $resp['status'] . ($errApi !== '' ? ": {$errApi}" : ': ' . substr((string)$resp['body'], 0, 400));
    marcarTelegramError($pdo, $id, $errTxt);
    // Deteccion pasiva de sesion caida: si el error contiene alguno de los
    // codigos de auth que devuelve Telegram cuando la sesion ya no es valida,
    // marcamos el canal como offline. No pisamos `latido` para conservar la
    // ultima vez que se supo vivo.
    if (esErrorSesionTelegramCaida($errTxt)) {
        marcarCanalTelegramOffline($pdo, (int)$m['canal_id']);
        registrarSuceso($pdo, $origen, 'error',
            "Canal Telegram '{$m['canal_slug']}' marcado OFFLINE por error de sesion: {$errTxt}");
    }
    registrarSuceso($pdo, $origen, 'alerta', "Telegram mensaje #{$id}: {$errTxt}");
    return ['ok' => false, 'error' => $errTxt, 'destino' => $destinoRaw, 'canal_nombre' => $canalNombre];
}

// ============================================================================
// Helpers internos
// ============================================================================

function marcarTelegramError(PDO $pdo, int $id, string $err): void {
    $st = $pdo->prepare("
        UPDATE telegram_mensajes
           SET estado = 'error',
               error  = :err,
               demora = TIMESTAMPDIFF(SECOND, COALESCE(encolado, fecha, NOW()), NOW())
         WHERE id = :id
    ");
    $st->execute([':err' => substr($err, 0, 1000), ':id' => $id]);
}

/**
 * Marca el canal como online='1' y actualiza `latido = NOW()`. Se llama
 * desde el sender pasivo cada vez que un envio real termina OK -- gratis
 * (piggyback sobre trafico organico). No-op si el canal_id es 0/NULL.
 */
function marcarCanalTelegramOnline(PDO $pdo, int $canalId): void {
    if ($canalId <= 0) return;
    $st = $pdo->prepare("
        UPDATE telegram_canales
           SET online      = '1',
               latido      = NOW(),
               actualizado = NOW()
         WHERE id = :id
    ");
    $st->execute([':id' => $canalId]);
}

/**
 * Marca el canal como online='0' (sesion caida). NO pisa `latido` --
 * queda congelado el ultimo instante en que se supo vivo, para que el ABM
 * muestre "hace X horas" y sea evidente cuando se cayo.
 */
function marcarCanalTelegramOffline(PDO $pdo, int $canalId): void {
    if ($canalId <= 0) return;
    $st = $pdo->prepare("
        UPDATE telegram_canales
           SET online      = '0',
               actualizado = NOW()
         WHERE id = :id
    ");
    $st->execute([':id' => $canalId]);
}

/**
 * Clasifica un mensaje de error como "sesion caida" segun los codigos que
 * devuelve Telegram/MadelineProto cuando la auth key ya no es valida en un
 * DC (o el usuario se desactivo). Se usa para bajar `online='0'` sin
 * requerir un health check activo.
 *
 * Codigos considerados fatales de sesion:
 *   - AUTH_KEY_UNREGISTERED  : la auth key nunca se autorizo en este DC.
 *   - AUTH_KEY_INVALID       : la auth key expiro o fue rechazada.
 *   - AUTH_KEY_DUPLICATED    : otra conexion tomo la sesion (fuerza logout).
 *   - SESSION_REVOKED        : el usuario revoco la sesion desde la app.
 *   - SESSION_EXPIRED        : Telegram invalido la sesion por inactividad.
 *   - USER_DEACTIVATED       : la cuenta fue desactivada (auto o baneada).
 *   - USER_DEACTIVATED_BAN   : baneo explicito.
 *
 * NO cuentan como sesion caida (son transitorios / de la peer):
 *   - PHONE_NUMBER_INVALID / PEER_ID_INVALID / user unreachable / timeouts.
 */
function esErrorSesionTelegramCaida(string $err): bool {
    static $codigos = [
        'AUTH_KEY_UNREGISTERED',
        'AUTH_KEY_INVALID',
        'AUTH_KEY_DUPLICATED',
        'SESSION_REVOKED',
        'SESSION_EXPIRED',
        'USER_DEACTIVATED',
        'USER_DEACTIVATED_BAN',
    ];
    $up = strtoupper($err);
    foreach ($codigos as $c) {
        if (strpos($up, $c) !== false) return true;
    }
    return false;
}

/**
 * Devuelve una apikey de `aplicaciones` para hacer el loopback al v4.
 * Preferimos la app "Kernel"; si no existe, agarramos cualquiera habilitada.
 * Cacheado en memoria del proceso (una sola query por corrida del worker).
 */
function telegramWorkerApiKey(PDO $pdo): ?string {
    static $cached = false;
    static $key    = null;
    if ($cached) return $key;
    $cached = true;

    $st = $pdo->prepare("
        SELECT apikey
          FROM aplicaciones
         WHERE habilitada = '1' AND apikey IS NOT NULL AND apikey <> ''
      ORDER BY (nombre = 'Kernel') DESC, id ASC
         LIMIT 1
    ");
    $st->execute();
    $val = $st->fetchColumn();
    $key = ($val === false || $val === null) ? null : (string) $val;
    return $key;
}

/**
 * POST al microservicio v4/telegram/mensajes con Bearer apikey y body JSON.
 * Devuelve ['status'=>int, 'body'=>string, 'decoded'=>?array]. Tira excepcion
 * en fallas de red / cURL (no en errores HTTP -- esos van en 'status').
 */
function telegramV4Post(string $apikey, array $body): array {
    $ch = curl_init(TG_V4_ENDPOINT);
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => json_encode($body, JSON_UNESCAPED_UNICODE),
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => TG_V4_TIMEOUT_SEG,
        CURLOPT_CONNECTTIMEOUT => TG_V4_CONNECT_SEG,
        CURLOPT_HTTPHEADER     => [
            'Authorization: Bearer ' . $apikey,
            'Content-Type: application/json; charset=utf-8',
            'Accept: application/json',
        ],
    ]);
    $raw   = curl_exec($ch);
    $err   = curl_error($ch);
    $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($raw === false) {
        throw new RuntimeException($err ?: 'cURL sin detalle');
    }
    $decoded = json_decode((string)$raw, true);
    return [
        'status'  => $status,
        'body'    => (string) $raw,
        'decoded' => is_array($decoded) ? $decoded : null,
    ];
}
