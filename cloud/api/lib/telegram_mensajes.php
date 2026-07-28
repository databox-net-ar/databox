<?php
// api/lib/telegram_mensajes.php
// Punto UNICO de entrada para insertar en `telegram_mensajes`. Cualquier caller
// (endpoint HTTP del ABM cloud, microservicio v4 de ingesta, futuros scripts CLI
// o jobs manuales) debe pasar por enviarTelegramMensaje() para garantizar las
// mismas reglas de ingreso -- sanitizacion, obligatorios, defaults y envio
// sincrono contra la Bot API de Telegram.
//
// Espejo estructural de cloud/api/lib/evolution_mensajes.php, con dos
// diferencias fundamentales:
//   1. NO hay cola ni motor: el envio es SINCRONO en el mismo POST. El caller
//      espera la respuesta de Telegram y el mensaje queda persistido con estado
//      final ('enviado' o 'error') antes de devolver el id. Esto simplifica el
//      flow y elimina la necesidad de sender worker, banderas de motor y
//      wake-on-demand.
//   2. El `canal_id` apunta a `telegram_bots` (no a un canal WhatsApp), y el
//      `destino` es un chat_id de Telegram (numerico, puede ser negativo para
//      grupos/canales). El token para autenticar contra la Bot API se lee del
//      bot al momento del envio.
//
// Contrato:
//   int enviarTelegramMensaje(PDO $pdo, array $datos)
//     -> devuelve el id insertado (siempre, aunque Telegram haya devuelto error)
//     -> tira InvalidArgumentException si faltan obligatorios (la capa HTTP
//        lo traduce a 400 via jsonError)
//     -> tira RuntimeException si el bot no existe / no tiene token
//        (la capa HTTP lo traduce a 400 tambien)
//     -> el resultado del envio queda persistido en la fila (estado + error);
//        el caller puede releerla con SELECT ... WHERE id = :id para saber
//        si Telegram acepto el mensaje

require_once __DIR__ . '/../db.php';

// Mapa columna -> regla del sanitizador. Reusado para INSERT completo.
const TG_MSG_SANITIZERS = [
    'uuid'         => 'str:255',
    'fecha'        => 'dt',
    'proyecto_id'  => 'int',
    'canal_id'     => 'int',
    'plantilla_id' => 'int',
    'contacto_id'  => 'int',
    'remitente'    => 'str:255',
    'remite'       => 'str:255',
    'destinatario' => 'str:255',
    'destino'      => 'str:255',
    'prioridad'    => 'int',
    'asunto'       => 'str:255',
    'cuerpo'       => 'str',
    'formato'      => 'str:20',
    'adjunto'      => 'str:500',
    'tags'         => 'str:255',
    'estado'       => 'str:20',
    'error'        => 'str:1000',
    'encolado'     => 'dt',
    'programado'   => 'dt',
    'enviado'      => 'dt',
    'demora'       => 'int',
];

// Campos obligatorios al enviar un mensaje nuevo. Sin canal_id no hay bot para
// autenticar contra la Bot API; sin destino no hay chat_id al que mandar; sin
// cuerpo no hay texto que enviar. `proyecto_id` es obligatorio por consistencia
// con evolution/aws (permite filtrar/reportar por proyecto).
const TG_MSG_REQUERIDOS_CREATE = [
    'proyecto_id' => 'Proyecto',
    'canal_id'    => 'Bot',
    'destino'     => 'Destino (chat_id)',
    'cuerpo'      => 'Cuerpo',
];

// Endpoint base de la Bot API. El token va como parte del path.
// Docs: https://core.telegram.org/bots/api#sendmessage
const TG_API_BASE = 'https://api.telegram.org';

// ----------------------------------------------------------------------------
// API publica
// ----------------------------------------------------------------------------

