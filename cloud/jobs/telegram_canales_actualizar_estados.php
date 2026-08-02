<?php
/**
 * cloud/jobs/telegramcanales_actualizar_estados.php
 * Cron activo que audita la salud de las sesiones MTProto de los canales de
 * Telegram. Complementa el monitoreo pasivo del sender
 * (cloud/api/lib/telegram_mensajes_enviar.php), que ya actualiza
 * telegram_canales.online/latido cada vez que un envio real sale OK.
 *
 * Este job cubre el caso "no hay trafico organico": pinguea via getSelf()
 * los canales cuyo `latido` este viejo o nulo, para detectar sesiones caidas
 * (AUTH_KEY_UNREGISTERED / SESSION_REVOKED / USER_DEACTIVATED) aunque nadie
 * este mandando mensajes por ese canal.
 *
 * Reglas:
 *   - Solo se auditan canales habilitados ('1').
 *   - Solo se auditan los canales con `latido IS NULL` o
 *     `latido < NOW() - INTERVAL TG_HEALTH_UMBRAL_MIN MINUTE`. Los canales
 *     recien "latidos" (por el pasivo o por una corrida previa de este cron)
 *     se saltean para no gastar cold-start del daemon MadelineProto en gusto.
 *   - Cada canal se pinguea via POST loopback a /v4/telegram/health, mismo
 *     patron que el sender contra /v4/telegram/mensajes.
 *   - Los errores por canal NO frenan el job: se registra suceso y sigue.
 *
 * Cadencia recomendada: cada 5 min con umbral 15 min (via `tareas.cron_expr`
 * seteado en la migracion 20260731_0500). Baja a proposito -- el cold start
 * del daemon IPC puede costar 5-15 s si esta frio.
 *
 * Se registra desde el Programador de tareas (tabla `tareas`) apuntando
 * `script` = "telegramcanales_actualizar_estados".
 */

require_once __DIR__ . '/_bootstrap.php';

const TG_V4_HEALTH_ENDPOINT = 'http://localhost:8114/v4/telegram/health';
const TG_HEALTH_TIMEOUT_SEG = 60;   // MadelineProto puede tardar ~15 s en cold start
const TG_HEALTH_CONNECT_SEG = 5;
const TG_HEALTH_UMBRAL_MIN  = 15;   // solo canales con latido mas viejo que esto

$ORIGEN_SUCESO = 'cron/telegramcanales_estados';

