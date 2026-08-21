<?php
// api/datarocketlistas.php
// ABM de listas Datarocket. Lee/escribe sobre la tabla `datarocket_listas`
// definida en db/schema.sql (creada por la migracion
// 20260811_1200_crear_datarocket_listas.sql; `slug` agregado por la
// 20260821_1000_datarocket_listas_agregar_slug.sql).
//   GET    api/datarocketlistas.php          -> listado con filtros (query string)
//   GET    api/datarocketlistas.php?id=N     -> registro individual
//   POST   api/datarocketlistas.php          -> alta (JSON body)
//   PUT    api/datarocketlistas.php?id=N     -> modificacion (JSON body)
//   DELETE api/datarocketlistas.php?id=N     -> baja
// Respuesta siempre {ok: true, data: ...} u {ok: false, error: '...'} (STACK.md sec. 10).

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/lib/auth_check.php';

const DR_LI_COLS = "id, proyecto_id, nombre, slug, descripcion, suscriptos";

header('Content-Type: application/json; charset=utf-8');

try {
    requirePermCrud('datarocket.listas');
    $pdo    = db();
    $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
    $id     = isset($_GET['id']) ? (int)$_GET['id'] : 0;

    if ($method === 'GET' && $id > 0) {
        handleGetOne($pdo, $id);
    } elseif ($method === 'GET') {
        handleList($pdo, $_GET);
    } elseif ($method === 'POST') {
        handleCreate($pdo, readJsonBody());
    } elseif ($method === 'PUT') {
        if ($id <= 0) jsonError('Falta id', 400);
        handleUpdate($pdo, $id, readJsonBody());
    } elseif ($method === 'DELETE') {
        if ($id <= 0) jsonError('Falta id', 400);
        handleDelete($pdo, $id);
    } else {
        jsonError('Metodo no soportado', 405);
    }
} catch (Throwable $e) {
    jsonError($e->getMessage(), 500);
}

// ----------------------------------------------------------------------------
// Listado y stats
// ----------------------------------------------------------------------------

