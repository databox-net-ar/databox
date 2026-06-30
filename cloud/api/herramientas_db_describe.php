<?php
/**
 * API cloud — Herramientas: Explorador DB
 * Describe los campos de una tabla (nombre, tipo, nullable, clave, default,
 * extra, comentario). El nombre de tabla se valida contra INFORMATION_SCHEMA
 * para no concatenar entradas crudas a SQL.
 */
ini_set('display_errors', 0);
error_reporting(E_ALL);
header('Content-Type: application/json; charset=utf-8');

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'OPTIONS') { exit; }

require_once __DIR__ . '/lib/auth_check.php';
requireAuth();
require_once __DIR__ . '/db.php';

$tabla = isset($_GET['tabla']) ? trim((string)$_GET['tabla']) : '';
if ($tabla === '') jsonError('Falta el parámetro "tabla"', 400);

try {
    $pdo = db();
    $dbName = (string)$pdo->query('SELECT DATABASE()')->fetchColumn();
    if ($dbName === '') jsonError('No se pudo determinar la base de datos actual', 500);

    $check = $pdo->prepare(
        "SELECT 1 FROM INFORMATION_SCHEMA.TABLES
          WHERE TABLE_SCHEMA = :db AND TABLE_NAME = :t AND TABLE_TYPE = 'BASE TABLE'"
    );
    $check->execute([':db' => $dbName, ':t' => $tabla]);
    if (!$check->fetchColumn()) jsonError('La tabla no existe en esta base', 404);

    $stmt = $pdo->prepare(
        "SELECT ORDINAL_POSITION AS posicion,
                COLUMN_NAME      AS nombre,
                COLUMN_TYPE      AS tipo,
                IS_NULLABLE      AS nullable,
                COLUMN_KEY       AS clave,
                COLUMN_DEFAULT   AS predeterminado,
                EXTRA            AS extra,
                COLUMN_COMMENT   AS comentario
           FROM INFORMATION_SCHEMA.COLUMNS
          WHERE TABLE_SCHEMA = :db AND TABLE_NAME = :t
          ORDER BY ORDINAL_POSITION ASC"
    );
    $stmt->execute([':db' => $dbName, ':t' => $tabla]);
    $columnas = $stmt->fetchAll();

    jsonOk([
        'database' => $dbName,
        'tabla'    => $tabla,
        'columnas' => $columnas,
    ]);
} catch (Throwable $e) {
    jsonError($e->getMessage(), 500);
}
