<?php
/**
 * cloud/jobs/evolutioncanales_actualizar_estados.php
 * Recorre todos los canales de la tabla `evolutioncanales` y refresca su
 * estado consultando Evolution API (endpoint /instance/fetchInstances con
 * la apikey del canal). Para cada canal actualiza:
 *   - online       : '1' si connectionStatus == 'open', '0' en otro caso
 *   - celular      : ownerJid sin el sufijo @s.whatsapp.net
 *   - numero       : ultimos 10 digitos del celular
 *   - canalEstado  : JSON crudo devuelto por Evolution (util para debug
 *                    desde el modal "Consultar" del ABM de canales)
 *
 * Deja un suceso por canal en la tabla `sucesos`:
 *   - tipo=info   : Evolution respondio OK, cache actualizado.
 *   - tipo=alerta : falta configuracion (uuid/token) o Evolution devolvio
 *                   error / respuesta invalida.
 *
 * Los errores por canal NO frenan el job: sigue con el proximo.
 *
 * Se registra desde el Programador de tareas (tabla `tareas`) apuntando
 * `script` = "evolutioncanales_actualizar_estados".
 *
 * Referencia: databox_legacy/databox-api/robot/evolutionCanales.php
 */

require_once __DIR__ . '/_bootstrap.php';

const EVOLUTION_ENDPOINT = 'https://evolution.york.databox.net.ar';
const EVOLUTION_TIMEOUT_SEG = 30;

$ORIGEN_SUCESO = 'cron/evolutioncanales_estados';

