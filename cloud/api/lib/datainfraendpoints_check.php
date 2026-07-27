<?php
// api/lib/datainfraendpoints_check.php
// Logica del health-check de un endpoint HTTP/HTTPS de la tabla
// `datainfra_endpoints`. La usa el endpoint HTTP on-demand
// `api/datainfraendpoints_check.php` (streaming desde el UI) y tambien el
// job cron `jobs/datainfra_endpoints_check.php` (batch periodico).
//
// Reglas de estado (mismo criterio en on-demand y en cron):
//   * cURL timeout (CURLE_OPERATION_TIMEDOUT)             -> `timeout`
//   * cURL con error de red / DNS / SSL / connect refused -> `error`
//   * HTTP code recibido != `codigo_esperado`             -> `error`
//   * `patron_respuesta` no vacio y no aparece en el body -> `error`
//   * Todo bien                                           -> `ok`
//
// Al terminar, siempre:
//   * UPDATE `datainfra_endpoints` -> ultimo_check / ultimo_estado /
//     ultimo_codigo / ultimo_tiempo_ms / ultimo_error.
//   * INSERT `datainfra_endpoints_ejecuciones` con la corrida.

if (!function_exists('diepChequearEndpoint')) {

/**
 * Corre el health-check de un endpoint y guarda el resultado.
 *
 * @param PDO      $pdo
 * @param int      $id   Id de la fila en `datainfra_endpoints`.
 * @param callable $log  Callable que recibe cada linea de log (string).
 *                       Pasar `fn($m) => null` para silenciar.
 * @return array {
 *     ok:            bool,      True si estado === 'ok'.
 *     estado:        string,    'ok' | 'error' | 'timeout'.
 *     codigo:        int|null,  Codigo HTTP recibido (null si no hubo respuesta).
 *     tiempo_ms:     int,       Tiempo total de la request en ms.
 *     error:         string|null, Descripcion del fallo si estado != ok.
 *     ejecucion_id:  int,       Id de la fila insertada en _ejecuciones.
 * }
 */
function diepChequearEndpoint(PDO $pdo, int $id, callable $log): array {
    $stmt = $pdo->prepare(
        'SELECT id, nombre, url, metodo, headers, body,
                codigo_esperado, patron_respuesta, timeout_seg, activo
           FROM datainfra_endpoints
          WHERE id = ?
          LIMIT 1'
    );
    $stmt->execute([$id]);
    $ep = $stmt->fetch();
    if (!$ep) {
        $log("No existe el endpoint #{$id}.");
        return [
            'ok'           => false,
            'estado'       => 'error',
            'codigo'       => null,
            'tiempo_ms'    => 0,
            'error'        => 'endpoint no encontrado',
            'ejecucion_id' => 0,
        ];
    }

    $nombre          = (string)$ep['nombre'];
    $url             = trim((string)$ep['url']);
    $metodo          = strtoupper(trim((string)($ep['metodo'] ?? 'GET')));
    $headersJson     = (string)($ep['headers']       ?? '');
    $bodyRaw         = (string)($ep['body']          ?? '');
    $codigoEsperado  = (int)($ep['codigo_esperado']  ?? 200);
    $patron          = (string)($ep['patron_respuesta'] ?? '');
    $timeoutSeg      = max(1, (int)($ep['timeout_seg'] ?? 15));

    $log("Endpoint #{$id} \"{$nombre}\"");
    $log("  {$metodo} {$url}  (timeout {$timeoutSeg}s, espera HTTP {$codigoEsperado})");

    if ($url === '') {
        return diepPersistirResultado($pdo, $id, 'error', null, 0, 'URL vacia');
    }

    // Parsear headers (JSON opcional) a formato cURL "Key: Value".
    $curlHeaders = [];
    if ($headersJson !== '') {
        $parsed = json_decode($headersJson, true);
        if (!is_array($parsed)) {
            return diepPersistirResultado($pdo, $id, 'error', null, 0,
                'Headers no son JSON valido');
        }
        foreach ($parsed as $k => $v) {
            $curlHeaders[] = trim((string)$k) . ': ' . (string)$v;
        }
        if ($curlHeaders) $log('  Headers: ' . count($curlHeaders) . ' entradas');
    }

    // Armar la request. Seguimos redirects (hasta 5, como un browser)
    // porque muchisimos servers hacen 301/302 a la URL canonica (ej.
    // /path -> /path/) y el legacy `hipervisorservicios` tambien los
    // seguia. Si el operador quiere validar la URL exacta sin seguir el
    // redirect, seteando `codigo_esperado = 301` (o 302) en el endpoint
    // el check pasa a considerar OK cuando el server responde con esa
    // redireccion inicial.
    $ch = curl_init($url);
    $opts = [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HEADER         => false,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_MAXREDIRS      => 5,
        CURLOPT_TIMEOUT        => $timeoutSeg,
        CURLOPT_CONNECTTIMEOUT => min($timeoutSeg, 10),
        CURLOPT_USERAGENT      => 'Databox-HealthCheck/1.0',
    ];
    if ($curlHeaders) $opts[CURLOPT_HTTPHEADER] = $curlHeaders;

    switch ($metodo) {
        case 'GET':
            // default
            break;
        case 'HEAD':
            $opts[CURLOPT_NOBODY]        = true;
            $opts[CURLOPT_CUSTOMREQUEST] = 'HEAD';
            break;
        case 'POST':
            $opts[CURLOPT_POST]       = true;
            if ($bodyRaw !== '') $opts[CURLOPT_POSTFIELDS] = $bodyRaw;
            break;
        default:
            $opts[CURLOPT_CUSTOMREQUEST] = $metodo;
            if ($bodyRaw !== '') $opts[CURLOPT_POSTFIELDS] = $bodyRaw;
            break;
    }
    curl_setopt_array($ch, $opts);

    $t0        = microtime(true);
    $body      = curl_exec($ch);
    $tiempoMs  = (int)round((microtime(true) - $t0) * 1000);
    $errno     = curl_errno($ch);
    $errStr    = curl_error($ch);
    $codigo    = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $urlFinal  = (string)curl_getinfo($ch, CURLINFO_EFFECTIVE_URL);
    $redirects = (int)curl_getinfo($ch, CURLINFO_REDIRECT_COUNT);
    // En PHP 8+ el handle de cURL es un objeto y se cierra solo al salir
    // de scope; `curl_close()` esta deprecado y se omite.

    if ($redirects > 0 && $urlFinal !== '' && $urlFinal !== $url) {
        $log("  Redirect: {$redirects} salto/s -> {$urlFinal}");
    }
    $log("  Respuesta: HTTP {$codigo}  ({$tiempoMs} ms)");

    // Determinar estado.
    if ($errno === CURLE_OPERATION_TIMEDOUT || $errno === 28) {
        return diepPersistirResultado($pdo, $id, 'timeout', $codigo ?: null, $tiempoMs,
            "timeout ({$timeoutSeg}s): {$errStr}");
    }
    if ($errno !== 0 || $body === false) {
        return diepPersistirResultado($pdo, $id, 'error', $codigo ?: null, $tiempoMs,
            "cURL error {$errno}: {$errStr}");
    }
    if ($codigo !== $codigoEsperado) {
        return diepPersistirResultado($pdo, $id, 'error', $codigo, $tiempoMs,
            "HTTP {$codigo} != esperado {$codigoEsperado}");
    }
    if ($patron !== '' && !str_contains((string)$body, $patron)) {
        $log('  Patron esperado NO aparece en la respuesta.');
        return diepPersistirResultado($pdo, $id, 'error', $codigo, $tiempoMs,
            "patron \"{$patron}\" no encontrado en la respuesta");
    }

    if ($patron !== '') $log('  Patron esperado encontrado en la respuesta.');
    $log('  OK');
    return diepPersistirResultado($pdo, $id, 'ok', $codigo, $tiempoMs, null);
}

/**
 * Guarda el snapshot en `datainfra_endpoints` + inserta la fila del historial
 * en `datainfra_endpoints_ejecuciones`. Devuelve el resumen JSON-serializable
 * que consume el UI (via streaming) o el job (via return).
 */
function diepPersistirResultado(PDO $pdo, int $id, string $estado, ?int $codigo,
                                int $tiempoMs, ?string $error): array {
    // Truncar el error a 500 chars (limite de la columna en ambas tablas).
    $errorGuardar = $error !== null ? mb_substr($error, 0, 500) : null;

    $ahora = date('Y-m-d H:i:s');
    $fin   = date('Y-m-d H:i:s', time());
    // `inicio` calculado desde el timepo transcurrido para que el par
    // (inicio, fin) refleje la ventana real de la request.
    $inicio = date('Y-m-d H:i:s', (int)(time() - ceil($tiempoMs / 1000)));

    $upd = $pdo->prepare(
        'UPDATE datainfra_endpoints
            SET ultimo_check     = :chk,
                ultimo_estado    = :estado,
                ultimo_codigo    = :codigo,
                ultimo_tiempo_ms = :tms,
                ultimo_error     = :err
          WHERE id = :id'
    );
    $upd->execute([
        ':chk'    => $ahora,
        ':estado' => $estado,
        ':codigo' => $codigo,
        ':tms'    => $tiempoMs,
        ':err'    => $errorGuardar,
        ':id'     => $id,
    ]);

    $ins = $pdo->prepare(
        'INSERT INTO datainfra_endpoints_ejecuciones
            (endpoint_id, inicio, fin, estado, codigo, tiempo_ms, error)
         VALUES
            (:eid, :inicio, :fin, :estado, :codigo, :tms, :err)'
    );
    $ins->execute([
        ':eid'    => $id,
        ':inicio' => $inicio,
        ':fin'    => $fin,
        ':estado' => $estado,
        ':codigo' => $codigo,
        ':tms'    => $tiempoMs,
        ':err'    => $errorGuardar,
    ]);
    $ejecucionId = (int)$pdo->lastInsertId();

    return [
        'ok'           => $estado === 'ok',
        'estado'       => $estado,
        'codigo'       => $codigo,
        'tiempo_ms'    => $tiempoMs,
        'error'        => $errorGuardar,
        'ejecucion_id' => $ejecucionId,
    ];
}

} // if (!function_exists('diepChequearEndpoint'))
