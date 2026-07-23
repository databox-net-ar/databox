<?php
/**
 * cloud/jobs/_curl.php
 * Job "sistema" que hace una llamada GET a una URL. Sirve como target para
 * las tareas del Programador cuyo `tipo` es 'url' (ver `tareas.tipo`).
 *
 * El scheduler lo invoca con dos variables de entorno:
 *   TAREA_URL      URL a la que pegarle (obligatoria).
 *   TAREA_TIMEOUT  Timeout en segundos para la request HTTP (opcional, cap
 *                  interno = timeout_seg - 5 para dejar margen al bootstrap).
 *
 * Reutiliza `_bootstrap.php` para el cierre de fila, timeout via SIGTERM,
 * shutdown handler y logs. El stdout se redirige al `.log` de la ejecucion
 * desde el scheduler, asi que basta con echo/print para dejar rastro.
 *
 * Exit codes:
 *   0  HTTP 2xx o 3xx.
 *   1  HTTP 4xx o 5xx.
 *   2  Error de red / DNS / timeout de la libreria curl / config invalida.
 */

require_once __DIR__ . '/_bootstrap.php';

$url = trim((string) getenv('TAREA_URL'));
if ($url === '') {
    marcarEjecucionError(new Exception('_curl: falta TAREA_URL en el env'));
    exit(2);
}

$timeoutHttp = (int) getenv('TAREA_TIMEOUT');
if ($timeoutHttp <= 0) $timeoutHttp = 30;

anotarLog("GET {$url}");
anotarLog("timeout HTTP: {$timeoutHttp}s");

$ch = curl_init();
curl_setopt_array($ch, [
    CURLOPT_URL            => $url,
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_FOLLOWLOCATION => true,
    CURLOPT_MAXREDIRS      => 5,
    CURLOPT_TIMEOUT        => $timeoutHttp,
    CURLOPT_CONNECTTIMEOUT => min(10, $timeoutHttp),
    CURLOPT_HEADER         => true,
    CURLOPT_USERAGENT      => 'databox-cloud/tareas',
]);

$resp    = curl_exec($ch);
$errno   = curl_errno($ch);
$errstr  = curl_error($ch);
$status  = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
$hdrSize = (int) curl_getinfo($ch, CURLINFO_HEADER_SIZE);
$elapsed = (float) curl_getinfo($ch, CURLINFO_TOTAL_TIME);
$effUrl  = (string) curl_getinfo($ch, CURLINFO_EFFECTIVE_URL);
curl_close($ch);

if ($errno) {
    anotarLog("ERROR curl ({$errno}): {$errstr}");
    marcarEjecucionError(new Exception("curl error {$errno}: {$errstr}"));
    exit(2);
}

$headers  = substr((string) $resp, 0, $hdrSize);
$body     = substr((string) $resp, $hdrSize);
$bodySize = strlen($body);

anotarLog(sprintf('HTTP %d — %d bytes — %.3fs', $status, $bodySize, $elapsed));
if ($effUrl !== '' && $effUrl !== $url) {
    anotarLog("efectiva: {$effUrl}");
}
anotarLog('--- headers ---');
foreach (explode("\n", trim($headers)) as $h) {
    $h = rtrim($h);
    if ($h !== '') anotarLog($h);
}
if ($bodySize > 0) {
    anotarLog('--- body (primeros 4KB) ---');
    echo substr($body, 0, 4096) . PHP_EOL;
    if ($bodySize > 4096) {
        anotarLog('... (' . ($bodySize - 4096) . ' bytes mas, truncado)');
    }
}

if ($status >= 200 && $status < 400) {
    marcarEjecucionOk("HTTP {$status} ({$bodySize} bytes)");
    exit(0);
}
marcarEjecucionError(new Exception("HTTP {$status}"));
exit(1);
