<?php
// api/dolarhoy_realtime.php
// Cotizacion realtime del dolar OFICIAL scrapeada desde dolarhoy.com.
//   GET api/dolarhoy_realtime.php -> {ok:true, data:{compra, venta, fuente, fecha}}
// Cachea 60s en /tmp para no golpear a dolarhoy en cada page load.

require_once __DIR__ . '/db.php';

header('Content-Type: application/json; charset=utf-8');

const DH_RT_URL       = 'https://dolarhoy.com/i/cotizaciones/dolar-oficial';
const DH_RT_CACHE_TTL = 60;
const DH_RT_CACHE_KEY = 'dolarhoy_oficial_realtime';

try {
    $cache = leerCacheDolarhoy();
    if ($cache !== null) {
        jsonOk($cache + ['cache' => true]);
    }

    $ch = curl_init(DH_RT_URL);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 8);
    curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 4);
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
    curl_setopt($ch, CURLOPT_USERAGENT, 'Mozilla/5.0 (Databox Panel)');
    $html = curl_exec($ch);
    $err  = curl_error($ch);
    curl_close($ch);

    if ($html === false || $html === '') {
        jsonError('No se pudo obtener el HTML de dolarhoy.com' . ($err ? " ({$err})" : ''), 502);
    }

    $compra = extraerCotizacionDolarhoy($html, 'Compra');
    $venta  = extraerCotizacionDolarhoy($html, 'Venta');

    if ($compra === null && $venta === null) {
        jsonError('No se encontraron valores en la respuesta de dolarhoy.com', 502);
    }

    $payload = [
        'compra' => $compra,
        'venta'  => $venta,
        'fuente' => DH_RT_URL,
        'fecha'  => (new DateTime('now', new DateTimeZone('America/Argentina/Buenos_Aires')))
                    ->format('Y-m-d H:i:s'),
    ];
    escribirCacheDolarhoy($payload);
    jsonOk($payload + ['cache' => false]);
} catch (Throwable $e) {
    jsonError($e->getMessage(), 500);
}

function extraerCotizacionDolarhoy(string $html, string $etiqueta): ?float {
    $pattern = '/<p>\$?([\d.,]+)<span>' . preg_quote($etiqueta, '/') . '<\/span><\/p>/i';
    if (!preg_match($pattern, $html, $m)) return null;
    $raw = str_replace(['$', ' '], '', $m[1]);
    // Formato AR: miles con "." y decimales con ",". Sacamos los "." y cambiamos "," por ".".
    $raw = str_replace('.', '', $raw);
    $raw = str_replace(',', '.', $raw);
    return is_numeric($raw) ? (float)$raw : null;
}

function cacheFileDolarhoy(): string {
    return sys_get_temp_dir() . '/' . DH_RT_CACHE_KEY . '.json';
}

function leerCacheDolarhoy(): ?array {
    $f = cacheFileDolarhoy();
    if (!is_file($f)) return null;
    $mtime = @filemtime($f);
    if ($mtime === false || (time() - $mtime) > DH_RT_CACHE_TTL) return null;
    $raw = @file_get_contents($f);
    if ($raw === false || $raw === '') return null;
    $j = json_decode($raw, true);
    return is_array($j) ? $j : null;
}

function escribirCacheDolarhoy(array $data): void {
    @file_put_contents(cacheFileDolarhoy(), json_encode($data, JSON_UNESCAPED_UNICODE));
}