try {
    $pdo = db();

    // Solo canales habilitados y con `latido` viejo o nulo. El indice
    // idx_telegram_canales_latido cubre esta clausula.
    $stmt = $pdo->prepare("
        SELECT id, slug, nombre, telefono, latido
          FROM telegram_canales
         WHERE habilitado = '1'
           AND slug     IS NOT NULL AND slug     <> ''
           AND telefono IS NOT NULL AND telefono <> ''
           AND (latido IS NULL OR latido < NOW() - INTERVAL :umbral MINUTE)
         ORDER BY id
    ");
    $stmt->execute([':umbral' => TG_HEALTH_UMBRAL_MIN]);
    $canales = $stmt->fetchAll();
    $total   = count($canales);

    anotarLog("Canales Telegram a auditar (latido > " . TG_HEALTH_UMBRAL_MIN . " min): {$total}");
    if ($total === 0) {
        marcarEjecucionOk('Sin canales Telegram con latido viejo.');
        exit(0);
    }

    // Apikey para el loopback al v4 (misma logica que el sender).
    $apikey = telegramCanalesHealthApiKey($pdo);
    if ($apikey === null) {
        $msg = 'No hay ninguna aplicacion habilitada con apikey para el loopback';
        registrarSuceso($pdo, $ORIGEN_SUCESO, 'error', $msg);
        marcarEjecucionError(new RuntimeException($msg));
        exit(1);
    }

    $okCount   = 0;
    $errCount  = 0;
    $skipCount = 0;

    foreach ($canales as $i => $c) {
        $prefix = sprintf('[%d/%d] canal #%d (%s +%s)',
            $i + 1, $total, (int)$c['id'], $c['nombre'] ?? '', (string)$c['telefono']);

        anotarLog("{$prefix} - consultando getSelf()...");
        try {
            $r = verificarSalud($apikey, (string)$c['slug']);
        } catch (Throwable $e) {
            $msg = 'excepcion cURL: ' . $e->getMessage();
            marcarCanalTelegramOfflineLocal($pdo, (int)$c['id']);
            anotarLog("{$prefix} - FALLO ({$msg}) -> offline");
            registrarSuceso($pdo, $ORIGEN_SUCESO, 'alerta',
                "Telegram canal #{$c['id']} ({$c['nombre']}) - {$msg}");
            $errCount++;
            continue;
        }

        if ($r['ok']) {
            marcarCanalTelegramOnlineLocal($pdo, (int)$c['id']);
            $anotacion = 'user_id=' . ($r['user_id'] ?? '?');
            if (!empty($r['username']))   $anotacion .= " username=@{$r['username']}";
            if (!empty($r['first_name'])) $anotacion .= " nombre='{$r['first_name']}'";
            anotarLog("{$prefix} - OK ({$anotacion})");
            registrarSuceso($pdo, $ORIGEN_SUCESO, 'info',
                "Telegram canal #{$c['id']} ({$c['nombre']}) - vivo ({$anotacion})");
            $okCount++;
            continue;
        }

        // Error de la app / de Telegram. Si contiene alguno de los codigos
        // de sesion caida, lo tratamos como OFFLINE explicito; si no,
        // dejamos el flag como estaba (puede ser un timeout transitorio).
        $err = (string)$r['error'];
        if (esErrorSesionTelegramCaida($err)) {
            marcarCanalTelegramOfflineLocal($pdo, (int)$c['id']);
            anotarLog("{$prefix} - SESION CAIDA -> offline ({$err})");
            registrarSuceso($pdo, $ORIGEN_SUCESO, 'error',
                "Telegram canal #{$c['id']} ({$c['nombre']}) OFFLINE por: {$err}");
            $errCount++;
        } else {
            anotarLog("{$prefix} - error transitorio (no toco flag): {$err}");
            registrarSuceso($pdo, $ORIGEN_SUCESO, 'alerta',
                "Telegram canal #{$c['id']} ({$c['nombre']}) - {$err}");
            $skipCount++;
        }
    }

    $resumen = "{$okCount} OK | {$errCount} offline | {$skipCount} transitorios | {$total} total";
    anotarLog("Finalizado: {$resumen}");
    marcarEjecucionOk($resumen);

} catch (Throwable $e) {
    anotarLog('ERROR fatal: ' . $e->getMessage());
    marcarEjecucionError($e);
    throw $e;
}

// ============================================================================
// Helpers
// ============================================================================

/**
 * POST loopback al endpoint /v4/telegram/health con el `canal_slug`.
 * Devuelve ['ok'=>true, 'user_id'=>int, 'username'=>?string, 'first_name'=>?string]
 *      o  ['ok'=>false, 'error'=>string].
 * Tira excepcion solo en fallas de red / cURL (no en errores HTTP -- esos
 * los mapea al ok=false).
 */
function verificarSalud(string $apikey, string $canalSlug): array {
    $ch = curl_init(TG_V4_HEALTH_ENDPOINT);
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => json_encode(['canal_slug' => $canalSlug], JSON_UNESCAPED_UNICODE),
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => TG_HEALTH_TIMEOUT_SEG,
        CURLOPT_CONNECTTIMEOUT => TG_HEALTH_CONNECT_SEG,
        CURLOPT_HTTPHEADER     => [
            'Authorization: Bearer ' . $apikey,
            'Content-Type: application/json; charset=utf-8',
            'Accept: application/json',
        ],
    ]);
    $raw    = curl_exec($ch);
    $err    = curl_error($ch);
    $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    // Nota: curl_close esta deprecado en PHP 8.0+ pero seguimos el patron
    // del resto del proyecto (cloud/api/lib/telegram_mensajes_enviar.php).
    curl_close($ch);

    if ($raw === false) {
        throw new RuntimeException($err ?: 'cURL sin detalle');
    }
    $decoded = json_decode((string)$raw, true);
    $ok      = $status >= 200 && $status < 300 && is_array($decoded) && !empty($decoded['ok']);
    if ($ok) {
        $data = is_array($decoded['data'] ?? null) ? $decoded['data'] : $decoded;
        return [
            'ok'         => true,
            'user_id'    => (int)($data['user_id'] ?? 0),
            'username'   => $data['username']   ?? null,
            'first_name' => $data['first_name'] ?? null,
        ];
    }
    $errMsg = is_array($decoded) && isset($decoded['error']) ? (string)$decoded['error'] : '';
    $errTxt = 'HTTP ' . $status . ($errMsg !== '' ? ": {$errMsg}" : ': ' . substr((string)$raw, 0, 400));
    return ['ok' => false, 'error' => $errTxt];
}

/**
 * Devuelve una apikey de `aplicaciones` para hacer el loopback al v4.
 * Preferimos la app "Kernel"; si no existe, agarramos cualquiera habilitada.
 * Mirror de telegramWorkerApiKey() en lib/telegram_mensajes_enviar.php.
 */
function telegramCanalesHealthApiKey(PDO $pdo): ?string {
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
 * Marca online='1' + latido=NOW() + actualizado=NOW(). Duplicado local de
 * marcarCanalTelegramOnline() en lib/telegram_mensajes_enviar.php para no
 * arrastrar todo ese lib al cron activo.
 */
function marcarCanalTelegramOnlineLocal(PDO $pdo, int $canalId): void {
    $st = $pdo->prepare("
        UPDATE telegram_canales
           SET online      = '1',
               latido      = NOW(),
               actualizado = NOW()
         WHERE id = :id
    ");
    $st->execute([':id' => $canalId]);
}

/** Marca online='0'. NO pisa `latido`, conserva el ultimo momento vivo. */
function marcarCanalTelegramOfflineLocal(PDO $pdo, int $canalId): void {
    $st = $pdo->prepare("
        UPDATE telegram_canales
           SET online      = '0',
               actualizado = NOW()
         WHERE id = :id
    ");
    $st->execute([':id' => $canalId]);
}

/**
 * Clasifica un mensaje de error como "sesion caida". Duplicado local de
 * esErrorSesionTelegramCaida() en lib/telegram_mensajes_enviar.php.
 * Mantener alineado con esa lista.
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
