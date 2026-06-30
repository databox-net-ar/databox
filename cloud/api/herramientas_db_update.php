<?php
/**
 * API cloud — Herramientas: Explorador DB
 * Actualiza el valor de UNA columna en UNA fila identificada por su PK.
 *
 * POST application/json:
 *   {
 *     "tabla":   "<nombre>",
 *     "columna": "<nombre>",
 *     "pk":      { "<col_pk_1>": <val>, ... },
 *     "valor":   <string|number|bool|null>
 *   }
 *
 * Restricciones de seguridad:
 *  - Sólo tablas BASE TABLE de la BD activa (validado vs INFORMATION_SCHEMA).
 *  - La tabla debe tener PK (sin PK no se puede identificar una fila única).
 *  - No se pueden editar columnas que formen parte de la PK ni columnas
 *    auto_increment (romperían identidad / FKs).
 *  - El parámetro `pk` debe traer exactamente las columnas de la PK con valor.
 *  - El nombre de tabla y columnas se citan con backticks; los valores van
 *    como bind params (PDO).
 */
ini_set('display_errors', 0);
error_reporting(E_ALL);
header('Content-Type: application/json; charset=utf-8');

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'OPTIONS') { exit; }
if ($_SERVER['REQUEST_METHOD'] !== 'POST') jsonError('Método no permitido', 405);

require_once __DIR__ . '/lib/auth_check.php';
requireAuth();
require_once __DIR__ . '/db.php';

$body    = readJsonBody();
$tabla   = isset($body['tabla'])   ? trim((string)$body['tabla'])   : '';
$columna = isset($body['columna']) ? trim((string)$body['columna']) : '';
$pk      = isset($body['pk']) && is_array($body['pk']) ? $body['pk'] : [];
$valor   = array_key_exists('valor', $body) ? $body['valor'] : null;
$valorEsNull = !array_key_exists('valor', $body) || $valor === null;

if ($tabla === '')   jsonError('Falta "tabla"', 400);
if ($columna === '') jsonError('Falta "columna"', 400);
if (!$pk)            jsonError('Falta "pk"', 400);

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
    if (!$pkCols) jsonError('La tabla no tiene PK — no es posible editar registros individuales.', 409);

    $colStmt = $pdo->prepare(
        "SELECT COLUMN_NAME, COLUMN_KEY, EXTRA, IS_NULLABLE, DATA_TYPE
           FROM INFORMATION_SCHEMA.COLUMNS
          WHERE TABLE_SCHEMA = :db AND TABLE_NAME = :t"
    );
    $colStmt->execute([':db' => $dbName, ':t' => $tabla]);
    $colsByName = [];
    foreach ($colStmt->fetchAll() as $c) $colsByName[$c['COLUMN_NAME']] = $c;

    if (!isset($colsByName[$columna])) jsonError('La columna no existe', 404);
    $colMeta = $colsByName[$columna];
    if (in_array($columna, $pkCols, true)) {
        jsonError('No se puede editar una columna que forma parte de la PK.', 409);
    }
    if (stripos((string)$colMeta['EXTRA'], 'auto_increment') !== false) {
        jsonError('No se puede editar una columna auto_increment.', 409);
    }

    // Validar que el PK enviado tiene exactamente las columnas correctas.
    $pkRecibidas = array_keys($pk);
    sort($pkRecibidas);
    $pkEsperadas = $pkCols;
    sort($pkEsperadas);
    if ($pkRecibidas !== $pkEsperadas) {
        jsonError('PK incompleta. Se esperaba: ' . implode(', ', $pkCols), 400);
    }

    // NULL solo si la columna lo permite.
    if ($valorEsNull && strtoupper((string)$colMeta['IS_NULLABLE']) !== 'YES') {
        jsonError('La columna no permite NULL.', 409);
    }

    // Armar UPDATE … WHERE pk1=? AND pk2=? …
    $sets   = bq($columna) . ' = ?';
    $where  = implode(' AND ', array_map(fn($c) => bq($c) . ' = ?', $pkCols));
    $sql    = 'UPDATE ' . bq($tabla) . ' SET ' . $sets . ' WHERE ' . $where . ' LIMIT 1';

    $params = [];
    $params[] = $valorEsNull ? null : $valor;
    foreach ($pkCols as $c) $params[] = $pk[$c];

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $filas = $stmt->rowCount();

    // Releer el valor recién guardado para devolverlo al frontend (DB pudo
    // haberlo casteado: trim de varchar, cast a int, normalización de fecha…).
    $sel = $pdo->prepare(
        'SELECT ' . bq($columna) . ' AS v FROM ' . bq($tabla) . ' WHERE ' . $where . ' LIMIT 1'
    );
    $sel->execute(array_values($pk));
    $nuevo = $sel->fetchColumn();

    jsonOk([
        'filas_afectadas' => $filas,
        'valor_guardado'  => ($nuevo === false ? null : $nuevo),
    ]);
} catch (Throwable $e) {
    jsonError($e->getMessage(), 500);
}
