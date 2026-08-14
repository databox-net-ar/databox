<?php
// api/dolarhoy_realtime.php
// Cotizacion realtime del dolar OFICIAL scrapeada desde dolarhoy.com.
//   GET api/dolarhoy_realtime.php -> {ok:true, data:{compra, venta, fuente, fecha}}
// Cachea 60s en /tmp para no golpear a dolarhoy en cada page load.
//
// Solo lectura: NO escribe en `dolarhoy_cotizaciones`. Quien graba la fila del
// dia es el job cloud/jobs/dolarhoy_cotizacion_actualizar.php (L-V 07:00).
// El scraping en si vive en api/lib/dolarhoy_cotizacion.php, compartido con
// ese job.

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/lib/auth_check.php';
require_once __DIR__ . '/lib/dolarhoy_cotizacion.php';

header('Content-Type: application/json; charset=utf-8');

const DH_RT_CACHE_TTL = 60;
const DH_RT_CACHE_KEY = 'dolarhoy_oficial_realtime';

try {
    requirePermission('plataformas.dolarhoy.cotizaciones.consultar');
    $cache = leerCacheDolarhoy();
    if ($cache !== null) {
        jsonOk($cache + ['cache' => true]);
    }

    try {
        $payload = dhScrapearOficial();
    } catch (RuntimeException $e) {
        jsonError($e->getMessage(), 502);
    }

    escribirCacheDolarhoy($payload);
    jsonOk($payload + ['cache' => false]);
} catch (Throwable $e) {
    jsonError($e->getMessage(), 500);
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