try {
    $pdo = db();

    // Solo se verifican los canales habilitados ('1'). Los deshabilitados
    // ('0' o NULL) se ignoran silenciosamente: no tiene sentido consultar
    // Evolution para un canal que el operador desactivo a proposito.
    $stmt = $pdo->query("
        SELECT id, nombre, uuid, token
          FROM evolutioncanales
         WHERE habilitado = '1'
         ORDER BY id
    ");
    $canales = $stmt->fetchAll();
    $total   = count($canales);

    anotarLog("Canales Evolution habilitados a verificar: {$total}");
    if ($total === 0) {
        marcarEjecucionOk('Sin canales Evolution habilitados.');
        exit(0);
    }

    $okCount   = 0;
    $errCount  = 0;
    $skipCount = 0;

    foreach ($canales as $i => $c) {
        $prefix = sprintf('[%d/%d] canal #%d (%s)',
            $i + 1, $total, (int)$c['id'], $c['nombre'] ?? '');

        // Canales sin uuid/token no se pueden consultar: alerta + siguiente.
        $faltantes = [];
        if (empty($c['uuid']))  $faltantes[] = 'uuid';
        if (empty($c['token'])) $faltantes[] = 'token';
        if ($faltantes) {
            $msg = 'Sin configuracion completa (falta: ' . implode(', ', $faltantes) . ')';
            anotarLog("{$prefix} - salteado: {$msg}");
            registrarSuceso(
                $pdo, $ORIGEN_SUCESO, 'alerta',
                "Evolution canal #{$c['id']} ({$c['nombre']}) - {$msg}"
            );
            $skipCount++;
            continue;
        }

        anotarLog("{$prefix} - consultando Evolution...");
        try {
            $r = verificarCanalEvolution($pdo, $c);
            if ($r['ok']) {
                anotarLog("{$prefix} - OK ({$r['summary']})");
                registrarSuceso(
                    $pdo, $ORIGEN_SUCESO, 'info',
                    "Evolution canal #{$c['id']} ({$c['nombre']}) - {$r['summary']}"
                );
                $okCount++;
            } else {
                anotarLog("{$prefix} - fallo: {$r['error']}");
                registrarSuceso(
                    $pdo, $ORIGEN_SUCESO, 'alerta',
                    "Evolution canal #{$c['id']} ({$c['nombre']}) - {$r['error']}"
                );
                $errCount++;
            }
        } catch (Throwable $e) {
            $msg = $e->getMessage();
            anotarLog("{$prefix} - excepcion: {$msg}");
            registrarSuceso(
                $pdo, $ORIGEN_SUCESO, 'alerta',
                "Evolution canal #{$c['id']} ({$c['nombre']}) - excepcion: {$msg}"
            );
            $errCount++;
        }
    }

    $resumen = "{$okCount} OK | {$errCount} con error | {$skipCount} salteados | {$total} total";
    anotarLog("Finalizado: {$resumen}");
    marcarEjecucionOk($resumen);

} catch (Throwable $e) {
    anotarLog('ERROR fatal: ' . $e->getMessage());
    marcarEjecucionError($e);
    throw $e;
}

/**
 * Consulta /instance/fetchInstances para el canal dado y actualiza la fila
 * en la BD. Devuelve ['ok' => bool, 'summary' => string, 'error' => string].
 * No lanza: encapsula fallos de red / JSON invalido en el flag ok=false.
 */
function verificarCanalEvolution(PDO $pdo, array $c): array {
    $curl = curl_init();
    curl_setopt_array($curl, [
        CURLOPT_URL            => EVOLUTION_ENDPOINT . '/instance/fetchInstances',
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_ENCODING       => '',
        CURLOPT_MAXREDIRS      => 10,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_TIMEOUT        => EVOLUTION_TIMEOUT_SEG,
        CURLOPT_HTTP_VERSION   => CURL_HTTP_VERSION_1_1,
        CURLOPT_CUSTOMREQUEST  => 'GET',
        CURLOPT_HTTPHEADER     => [
            'accept: application/json',
            'apikey: ' . $c['token'],
        ],
    ]);
    $body    = curl_exec($curl);
    $err     = curl_error($curl);
    $status  = (int) curl_getinfo($curl, CURLINFO_HTTP_CODE);
    curl_close($curl);

    if ($err !== '') {
        return ['ok' => false, 'summary' => '', 'error' => "cURL: {$err}"];
    }
    if ($status < 200 || $status >= 300) {
        $preview = substr((string) $body, 0, 200);
        return ['ok' => false, 'summary' => '',
                'error' => "HTTP {$status}: {$preview}"];
    }

    $data = json_decode((string) $body, true);
    if (!is_array($data)) {
        $preview = substr((string) $body, 0, 200);
        return ['ok' => false, 'summary' => '',
                'error' => "Respuesta no es JSON: {$preview}"];
    }

    // La respuesta es un array de instancias. Cuando el apikey es del
    // canal (no global), Evolution filtra a esa unica instancia.
    // Preferimos matchear por uuid/token; si no matchea, tomamos la
    // primera (comportamiento del robot legacy).
    $instancia = elegirInstanciaEvolution($data, $c);
    if ($instancia === null) {
        return ['ok' => false, 'summary' => '',
                'error' => 'Evolution devolvio 0 instancias para este canal'];
    }

    $connStatus = (string) ($instancia['connectionStatus'] ?? '');
    $ownerJid   = (string) ($instancia['ownerJid']         ?? '');
    $online     = $connStatus === 'open' ? '1' : '0';
    $celular    = $ownerJid !== ''
                    ? str_replace('@s.whatsapp.net', '', $ownerJid)
                    : null;
    $numero     = $celular !== null ? substr($celular, -10) : null;
    // Guardamos el JSON completo (crudo) para inspeccion desde el modal
    // Consultar. Truncamos a 60k por prudencia (mediumtext admite ~16MB
    // pero no queremos payloads gigantes en logs / respuestas del ABM).
    $canalEstado = substr(json_encode($instancia, JSON_UNESCAPED_UNICODE), 0, 60000);

    $upd = $pdo->prepare('
        UPDATE evolutioncanales
           SET online      = :online,
               celular     = COALESCE(:celular, celular),
               numero      = COALESCE(:numero,  numero),
               canalEstado = :canalEstado,
               actualizado = NOW()
         WHERE id = :id
    ');
    $upd->execute([
        ':online'      => $online,
        ':celular'     => $celular,
        ':numero'      => $numero,
        ':canalEstado' => $canalEstado,
        ':id'          => (int) $c['id'],
    ]);

    $summary = 'online=' . ($online === '1' ? 'si' : 'no')
             . ($celular !== null ? ", celular={$celular}" : '')
             . ", connectionStatus={$connStatus}";
    return ['ok' => true, 'summary' => $summary, 'error' => ''];
}

/**
 * Elige la instancia correcta dentro de la respuesta de fetchInstances.
 * Preferencia: match por uuid (instanceName) o por token. Fallback: [0].
 * Devuelve null si el array esta vacio.
 */
function elegirInstanciaEvolution(array $data, array $c): ?array {
    if (!$data) return null;
    $uuid  = (string) ($c['uuid']  ?? '');
    $token = (string) ($c['token'] ?? '');
    foreach ($data as $inst) {
        if (!is_array($inst)) continue;
        $instName  = (string) ($inst['name'] ?? $inst['instanceName'] ?? '');
        $instToken = (string) ($inst['token'] ?? '');
        if ($uuid  !== '' && $instName  === $uuid)  return $inst;
        if ($token !== '' && $instToken === $token) return $inst;
    }
    $first = $data[0] ?? null;
    return is_array($first) ? $first : null;
}
