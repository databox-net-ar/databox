<?php
/**
 * API cloud — Herramientas: Explorador DB
 * Devuelve los últimos N (default 10) registros de la tabla pedida, con los
 * más nuevos arriba. Orden: PK DESC si existe; si la tabla no tiene PK cae
 * a un SELECT sin ORDER BY (devolvemos los que MySQL entregue primero).
 *
 * El nombre de tabla se valida contra INFORMATION_SCHEMA y se backtick-cita
 * para evitar SQL injection. Solo SELECT — endpoint de solo lectura.
 */
ini_set('display_errors', 0);
error_reporting(E_ALL);
header('Content-Type: application/json; charset=utf-8');

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'OPTIONS') { exit; }

require_once __DIR__ . '/lib/auth_check.php';
requireAuth();
require_once __DIR__ . '/db.php';

$tabla  = isset($_GET['tabla'])  ? trim((string)$_GET['tabla'])  : '';
$limite = isset($_GET['limite']) ? (int)$_GET['limite']          : 50;
if ($tabla === '') jsonError('Falta el parámetro "tabla"', 400);
if ($limite < 1 || $limite > 500) $limite = 50;

function bq(string $ident): string {
    return '`' . str_replace('`', '``', $ident) . '`';
}

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

    $pkStmt = $pdo->prepare(
        "SELECT COLUMN_NAME
           FROM INFORMATION_SCHEMA.KEY_COLUMN_USAGE
          WHERE TABLE_SCHEMA = :db AND TABLE_NAME = :t AND CONSTRAINT_NAME = 'PRIMARY'
          ORDER BY ORDINAL_POSITION ASC"
    );
    $pkStmt->execute([':db' => $dbName, ':t' => $tabla]);
    $pkCols = array_column($pkStmt->fetchAll(), 'COLUMN_NAME');

    $colsStmt = $pdo->prepare(
        "SELECT COLUMN_NAME, EXTRA, IS_NULLABLE
           FROM INFORMATION_SCHEMA.COLUMNS
          WHERE TABLE_SCHEMA = :db AND TABLE_NAME = :t
          ORDER BY ORDINAL_POSITION ASC"
    );
    $colsStmt->execute([':db' => $dbName, ':t' => $tabla]);
    $colsMeta = $colsStmt->fetchAll();
    $columnas = array_column($colsMeta, 'COLUMN_NAME');
    $autoIncCols = array_values(array_map(
        fn($c) => $c['COLUMN_NAME'],
        array_filter($colsMeta, fn($c) => stripos((string)$c['EXTRA'], 'auto_increment') !== false)
    ));
    $nullableCols = array_values(array_map(
        fn($c) => $c['COLUMN_NAME'],
        array_filter($colsMeta, fn($c) => strtoupper((string)$c['IS_NULLABLE']) === 'YES')
    ));

    $sql = 'SELECT * FROM ' . bq($tabla);
    if (!empty($pkCols)) {
        $order = implode(', ', array_map(fn($c) => bq($c) . ' DESC', $pkCols));
        $sql .= ' ORDER BY ' . $order;
    }
    $sql .= ' LIMIT ' . $limite;

    $rows = $pdo->query($sql)->fetchAll();

    // Truncar valores muy largos para que la UI no quede inservible si hay
    // BLOBs / TEXT enormes (JSON, base64, etc.). El backend marca explícito
    // el corte; el front no intenta adivinar.
    $MAX_LEN = 500;
    $out = [];
    foreach ($rows as $r) {
        $clean = [];
        foreach ($r as $k => $v) {
            if (is_string($v) && strlen($v) > $MAX_LEN) {
                $clean[$k] = substr($v, 0, $MAX_LEN) . '… (truncado)';
            } else {
                $clean[$k] = $v;
            }
        }
        $out[] = $clean;
    }

    $total = (int)$pdo->query('SELECT COUNT(*) FROM ' . bq($tabla))->fetchColumn();

    jsonOk([
        'database'      => $dbName,
        'tabla'         => $tabla,
        'pk'            => $pkCols,
        'auto_inc'      => $autoIncCols,
        'nullable'      => $nullableCols,
        'columnas'      => $columnas,
        'limite'        => $limite,
        'total'         => $total,
        'registros'     => $out,
    ]);
} catch (Throwable $e) {
    jsonError($e->getMessage(), 500);
}
