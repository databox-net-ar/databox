<?php
// api/datarocket_embudos.php
// Embudos Datarocket (CRUD). Lee/escribe sobre la tabla `datarocket_embudos`
// definida en db/schema.sql — catalogo de pipelines de venta / captacion.
//
//   GET    api/datarocket_embudos.php[?q=...&activo=1&limite=100&orden=id&dir=desc]
//                                          -> listado + stats (incluye
//                                             `oportunidades_count` derivado de
//                                             datarocket_oportunidades.embudo_id)
//   GET    api/datarocket_embudos.php?id=N
//                                          -> registro individual
//   POST   api/datarocket_embudos.php      -> alta (JSON body)
//   PUT    api/datarocket_embudos.php?id=N -> modificacion (JSON body)
//   DELETE api/datarocket_embudos.php?id=N -> baja (bloqueada si tiene
//                                             oportunidades, la FK RESTRICT
//                                             ya lo impone a nivel DB)
//
// Respuesta siempre {ok: true, data: ...} u {ok: false, error: '...'} (STACK.md sec. 10).

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/lib/auth_check.php';
require_once __DIR__ . '/lib/sucesos.php';

requireAuth();
header('Content-Type: application/json; charset=utf-8');

const DREM_COLS    = 'e.id, e.proyecto_id, e.slug, e.nombre, e.descripcion, e.activo, e.fecha_creacion, e.fecha_modificacion';
const DREM_ORDENES = ['id', 'proyecto_id', 'slug', 'nombre', 'activo', 'fecha_creacion', 'fecha_modificacion'];

try {
    $pdo    = db();
    $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
    $id     = isset($_GET['id']) ? (int)$_GET['id'] : 0;

    requirePermCrud('datarocket.embudos');

    if ($method === 'GET' && $id > 0) {
        handleGetOneEmbudo($pdo, $id);
    } elseif ($method === 'GET') {
        handleListEmbudos($pdo, $_GET);
    } elseif ($method === 'POST') {
        handleCreateEmbudo($pdo, readJsonBody());
    } elseif ($method === 'PUT') {
        if ($id <= 0) jsonError('Falta id', 400);
        handleUpdateEmbudo($pdo, $id, readJsonBody());
    } elseif ($method === 'DELETE') {
        if ($id <= 0) jsonError('Falta id', 400);
        handleDeleteEmbudo($pdo, $id);
    } else {
        jsonError('Metodo no soportado', 405);
    }
} catch (Throwable $e) {
    jsonError($e->getMessage(), 500);
}

// ----------------------------------------------------------------------------
// Helpers
// ----------------------------------------------------------------------------

function normalizarFilaEmbudo(array $r): array {
    return [
        'id'                 => (int)($r['id'] ?? 0),
        'proyecto_id'        => isset($r['proyecto_id']) ? (int)$r['proyecto_id'] : null,
        'proyecto_nombre'    => $r['proyecto_nombre'] ?? null,
        'slug'               => (string)($r['slug'] ?? ''),
        'nombre'             => (string)($r['nombre'] ?? ''),
        'descripcion'        => $r['descripcion'] !== null ? (string)$r['descripcion'] : null,
        'activo'             => (int)($r['activo'] ?? 0),
        'oportunidades_count' => isset($r['oportunidades_count']) ? (int)$r['oportunidades_count'] : null,
        'etapas_count'       => isset($r['etapas_count'])     ? (int)$r['etapas_count']     : null,
        'fecha_creacion'     => $r['fecha_creacion']     ?? null,
        'fecha_modificacion' => $r['fecha_modificacion'] ?? null,
    ];
}

// Normaliza un string a un slug kebab-case: [a-z0-9-]+, sin acentos, sin
// caracteres raros, sin guiones al borde, colapsando corridas de separadores.
// Se usa como fallback cuando el operador no llena el campo `slug` a mano.
// Espejo JS en app.js (`dremSlugify`) y en la migracion 20260818_1400.
function dremSlugify(string $s): string {
    $s = trim($s);
    if ($s === '') return '';
    $pares = [
        'á'=>'a','é'=>'e','í'=>'i','ó'=>'o','ú'=>'u',
        'à'=>'a','è'=>'e','ì'=>'i','ò'=>'o','ù'=>'u',
        'ä'=>'a','ë'=>'e','ï'=>'i','ö'=>'o','ü'=>'u',
        'Á'=>'a','É'=>'e','Í'=>'i','Ó'=>'o','Ú'=>'u',
        'ñ'=>'n','Ñ'=>'n','ç'=>'c','Ç'=>'c',
    ];
    $s = strtr($s, $pares);
    $s = mb_strtolower($s);
    $s = preg_replace('/[^a-z0-9]+/', '-', $s);
    $s = trim($s, '-');
    return substr($s, 0, 40);
}

