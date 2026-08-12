<?php
// api/datarocket_etapas.php
// Etapas Datarocket (CRUD). Lee/escribe sobre la tabla `datarocket_etapas`
// definida en db/schema.sql — columnas del kanban de cada embudo.
//
//   GET    api/datarocket_etapas.php[?q=...&embudo_id=N&tipo=activa&limite=100&orden=orden&dir=asc]
//                                          -> listado + stats (incluye
//                                             `prospectos_count` derivado de
//                                             datarocket_prospectos.etapa_id
//                                             y `embudo_nombre` para render)
//   GET    api/datarocket_etapas.php?id=N
//                                          -> registro individual
//   POST   api/datarocket_etapas.php       -> alta (JSON body)
//   PUT    api/datarocket_etapas.php?id=N  -> modificacion (JSON body)
//   DELETE api/datarocket_etapas.php?id=N  -> baja (bloqueada si tiene
//                                             prospectos, la FK RESTRICT
//                                             ya lo impone a nivel DB)
//
// Respuesta siempre {ok: true, data: ...} u {ok: false, error: '...'} (STACK.md sec. 10).

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/lib/auth_check.php';
require_once __DIR__ . '/lib/sucesos.php';

requireAuth();
header('Content-Type: application/json; charset=utf-8');

const DRET_COLS    = 'e.id, e.embudo_id, e.nombre, e.orden, e.color, e.tipo, e.probabilidad, e.fecha_creacion, e.fecha_modificacion';
const DRET_ORDENES = ['id', 'embudo_id', 'nombre', 'orden', 'tipo', 'probabilidad', 'fecha_creacion', 'fecha_modificacion'];
const DRET_TIPOS   = ['activa', 'ganada', 'perdida'];

try {
    $pdo    = db();
    $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
    $id     = isset($_GET['id']) ? (int)$_GET['id'] : 0;

    requirePermCrud('datarocket.etapas');

    if ($method === 'GET' && $id > 0) {
        handleGetOneEtapa($pdo, $id);
    } elseif ($method === 'GET') {
        handleListEtapas($pdo, $_GET);
    } elseif ($method === 'POST') {
        handleCreateEtapa($pdo, readJsonBody());
    } elseif ($method === 'PUT') {
        if ($id <= 0) jsonError('Falta id', 400);
        handleUpdateEtapa($pdo, $id, readJsonBody());
    } elseif ($method === 'DELETE') {
        if ($id <= 0) jsonError('Falta id', 400);
        handleDeleteEtapa($pdo, $id);
    } else {
        jsonError('Metodo no soportado', 405);
    }
} catch (Throwable $e) {
    jsonError($e->getMessage(), 500);
}

// ----------------------------------------------------------------------------
// Helpers
// ----------------------------------------------------------------------------

function normalizarFilaEtapa(array $r): array {
    return [
        'id'                 => (int)($r['id'] ?? 0),
        'embudo_id'          => (int)($r['embudo_id'] ?? 0),
        'embudo_nombre'      => $r['embudo_nombre'] ?? null,
        'nombre'             => (string)($r['nombre'] ?? ''),
        'orden'              => (int)($r['orden'] ?? 0),
        'color'              => $r['color'] !== null && $r['color'] !== '' ? (string)$r['color'] : null,
        'tipo'               => (string)($r['tipo'] ?? 'activa'),
        'probabilidad'       => $r['probabilidad'] !== null ? (int)$r['probabilidad'] : null,
        'prospectos_count'   => isset($r['prospectos_count']) ? (int)$r['prospectos_count'] : null,
        'fecha_creacion'     => $r['fecha_creacion']     ?? null,
        'fecha_modificacion' => $r['fecha_modificacion'] ?? null,
    ];
}