/**
 * Punto UNICO de entrada para insertar+enviar un mensaje de Telegram.
 *
 * A diferencia de evolution/aws (asincronos, encolan y devuelven), aca:
 *   1. Sanitiza y valida los obligatorios.
 *   2. Aplica plantilla si viene plantilla_id (sobreescribe cuerpo/asunto/etc).
 *   3. Inserta la fila con estado='enviando' (para dejar traza si el proceso
 *      revienta antes del UPDATE final).
 *   4. Llama a la Bot API con el token del bot y el chat_id del destino.
 *   5. Actualiza la fila con estado='enviado' + demora, o estado='error' +
 *      mensaje de error segun el resultado.
 *
 * Siempre devuelve el id insertado. El caller debe releer la fila para saber
 * si Telegram acepto el mensaje.
 */
function enviarTelegramMensaje(PDO $pdo, array $datos): int {
    $p = sanitizeTgMsgPayload($pdo, $datos);

    // Validar obligatorios antes de tocar la BD.
    $faltantes = [];
    foreach (TG_MSG_REQUERIDOS_CREATE as $col => $label) {
        if ($p[$col] === null) $faltantes[] = $label;
    }
    if ($faltantes) {
        throw new InvalidArgumentException(
            'Faltan campos obligatorios: ' . implode(', ', $faltantes)
        );
    }

    // Buscar el bot y validar que este habilitado y tenga token. Lo hacemos
    // ANTES del INSERT: si el bot no sirve, no vale la pena persistir la fila.
    $bot = tgMsgCargarBot($pdo, (int)$p['canal_id']);
    if ($bot === null) {
        throw new InvalidArgumentException("Bot #{$p['canal_id']} no existe");
    }
    if (empty($bot['token'])) {
        throw new InvalidArgumentException("Bot #{$p['canal_id']} no tiene token configurado");
    }
    if (($bot['habilitado'] ?? '') === '0') {
        throw new InvalidArgumentException("Bot #{$p['canal_id']} esta deshabilitado");
    }

    // Defaults system-managed. Los sanitizadores devuelven null si el caller
    // no envio el campo; aca decidimos que ponemos por defecto:
    //   - fecha      : NOW() en zona AR (cuando se creo el mensaje)
    //   - encolado   : mismo instante que fecha (sync, no hay cola real)
    //   - programado : mismo instante que fecha (sync, sale al toque)
    //   - estado     : 'enviando' (se transiciona a 'enviado' o 'error' abajo)
    //   - formato    : 'texto' (por default Telegram texto plano)
    //   - prioridad  : 3 = media (rango 1..5, mantenemos por consistencia
    //                  con evolution/aws aunque en telegram no aplique)
    if ($p['fecha'] === null) {
        $p['fecha'] = (new DateTime('now', new DateTimeZone('America/Argentina/Buenos_Aires')))
                      ->format('Y-m-d H:i:s');
    }
    if ($p['encolado']   === null) $p['encolado']   = $p['fecha'];
    if ($p['programado'] === null) $p['programado'] = $p['fecha'];
    if ($p['estado']     === null) $p['estado']     = 'enviando';
    if ($p['formato']    === null) $p['formato']    = 'texto';
    if ($p['prioridad']  === null) $p['prioridad']  = 3;

    // uuid: identificador propio del mensaje en formato UUID estandar RFC 4122
    // (36 chars con guiones). Se genera aca si el caller no lo mando -- asi
    // toda insercion nueva queda con uuid poblado (mirror del pattern de
    // evolution_mensajes / aws_mensajes).
    if ($p['uuid'] === null) {
        $p['uuid'] = (string)$pdo->query('SELECT UUID()')->fetchColumn();
    }

    // INSERT inicial con estado='enviando'. Si el proceso PHP se muere antes
    // del UPDATE final, la fila queda en 'enviando' -- estado inconsistente,
    // pero al menos hay traza. Un operador puede borrarla / re-enviarla.
    $sql = "
        INSERT INTO telegram_mensajes
            (uuid, fecha, proyecto_id, canal_id, plantilla_id, contacto_id, remitente,
             remite, destinatario, destino, prioridad, asunto, cuerpo, formato,
             adjunto, tags, estado, error, encolado, programado, enviado, demora)
        VALUES
            (:uuid, :fecha, :proyecto_id, :canal_id, :plantilla_id, :contacto_id, :remitente,
             :remite, :destinatario, :destino, :prioridad, :asunto, :cuerpo, :formato,
             :adjunto, :tags, :estado, :error, :encolado, :programado, :enviado, :demora)
    ";
    $stmt = $pdo->prepare($sql);
    $stmt->execute([
        ':uuid'         => $p['uuid'],
        ':fecha'        => $p['fecha'],
        ':proyecto_id'  => $p['proyecto_id'],
        ':canal_id'     => $p['canal_id'],
        ':plantilla_id' => $p['plantilla_id'],
        ':contacto_id'  => $p['contacto_id'],
        ':remitente'    => $p['remitente'],
        ':remite'       => $p['remite'],
        ':destinatario' => $p['destinatario'],
        ':destino'      => $p['destino'],
        ':prioridad'    => $p['prioridad'],
        ':asunto'       => $p['asunto'],
        ':cuerpo'       => $p['cuerpo'],
        ':formato'      => $p['formato'],
        ':adjunto'      => $p['adjunto'],
        ':tags'         => $p['tags'],
        ':estado'       => $p['estado'],
        ':error'        => $p['error'],
        ':encolado'     => $p['encolado'],
        ':programado'   => $p['programado'],
        ':enviado'      => $p['enviado'],
        ':demora'       => $p['demora'],
    ]);
    $id = (int)$pdo->lastInsertId();

    // Envio sincrono a la Bot API. Si el asunto viene seteado lo anteponemos
    // en negrita al cuerpo (mismo patron que el sender de Evolution).
    $texto = $p['cuerpo'];
    if ($p['asunto'] !== null && $p['asunto'] !== '') {
        $texto = "*" . $p['asunto'] . "*\n\n" . $texto;
    }

    $t0 = microtime(true);
    $res = tgMsgLlamarBotApi(
        (string)$bot['token'],
        (string)$p['destino'],
        (string)$texto,
        $p['adjunto']
    );
    $demora = (int)round(microtime(true) - $t0);

    $ahora = (new DateTime('now', new DateTimeZone('America/Argentina/Buenos_Aires')))
             ->format('Y-m-d H:i:s');

    if ($res['ok']) {
        $upd = $pdo->prepare("
            UPDATE telegram_mensajes
               SET estado = 'enviado',
                   enviado = :enviado,
                   demora  = :demora,
                   error   = NULL
             WHERE id = :id
        ");
        $upd->execute([
            ':enviado' => $ahora,
            ':demora'  => $demora,
            ':id'      => $id,
        ]);
    } else {
        $upd = $pdo->prepare("
            UPDATE telegram_mensajes
               SET estado = 'error',
                   demora = :demora,
                   error  = :error
             WHERE id = :id
        ");
        $upd->execute([
            ':demora' => $demora,
            ':error'  => substr((string)$res['error'], 0, 1000),
            ':id'     => $id,
        ]);
    }

    return $id;
}

// ----------------------------------------------------------------------------
// Llamado a la Bot API de Telegram
// ----------------------------------------------------------------------------

/**
 * POST a https://api.telegram.org/bot<TOKEN>/sendMessage con chat_id y text.
 * Si `adjunto` esta seteado y es una URL http(s) usa sendPhoto en su lugar
 * (con la URL como parametro `photo`) -- adecuado para fotos publicas. Para
 * casos mas complejos (documentos, videos, audios, uploads binarios) el
 * caller deberia armar el request a mano.
 *
 * Devuelve ['ok' => true] si Telegram devolvio ok, ['ok' => false, 'error' => '...']
 * en caso contrario. Nunca tira -- el caller ya maneja la persistencia del
 * error.
 */
function tgMsgLlamarBotApi(string $token, string $chatId, string $texto, ?string $adjunto): array {
    // Elegir metodo: sendPhoto si viene un URL http(s) en adjunto, sendMessage
    // en caso contrario.
    $esFotoUrl = $adjunto !== null && $adjunto !== ''
                 && (str_starts_with($adjunto, 'http://') || str_starts_with($adjunto, 'https://'));

    if ($esFotoUrl) {
        $endpoint = TG_API_BASE . "/bot{$token}/sendPhoto";
        $body = [
            'chat_id'    => $chatId,
            'photo'      => $adjunto,
            'caption'    => $texto,
            'parse_mode' => 'Markdown',
        ];
    } else {
        $endpoint = TG_API_BASE . "/bot{$token}/sendMessage";
        $body = [
            'chat_id'    => $chatId,
            'text'       => $texto,
            'parse_mode' => 'Markdown',
        ];
    }

    $ch = curl_init($endpoint);
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => http_build_query($body),
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 15,
        CURLOPT_CONNECTTIMEOUT => 5,
        CURLOPT_HTTPHEADER     => ['Content-Type: application/x-www-form-urlencoded'],
    ]);
    $raw   = curl_exec($ch);
    $http  = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $cerr  = curl_error($ch);
    curl_close($ch);

    if ($raw === false) {
        return ['ok' => false, 'error' => 'curl: ' . $cerr];
    }

    $j = json_decode((string)$raw, true);
    if (!is_array($j)) {
        return ['ok' => false, 'error' => "HTTP {$http}: respuesta no-JSON"];
    }
    if (!empty($j['ok'])) {
        return ['ok' => true];
    }

    $desc = $j['description'] ?? "HTTP {$http}";
    $code = $j['error_code'] ?? $http;
    return ['ok' => false, 'error' => "Telegram [{$code}]: {$desc}"];
}

