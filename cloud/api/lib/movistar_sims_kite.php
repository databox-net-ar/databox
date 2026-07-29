<?php
/**
 * api/lib/movistar_sims_kite.php
 * Nucleo compartido entre el endpoint api/movistar_sims_sync.php y el job
 * cloud/jobs/movistar_sims_actualizar.php. Consulta el inventario de SIMs
 * Movistar en Kite Platform (mTLS) y hace UPSERT sobre la tabla
 * `movistar_sims` por ICCID.
 *
 * NO escribe en `sucesos`: cada caller decide como loguear el resultado
 * (el endpoint devuelve el resumen en JSON; el job lo escribe como suceso).
 */

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
    return kiteRequest($cfg, 'GET', $path, null);
}

/**
 * PUT mTLS contra Kite con body JSON. Usado por las operaciones de
 * cambio de estado (lifeCycleStatus, gprsStatus, etc).
 */
function kitePut(array $cfg, string $path, array $body): array {
    return kiteRequest($cfg, 'PUT', $path, $body);
}

/**
 * Core del cliente HTTP mTLS contra Kite. GET no lleva body; PUT/POST si.
 * Devuelve el JSON decodificado o tira excepcion con el codigo y snippet
 * del body de respuesta para que el caller pueda mostrar el error real.
 */
function kiteRequest(array $cfg, string $method, string $path, ?array $body): array {
    $url = "https://{$cfg['host']}:{$cfg['port']}{$path}";

    $headers = ['Accept: application/json'];
    $payload = null;
    if ($body !== null) {
        $payload   = json_encode($body, JSON_UNESCAPED_SLASHES);
        $headers[] = 'Content-Type: application/json';
    }

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_TIMEOUT        => 70,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2,
        CURLOPT_SSLCERT        => $cfg['cert'],
        CURLOPT_SSLCERTTYPE    => $cfg['tipo'],
        CURLOPT_HTTPHEADER     => $headers,
        CURLOPT_CUSTOMREQUEST  => $method,
    ]);
    if ($payload !== null) {
        curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);
    }
    // Password solo aplica al .pfx (P12). El .key PEM lo extrajimos con -nodes,
    // asi que no tiene passphrase.
    if ($cfg['tipo'] === 'P12') {
        curl_setopt($ch, CURLOPT_KEYPASSWD, $cfg['pass']);
    } elseif ($cfg['key'] !== '') {
        curl_setopt($ch, CURLOPT_SSLKEY,     $cfg['key']);
        curl_setopt($ch, CURLOPT_SSLKEYTYPE, 'PEM');
    }

    $respBody = curl_exec($ch);
    $errno    = curl_errno($ch);
    $err      = curl_error($ch);
    $code     = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);

    if ($errno !== 0) {
        throw new RuntimeException("Kite curl error: {$err} (errno {$errno}).");
    }
    if ($code < 200 || $code >= 300) {
        throw new RuntimeException("Kite HTTP {$code}: " . substr((string)$respBody, 0, 300));
    }
    // Kite a veces devuelve 204 (No Content) en operaciones de cambio de
    // estado — no tiene body pero tampoco es error.
    if ($respBody === '' || $respBody === false) return [];

    $decoded = json_decode((string)$respBody, true);
    if (!is_array($decoded)) {
        throw new RuntimeException('Respuesta de Kite no es JSON valido.');
    }
    return $decoded;
}

/**
 * Cambia el ciclo de vida de una SIM en Kite. `target` debe ser uno de los
 * valores enumerados por Kite: "ACTIVE", "DEACTIVATED", "INACTIVE_NEW",
 * "TEST", "ACTIVATION_PENDANT", "ACTIVATION_READY".
 *
 * Endpoint (Inventory API - modifySubscription, seccion 5.1.2 del manual):
 *   PUT /services/REST/GlobalM2M/Inventory/v13/r12/sim/icc:{ICC}
 *   Body: {"lifeCycleStatus": "ACTIVE"|"DEACTIVATED"|...}
 * Kite responde 204 No Content en exito.
 */