function sanitizePayloadEtapa(array $in, bool $esAlta): array {
    $embudoId    = isset($in['embudo_id']) ? (int)$in['embudo_id'] : 0;
    $nombre      = trim((string)($in['nombre'] ?? ''));
    $orden       = isset($in['orden']) ? (int)$in['orden'] : 0;
    $color       = trim((string)($in['color'] ?? ''));
    $tipo        = trim((string)($in['tipo'] ?? 'activa'));
    $probRaw     = $in['probabilidad'] ?? null;
    $probabilidad = ($probRaw === null || $probRaw === '') ? null : (int)$probRaw;

    if ($esAlta && $embudoId <= 0) {
        jsonError('El embudo es obligatorio.', 400);
    }
    if ($esAlta && $nombre === '') {
        jsonError('El nombre es obligatorio.', 400);
    }
    if ($nombre !== '' && mb_strlen($nombre) > 80) {
        jsonError('El nombre no puede superar los 80 caracteres.', 400);
    }
    if ($color !== '' && !preg_match('/^#[0-9a-fA-F]{6}$/', $color)) {
        jsonError('El color debe ser un valor hex #RRGGBB.', 400);
    }
    if (!in_array($tipo, DRET_TIPOS, true)) {
        jsonError('El tipo debe ser activa, ganada o perdida.', 400);
    }
    if ($probabilidad !== null && ($probabilidad < 0 || $probabilidad > 100)) {
        jsonError('La probabilidad debe estar entre 0 y 100.', 400);
    }

    return [
        'embudo_id'    => $embudoId,
        'nombre'       => $nombre,
        'orden'        => $orden,
        'color'        => $color === '' ? null : $color,
        'tipo'         => $tipo,
        'probabilidad' => $probabilidad,
    ];
}

// ----------------------------------------------------------------------------
// Handlers
// ----------------------------------------------------------------------------

function handleListEtapas(PDO $pdo, array $q): void {
    $search   = trim((string)($q['q'] ?? ''));
    $embudoId = isset($q['embudo_id']) && $q['embudo_id'] !== '' ? (int)$q['embudo_id'] : null;
    $tipo     = trim((string)($q['tipo'] ?? ''));
    $limite   = max(1, min(1000, (int)($q['limite'] ?? 100)));
    // Default `orden` para etapas es `orden` (posicion en el kanban), no `id`.
    $orden    = in_array(($q['orden'] ?? ''), DRET_ORDENES, true) ? $q['orden'] : 'orden';
    $dir      = strtolower((string)($q['dir'] ?? 'asc')) === 'desc' ? 'DESC' : 'ASC';

    $where  = [];
    $params = [];

    if ($search !== '') {
        $where[] = 'e.nombre LIKE :s_nom';
        $params[':s_nom'] = "%{$search}%";
    }
    if ($embudoId !== null) {
        $where[] = 'e.embudo_id = :embudo_id';
        $params[':embudo_id'] = $embudoId;
    }
    if ($tipo !== '' && in_array($tipo, DRET_TIPOS, true)) {
        $where[] = 'e.tipo = :tipo';
        $params[':tipo'] = $tipo;
    }

    $sqlWhere = $where ? ('WHERE ' . implode(' AND ', $where)) : '';

    // Cuando se ordena por `orden` conviene desempatar por (embudo_id, orden)
    // para que las etapas de distintos embudos no se intercalen.
    $orderExtra = $orden === 'orden' ? ', e.id ASC' : '';

    $sql = 'SELECT ' . DRET_COLS . ",
                   b.nombre AS embudo_nombre,
                   COALESCE(pc.c, 0) AS prospectos_count
              FROM datarocket_etapas e
         LEFT JOIN datarocket_embudos b ON b.id = e.embudo_id
         LEFT JOIN (SELECT etapa_id, COUNT(*) c FROM datarocket_prospectos GROUP BY etapa_id) pc
                ON pc.etapa_id = e.id
             {$sqlWhere}
          ORDER BY e.embudo_id ASC, {$orden} {$dir}{$orderExtra}
             LIMIT {$limite}";
    $st = $pdo->prepare($sql);
    $st->execute($params);
    $rows = array_map('normalizarFilaEtapa', $st->fetchAll());

    $stats = [
        'total'    => (int)$pdo->query('SELECT COUNT(*) FROM datarocket_etapas')->fetchColumn(),
        'activas'  => (int)$pdo->query("SELECT COUNT(*) FROM datarocket_etapas WHERE tipo = 'activa'")->fetchColumn(),
        'ganadas'  => (int)$pdo->query("SELECT COUNT(*) FROM datarocket_etapas WHERE tipo = 'ganada'")->fetchColumn(),
        'perdidas' => (int)$pdo->query("SELECT COUNT(*) FROM datarocket_etapas WHERE tipo = 'perdida'")->fetchColumn(),
    ];

    jsonOk(['items' => $rows, 'stats' => $stats]);
}