// ----------------------------------------------------------------------------
// Sanitizacion
// ----------------------------------------------------------------------------

// Sanitiza TODAS las columnas -- los faltantes quedan NULL.
function sanitizeTgMsgPayload(PDO $pdo, array $in): array {
    // Aliases legacy: aceptar `proyecto`/`canal`/`plantilla` (sin sufijo _id)
    // ademas de la forma canonica. Asi bookmarks y clientes viejos del v4
    // siguen andando.
    if (!array_key_exists('proyecto_id',  $in) && array_key_exists('proyecto',  $in)) $in['proyecto_id']  = $in['proyecto'];
    if (!array_key_exists('canal_id',     $in) && array_key_exists('canal',     $in)) $in['canal_id']     = $in['canal'];
    if (!array_key_exists('plantilla_id', $in) && array_key_exists('plantilla', $in)) $in['plantilla_id'] = $in['plantilla'];

    // Resolucion de slugs -> ids (proyecto_slug, canal_slug, plantilla_slug).
    $in = resolverTgMsgSlugs($pdo, $in);

    // Aplicacion de plantilla: si viene plantilla_id, sobrescribe
    // cuerpo/asunto/formato/adjunto/remite/remitente con los valores de la
    // plantilla, y sustituye placeholders `{clave}` con $in['variables'].
    $in = aplicarPlantillaTgMsg($pdo, $in);

    // Si no vino `destino` pero el bot elegido tiene chat_id default, usarlo.
    // Se hace ANTES del sanitizer para que quede aplicado al final.
    if ((!array_key_exists('destino', $in) || $in['destino'] === null || $in['destino'] === '')
        && !empty($in['canal_id'])) {
        $st = $pdo->prepare("SELECT chat_id FROM telegram_bots WHERE id = :id LIMIT 1");
        $st->execute([':id' => (int)$in['canal_id']]);
        $chat = $st->fetchColumn();
        if ($chat !== false && $chat !== null && $chat !== '') {
            $in['destino'] = $chat;
        }
    }

    $out = [];
    foreach (TG_MSG_SANITIZERS as $col => $rule) {
        $out[$col] = applyTgMsgSanitizer($rule, $in[$col] ?? null);
    }
    return $out;
}