function sanitizePayloadEmbudo(array $in, bool $esAlta): array {
    $proyectoId  = isset($in['proyecto_id']) ? (int)$in['proyecto_id'] : 0;
    $slug        = strtolower(trim((string)($in['slug'] ?? '')));
    $nombre      = trim((string)($in['nombre']      ?? ''));
    $descripcion = trim((string)($in['descripcion'] ?? ''));
    $activo      = isset($in['activo']) ? (int)!!$in['activo'] : 1;

    if ($esAlta && $proyectoId <= 0) {
        jsonError('El proyecto es obligatorio.', 400);
    }
    if ($esAlta && $nombre === '') {
        jsonError('El nombre es obligatorio.', 400);
    }
    if ($esAlta) {
        // Slug en alta: si vino vacio, derivarlo del nombre.
        if ($slug === '') $slug = dremSlugify($nombre);
        if ($slug === '') jsonError('No se pudo derivar un slug a partir del nombre. Cargalo manualmente.', 400);
    } elseif (array_key_exists('slug', $in) && $slug === '') {
        // En update, si vino explicito pero vacio, error (no se puede "borrar").
        jsonError('El slug no puede quedar vacio.', 400);
    }
    if ($slug !== '' && !preg_match('/^[a-z0-9]+(?:-[a-z0-9]+)*$/', $slug)) {
        jsonError('El slug solo admite minusculas, digitos y guiones (kebab-case).', 400);
    }
    if ($slug !== '' && strlen($slug) > 40) {
        jsonError('El slug no puede superar los 40 caracteres.', 400);
    }
    if ($nombre !== '' && mb_strlen($nombre) > 80) {
        jsonError('El nombre no puede superar los 80 caracteres.', 400);
    }
    if ($descripcion !== '' && mb_strlen($descripcion) > 500) {
        jsonError('La descripcion no puede superar los 500 caracteres.', 400);
    }

    return [
        'proyecto_id' => $proyectoId,
        'slug'        => $slug,
        'nombre'      => $nombre,
        'descripcion' => $descripcion === '' ? null : $descripcion,
        'activo'      => $activo,
    ];
}

// Verifica que el proyecto exista; corta el request con jsonError si no.
function drEmValidarProyecto(PDO $pdo, int $proyectoId): void {
    $st = $pdo->prepare('SELECT id FROM proyectos WHERE id = :id LIMIT 1');
    $st->execute([':id' => $proyectoId]);
    if (!$st->fetch()) jsonError('El proyecto indicado no existe.', 400);
}

// ----------------------------------------------------------------------------
// Handlers
// ----------------------------------------------------------------------------

function handleListEmbudos(PDO $pdo, array $q): void {
    $search    = trim((string)($q['q'] ?? ''));
    $activo    = isset($q['activo'])      && $q['activo']      !== '' ? (int)!!$q['activo']    : null;
    $proyecto  = isset($q['proyecto_id']) && $q['proyecto_id'] !== '' ? (int)$q['proyecto_id'] : null;
    $limite    = max(1, min(1000, (int)($q['limite'] ?? 100)));
    $orden     = in_array(($q['orden'] ?? ''), DREM_ORDENES, true) ? $q['orden'] : 'id';
    $dir       = strtolower((string)($q['dir'] ?? 'desc')) === 'asc' ? 'ASC' : 'DESC';

    $where  = [];
    $params = [];

    if ($search !== '') {
        $where[] = '(e.slug LIKE :s_slug OR e.nombre LIKE :s_nom OR e.descripcion LIKE :s_desc)';
        $params[':s_slug'] = "%{$search}%";
        $params[':s_nom']  = "%{$search}%";
        $params[':s_desc'] = "%{$search}%";
    }
    if ($activo !== null) {
        $where[] = 'e.activo = :activo';
        $params[':activo'] = $activo;
    }
    if ($proyecto !== null) {
        $where[] = 'e.proyecto_id = :proyecto_id';
        $params[':proyecto_id'] = $proyecto;
    }

    $sqlWhere = $where ? ('WHERE ' . implode(' AND ', $where)) : '';

    // JOIN a subqueries para contar oportunidades y etapas por embudo + LEFT JOIN a
    // proyectos para resolver el nombre. Es cheap aunque no este cacheado (las
    // tablas son chicas: pocos embudos, decenas de oportunidades al principio, y
    // las etapas suelen ser 5-10 por embudo).
    $sql = 'SELECT ' . DREM_COLS . ",
                   p.nombre AS proyecto_nombre,
                   COALESCE(oc.c, 0) AS oportunidades_count,
                   COALESCE(et.c, 0) AS etapas_count
              FROM datarocket_embudos e
         LEFT JOIN proyectos p ON p.id = e.proyecto_id
         LEFT JOIN (SELECT embudo_id, COUNT(*) c FROM datarocket_oportunidades GROUP BY embudo_id) oc
                ON oc.embudo_id = e.id
         LEFT JOIN (SELECT embudo_id, COUNT(*) c FROM datarocket_etapas GROUP BY embudo_id) et
                ON et.embudo_id = e.id
             {$sqlWhere}
          ORDER BY {$orden} {$dir}
             LIMIT {$limite}";
    $st = $pdo->prepare($sql);
    $st->execute($params);
    $rows = array_map('normalizarFilaEmbudo', $st->fetchAll());

    $stats = [
        'total'   => (int)$pdo->query('SELECT COUNT(*) FROM datarocket_embudos')->fetchColumn(),
        'activos' => (int)$pdo->query('SELECT COUNT(*) FROM datarocket_embudos WHERE activo = 1')->fetchColumn(),
    ];

    jsonOk(['items' => $rows, 'stats' => $stats]);
}

