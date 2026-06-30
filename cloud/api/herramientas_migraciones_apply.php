<?php
/**
 * API cloud — Herramientas: Migrador DB (aplicar).
 *
 * Aplica una migracion contra la BD del entorno actual: ejecuta el SQL
 * via PDO::exec (soporta multi-statement) y registra la fila en `migraciones`.
 *
 * Notas:
 *  - El target es SIEMPRE la BD del propio panel (dev = databox_dev,
 *    prod = RDS). No hay selector cruzado.
 *  - El SQL puede contener DDL (CREATE / ALTER), que en MySQL hace auto-commit
 *    por sentencia: si falla la sentencia N, las 1..N-1 ya quedaron aplicadas
 *    y no hay rollback posible. La fila en `migraciones` solo se inserta si
 *    PDO::exec termina sin excepcion, asi que en caso de fallo parcial la
 *    migracion seguira figurando como pendiente — el usuario corrige el SQL
 *    y reintenta (las primeras sentencias deberian ser idempotentes para que
 *    el reintento no choque).
 *  - Prevenir re-apply: si ya hay fila en `migraciones` con ese `nombre`,
 *    devolvemos 409 y no ejecutamos nada.
 *
 *   POST api/herramientas_migraciones_apply.php
 *     body: {"nombre": "20260101_xxx.sql"}
 *     -> {ok:true, data:{nombre, hash, aplicada, duracion_ms}}
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
    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
        jsonError('Metodo no soportado', 405);
    }

    $in     = readJsonBody();
    $nombre = trim((string)($in['nombre'] ?? ''));
    if (!nombreMigracionValido($nombre)) {
        jsonError('Nombre de migracion invalido.', 400);
    }

    $ruta = migracionesDir() . '/' . $nombre;
    if (!is_file($ruta)) {
        jsonError('La migracion no existe.', 404);
    }

    $sql = (string)file_get_contents($ruta);
    if (trim($sql) === '') {
        jsonError('La migracion esta vacia.', 400);
    }

    $pdo = db();
    asegurarTablaMigraciones($pdo);

    $chk = $pdo->prepare('SELECT id, aplicada FROM migraciones WHERE nombre = :n LIMIT 1');
    $chk->execute([':n' => $nombre]);
    $row = $chk->fetch();
    if ($row) {
        jsonError('La migracion ya fue aplicada el ' . $row['aplicada'] . '.', 409);
    }

    $hash = hash('sha256', $sql);
    $t0   = microtime(true);

    try {
        $pdo->exec($sql);
    } catch (Throwable $e) {
        jsonError('Error al ejecutar la migracion: ' . $e->getMessage(), 500);
    }

    $duracion = (int)round((microtime(true) - $t0) * 1000);
    $aplicada = date('Y-m-d H:i:s');

    $ins = $pdo->prepare(
        'INSERT INTO migraciones (nombre, hash, aplicada) VALUES (:n, :h, :a)'
    );
    $ins->execute([':n' => $nombre, ':h' => $hash, ':a' => $aplicada]);

    jsonOk([
        'nombre'      => $nombre,
        'hash'        => $hash,
        'aplicada'    => $aplicada,
        'duracion_ms' => $duracion,
    ]);
} catch (Throwable $e) {
    jsonError($e->getMessage(), 500);
}
