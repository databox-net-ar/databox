<?php
/**
 * API cloud — Herramientas: Migrador DB (listado).
 *
 * Lee los archivos *.sql de cloud/sql/migrations/ (alfabetico) y los cruza
 * contra la tabla `migraciones` de la BD del entorno actual para indicar
 * cuales ya estan aplicadas, cuales pendientes y si hubo drift de hash
 * (el archivo cambio despues de aplicar).
 *
 * Asegura que la tabla `migraciones` exista (la crea si no esta) para que
 * la herramienta funcione tambien en entornos pre-existentes a esta feature.
 *
 *   GET api/herramientas_migraciones_list.php
 *     -> {ok:true, data:{database, env, items:[...]}}
 */
ini_set('display_errors', 0);
error_reporting(E_ALL);
header('Content-Type: application/json; charset=utf-8');

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'OPTIONS') { exit; }

require_once __DIR__ . '/lib/auth_check.php';
requireAuth();
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/lib/migraciones.php';

try {
    $pdo = db();
    asegurarTablaMigraciones($pdo);

    $dbName = (string)$pdo->query('SELECT DATABASE()')->fetchColumn();
    if ($dbName === '') jsonError('No se pudo determinar la base de datos actual', 500);

    $dir = migracionesDir();
    if (!is_dir($dir)) {
        jsonOk([
            'database' => $dbName,
            'env'      => getenv('APP_ENV') ?: 'unknown',
            'items'    => [],
        ]);
    }

    $archivos = glob($dir . '/*.sql') ?: [];
    sort($archivos, SORT_STRING);

    $stmt = $pdo->query('SELECT nombre, hash, aplicada FROM migraciones');
    $aplicadas = [];
    foreach ($stmt->fetchAll() as $r) {
        $aplicadas[(string)$r['nombre']] = $r;
    }

    $items = [];
    foreach ($archivos as $ruta) {
        $nombre   = basename($ruta);
        $contenido = (string)@file_get_contents($ruta);
        $hashAct  = hash('sha256', $contenido);
        $tamano   = strlen($contenido);

        $apl = $aplicadas[$nombre] ?? null;
        if ($apl) {
            $items[] = [
                'nombre'      => $nombre,
                'hash'        => $hashAct,
                'tamano'      => $tamano,
                'aplicada'    => $apl['aplicada'],
                'hash_drift'  => $apl['hash'] !== null && $apl['hash'] !== $hashAct,
                'estado'      => 'aplicada',
            ];
        } else {
            $items[] = [
                'nombre'      => $nombre,
                'hash'        => $hashAct,
                'tamano'      => $tamano,
                'aplicada'    => null,
                'hash_drift'  => false,
                'estado'      => 'pendiente',
            ];
        }
    }

    jsonOk([
        'database' => $dbName,
        'env'      => getenv('APP_ENV') ?: 'unknown',
        'items'    => $items,
    ]);
} catch (Throwable $e) {
    jsonError($e->getMessage(), 500);
}