function handleGetOneEmbudo(PDO $pdo, int $id): void {
    $st = $pdo->prepare('SELECT ' . DREM_COLS . ",
                                p.nombre AS proyecto_nombre,
                                COALESCE(oc.c, 0) AS oportunidades_count,
                                COALESCE(et.c, 0) AS etapas_count
                           FROM datarocket_embudos e
                      LEFT JOIN proyectos p ON p.id = e.proyecto_id
                      LEFT JOIN (SELECT embudo_id, COUNT(*) c FROM datarocket_oportunidades GROUP BY embudo_id) oc
                             ON oc.embudo_id = e.id
                      LEFT JOIN (SELECT embudo_id, COUNT(*) c FROM datarocket_etapas GROUP BY embudo_id) et
                             ON et.embudo_id = e.id
                          WHERE e.id = :id
                          LIMIT 1");
    $st->execute([':id' => $id]);
    $row = $st->fetch();
    if (!$row) jsonError('Embudo no encontrado', 404);
    jsonOk(normalizarFilaEmbudo($row));
}

function handleCreateEmbudo(PDO $pdo, array $body): void {
    $p = sanitizePayloadEmbudo($body, true);

    drEmValidarProyecto($pdo, $p['proyecto_id']);

    // UNIQUE (proyecto_id, nombre): dos embudos con el mismo nombre en el
    // mismo proyecto no se permiten (chequeo amigable antes del error crudo).
    $st = $pdo->prepare('SELECT id FROM datarocket_embudos WHERE proyecto_id = :p AND nombre = :n LIMIT 1');
    $st->execute([':p' => $p['proyecto_id'], ':n' => $p['nombre']]);
    if ($st->fetch()) jsonError('Ya existe un embudo con ese nombre en el proyecto seleccionado.', 409);

    // Mismo criterio para UNIQUE (proyecto_id, slug).
    $st = $pdo->prepare('SELECT id FROM datarocket_embudos WHERE proyecto_id = :p AND slug = :s LIMIT 1');
    $st->execute([':p' => $p['proyecto_id'], ':s' => $p['slug']]);
    if ($st->fetch()) jsonError('Ya existe un embudo con ese slug en el proyecto seleccionado.', 409);

    $st = $pdo->prepare(
        'INSERT INTO datarocket_embudos (proyecto_id, slug, nombre, descripcion, activo)
         VALUES (:proyecto_id, :slug, :nombre, :descripcion, :activo)'
    );
    $st->execute([
        ':proyecto_id' => $p['proyecto_id'],
        ':slug'        => $p['slug'],
        ':nombre'      => $p['nombre'],
        ':descripcion' => $p['descripcion'],
        ':activo'      => $p['activo'],
    ]);

    $id = (int)$pdo->lastInsertId();
    registrarSuceso($pdo, 'datarocket_embudos', 'info',
        "Alta embudo #{$id} \u{2014} \"{$p['nombre']}\" ({$p['slug']}, proyecto #{$p['proyecto_id']})");

    handleGetOneEmbudo($pdo, $id);
}

function handleUpdateEmbudo(PDO $pdo, int $id, array $body): void {
    $st = $pdo->prepare('SELECT id, proyecto_id, slug, nombre FROM datarocket_embudos WHERE id = :id LIMIT 1');
    $st->execute([':id' => $id]);
    $prev = $st->fetch();
    if (!$prev) jsonError('Embudo no encontrado', 404);

    $p = sanitizePayloadEmbudo($body, false);

    // Si viene proyecto_id, validar que exista.
    if (array_key_exists('proyecto_id', $body)) {
        if ($p['proyecto_id'] <= 0) jsonError('El proyecto es obligatorio.', 400);
        drEmValidarProyecto($pdo, $p['proyecto_id']);
    }

    // UNIQUE (proyecto_id, nombre) contra los valores finales tras aplicar el payload.
    $proyFinal   = array_key_exists('proyecto_id', $body) ? $p['proyecto_id'] : (int)$prev['proyecto_id'];
    $nombreFinal = array_key_exists('nombre',      $body) && $p['nombre'] !== '' ? $p['nombre'] : (string)$prev['nombre'];
    if ($proyFinal !== (int)$prev['proyecto_id'] || $nombreFinal !== (string)$prev['nombre']) {
        $st = $pdo->prepare('SELECT id FROM datarocket_embudos WHERE proyecto_id = :p AND nombre = :n AND id <> :id LIMIT 1');
        $st->execute([':p' => $proyFinal, ':n' => $nombreFinal, ':id' => $id]);
        if ($st->fetch()) jsonError('Ya existe otro embudo con ese nombre en el proyecto seleccionado.', 409);
    }

    // Idem para UNIQUE (proyecto_id, slug).
    $slugFinal = array_key_exists('slug', $body) && $p['slug'] !== '' ? $p['slug'] : (string)$prev['slug'];
    if ($proyFinal !== (int)$prev['proyecto_id'] || $slugFinal !== (string)$prev['slug']) {
        $st = $pdo->prepare('SELECT id FROM datarocket_embudos WHERE proyecto_id = :p AND slug = :s AND id <> :id LIMIT 1');
        $st->execute([':p' => $proyFinal, ':s' => $slugFinal, ':id' => $id]);
        if ($st->fetch()) jsonError('Ya existe otro embudo con ese slug en el proyecto seleccionado.', 409);
    }

    $sets   = [];
    $params = [':id' => $id];

    if (array_key_exists('proyecto_id', $body)) {
        $sets[] = 'proyecto_id = :proyecto_id';
        $params[':proyecto_id'] = $p['proyecto_id'];
    }
    if (array_key_exists('slug', $body) && $p['slug'] !== '') {
        $sets[] = 'slug = :slug';
        $params[':slug'] = $p['slug'];
    }
    if (array_key_exists('nombre', $body) && $p['nombre'] !== '') {
        $sets[] = 'nombre = :nombre';
        $params[':nombre'] = $p['nombre'];
    }
    if (array_key_exists('descripcion', $body)) {
        $sets[] = 'descripcion = :descripcion';
        $params[':descripcion'] = $p['descripcion'];
    }
    if (array_key_exists('activo', $body)) {
        $sets[] = 'activo = :activo';
        $params[':activo'] = $p['activo'];
    }

    if (empty($sets)) jsonError('No hay campos para actualizar.', 400);

    $sql = 'UPDATE datarocket_embudos SET ' . implode(', ', $sets) . ' WHERE id = :id';
    $st  = $pdo->prepare($sql);
    $st->execute($params);

    registrarSuceso($pdo, 'datarocket_embudos', 'info',
        "Modificacion embudo #{$id} \u{2014} \"{$prev['nombre']}\"");

    handleGetOneEmbudo($pdo, $id);
}

function handleDeleteEmbudo(PDO $pdo, int $id): void {
    $st = $pdo->prepare('SELECT nombre FROM datarocket_embudos WHERE id = :id LIMIT 1');
    $st->execute([':id' => $id]);
    $prev = $st->fetch();
    if (!$prev) jsonError('Embudo no encontrado', 404);

    // Chequeo explicito de dependencias para devolver un mensaje amigable
    // antes de que la FK RESTRICT tire el error crudo de MySQL.
    $st = $pdo->prepare('SELECT COUNT(*) FROM datarocket_oportunidades WHERE embudo_id = :id');
    $st->execute([':id' => $id]);
    $count = (int)$st->fetchColumn();
    if ($count > 0) {
        jsonError("No se puede eliminar el embudo: tiene {$count} oportunidad(es) asignada(s). Reasignalas antes de eliminar.", 409);
    }

    // Las etapas del embudo caen en cascada por la FK de datarocket_etapas.embudo_id.
    $sd = $pdo->prepare('DELETE FROM datarocket_embudos WHERE id = :id');
    $sd->execute([':id' => $id]);

    registrarSuceso($pdo, 'datarocket_embudos', 'info',
        "Baja embudo #{$id} \u{2014} \"{$prev['nombre']}\"");

    jsonOk(['id' => $id]);
}