function kiteChangeLifeCycle(array $cfg, string $icc, string $target): array {
    $icc = trim($icc);
    if ($icc === '') throw new RuntimeException('ICC vacio');
    $path = "/services/REST/GlobalM2M/Inventory/v13/r12/sim/"
          . "icc:" . rawurlencode($icc);
    return kitePut($cfg, $path, ['lifeCycleStatus' => $target]);
}

/**
 * Recorre todo el inventario de Kite paginando de a `maxBatchSize` (max 1000).
 * Rate limit: getSubscriptions esta topeado en 4 TPS -> minimo 250ms entre
 * requests. Se hace un usleep entre paginas para no caer en el error POL 1005.
 *
 * Sync en una unica fase (bulk): getSubscriptions paginado trae todos los
 * campos "de peso" (nombre/customField1, MSISDN, IMEI, estado, GPRS, LTE,
 * consumo, limite).
 *
 * `ultimo_trafico` se DERIVA del delta de `consumo_datos` — la API v13/r12
 * de Kite (confirmado con la doc SC-API3-CU) NO expone la fecha del ultimo
 * trafico de datos: el campo `lastTrafficDate` aparece solo en el changelog
 * de v5.0.0 pero no en la respuesta actual. Estrategia:
 *   - SIM existente: si el `consumo_datos` que trae Kite difiere del que
 *     tiene la BD -> hubo actividad entre esta corrida y la anterior ->
 *     `ultimo_trafico = NOW()`.
 *   - SIM existente sin cambio: se preserva el valor previo.
 *   - SIM nueva (INSERT): `ultimo_trafico = NULL` (no hay base para comparar);
 *     se completa en la primer sync posterior en que se detecte un delta.
 *
 * Caveat: el reset mensual del contador (por ej. "500 MB" -> "0 MB") tambien
 * cuenta como delta y marca `ultimo_trafico`. Es un falso positivo mensual,
 * preferible a perder los positivos verdaderos.
 */
