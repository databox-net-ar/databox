<?php
/**
 * API cloud — Herramientas: Explorador DB
 * Lista las tablas de la base de datos del entorno actual (dev = databox_dev,
 * prod = RDS / databox). Consulta INFORMATION_SCHEMA filtrando por la base
 * que está apuntando la conexión PDO, así "qué base" es automático según
 * el .env cargado.
 */
ini_set('display_errors', 0);
error_reporting(E_ALL);
header('Content-Type: application/json; charset=utf-8');

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'OPTIONS') { exit; }

require_once __DIR__ . '/lib/auth_check.php';
requireAuth();
require_once __DIR__ . '/db.php';

try {
    $pdo = db();
    $dbName = (string)$pdo->query('SELECT DATABASE()')->fetchColumn();
    if ($dbName === '') jsonError('No se pudo determinar la base de datos actual', 500);

    $stmt = $pdo->prepare(
        "SELECT TABLE_NAME    AS nombre,
                TABLE_ROWS    AS filas_aprox,
                ENGINE        AS engine,
                TABLE_COMMENT AS comentario
           FROM INFORMATION_SCHEMA.TABLES
          WHERE TABLE_SCHEMA = :db AND TABLE_TYPE = 'BASE TABLE'
          ORDER BY TABLE_NAME ASC"
    );
    $stmt->execute([':db' => $dbName]);
    $tablas = $stmt->fetchAll();

    jsonOk([
        'database' => $dbName,
        'env'      => getenv('APP_ENV') ?: 'unknown',
        'tablas'   => $tablas,
    ]);
} catch (Throwable $e) {
    jsonError($e->getMessage(), 500);
}
