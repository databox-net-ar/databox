<?php
// api/movistarsims_sync.php
// Sincroniza el inventario de SIMs Movistar desde Kite Platform.
// Pagina GET /Inventory/v13/r12/sim (maxBatchSize=1000) respetando el limite
// de 4 TPS documentado por Kite para esa operacion, y hace UPSERT sobre la
// tabla `movistarsims` por ICCID (UNIQUE KEY uk_movistarsims_icc). Idempotente.
//
// Consume:
//   POST api/movistarsims_sync.php   (sin body)
//
// Respuesta:
//   200 {ok:true, data:{fetched, insertados, actualizados, paginas, duracion_ms, ultima_sync}}
//   500 en caso de error de handshake / API Kite / DB.
//
// Requiere en el .env:
//   KITE_API_HOST, KITE_API_PORT, KITE_CERT_PATH, KITE_KEY_PATH, KITE_CERT_PASS.

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/lib/auth_check.php';

header('Content-Type: application/json; charset=utf-8');

requireAuth();

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
if ($method !== 'POST') jsonError('Metodo no soportado', 405);

try {
    requirePermission('plataformas.movistar.sims.sincronizar');
    $cfg   = kiteConfig();
    $t0    = microtime(true);
    $stats = kiteSyncSims($cfg, db());
    $stats['duracion_ms'] = (int) round((microtime(true) - $t0) * 1000);
    jsonOk($stats);
} catch (Throwable $e) {
    jsonError($e->getMessage(), 500);
}

// ----------------------------------------------------------------------------
// Configuracion y cliente HTTP mTLS
// ----------------------------------------------------------------------------

/**
 * Lee la configuracion mTLS desde el entorno.
 * Detecta si el certificado es PEM (.cer + .key) o PKCS12 (.pfx / .p12).
 * Preferir PEM: OpenSSL 3 rechaza el cifrado legacy que usa el .pfx que
 * emite Kite. Los PEM se extraen una unica vez con:
 *   openssl pkcs12 -in movistar.pfx -clcerts -nokeys -passin env:PW -legacy -out movistar.cer
 *   openssl pkcs12 -in movistar.pfx -nocerts -nodes  -passin env:PW -legacy -out movistar.key
 */
function kiteConfig(): array {
    $host = trim((string)(getenv('KITE_API_HOST') ?: ''));
    $port = (int)(getenv('KITE_API_PORT') ?: 0);
    $cert = trim((string)(getenv('KITE_CERT_PATH') ?: ''));
    $key  = trim((string)(getenv('KITE_KEY_PATH')  ?: ''));
    $pass = (string)(getenv('KITE_CERT_PASS') ?: '');

    if ($host === '' || $port <= 0) {
        throw new RuntimeException('Falta configurar KITE_API_HOST / KITE_API_PORT en el .env.');
    }
    if ($cert === '') {
        throw new RuntimeException('Falta configurar KITE_CERT_PATH en el .env.');
    }

    // Fallback: si el .cer declarado no existe pero si esta el .pfx del mismo
    // basename, usamos el .pfx (OpenSSL 3 no lo soporta pero al menos el error
    // sera claro y sabra el dev que tiene que extraer los PEM con -legacy).
    if (!is_readable($cert)) {
        $pfxCandidate = preg_replace('/\.(cer|crt|pem)$/i', '.pfx', $cert) ?? $cert;
        if ($pfxCandidate !== $cert && is_readable($pfxCandidate)) {
            $cert = $pfxCandidate;
        }
    }
    $tipo = preg_match('/\.(pfx|p12)$/i', $cert) ? 'P12' : 'PEM';
    if ($tipo === 'P12') $key = ''; // el .pfx trae la clave dentro

    if (!is_readable($cert)) {
        throw new RuntimeException("No se puede leer el certificado Kite en {$cert}.");
    }
    if ($tipo === 'PEM' && $key !== '' && !is_readable($key)) {
        throw new RuntimeException("No se puede leer la clave privada Kite en {$key}.");
    }

    return compact('host', 'port', 'cert', 'key', 'pass', 'tipo');
}

/**
 * GET mTLS contra Kite. Devuelve el JSON decodificado o tira excepcion.
 * Timeout 70s por operacion (limite documentado por Kite).
 */
function kiteGet(array $cfg, string $path): array {
    $url = "https://{$cfg['host']}:{$cfg['port']}{$path}";

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_TIMEOUT        => 70,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2,
        CURLOPT_SSLCERT        => $cfg['cert'],
        CURLOPT_SSLCERTTYPE    => $cfg['tipo'],
        CURLOPT_HTTPHEADER     => ['Accept: application/json'],
    ]);
    // Password solo aplica al .pfx (P12). El .key PEM lo extrajimos con -nodes,
    // asi que no tiene passphrase.
    if ($cfg['tipo'] === 'P12') {
        curl_setopt($ch, CURLOPT_KEYPASSWD, $cfg['pass']);
    } elseif ($cfg['key'] !== '') {
        curl_setopt($ch, CURLOPT_SSLKEY,     $cfg['key']);
        curl_setopt($ch, CURLOPT_SSLKEYTYPE, 'PEM');
    }

    $body  = curl_exec($ch);
    $errno = curl_errno($ch);
    $err   = curl_error($ch);
    $code  = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);

    if ($errno !== 0) {
        throw new RuntimeException("Kite curl error: {$err} (errno {$errno}).");
    }
    if ($code < 200 || $code >= 300) {
        throw new RuntimeException("Kite HTTP {$code}: " . substr((string)$body, 0, 300));
    }
    $decoded = json_decode((string)$body, true);
    if (!is_array($decoded)) {
        throw new RuntimeException('Respuesta de Kite no es JSON valido.');
    }
    return $decoded;
}

