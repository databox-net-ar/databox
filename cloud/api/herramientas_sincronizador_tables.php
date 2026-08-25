<?php
/**
 * API cloud — Herramientas: Sincronizador de tablas (listar tablas).
 *
 * Lista las tablas de la BD origen (dev o prod) para poblar el <select>
 * del modal. Solo funciona con el panel corriendo en desarrollo.
 *
 *   GET api/herramientas_sincronizador_tables.php?origen=dev|prod
 *     -> {ok:true, data:{origen:{ambiente,host,database}, tablas:[{nombre}]}}
 *
 * IMPORTANTE: este listado NO devuelve cantidad de filas — ni con COUNT(*)
 * ni con TABLE_ROWS. Cualquier columna de estadisticas de
 * INFORMATION_SCHEMA.TABLES obliga al motor a abrir cada tabla y leer las
 * stats del storage engine, tomando un metadata lock por tabla: costo
 * impredecible (depende de si la cache de stats esta vigente y de que no
 * haya otra sesion con la tabla tomada) y, cuando se traba, se traba el
 * combo entero y con el la herramienta. Pidiendo solo TABLE_NAME el motor
 * resuelve el listado desde el diccionario, sin tocar los datos.
 * El filtro TABLE_TYPE se conserva porque prod tiene vistas y no son
 * sincronizables.
 */
ini_set('display_errors', 0);
error_reporting(E_ALL);
header('Content-Type: application/json; charset=utf-8');

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'OPTIONS') { exit; }

require_once __DIR__ . '/lib/auth_check.php';
requireAuth();
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/lib/sincronizador.php';

sincronizadorAssertDev();

try {
    requirePermission('administracion.herramientas.sincronizador.ejecutar');
    $origen = strtolower(trim((string)($_GET['origen'] ?? '')));
    if ($origen !== 'dev' && $origen !== 'prod') {
        jsonError('Parametro "origen" invalido. Usar dev o prod.', 400);
    }

    $pdo   = sincronizadorPdo($origen);
    $meta  = sincronizadorEntorno($origen);

    $stmt = $pdo->prepare(
        "SELECT TABLE_NAME AS nombre
           FROM INFORMATION_SCHEMA.TABLES
          WHERE TABLE_SCHEMA = :db AND TABLE_TYPE = 'BASE TABLE'
          ORDER BY TABLE_NAME ASC"
    );
    $stmt->execute([':db' => $meta['database']]);
    $tablas = $stmt->fetchAll();

    jsonOk([
        'origen' => $meta,
        'tablas' => $tablas,
    ]);
} catch (Throwable $e) {
    jsonError($e->getMessage(), 500);
}