// Si viene `plantilla_id`, carga la fila de `datarocket_plantillas` y
// sobrescribe con ella los campos `cuerpo`, `asunto`, `formato`, `adjunto`,
// `remite` y `remitente` del payload (la plantilla siempre gana sobre lo que
// venga en el body -- solo respeta los campos que la plantilla no tenga
// seteados). Despues aplica sustitucion de variables `{clave}` sobre `cuerpo`
// y `asunto` usando el dict `$in['variables']` (opcional).
//
// El campo `variables` no se guarda como columna en `telegram_mensajes`; se
// descarta al final. Si no viene `plantilla_id`, es no-op.
const TG_MSG_PLANTILLA_COLS = ['cuerpo', 'asunto', 'formato', 'adjunto', 'remite', 'remitente'];

function aplicarPlantillaTgMsg(PDO $pdo, array $in): array {
    if (empty($in['plantilla_id'])) {
        unset($in['variables']);
        return $in;
    }
    $st = $pdo->prepare(
        "SELECT " . implode(', ', TG_MSG_PLANTILLA_COLS) . "
           FROM datarocket_plantillas WHERE id = :id LIMIT 1"
    );
    $st->execute([':id' => (int)$in['plantilla_id']]);
    $pl = $st->fetch();
    if (!$pl) {
        unset($in['variables']);
        return $in;
    }

    foreach (TG_MSG_PLANTILLA_COLS as $col) {
        if (isset($pl[$col]) && (string)$pl[$col] !== '') {
            $in[$col] = $pl[$col];
        }
    }

    $vars = $in['variables'] ?? null;
    if (is_array($vars)) {
        foreach (['cuerpo', 'asunto'] as $col) {
            if (empty($in[$col])) continue;
            foreach ($vars as $k => $v) {
                $k = trim((string)$k);
                if ($k === '') continue;
                $in[$col] = str_replace('{' . $k . '}', (string)$v, (string)$in[$col]);
            }
        }
    }
    unset($in['variables']);
    return $in;
}

