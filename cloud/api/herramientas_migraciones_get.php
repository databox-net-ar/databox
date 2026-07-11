<?php
/**
 * API cloud — Herramientas: Migrador DB (preview).
 *
 * Devuelve el contenido SQL de una migracion para previsualizarla antes
 * de aplicar. Solo lee del disco — no toca la BD.
 *
 *   GET api/herramientas_migraciones_get.php?nombre=20260101_xxx.sql
 *     -> {ok:true, data:{nombre, contenido, tamano, hash}}
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
    requirePermission('administracion.herramientas.migrador_db.consultar');
    $nombre = (string)($_GET['nombre'] ?? '');
    if (!nombreMigracionValido($nombre)) {
        jsonError('Nombre de migracion invalido.', 400);
    }

    $ruta = migracionesDir() . '/' . $nombre;
    if (!is_file($ruta)) {
        jsonError('La migracion no existe.', 404);
    }

    $contenido = (string)file_get_contents($ruta);
    jsonOk([
        'nombre'    => $nombre,
        'contenido' => $contenido,
        'tamano'    => strlen($contenido),
        'hash'      => hash('sha256', $contenido),
    ]);
} catch (Throwable $e) {
    jsonError($e->getMessage(), 500);
}