function handleGetOneEtapa(PDO $pdo, int $id): void {
    $st = $pdo->prepare('SELECT ' . DRET_COLS . ",
                                b.nombre AS embudo_nombre,
                                COALESCE(pc.c, 0) AS prospectos_count
                           FROM datarocket_etapas e
                      LEFT JOIN datarocket_embudos b ON b.id = e.embudo_id
                      LEFT JOIN (SELECT etapa_id, COUNT(*) c FROM datarocket_prospectos GROUP BY etapa_id) pc
                             ON pc.etapa_id = e.id
                          WHERE e.id = :id
                          LIMIT 1");
    $st->execute([':id' => $id]);
    $row = $st->fetch();
    if (!$row) jsonError('Etapa no encontrada', 404);
    jsonOk(normalizarFilaEtapa($row));
}

function handleCreateEtapa(PDO $pdo, array $body): void {
    $p = sanitizePayloadEtapa($body, true);

    // Validacion de FK antes de INSERT — mensaje amigable.
    $st = $pdo->prepare('SELECT id FROM datarocket_embudos WHERE id = :id LIMIT 1');
    $st->execute([':id' => $p['embudo_id']]);
    if (!$st->fetch()) jsonError('El embudo indicado no existe.', 400);

    // UNIQUE(embudo_id, nombre) y UNIQUE(embudo_id, orden) — se chequean
    // aca para devolver mensajes amigables antes del error crudo del indice.
    $st = $pdo->prepare('SELECT id FROM datarocket_etapas WHERE embudo_id = :b AND nombre = :n LIMIT 1');
    $st->execute([':b' => $p['embudo_id'], ':n' => $p['nombre']]);
    if ($st->fetch()) jsonError('Ya existe una etapa con ese nombre en este embudo.', 409);

    $st = $pdo->prepare('SELECT id FROM datarocket_etapas WHERE embudo_id = :b AND orden = :o LIMIT 1');
    $st->execute([':b' => $p['embudo_id'], ':o' => $p['orden']]);
    if ($st->fetch()) jsonError('Ya existe una etapa con ese orden en este embudo.', 409);

    $st = $pdo->prepare(
        'INSERT INTO datarocket_etapas (embudo_id, nombre, orden, color, tipo, probabilidad)
         VALUES (:embudo_id, :nombre, :orden, :color, :tipo, :probabilidad)'
    );
    $st->execute([
        ':embudo_id'    => $p['embudo_id'],
        ':nombre'       => $p['nombre'],
        ':orden'        => $p['orden'],
        ':color'        => $p['color'],
        ':tipo'         => $p['tipo'],
        ':probabilidad' => $p['probabilidad'],
    ]);

    $id = (int)$pdo->lastInsertId();
    registrarSuceso($pdo, 'datarocket_etapas', 'info',
        "Alta etapa #{$id} \u{2014} \"{$p['nombre']}\" (embudo #{$p['embudo_id']})");

    handleGetOneEtapa($pdo, $id);
}

function handleUpdateEtapa(PDO $pdo, int $id, array $body): void {
    $st = $pdo->prepare('SELECT id, embudo_id, nombre, orden FROM datarocket_etapas WHERE id = :id LIMIT 1');
    $st->execute([':id' => $id]);
    $prev = $st->fetch();
    if (!$prev) jsonError('Etapa no encontrada', 404);

    $p = sanitizePayloadEtapa($body, false);

    // Si se cambio de embudo, validar que exista.
    $nuevoEmbudo = array_key_exists('embudo_id', $body) && $p['embudo_id'] > 0 ? $p['embudo_id'] : (int)$prev['embudo_id'];
    if ($nuevoEmbudo !== (int)$prev['embudo_id']) {
        $st = $pdo->prepare('SELECT id FROM datarocket_embudos WHERE id = :id LIMIT 1');
        $st->execute([':id' => $nuevoEmbudo]);
        if (!$st->fetch()) jsonError('El embudo indicado no existe.', 400);
    }

    // Validaciones UNIQUE contra el embudo destino, excluyendo la misma fila.
    if (array_key_exists('nombre', $body) && $p['nombre'] !== '') {
        $st = $pdo->prepare('SELECT id FROM datarocket_etapas WHERE embudo_id = :b AND nombre = :n AND id <> :id LIMIT 1');
        $st->execute([':b' => $nuevoEmbudo, ':n' => $p['nombre'], ':id' => $id]);
        if ($st->fetch()) jsonError('Ya existe otra etapa con ese nombre en este embudo.', 409);
    }
    if (array_key_exists('orden', $body)) {
        $st = $pdo->prepare('SELECT id FROM datarocket_etapas WHERE embudo_id = :b AND orden = :o AND id <> :id LIMIT 1');
        $st->execute([':b' => $nuevoEmbudo, ':o' => $p['orden'], ':id' => $id]);
        if ($st->fetch()) jsonError('Ya existe otra etapa con ese orden en este embudo.', 409);
    }

    $sets   = [];
    $params = [':id' => $id];

    if (array_key_exists('embudo_id', $body) && $p['embudo_id'] > 0) {
        $sets[] = 'embudo_id = :embudo_id';
        $params[':embudo_id'] = $p['embudo_id'];
    }
    if (array_key_exists('nombre', $body) && $p['nombre'] !== '') {
        $sets[] = 'nombre = :nombre';
        $params[':nombre'] = $p['nombre'];
    }
    if (array_key_exists('orden', $body)) {
        $sets[] = 'orden = :orden';
        $params[':orden'] = $p['orden'];
    }
    if (array_key_exists('color', $body)) {
        $sets[] = 'color = :color';
        $params[':color'] = $p['color'];
    }
    if (array_key_exists('tipo', $body)) {
        $sets[] = 'tipo = :tipo';
        $params[':tipo'] = $p['tipo'];
    }
    if (array_key_exists('probabilidad', $body)) {
        $sets[] = 'probabilidad = :probabilidad';
        $params[':probabilidad'] = $p['probabilidad'];
    }

    if (empty($sets)) jsonError('No hay campos para actualizar.', 400);

    $sql = 'UPDATE datarocket_etapas SET ' . implode(', ', $sets) . ' WHERE id = :id';
    $st  = $pdo->prepare($sql);
    $st->execute($params);

    registrarSuceso($pdo, 'datarocket_etapas', 'info',
        "Modificacion etapa #{$id} \u{2014} \"{$prev['nombre']}\"");

    handleGetOneEtapa($pdo, $id);
}

function handleDeleteEtapa(PDO $pdo, int $id): void {
    $st = $pdo->prepare('SELECT nombre FROM datarocket_etapas WHERE id = :id LIMIT 1');
    $st->execute([':id' => $id]);
    $prev = $st->fetch();
    if (!$prev) jsonError('Etapa no encontrada', 404);

    // Chequeo explicito antes de la FK RESTRICT.
    $st = $pdo->prepare('SELECT COUNT(*) FROM datarocket_prospectos WHERE etapa_id = :id');
    $st->execute([':id' => $id]);
    $count = (int)$st->fetchColumn();
    if ($count > 0) {
        jsonError("No se puede eliminar la etapa: tiene {$count} prospecto(s) asignados. Movelos a otra etapa antes de eliminar.", 409);
    }

    $sd = $pdo->prepare('DELETE FROM datarocket_etapas WHERE id = :id');
    $sd->execute([':id' => $id]);

    registrarSuceso($pdo, 'datarocket_etapas', 'info',
        "Baja etapa #{$id} \u{2014} \"{$prev['nombre']}\"");

    jsonOk(['id' => $id]);
}
