<?php
// api/proyectos.php
// Endpoint GET-only para poblar dropdowns de proyectos en distintos ABMs
// del panel (aws_mensajes, evolution_mensajes, datarocket, etc). No es un ABM
// completo — no acepta POST/PUT/DELETE. Solo autenticacion, sin permiso
// especifico: proyectos es data de referencia visible para cualquier user
// con sesion valida.
//
//   GET api/proyectos.php              -> todos los proyectos
//   GET api/proyectos.php?tipo=I       -> filtra por `tipo` (varchar(1))
//   GET api/proyectos.php?q=cas        -> busca por nombre (LIKE)
//
// Respuesta: {ok: true, data: {items: [{id, nombre, tipo, uuid}, ...]}}

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/lib/auth_check.php';

header('Content-Type: application/json; charset=utf-8');

try {
    requireAuth();
    $pdo    = db();
    $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

    if ($method !== 'GET') {
        jsonError('Metodo no soportado', 405);
    }

    $tipo   = trim((string)($_GET['tipo'] ?? ''));
    $search = trim((string)($_GET['q']    ?? ''));

    $where  = [];
    $params = [];

    if ($tipo !== '') {
        $where[]         = 'tipo = :tipo';
        $params[':tipo'] = substr($tipo, 0, 1);
    }
    if ($search !== '') {
        $where[]      = 'nombre LIKE :q';
        $params[':q'] = "%{$search}%";
    }

    $sqlWhere = $where ? ('WHERE ' . implode(' AND ', $where)) : '';

    $sql  = "SELECT id, uuid, nombre, tipo FROM proyectos {$sqlWhere} ORDER BY nombre";
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $rows = $stmt->fetchAll();

    jsonOk(['items' => $rows]);

} catch (Throwable $e) {
    jsonError($e->getMessage(), 500);
}