function handleList(PDO $pdo, array $q): void {
    $codigo     = isset($q['codigo'])      && $q['codigo']      !== '' ? (int)$q['codigo']      : null;
    $proyectoId = isset($q['proyecto_id']) && $q['proyecto_id'] !== '' ? (int)$q['proyecto_id'] : null;
    $search     = trim((string)($q['q'] ?? ''));

    $orderBy = $q['order_by'] ?? 'id';
    $dir     = strtolower((string)($q['dir'] ?? 'desc'));
    $limite  = isset($q['limite']) ? (int)$q['limite'] : 100;
    if ($limite < 1)    $limite = 1;
    if ($limite > 1000) $limite = 1000;

    $allowedOrder = ['id', 'nombre', 'slug', 'proyecto_id', 'suscriptos'];
    if (!in_array($orderBy, $allowedOrder, true)) $orderBy = 'id';
    $dirSql = $dir === 'asc' ? 'ASC' : 'DESC';

    $where  = [];
    $params = [];

    if ($codigo     !== null) { $where[] = 'id = :codigo';               $params[':codigo']      = $codigo; }
    if ($proyectoId !== null) { $where[] = 'proyecto_id = :proyecto_id'; $params[':proyecto_id'] = $proyectoId; }

    if ($search !== '') {
        $where[] = '(nombre LIKE :s1 OR slug LIKE :s2 OR descripcion LIKE :s3)';
        $like = "%{$search}%";
        $params[':s1'] = $like;
        $params[':s2'] = $like;
        $params[':s3'] = $like;
    }

    $sqlWhere = $where ? ('WHERE ' . implode(' AND ', $where)) : '';

    // Stats globales (ignoran filtros — son indicadores del recurso).
    $stats = $pdo->query("
        SELECT
            COUNT(*)                                                         AS total,
            SUM(CASE WHEN proyecto_id IS NOT NULL THEN 1 ELSE 0 END)         AS con_proyecto,
            COALESCE(SUM(CASE WHEN suscriptos IS NULL THEN 0 ELSE suscriptos END), 0) AS suscriptos_total
        FROM datarocket_listas
    ")->fetch();

    $sql = "
        SELECT " . DR_LI_COLS . "
        FROM datarocket_listas
        {$sqlWhere}
        ORDER BY {$orderBy} {$dirSql}
        LIMIT {$limite}
    ";
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $rows = $stmt->fetchAll();

    jsonOk([
        'stats' => [
            'total'           => (int)($stats['total']           ?? 0),
            'con_proyecto'    => (int)($stats['con_proyecto']    ?? 0),
            'suscriptos_total' => (int)($stats['suscriptos_total'] ?? 0),
        ],
        'items' => $rows,
    ]);
}

function handleGetOne(PDO $pdo, int $id): void {
    $stmt = $pdo->prepare("SELECT " . DR_LI_COLS . " FROM datarocket_listas WHERE id = :id");
    $stmt->execute([':id' => $id]);
    $row = $stmt->fetch();
    if (!$row) jsonError('Lista no encontrada', 404);
    jsonOk($row);
}

// ----------------------------------------------------------------------------
// Alta / Modificacion / Baja
// ----------------------------------------------------------------------------

function nullableStr(mixed $v, ?int $max = null): ?string {
    if ($v === null) return null;
    $s = trim((string)$v);
    if ($s === '') return null;
    if ($max !== null) $s = substr($s, 0, $max);
    return $s;
}

function nullableInt(mixed $v): ?int {
    if ($v === null || $v === '') return null;
    return (int)$v;
}

// Normaliza un string a un slug kebab-case: [a-z0-9-]+, sin acentos, sin
// caracteres raros, sin guiones al borde, colapsando corridas de separadores.
// Se usa como fallback cuando el operador no llena el campo `slug` a mano.
// Espejo JS en app.js (`drliSlugify`) y en la migracion 20260821_1000.
function drliSlugify(string $s): string {
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

function sanitizePayload(array $in): array {
    $nombre = nullableStr($in['nombre'] ?? null, 255);

    // `slug` es NOT NULL en la DB. Si el operador no lo carga, se deriva del
    // nombre (mismo criterio que datarocket_embudos y datacount_empresas).
    $slug = strtolower(trim((string)($in['slug'] ?? '')));
    if ($slug === '' && $nombre !== null) $slug = drliSlugify($nombre);
    if ($slug !== '' && !preg_match('/^[a-z0-9]+(?:-[a-z0-9]+)*$/', $slug)) {
        jsonError('El slug solo admite minusculas, digitos y guiones (kebab-case).', 400);
    }
    if (strlen($slug) > 40) {
        jsonError('El slug no puede superar los 40 caracteres.', 400);
    }

    return [
        'proyecto_id' => nullableInt($in['proyecto_id'] ?? null),
        'nombre'      => $nombre,
        'slug'        => $slug,
        'descripcion' => nullableStr($in['descripcion'] ?? null, 500),
    ];
}

// Chequeo amigable del UNIQUE (proyecto_id, slug) antes de que MySQL tire el
// error crudo. `$excluirId` es la propia lista en un update (0 en el alta).
//
// OJO: con `proyecto_id IS NULL` el UNIQUE de la DB no restringe nada (cada
// NULL es distinto en un indice unico), asi que aca tampoco chequeamos —
// no hay un alcance definido contra el cual comparar.
function drLiValidarSlugUnico(PDO $pdo, ?int $proyectoId, string $slug, int $excluirId = 0): void {
    if ($proyectoId === null) return;
    $st = $pdo->prepare(
        'SELECT id FROM datarocket_listas
          WHERE proyecto_id = :p AND slug = :s AND id <> :id LIMIT 1'
    );
    $st->execute([':p' => $proyectoId, ':s' => $slug, ':id' => $excluirId]);
    if ($st->fetch()) {
        jsonError('Ya existe otra lista con ese slug en el proyecto seleccionado.', 409);
    }
}

function handleCreate(PDO $pdo, array $in): void {
    $p = sanitizePayload($in);
    if ($p['nombre'] === null) jsonError('El nombre es obligatorio', 400);
    if ($p['slug'] === '') {
        jsonError('No se pudo derivar un slug a partir del nombre. Cargalo manualmente.', 400);
    }
    drLiValidarSlugUnico($pdo, $p['proyecto_id'], $p['slug']);

    // `suscriptos` no se toca desde el ABM (lo recalcula el motor); queda
    // con el DEFAULT NULL de la columna al crear una lista nueva.
    $sql = "
        INSERT INTO datarocket_listas
            (proyecto_id, nombre, slug, descripcion)
        VALUES
            (:proyecto_id, :nombre, :slug, :descripcion)
    ";
    $stmt = $pdo->prepare($sql);
    $stmt->execute([
        ':proyecto_id' => $p['proyecto_id'],
        ':nombre'      => $p['nombre'],
        ':slug'        => $p['slug'],
        ':descripcion' => $p['descripcion'],
    ]);
    jsonOk(['id' => (int)$pdo->lastInsertId()], 201);
}

function handleUpdate(PDO $pdo, int $id, array $in): void {
    $exists = $pdo->prepare('SELECT id, slug FROM datarocket_listas WHERE id = :id');
    $exists->execute([':id' => $id]);
    $prev = $exists->fetch();
    if (!$prev) jsonError('Lista no encontrada', 404);

    // El `slug` es un identificador estable: si el cliente no lo manda, se
    // conserva el actual en vez de re-derivarlo del nombre (re-derivarlo
    // romperia las referencias externas, que es justo lo que el slug evita).
    // El ABM del panel siempre lo envia; esto cubre a otros consumidores.
    if (!array_key_exists('slug', $in)) $in['slug'] = (string)$prev['slug'];

    $p = sanitizePayload($in);
    if ($p['nombre'] === null) jsonError('El nombre es obligatorio', 400);
    if ($p['slug'] === '') jsonError('El slug no puede quedar vacio.', 400);
    drLiValidarSlugUnico($pdo, $p['proyecto_id'], $p['slug'], $id);

    // `suscriptos` es un contador denormalizado que recalcula el motor
    // desde afuera; el ABM no lo edita, asi que no se toca en el UPDATE
    // (si lo incluyeramos con :suscriptos = NULL, pisariamos el valor real
    // que dejo el motor).
    $sql = "
        UPDATE datarocket_listas SET
            proyecto_id = :proyecto_id,
            nombre      = :nombre,
            slug        = :slug,
            descripcion = :descripcion
        WHERE id = :id
    ";
    $stmt = $pdo->prepare($sql);
    $stmt->execute([
        ':proyecto_id' => $p['proyecto_id'],
        ':nombre'      => $p['nombre'],
        ':slug'        => $p['slug'],
        ':descripcion' => $p['descripcion'],
        ':id'          => $id,
    ]);
    jsonOk(['id' => $id]);
}

function handleDelete(PDO $pdo, int $id): void {
    $stmt = $pdo->prepare('DELETE FROM datarocket_listas WHERE id = :id');
    $stmt->execute([':id' => $id]);
    if ($stmt->rowCount() === 0) jsonError('Lista no encontrada', 404);
    jsonOk(['id' => $id]);
}
