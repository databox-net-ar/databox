<?php
// api/datarocket_etiquetas.php
// Etiquetas Datarocket (CRUD). Lee/escribe sobre la tabla
// `datarocket_etiquetas` — catalogo de etiquetas reutilizables que se aplican
// a otros recursos del stack Datarocket via tablas de union (por ahora solo
// `datarocket_prospectos_etiquetas`). El campo `slug` (identificador estable
// en kebab-case, UNIQUE global) lo agrego la migracion
// 20260821_1100_datarocket_etiquetas_agregar_slug.sql.
//
//   GET    api/datarocket_etiquetas.php[?q=...&limite=100&orden=id&dir=desc]
//                                          -> listado + stats (incluye
//                                             `etiquetados` = contador
//                                             denormalizado de prospectos
//                                             con la etiqueta)
//   GET    api/datarocket_etiquetas.php?id=N
//                                          -> registro individual
//   POST   api/datarocket_etiquetas.php     -> alta (JSON body)
//   POST   api/datarocket_etiquetas.php?action=recalcular
//                                          -> recomputa `etiquetados` para
//                                             TODAS las filas desde la
//                                             tabla puente. Requiere permiso
//                                             `datarocket.etiquetas.editar`.
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

// Columnas persistidas + `etiquetados` (contador denormalizado, sync manual
// via ?action=recalcular). Sirven tanto para SELECT como para el orden.
const DRE_COLS    = 'id, nombre, slug, descripcion, etiquetados, fecha_creacion, fecha_modificacion';
const DRE_ORDENES = ['id', 'nombre', 'slug', 'etiquetados', 'fecha_creacion', 'fecha_modificacion'];

try {
    $pdo    = db();
    $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
    $id     = isset($_GET['id']) ? (int)$_GET['id'] : 0;
    $action = (string)($_GET['action'] ?? '');

    // Accion "recalcular" (POST + ?action=recalcular) es una modificacion
    // masiva — se rige por el permiso .editar (no .agregar del POST default).
    if ($method === 'POST' && $action === 'recalcular') {
        requirePermission('datarocket.etiquetas.editar');
        handleRecalcularEtiquetados($pdo);
        return;
    }

    requirePermCrud('datarocket.etiquetas');

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
        'slug'               => (string)($r['slug'] ?? ''),
        'descripcion'        => $r['descripcion'] !== null ? (string)$r['descripcion'] : null,
        'etiquetados'        => (int)($r['etiquetados'] ?? 0),
        'fecha_creacion'     => $r['fecha_creacion']     ?? null,
        'fecha_modificacion' => $r['fecha_modificacion'] ?? null,
    ];
}

// Normaliza un string a un slug kebab-case: [a-z0-9-]+, sin acentos, sin
// caracteres raros, sin guiones al borde, colapsando corridas de separadores.
// Se usa como fallback cuando el operador no llena el campo `slug` a mano.
// Espejo JS en app.js (`dreSlugify`) y en la migracion 20260821_1100.
function dreSlugify(string $s): string {
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

    // `slug` es NOT NULL en la DB (migracion 20260821_1100). Si el operador no
    // lo carga, se deriva del nombre — mismo criterio que datarocket_listas y
    // datarocket_embudos.
    $slug = strtolower(trim((string)($in['slug'] ?? '')));
    if ($slug === '' && $nombre !== '') $slug = dreSlugify($nombre);
    if ($slug !== '' && !preg_match('/^[a-z0-9]+(?:-[a-z0-9]+)*$/', $slug)) {
        jsonError('El slug solo admite minusculas, digitos y guiones (kebab-case).', 400);
    }
    if (strlen($slug) > 40) {
        jsonError('El slug no puede superar los 40 caracteres.', 400);
    }

    return [
        'nombre'      => $nombre,
        'slug'        => $slug,
        'descripcion' => $descripcion === '' ? null : $descripcion,
    ];
}

// Chequeo amigable del UNIQUE de `slug` antes de que MySQL tire el error crudo.
// A diferencia de listas/embudos el alcance es global: esta tabla no tiene
// `proyecto_id`. `$excluirId` es la propia etiqueta en un update (0 en el alta).
function dreValidarSlugUnico(PDO $pdo, string $slug, int $excluirId = 0): void {
    $st = $pdo->prepare('SELECT id FROM datarocket_etiquetas WHERE slug = :s AND id <> :id LIMIT 1');
    $st->execute([':s' => $slug, ':id' => $excluirId]);
    if ($st->fetch()) jsonError('Ya existe otra etiqueta con ese slug.', 409);
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
        $where[] = '(e.nombre LIKE :s_nom OR e.slug LIKE :s_slug OR e.descripcion LIKE :s_desc)';
        $params[':s_nom']  = "%{$search}%";
        $params[':s_slug'] = "%{$search}%";
        $params[':s_desc'] = "%{$search}%";
    }

    $sqlWhere = $where ? ('WHERE ' . implode(' AND ', $where)) : '';

    // `etiquetados` es columna persistida — se sincroniza via
    // ?action=recalcular. Sin JOINs.
    $sql = 'SELECT ' . DRE_COLS . " FROM datarocket_etiquetas e {$sqlWhere} ORDER BY {$orden} {$dir} LIMIT {$limite}";
    $st = $pdo->prepare($sql);
    $st->execute($params);
    $rows = array_map('normalizarFilaEtiqueta', $st->fetchAll());

    $stats = [
        'total' => (int)$pdo->query('SELECT COUNT(*) FROM datarocket_etiquetas')->fetchColumn(),
    ];

    jsonOk(['items' => $rows, 'stats' => $stats]);
}

