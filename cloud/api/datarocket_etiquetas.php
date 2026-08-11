<?php
// api/datarocket_etiquetas.php
// Etiquetas Datarocket (CRUD). Lee/escribe sobre la tabla
// `datarocket_etiquetas` definida en db/schema.sql — catalogo de etiquetas
// reutilizables que se aplican a otros recursos del stack Datarocket via
// tablas de union (por ahora solo `datarocket_contactos_etiquetas`).
//
//   GET    api/datarocket_etiquetas.php[?q=...&limite=100&orden=id&dir=desc]
//                                          -> listado + stats (incluye
//                                             `etiquetados` = COUNT de
//                                             contactos con la etiqueta)
//   GET    api/datarocket_etiquetas.php?id=N
//                                          -> registro individual + etiquetados
//   POST   api/datarocket_etiquetas.php     -> alta (JSON body)
//   PUT    api/datarocket_etiquetas.php?id=N
//                                          -> modificacion (JSON body)
//   DELETE api/datarocket_etiquetas.php?id=N
//                                          -> baja
//
// Respuesta siempre {ok: true, data: ...} u {ok: false, error: '...'} (STACK.md sec. 10).

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/lib/auth_check.php';
require_once __DIR__ . '/lib/sucesos.php';

requireAuth();
header('Content-Type: application/json; charset=utf-8');

// `etiquetados` es un campo virtual (COUNT contra la tabla puente); se
// admite como opcion de orden en el listado.
const DRE_ORDENES = ['id', 'nombre', 'etiquetados', 'fecha_creacion', 'fecha_modificacion'];

try {
    requirePermCrud('datarocket.etiquetas');
    $pdo    = db();
    $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
    $id     = isset($_GET['id']) ? (int)$_GET['id'] : 0;

    if ($method === 'GET' && $id > 0) {
        handleGetOneEtiqueta($pdo, $id);
    } elseif ($method === 'GET') {
        handleListEtiquetas($pdo, $_GET);
    } elseif ($method === 'POST') {
        handleCreateEtiqueta($pdo, readJsonBody());
    } elseif ($method === 'PUT') {
        if ($id <= 0) jsonError('Falta id', 400);
        handleUpdateEtiqueta($pdo, $id, readJsonBody());
    } elseif ($method === 'DELETE') {
        if ($id <= 0) jsonError('Falta id', 400);
        handleDeleteEtiqueta($pdo, $id);
    } else {
        jsonError('Metodo no soportado', 405);
    }
} catch (Throwable $e) {
    jsonError($e->getMessage(), 500);
}

// ----------------------------------------------------------------------------
// Helpers
// ----------------------------------------------------------------------------

function normalizarFilaEtiqueta(array $r): array {
    return [
        'id'                 => (int)($r['id'] ?? 0),
        'nombre'             => (string)($r['nombre'] ?? ''),
        'descripcion'        => $r['descripcion'] !== null ? (string)$r['descripcion'] : null,
        'etiquetados'        => (int)($r['etiquetados'] ?? 0),
        'fecha_creacion'     => $r['fecha_creacion']     ?? null,
        'fecha_modificacion' => $r['fecha_modificacion'] ?? null,
    ];
}

function sanitizePayloadEtiqueta(array $in, bool $esAlta): array {
    $nombre      = trim((string)($in['nombre']      ?? ''));
    $descripcion = trim((string)($in['descripcion'] ?? ''));

    if ($esAlta && $nombre === '') {
        jsonError('El nombre es obligatorio.', 400);
    }
    if ($nombre !== '' && mb_strlen($nombre) > 80) {
        jsonError('El nombre no puede superar los 80 caracteres.', 400);
    }
    if ($descripcion !== '' && mb_strlen($descripcion) > 500) {
        jsonError('La descripcion no puede superar los 500 caracteres.', 400);
    }

    return [
        'nombre'      => $nombre,
        'descripcion' => $descripcion === '' ? null : $descripcion,
    ];
}

// ----------------------------------------------------------------------------
// Handlers
// ----------------------------------------------------------------------------

function handleListEtiquetas(PDO $pdo, array $q): void {
    $search = trim((string)($q['q'] ?? ''));
    $limite = max(1, min(1000, (int)($q['limite'] ?? 100)));
    $orden  = in_array(($q['orden'] ?? ''), DRE_ORDENES, true) ? $q['orden'] : 'id';
    $dir    = strtolower((string)($q['dir'] ?? 'desc')) === 'asc' ? 'ASC' : 'DESC';

    $where  = [];
    $params = [];

    if ($search !== '') {
        $where[] = '(e.nombre LIKE :s_nom OR e.descripcion LIKE :s_desc)';
        $params[':s_nom']  = "%{$search}%";
        $params[':s_desc'] = "%{$search}%";
    }

    $sqlWhere = $where ? ('WHERE ' . implode(' AND ', $where)) : '';

    // LEFT JOIN + GROUP BY para calcular `etiquetados` en una sola vuelta
    // (LEFT para que las etiquetas sin ningun contacto asignado igual
    // aparezcan con etiquetados = 0).
    $sql = "
        SELECT e.id, e.nombre, e.descripcion, e.fecha_creacion, e.fecha_modificacion,
               COALESCE(COUNT(dce.contacto_id), 0) AS etiquetados
          FROM datarocket_etiquetas e
     LEFT JOIN datarocket_contactos_etiquetas dce ON dce.etiqueta_id = e.id
          {$sqlWhere}
      GROUP BY e.id
      ORDER BY {$orden} {$dir}
         LIMIT {$limite}
    ";
    $st = $pdo->prepare($sql);
    $st->execute($params);
    $rows = array_map('normalizarFilaEtiqueta', $st->fetchAll());

    $stats = [
        'total' => (int)$pdo->query('SELECT COUNT(*) FROM datarocket_etiquetas')->fetchColumn(),
    ];

    jsonOk(['items' => $rows, 'stats' => $stats]);
}

