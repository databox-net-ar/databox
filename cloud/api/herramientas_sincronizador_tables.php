<?php
/**
 * API cloud — Herramientas: Sincronizador de tablas (listar tablas).
 *
 * Lista las tablas de la BD origen (dev o prod) para poblar el <select>
 * del modal. Solo funciona con el panel corriendo en desarrollo.
 *
 *   GET api/herramientas_sincronizador_tables.php?origen=dev|prod
 *     -> {ok:true, data:{origen:{ambiente,host,database}, tablas:[{nombre,filas_aprox}]}}
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
    $origen = strtolower(trim((string)($_GET['origen'] ?? '')));
    if ($origen !== 'dev' && $origen !== 'prod') {
        jsonError('Parametro "origen" invalido. Usar dev o prod.', 400);
    }

    $pdo   = sincronizadorPdo($origen);
    $meta  = sincronizadorEntorno($origen);

    $stmt = $pdo->prepare(
        "SELECT TABLE_NAME AS nombre, TABLE_ROWS AS filas_aprox
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