function handleGetOneEtiqueta(PDO $pdo, int $id): void {
    $st = $pdo->prepare('SELECT ' . DRE_COLS . ' FROM datarocket_etiquetas e WHERE e.id = :id LIMIT 1');
    $st->execute([':id' => $id]);
    $row = $st->fetch();
    if (!$row) jsonError('Etiqueta no encontrada', 404);
    jsonOk(normalizarFilaEtiqueta($row));
}

// Recomputa `datarocket_etiquetas.etiquetados` para TODAS las filas desde la
// tabla puente `datarocket_prospectos_etiquetas`. Es la operacion que dispara
// el boton "Recalcular etiquetados" del ABM. Idempotente: correrlo dos veces
// no cambia los valores. LEFT JOIN + subquery agrupada asegura que las
// etiquetas sin prospectos asignados queden en 0 (no NULL).
function handleRecalcularEtiquetados(PDO $pdo): void {
    $st = $pdo->prepare('
        UPDATE datarocket_etiquetas e
     LEFT JOIN (
                SELECT etiqueta_id, COUNT(*) AS c
                  FROM datarocket_prospectos_etiquetas
                 GROUP BY etiqueta_id
              ) g ON g.etiqueta_id = e.id
           SET e.etiquetados = COALESCE(g.c, 0)
    ');
    $st->execute();
    $filas = (int)$pdo->query('SELECT COUNT(*) FROM datarocket_etiquetas')->fetchColumn();

    registrarSuceso($pdo, 'datarocket_etiquetas', 'info',
        "Recalculo masivo de etiquetados sobre {$filas} etiquetas");

    jsonOk(['recalculadas' => $filas]);
}

function handleCreateEtiqueta(PDO $pdo, array $body): void {
    $p = sanitizePayloadEtiqueta($body, true);

    $st = $pdo->prepare('SELECT id FROM datarocket_etiquetas WHERE nombre = :n LIMIT 1');
    $st->execute([':n' => $p['nombre']]);
    if ($st->fetch()) jsonError('Ya existe una etiqueta con ese nombre.', 409);

    if ($p['slug'] === '') {
        jsonError('No se pudo derivar un slug a partir del nombre. Cargalo manualmente.', 400);
    }
    dreValidarSlugUnico($pdo, $p['slug']);

    $st = $pdo->prepare(
        'INSERT INTO datarocket_etiquetas (nombre, slug, descripcion)
         VALUES (:nombre, :slug, :descripcion)'
    );
    $st->execute([
        ':nombre'      => $p['nombre'],
        ':slug'        => $p['slug'],
        ':descripcion' => $p['descripcion'],
    ]);

    $id = (int)$pdo->lastInsertId();
    registrarSuceso($pdo, 'datarocket_etiquetas', 'info',
        "Alta etiqueta #{$id} — \"{$p['nombre']}\"");

    handleGetOneEtiqueta($pdo, $id);
}

function handleUpdateEtiqueta(PDO $pdo, int $id, array $body): void {
    $st = $pdo->prepare('SELECT id, nombre, slug, descripcion FROM datarocket_etiquetas WHERE id = :id LIMIT 1');
    $st->execute([':id' => $id]);
    $prev = $st->fetch();
    if (!$prev) jsonError('Etiqueta no encontrada', 404);

    // El `slug` es un identificador estable: si el cliente no lo manda, se
    // conserva el actual en vez de re-derivarlo del nombre (re-derivarlo
    // romperia las referencias externas, que es justo lo que el slug evita).
    // El update es parcial — solo se tocan las claves presentes en el body.
    $p = sanitizePayloadEtiqueta($body, false);

    if (array_key_exists('nombre', $body) && $p['nombre'] !== '' && $p['nombre'] !== $prev['nombre']) {
        $st = $pdo->prepare('SELECT id FROM datarocket_etiquetas WHERE nombre = :n AND id <> :id LIMIT 1');
        $st->execute([':n' => $p['nombre'], ':id' => $id]);
        if ($st->fetch()) jsonError('Ya existe otra etiqueta con ese nombre.', 409);
    }

    if (array_key_exists('slug', $body)) {
        if ($p['slug'] === '') jsonError('El slug no puede quedar vacio.', 400);
        if ($p['slug'] !== (string)$prev['slug']) dreValidarSlugUnico($pdo, $p['slug'], $id);
    }

    $sets   = [];
    $params = [':id' => $id];

    if (array_key_exists('nombre', $body) && $p['nombre'] !== '') {
        $sets[] = 'nombre = :nombre';
        $params[':nombre'] = $p['nombre'];
    }
    if (array_key_exists('slug', $body)) {
        $sets[] = 'slug = :slug';
        $params[':slug'] = $p['slug'];
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