function kiteSyncSims(array $cfg, PDO $pdo, ?callable $emit = null): array {
    @set_time_limit(0);

    // $emit(string $tipo, string $mensaje, array $extra = []) — opcional.
    // Tipos: 'info' (fases), 'ok' (progreso positivo), 'warn', 'error'.
    // Si no se pasa, la funcion es no-op y no cambia nada del comportamiento.
    $log = $emit ?? static function (): void {};

    $batch    = 1000;
    $delayUs  = 260_000; // 260ms > 250ms (4 TPS), con margen entre paginas

    $fetched         = 0;
    $insertados      = 0;
    $actualizados    = 0;
    $con_trafico     = 0;   // SIMs cuyo consumo cambio -> ultimo_trafico marcado
    $paginas         = 0;

    // Referencia para detectar SIMs "desaparecidas" del origen (ver bloque
    // post-paginado). Se captura ANTES del primer UPSERT porque el UPSERT
    // pisa `actualizado = NOW()`; toda fila cuya `actualizado` quede menor
    // (o NULL) al final es una que Kite no devolvio en esta corrida.
    $syncStartedAt = date('Y-m-d H:i:s');

    // Traemos consumo previo Y timestamp previo. El timestamp se preserva
    // cuando no hay delta (para no perder la ultima fecha buena).
    $lookup = $pdo->prepare("SELECT consumo_datos, ultimo_trafico FROM movistar_sims WHERE icc = :icc");
    // `nombre` NO se toca: es editable en el ABM y el sync no debe pisarlo.
    // El customField1 de Kite va a `alias` (columna dedicada).
    // `ultimo_trafico` se pasa como parametro calculado por el caller (NOW si
    // hubo delta, valor previo si no).
    $upsert = $pdo->prepare("
        INSERT INTO movistar_sims
            (alias, linea, icc, estado, estado_gprs, estado_lte, limite_datos, consumo_datos, imei, msisdn, actualizado, ultimo_trafico)
        VALUES
            (:alias, :linea, :icc, :estado, :estado_gprs, :estado_lte, :limite_datos, :consumo_datos, :imei, :msisdn, NOW(), :ultimo_trafico)
        ON DUPLICATE KEY UPDATE
            alias          = VALUES(alias),
            linea          = VALUES(linea),
            estado         = VALUES(estado),
            estado_gprs    = VALUES(estado_gprs),
            estado_lte     = VALUES(estado_lte),
            limite_datos   = VALUES(limite_datos),
            consumo_datos  = VALUES(consumo_datos),
            imei           = VALUES(imei),
            msisdn         = VALUES(msisdn),
            actualizado    = VALUES(actualizado),
            ultimo_trafico = VALUES(ultimo_trafico)
    ");

    // -----------------------------------------------------------------------
    // Bulk paginado
    // -----------------------------------------------------------------------
    $log('info', 'Descargando inventario de Kite (paginado, 1000 SIMs/pagina)...');
    for ($startIndex = 0; ; $startIndex += $batch) {
        $path = "/services/REST/GlobalM2M/Inventory/v13/r12/sim"
              . "?maxBatchSize={$batch}&startIndex={$startIndex}";

        if ($paginas > 0) usleep($delayUs); // no dormir antes de la primer request

        try {
            $resp = kiteGet($cfg, $path);
        } catch (Throwable $e) {
            $log('error', "Kite fallo al traer pagina " . ($paginas + 1) . ": " . $e->getMessage());
            throw $e;
        }
        $paginas++;
        $sims = $resp['subscriptionData'] ?? [];
        if (empty($sims)) break;

        $pagIns = 0; $pagAct = 0; $pagSkip = 0; $pagTraf = 0;
        foreach ($sims as $s) {
            $p = mapKiteSim($s);
            if ($p[':icc'] === null) { $pagSkip++; continue; } // sin ICC no se puede upsertar

            // Derivar ultimo_trafico por delta de consumo.
            $lookup->execute([':icc' => $p[':icc']]);
            $prev = $lookup->fetch(PDO::FETCH_ASSOC);
            $existente = $prev !== false;
            if ($existente) {
                $prevConsumo = (string) ($prev['consumo_datos'] ?? '');
                $nuevoConsumo = (string) ($p[':consumo_datos'] ?? '');
                if ($nuevoConsumo !== $prevConsumo) {
                    $p[':ultimo_trafico'] = date('Y-m-d H:i:s'); // hubo actividad
                    $con_trafico++; $pagTraf++;
                } else {
                    $p[':ultimo_trafico'] = $prev['ultimo_trafico']; // preservar
                }
            } else {
                $p[':ultimo_trafico'] = null; // nueva: sin base para comparar
            }

            $upsert->execute($p);
            if ($existente) { $actualizados++; $pagAct++; } else { $insertados++; $pagIns++; }
            $fetched++;
        }

        $msg = "Pagina {$paginas}: {$pagIns} nuevas, {$pagAct} actualizadas, {$pagTraf} con trafico nuevo"
             . ($pagSkip > 0 ? ", {$pagSkip} sin ICC (omitidas)" : '');
        $log($pagSkip > 0 ? 'warn' : 'ok', $msg);

        // Si Kite devolvio menos que el batch, era la ultima pagina.
        if (count($sims) < $batch) break;
    }
    $log('info', "Listo: {$fetched} SIMs upsertadas ({$insertados} nuevas, {$actualizados} actualizadas, {$con_trafico} con trafico nuevo) en {$paginas} paginas.");

    // -----------------------------------------------------------------------
    // Deteccion de SIMs "desaparecidas": las que estaban en la BD pero Kite
    // no las devolvio en ninguna pagina de esta corrida. Como el UPSERT pisa
    // `actualizado = NOW()`, todo lo que quede con `actualizado < syncStartedAt`
    // (o NULL) es una SIM huerfana. Se marca `estado = 'AUSENTE'` (nunca se
    // elimina la fila) para preservar el historial local y las ediciones
    // manuales del ABM (nombre, tags, en_uso, etc.). Idempotente: si en la
    // proxima corrida sigue faltando, la WHERE la filtra por estado y no
    // aparece de nuevo en el listado de "nuevos ausentes".
    //
    // Este bloque solo corre si el bucle termino sin excepcion — si Kite
    // fallo a mitad de paginacion, throw en el catch de arriba interrumpe
    // antes de llegar aca y no se marca nada como falso positivo.
    $ausentes = [];
    if ($fetched > 0) {
        $sel = $pdo->prepare("
            SELECT icc, linea, estado, actualizado
              FROM movistar_sims
             WHERE (actualizado IS NULL OR actualizado < :ini)
               AND (estado IS NULL OR estado <> 'AUSENTE')
        ");
        $sel->execute([':ini' => $syncStartedAt]);
        $ausentes = $sel->fetchAll(PDO::FETCH_ASSOC) ?: [];

        if (!empty($ausentes)) {
            $upd = $pdo->prepare("
                UPDATE movistar_sims
                   SET estado = 'AUSENTE'
                 WHERE (actualizado IS NULL OR actualizado < :ini)
                   AND (estado IS NULL OR estado <> 'AUSENTE')
            ");
            $upd->execute([':ini' => $syncStartedAt]);
            $log('warn', count($ausentes) . " SIMs no aparecieron en el origen y se marcaron como AUSENTE (no se eliminaron).");
        }
    }

    return [
        'fetched'         => $fetched,
        'insertados'      => $insertados,
        'actualizados'    => $actualizados,
        'con_trafico'     => $con_trafico,
        'paginas'         => $paginas,
        'ausentes_nuevos' => count($ausentes),
        'ausentes_iccs'   => array_map(static fn($r) => (string)$r['icc'], $ausentes),
        'ultima_sync'     => date('Y-m-d H:i:s'),
    ];
}

/**
 * Traduce una entrada de subscriptionData (Kite) a los :params del UPSERT.
 * Decisiones de mapping documentadas en el CLAUDE.md del modulo o en el commit.
 */
function mapKiteSim(array $s): array {
    $icc    = trim((string)($s['icc']    ?? ''));
    $msisdn = trim((string)($s['msisdn'] ?? ''));
    // Kite expone dos campos distintos: `imei` es el IMEI real del dispositivo
    // que actualmente esta conectado a la SIM, `imeiLock` es una restriccion
    // opcional (IMEI al que la SIM esta lockeada) que suele venir vacia.
    // Nosotros persistimos el primero.
    $imei   = trim((string)($s['imei'] ?? ''));
    $estado = trim((string)($s['lifeCycleStatus'] ?? ''));

    // alias: customField1 de Kite (editable en el portal). Antes se mapeaba
    // a `nombre`, pero eso borraba las ediciones manuales del ABM en cada
    // sync. `nombre` ahora queda como columna editable del ABM y este valor
    // vive en su columna dedicada.
    $customField1 = trim((string)($s['customField1'] ?? ''));
    $alias = $customField1 !== '' ? mb_substr($customField1, 0, 100) : null;

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

    // Consumo mensual de datos: Kite lo expone en `consumptionMonthly.data` pero
    // el nombre del campo varia segun la version del endpoint. Probamos los
    // nombres conocidos en orden y tomamos el primero numerico. Puede ser 0 al
    // principio del ciclo — lo persistimos igual como "0 MB".
    $dataBlock = $s['consumptionMonthly']['data'] ?? [];
    $usedRaw   = null;
    foreach (['consumption', 'used', 'consumed', 'value', 'count'] as $k) {
        if (isset($dataBlock[$k]) && is_numeric($dataBlock[$k])) {
            $usedRaw = $dataBlock[$k];
            break;
        }
    }
    $usedMB = $usedRaw !== null ? (int) round(((int)$usedRaw) / 1024 / 1024) . ' MB' : null;

    return [
        ':alias'          => $alias,
        ':linea'          => $linea,
        ':icc'            => $icc    !== '' ? $icc    : null,
        ':estado'         => $estado !== '' ? $estado : null,
        ':estado_gprs'    => $estadoGprs,
        ':estado_lte'     => $estadoLte,
        ':limite_datos'   => $limMB,
        ':consumo_datos'  => $usedMB,
        ':imei'           => $imei   !== '' ? $imei   : null,
        ':msisdn'         => $msisdn !== '' ? $msisdn : null,
        // :ultimo_trafico lo calcula el caller (kiteSyncSims) por delta de consumo.
    ];
}
