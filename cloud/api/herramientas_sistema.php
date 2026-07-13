<?php
/**
 * API cloud — Herramientas: Sistema (pestaña "General").
 *
 * Devuelve el snapshot general del panel: version corriendo (lo mismo que
 * `version.txt` en el filesystem), entorno (APP_ENV), version de PHP y
 * version del server de BD (MySQL / MariaDB).
 *
 * La info de zona horaria vive en `herramientas_zona_horaria.php`. Ambos
 * endpoints comparten el permiso `administracion.herramientas.sistema.consultar`
 * porque forman parte del mismo modal "Sistema" en la UI.
 *
 *   GET api/herramientas_sistema.php
 *     -> {ok:true, data:{version, env, php:{version, sapi, os}, mysql:{version}}}
 */
ini_set('display_errors', 0);
error_reporting(E_ALL);
header('Content-Type: application/json; charset=utf-8');

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'OPTIONS') { exit; }

require_once __DIR__ . '/lib/auth_check.php';
requireAuth();
require_once __DIR__ . '/db.php';

try {
    requirePermission('administracion.herramientas.sistema.consultar');

    // Version del panel (misma fuente que api/version.php).
    $versionPath = __DIR__ . '/../version.txt';
    $version     = is_readable($versionPath) ? trim((string)@file_get_contents($versionPath)) : '';
    if ($version === '') $version = '0.0.0';

    // Version del server de BD (por ejemplo `8.0.46` o `10.11.6-MariaDB`).
    $mysqlVersion = (string)db()->query('SELECT VERSION()')->fetchColumn();

    jsonOk([
        'version' => $version,
        'env'     => getenv('APP_ENV') ?: 'unknown',
        'php'     => [
            'version' => PHP_VERSION,
            'sapi'    => PHP_SAPI,
            'os'      => PHP_OS,
        ],
        'mysql'   => [
            'version' => $mysqlVersion,
        ],
    ]);
} catch (Throwable $e) {
    jsonError($e->getMessage(), 500);
}