function handleGetOneEtiqueta(PDO $pdo, int $id): void {
    // Subquery escalar para `etiquetados` — mas simple que un GROUP BY para
    // una sola fila. Con indice PK(etiqueta_id, contacto_id) el COUNT es
    // O(log n) via index-only scan.
    $sql = '
        SELECT e.id, e.nombre, e.descripcion, e.fecha_creacion, e.fecha_modificacion,
               (SELECT COUNT(*) FROM datarocket_contactos_etiquetas dce
                 WHERE dce.etiqueta_id = e.id) AS etiquetados
          FROM datarocket_etiquetas e
         WHERE e.id = :id
         LIMIT 1
    ';
    $st = $pdo->prepare($sql);
    $st->execute([':id' => $id]);
    $row = $st->fetch();
    if (!$row) jsonError('Etiqueta no encontrada', 404);
    jsonOk(normalizarFilaEtiqueta($row));
}

function handleCreateEtiqueta(PDO $pdo, array $body): void {
    $p = sanitizePayloadEtiqueta($body, true);

    $st = $pdo->prepare('SELECT id FROM datarocket_etiquetas WHERE nombre = :n LIMIT 1');
    $st->execute([':n' => $p['nombre']]);
    if ($st->fetch()) jsonError('Ya existe una etiqueta con ese nombre.', 409);

    $st = $pdo->prepare(
        'INSERT INTO datarocket_etiquetas (nombre, descripcion)
         VALUES (:nombre, :descripcion)'
    );
    $st->execute([
        ':nombre'      => $p['nombre'],
        ':descripcion' => $p['descripcion'],
    ]);

    $id = (int)$pdo->lastInsertId();
    registrarSuceso($pdo, 'datarocket_etiquetas', 'info',
        "Alta etiqueta #{$id} — \"{$p['nombre']}\"");

    handleGetOneEtiqueta($pdo, $id);
}

function handleUpdateEtiqueta(PDO $pdo, int $id, array $body): void {
    $st = $pdo->prepare('SELECT id, nombre, descripcion FROM datarocket_etiquetas WHERE id = :id LIMIT 1');
    $st->execute([':id' => $id]);
    $prev = $st->fetch();
    if (!$prev) jsonError('Etiqueta no encontrada', 404);

    $p = sanitizePayloadEtiqueta($body, false);

    if (array_key_exists('nombre', $body) && $p['nombre'] !== '' && $p['nombre'] !== $prev['nombre']) {
        $st = $pdo->prepare('SELECT id FROM datarocket_etiquetas WHERE nombre = :n AND id <> :id LIMIT 1');
        $st->execute([':n' => $p['nombre'], ':id' => $id]);
        if ($st->fetch()) jsonError('Ya existe otra etiqueta con ese nombre.', 409);
    }

    $sets   = [];
    $params = [':id' => $id];

    if (array_key_exists('nombre', $body) && $p['nombre'] !== '') {
        $sets[] = 'nombre = :nombre';
        $params[':nombre'] = $p['nombre'];
    }
    if (array_key_exists('descripcion', $body)) {
        $sets[] = 'descripcion = :descripcion';
        $params[':descripcion'] = $p['descripcion'];
    }

    if (empty($sets)) jsonError('No hay campos para actualizar.', 400);

    $sql = 'UPDATE datarocket_etiquetas SET ' . implode(', ', $sets) . ' WHERE id = :id';
    $st  = $pdo->prepare($sql);
    $st->execute($params);

    registrarSuceso($pdo, 'datarocket_etiquetas', 'info',
        "Modificacion etiqueta #{$id} — \"{$prev['nombre']}\"");

    handleGetOneEtiqueta($pdo, $id);
}

function handleDeleteEtiqueta(PDO $pdo, int $id): void {
    $st = $pdo->prepare('SELECT nombre FROM datarocket_etiquetas WHERE id = :id LIMIT 1');
    $st->execute([':id' => $id]);
    $prev = $st->fetch();
    if (!$prev) jsonError('Etiqueta no encontrada', 404);

    $sd = $pdo->prepare('DELETE FROM datarocket_etiquetas WHERE id = :id');
    $sd->execute([':id' => $id]);

    registrarSuceso($pdo, 'datarocket_etiquetas', 'info',
        "Baja etiqueta #{$id} — \"{$prev['nombre']}\"");

    jsonOk(['id' => $id]);
}