// ----------------------------------------------------------------------------
// Sync: paginacion + UPSERT
// ----------------------------------------------------------------------------

/**
 * Recorre todo el inventario de Kite paginando de a `maxBatchSize` (max 1000).
 * Rate limit: getSubscriptions esta topeado en 4 TPS -> minimo 250ms entre
 * requests. Se hace un usleep entre paginas para no caer en el error POL 1005.
 */
function kiteSyncSims(array $cfg, PDO $pdo): array {
    $batch    = 1000;
    $delayUs  = 260_000; // 260ms > 250ms (4 TPS), con margen

    $fetched      = 0;
    $insertados   = 0;
    $actualizados = 0;
    $paginas      = 0;

    $lookup = $pdo->prepare("SELECT id FROM movistarsims WHERE icc = :icc");
    $upsert = $pdo->prepare("
        INSERT INTO movistarsims
            (nombre, linea, icc, estado, estado_gprs, estado_lte, limite_datos, imei, msisdn, actualizado)
        VALUES
            (:nombre, :linea, :icc, :estado, :estado_gprs, :estado_lte, :limite_datos, :imei, :msisdn, NOW())
        ON DUPLICATE KEY UPDATE
            nombre       = VALUES(nombre),
            linea        = VALUES(linea),
            estado       = VALUES(estado),
            estado_gprs  = VALUES(estado_gprs),
            estado_lte   = VALUES(estado_lte),
            limite_datos = VALUES(limite_datos),
            imei         = VALUES(imei),
            msisdn       = VALUES(msisdn),
            actualizado  = VALUES(actualizado)
    ");

    for ($startIndex = 0; ; $startIndex += $batch) {
        $path = "/services/REST/GlobalM2M/Inventory/v13/r12/sim"
              . "?maxBatchSize={$batch}&startIndex={$startIndex}";

        if ($paginas > 0) usleep($delayUs); // no dormir antes de la primer request

        $resp = kiteGet($cfg, $path);
        $paginas++;
        $sims = $resp['subscriptionData'] ?? [];
        if (empty($sims)) break;

        foreach ($sims as $s) {
            $p = mapKiteSim($s);
            if ($p[':icc'] === null) continue; // sin ICC no se puede upsertar

            $lookup->execute([':icc' => $p[':icc']]);
            $existente = (bool) $lookup->fetchColumn();

            $upsert->execute($p);
            if ($existente) $actualizados++; else $insertados++;
            $fetched++;
        }

        // Si Kite devolvio menos que el batch, era la ultima pagina.
        if (count($sims) < $batch) break;
    }

    return [
        'fetched'      => $fetched,
        'insertados'   => $insertados,
        'actualizados' => $actualizados,
        'paginas'      => $paginas,
        'ultima_sync'  => date('Y-m-d H:i:s'),
    ];
}

/**
 * Traduce una entrada de subscriptionData (Kite) a los :params del UPSERT.
 * Decisiones de mapping documentadas en el CLAUDE.md del modulo o en el commit.
 */
function mapKiteSim(array $s): array {
    $icc    = trim((string)($s['icc']    ?? ''));
    $msisdn = trim((string)($s['msisdn'] ?? ''));
    $imei   = trim((string)($s['imeiLock'] ?? ''));
    $estado = trim((string)($s['lifeCycleStatus'] ?? ''));

    // nombre: customField1 (editable en Kite). Si esta vacio, usar alias solo
    // si tiene valor semantico distinto del ICC (Kite pone icc por default).
    $customField1 = trim((string)($s['customField1'] ?? ''));
    $alias        = trim((string)($s['alias']        ?? ''));
    $nombre = $customField1 !== '' ? $customField1
            : (($alias !== '' && $alias !== $icc) ? $alias : null);

    // linea: Kite no expone un identificador de linea distinto del MSISDN.
    // Se copia msisdn como valor por defecto; ABM permite editarlo despues.
    $linea = $msisdn !== '' ? $msisdn : null;

    // Estado GPRS: gprsStatus.status es un codigo numerico. Mapeamos los
    // valores conocidos y dejamos NULL para el resto (para no ensuciar).
    $gprsCode = $s['gprsStatus']['status'] ?? null;
    $estadoGprs = match ((int)$gprsCode) {
        1       => 'conectado',
        2       => 'desconectado',
        default => null,
    };

    // Estado LTE: derivado de radioAccessTech.tecLteEnabled (bool).
    $lteEnabled = $s['radioAccessTech']['tecLteEnabled'] ?? null;
    $estadoLte  = $lteEnabled === true  ? 'habilitado'
                : ($lteEnabled === false ? 'deshabilitado' : null);

    // Limite mensual de datos: viene en bytes; lo mostramos en MB para UI.
    $limBytes = (int)($s['consumptionMonthly']['data']['limit'] ?? 0);
    $limMB    = $limBytes > 0 ? (int) round($limBytes / 1024 / 1024) . ' MB' : null;

    return [
        ':nombre'       => $nombre,
        ':linea'        => $linea,
        ':icc'          => $icc    !== '' ? $icc    : null,
        ':estado'       => $estado !== '' ? $estado : null,
        ':estado_gprs'  => $estadoGprs,
        ':estado_lte'   => $estadoLte,
        ':limite_datos' => $limMB,
        ':imei'         => $imei   !== '' ? $imei   : null,
        ':msisdn'       => $msisdn !== '' ? $msisdn : null,
    ];
}