// Resuelve `proyecto_slug` / `canal_slug` / `plantilla_slug` a sus ids.
// Cuando viene el slug se ignora el id numerico del mismo campo (el slug es la
// fuente de verdad). Slug no encontrado -> InvalidArgumentException.
function resolverTgMsgSlugs(PDO $pdo, array $in): array {
    $resolver = [
        'proyecto_slug'  => ['proyecto_id',  'proyectos',              'Proyecto'],
        'canal_slug'     => ['canal_id',     'telegram_bots',          'Bot'],
        'plantilla_slug' => ['plantilla_id', 'datarocket_plantillas',  'Plantilla'],
    ];
    foreach ($resolver as $slugKey => [$idKey, $tabla, $label]) {
        if (!array_key_exists($slugKey, $in)) continue;
        $slug = trim((string)$in[$slugKey]);
        if ($slug === '') continue;
        $st = $pdo->prepare("SELECT id FROM {$tabla} WHERE slug = :s LIMIT 1");
        $st->execute([':s' => $slug]);
        $id = $st->fetchColumn();
        if ($id === false) {
            throw new InvalidArgumentException("{$label} con slug '{$slug}' no encontrado");
        }
        $in[$idKey] = (int)$id;
    }
    return $in;
}

// Carga la fila completa del bot (para leer token / habilitado / chat_id).
// Devuelve null si el bot no existe.
function tgMsgCargarBot(PDO $pdo, int $id): ?array {
    $st = $pdo->prepare("SELECT id, nombre, token, chat_id, habilitado
                           FROM telegram_bots WHERE id = :id LIMIT 1");
    $st->execute([':id' => $id]);
    $r = $st->fetch();
    return $r ?: null;
}

function applyTgMsgSanitizer(string $rule, mixed $val): mixed {
    if ($rule === 'int') return tgMsgNullableInt($val);
    if ($rule === 'dt')  return tgMsgNullableDateTime($val);
    if ($rule === 'str') return tgMsgNullableStr($val);
    if (str_starts_with($rule, 'str:')) {
        return tgMsgNullableStr($val, (int)substr($rule, 4));
    }
    throw new RuntimeException("Sanitizer desconocido: {$rule}");
}

function tgMsgNullableStr(mixed $v, ?int $max = null): ?string {
    if ($v === null) return null;
    $s = trim((string)$v);
    if ($s === '') return null;
    if ($max !== null) $s = substr($s, 0, $max);
    return $s;
}

function tgMsgNullableInt(mixed $v): ?int {
    if ($v === null || $v === '') return null;
    return (int)$v;
}

function tgMsgNullableDateTime(mixed $v): ?string {
    $s = tgMsgNullableStr($v);
    if ($s === null) return null;
    $s = str_replace('T', ' ', $s);
    if (preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}$/', $s)) $s .= ':00';
    if (!preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $s)) return null;
    return $s;
}
