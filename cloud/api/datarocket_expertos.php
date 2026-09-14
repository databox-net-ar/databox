<?php
// api/datarocket_expertos.php
// Datarocket > Expertos (CRUD). Lee/escribe sobre la tabla
// `datarocket_expertos` — cada fila es UN EXPERTO: la personalidad con la que
// una IA responde las consultas de los interesados en un proyecto del grupo.
// La justificacion del modelo esta en la migracion
// 20260914_1000_datarocket_expertos_modulo.sql.
//
//   GET    api/datarocket_expertos.php[?q=..&proyecto=..&activo=..&limite=100&orden=id&dir=desc]
//                                              -> listado + stats (contexto recortado)
//   GET    api/datarocket_expertos.php?id=N     -> registro individual (contexto completo)
//   GET    api/datarocket_expertos.php?lookups=1-> catalogos para los <select>
//   POST   api/datarocket_expertos.php          -> alta (JSON body)
//   PUT    api/datarocket_expertos.php?id=N     -> modificacion (JSON body)
//   DELETE api/datarocket_expertos.php?id=N     -> baja
//
// CONTEXTO: es el prompt de sistema, en MARKDOWN CRUDO. Se guarda y se devuelve
// tal cual — sin convertir a HTML ni sanitizar — porque es lo que se le manda
// al modelo. El render a HTML lo hace el front (mdRender()), que escapa antes
// de formatear; mismo criterio que la vista Documentacion.
//
// El LISTADO no devuelve el contexto entero: puede pesar decenas de KB por
// fila y la tabla solo necesita saber si hay o no y cuanto ocupa. Manda
// `contexto_largo` (caracteres) y `contexto_extracto` (primeros 160). El texto
// completo sale unicamente del GET por id, que es lo que piden Consultar y
// Editar.
//
// Respuesta siempre {ok: true, data: ...} u {ok: false, error: '...'} (STACK.md sec. 10).

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/lib/auth_check.php';
require_once __DIR__ . '/lib/sucesos.php';

requireAuth();
header('Content-Type: application/json; charset=utf-8');

const DREX_TABLA   = 'datarocket_expertos';
const DREX_ORDENES = ['id', 'nombre', 'slug', 'activo', 'fecha_creacion', 'fecha_modificacion'];

// Tope del `contexto`. MEDIUMTEXT aguanta 16 MB; el limite de aca es de
// cordura — un prompt de sistema de mas de 200.000 caracteres no entra en la
// ventana de ningun modelo y lo mas probable es que sea un pegado accidental.
const DREX_CONTEXTO_MAX = 200000;

// Cuanto contexto viaja en el listado para la columna de vista rapida.
const DREX_EXTRACTO_LEN = 160;

try {
    requirePermCrud('datarocket.expertos');
    $pdo    = db();
    $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
    $id     = isset($_GET['id']) ? (int) $_GET['id'] : 0;

    if ($method === 'GET' && ($_GET['lookups'] ?? '') !== '') {
        handleLookupsExperto($pdo);
    } elseif ($method === 'GET' && $id > 0) {
        handleGetOneExperto($pdo, $id);
    } elseif ($method === 'GET') {
        handleListExpertos($pdo, $_GET);
    } elseif ($method === 'POST') {
        handleCreateExperto($pdo, readJsonBody());
    } elseif ($method === 'PUT') {
        if ($id <= 0) jsonError('Falta id', 400);
        handleUpdateExperto($pdo, $id, readJsonBody());
    } elseif ($method === 'DELETE') {
        if ($id <= 0) jsonError('Falta id', 400);
        handleDeleteExperto($pdo, $id);
    } else {
        jsonError('Método no soportado', 405);
    }
} catch (Throwable $e) {
    jsonError($e->getMessage(), 500);
}

// ----------------------------------------------------------------------------
// Helpers
// ----------------------------------------------------------------------------

// `$conContexto = false` (listado) manda solo el largo y un extracto;
// `true` (GET por id) manda el Markdown completo. En los dos casos viajan
// `contexto_largo` y `contexto_extracto`, asi el front pinta la misma columna
// venga la fila de donde venga.
function normalizarFilaExperto(array $r, bool $conContexto): array {
    $contexto = (string) ($r['contexto'] ?? '');

    // El extracto se arma sobre el texto con los saltos colapsados: el Markdown
    // crudo arranca casi siempre con un '# Titulo' seguido de linea en blanco y
    // un extracto que los conserve se ve como una celda vacia.
    $plano    = trim(preg_replace('/\s+/u', ' ', $contexto));
    $extracto = mb_substr($plano, 0, DREX_EXTRACTO_LEN);
    if (mb_strlen($plano) > DREX_EXTRACTO_LEN) $extracto .= '…';

    $out = [
        'id'                 => (int) ($r['id'] ?? 0),
        'proyecto_id'        => $r['proyecto_id'] !== null ? (int) $r['proyecto_id'] : null,
        'proyecto_nombre'    => isset($r['proyecto_nombre']) && $r['proyecto_nombre'] !== null
                                  ? (string) $r['proyecto_nombre'] : null,
        'nombre'             => (string) ($r['nombre'] ?? ''),
        'slug'               => (string) ($r['slug']   ?? ''),
        'contexto_largo'     => mb_strlen($contexto),
        'contexto_extracto'  => $extracto,
        'activo'             => (int) ($r['activo'] ?? 1),
        'fecha_creacion'     => $r['fecha_creacion']     ?? null,
        'fecha_modificacion' => $r['fecha_modificacion'] ?? null,
    ];

    if ($conContexto) $out['contexto'] = $contexto === '' ? null : $contexto;

    return $out;
}

// Normaliza un string a kebab-case: [a-z0-9-]+, sin acentos, sin guiones al
// borde, colapsando corridas de separadores. Espejo JS en app.js (`drexSlugify`).
// Mismo criterio que datarocket_listas / _embudos / _etiquetas / _redes_sociales.
function drexSlugify(string $s): string {
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
    return substr($s, 0, 60);
}

function drexIntOpt(mixed $v): ?int {
    if ($v === '' || $v === null || $v === false) return null;
    $n = (int) $v;
    return $n === 0 ? null : $n;
}

// Chequeo amigable del UNIQUE de `slug` antes de que MySQL tire el error crudo.
// El alcance es global (la tabla no agrupa por proyecto): el slug es lo que un
// canal de entrada usa para pedir "contestá con este experto", asi que dos
// proyectos no pueden compartirlo.
function drexValidarSlugUnico(PDO $pdo, string $slug, int $excluirId = 0): void {
    $st = $pdo->prepare('SELECT id FROM ' . DREX_TABLA . ' WHERE slug = :s AND id <> :id LIMIT 1');
    $st->execute([':s' => $slug, ':id' => $excluirId]);
    if ($st->fetch()) jsonError('Ya existe otro experto con ese slug.', 409);
}

function sanitizePayloadExperto(array $in, bool $esAlta): array {
    $nombre = trim((string) ($in['nombre'] ?? ''));
    if ($esAlta && $nombre === '') jsonError('El nombre es obligatorio.', 400);
    if ($nombre !== '' && mb_strlen($nombre) > 150) {
        jsonError('El nombre no puede superar los 150 caracteres.', 400);
    }

    // `slug` es NOT NULL. Si el operador no lo carga, se deriva del nombre —
    // mismo criterio que listas, embudos, etiquetas y redes sociales.
    $slug = strtolower(trim((string) ($in['slug'] ?? '')));
    if ($slug === '' && $nombre !== '') $slug = drexSlugify($nombre);
    if ($slug !== '' && !preg_match('/^[a-z0-9]+(?:-[a-z0-9]+)*$/', $slug)) {
        jsonError('El slug solo admite minúsculas, dígitos y guiones (kebab-case).', 400);
    }
    if (strlen($slug) > 60) jsonError('El slug no puede superar los 60 caracteres.', 400);

    // El contexto se guarda TAL CUAL: es Markdown que se le manda al modelo, no
    // markup que el navegador vaya a ejecutar. Lo unico que se toca son los
    // saltos de linea de Windows, para que el prompt no llegue con \r sueltos.
    $contexto = null;
    if (array_key_exists('contexto', $in) && $in['contexto'] !== null) {
        $contexto = str_replace("\r\n", "\n", (string) $in['contexto']);
        $contexto = rtrim($contexto);
        if (mb_strlen($contexto) > DREX_CONTEXTO_MAX) {
            jsonError('El contexto no puede superar los ' . number_format(DREX_CONTEXTO_MAX, 0, ',', '.') . ' caracteres.', 400);
        }
        if ($contexto === '') $contexto = null;
    }

    return [
        'proyecto_id' => drexIntOpt($in['proyecto_id'] ?? null),
        'nombre'      => $nombre === '' ? null : $nombre,
        'slug'        => $slug,
        'contexto'    => $contexto,
        'activo'      => array_key_exists('activo', $in) ? (int) (bool) $in['activo'] : null,
    ];
}

// ----------------------------------------------------------------------------
// Handlers
// ----------------------------------------------------------------------------

// El LEFT JOIN con `proyectos` trae el nombre del proyecto, para que el listado
// no tenga que pedir el catalogo aparte ni el front resolverlo fila por fila.
function drexFrom(): string {
    return DREX_TABLA . ' x LEFT JOIN proyectos pr ON pr.id = x.proyecto_id';
}

function handleListExpertos(PDO $pdo, array $q): void {
    $search   = trim((string) ($q['q']        ?? ''));
    $proyecto = trim((string) ($q['proyecto'] ?? ''));
    $activo   = trim((string) ($q['activo']   ?? ''));
    $limite   = max(1, min(1000, (int) ($q['limite'] ?? 100)));
    $orden    = in_array(($q['orden'] ?? ''), DREX_ORDENES, true) ? $q['orden'] : 'id';
    $dir      = strtolower((string) ($q['dir'] ?? 'desc')) === 'asc' ? 'ASC' : 'DESC';

    $where  = [];
    $params = [];

    // El LIKE va sobre columnas utf8mb4_general_ci, que pliega mayusculas y
    // acentos: "databox" matchea "Databox" y "tecnico" matchea "Técnico". El
    // contexto entra en la busqueda a proposito: encontrar "el experto que
    // menciona tal producto" es la busqueda real de este modulo.
    if ($search !== '') {
        $where[] = '(x.nombre LIKE :s1 OR x.slug LIKE :s2 OR x.contexto LIKE :s3)';
        foreach (['s1', 's2', 's3'] as $k) $params[":{$k}"] = "%{$search}%";
    }
    if ($proyecto !== '' && ctype_digit($proyecto)) {
        $where[] = 'x.proyecto_id = :proyecto';
        $params[':proyecto'] = (int) $proyecto;
    }
    if ($activo !== '' && ctype_digit($activo)) {
        $where[] = 'x.activo = :activo';
        $params[':activo'] = (int) $activo;
    }

    $sqlWhere = $where ? ('WHERE ' . implode(' AND ', $where)) : '';

    // Desempate por `nombre` para que el orden sea determinista cuando la
    // columna elegida empata (pasa siempre con `activo`).
    $desempate = $orden === 'nombre' ? '' : ', x.nombre ASC';

    $sql = 'SELECT x.id, x.proyecto_id, x.slug, x.nombre, x.contexto, x.activo,
                   x.fecha_creacion, x.fecha_modificacion, pr.nombre AS proyecto_nombre
              FROM ' . drexFrom() . " {$sqlWhere} ORDER BY x.{$orden} {$dir}{$desempate} LIMIT {$limite}";
    $st = $pdo->prepare($sql);
    $st->execute($params);
    $rows = array_map(fn($r) => normalizarFilaExperto($r, false), $st->fetchAll());

    $stats = [
        'total'         => (int) $pdo->query('SELECT COUNT(*) FROM ' . DREX_TABLA)->fetchColumn(),
        'activos'       => (int) $pdo->query('SELECT COUNT(*) FROM ' . DREX_TABLA . ' WHERE activo = 1')->fetchColumn(),
        'con_contexto'  => (int) $pdo->query('SELECT COUNT(*) FROM ' . DREX_TABLA . " WHERE contexto IS NOT NULL AND contexto <> ''")->fetchColumn(),
        'proyectos'     => (int) $pdo->query('SELECT COUNT(DISTINCT proyecto_id) FROM ' . DREX_TABLA . ' WHERE proyecto_id IS NOT NULL')->fetchColumn(),
    ];

    jsonOk(['items' => $rows, 'stats' => $stats]);
}

function handleGetOneExperto(PDO $pdo, int $id): void {
    $st = $pdo->prepare(
        'SELECT x.id, x.proyecto_id, x.slug, x.nombre, x.contexto, x.activo,
                x.fecha_creacion, x.fecha_modificacion, pr.nombre AS proyecto_nombre
           FROM ' . drexFrom() . ' WHERE x.id = :id LIMIT 1'
    );
    $st->execute([':id' => $id]);
    $row = $st->fetch();
    if (!$row) jsonError('Experto no encontrado', 404);
    jsonOk(normalizarFilaExperto($row, true));
}

// Catalogos para los <select> del formulario y del modal de filtros.
// Los proyectos se filtran por `tipo = 'I'` (internos): el experto representa a
// un producto del grupo frente a sus interesados, no a un cliente — mismo
// recorte que usan los embudos y las redes sociales de Datarocket.
function handleLookupsExperto(PDO $pdo): void {
    $proyectos = $pdo->query(
        "SELECT id, COALESCE(NULLIF(TRIM(nombre), ''), CONCAT('#', id)) AS nombre
           FROM proyectos WHERE tipo = 'I' ORDER BY nombre ASC, id ASC"
    )->fetchAll();

    jsonOk([
        'proyectos' => array_map(fn($r) => [
            'id'     => (int) $r['id'],
            'nombre' => (string) $r['nombre'],
        ], $proyectos),
    ]);
}

function handleCreateExperto(PDO $pdo, array $body): void {
    $p = sanitizePayloadExperto($body, true);

    if ($p['slug'] === '') {
        jsonError('No se pudo derivar un slug a partir del nombre. Cargalo manualmente.', 400);
    }
    drexValidarSlugUnico($pdo, $p['slug']);

    $st = $pdo->prepare(
        'INSERT INTO ' . DREX_TABLA . '
            (proyecto_id, slug, nombre, contexto, activo)
         VALUES
            (:proyecto_id, :slug, :nombre, :contexto, :activo)'
    );
    $st->execute([
        ':proyecto_id' => $p['proyecto_id'],
        ':slug'        => $p['slug'],
        ':nombre'      => $p['nombre'],
        ':contexto'    => $p['contexto'],
        ':activo'      => $p['activo'] ?? 1,
    ]);

    $id = (int) $pdo->lastInsertId();
    registrarSuceso($pdo, DREX_TABLA, 'info',
        "Alta experto #{$id} — \"{$p['nombre']}\" ({$p['slug']})");

    handleGetOneExperto($pdo, $id);
}

function handleUpdateExperto(PDO $pdo, int $id, array $body): void {
    $st = $pdo->prepare('SELECT id, nombre, slug FROM ' . DREX_TABLA . ' WHERE id = :id LIMIT 1');
    $st->execute([':id' => $id]);
    $prev = $st->fetch();
    if (!$prev) jsonError('Experto no encontrado', 404);

    // El update es parcial: solo se tocan las claves presentes en el body. El
    // ABM manda el formulario completo, pero el toggle Activar/Desactivar del
    // menu contextual manda unicamente `activo` y no debe pisar el resto con
    // nulls — y menos que menos borrar el contexto.
    $p = sanitizePayloadExperto($body, false);

    // El `slug` es un identificador estable: si el cliente no lo manda, se
    // conserva el actual en vez de re-derivarlo del nombre (re-derivarlo
    // romperia las referencias externas, que es justo lo que el slug evita).
    if (array_key_exists('slug', $body)) {
        if ($p['slug'] === '') jsonError('El slug no puede quedar vacío.', 400);
        if ($p['slug'] !== (string) $prev['slug']) drexValidarSlugUnico($pdo, $p['slug'], $id);
    }

    $sets   = [];
    $params = [':id' => $id];
    foreach (['proyecto_id', 'slug', 'nombre', 'contexto', 'activo'] as $c) {
        if (!array_key_exists($c, $body)) continue;
        // Columnas NOT NULL: si vienen vacías se ignoran en vez de romper.
        // `contexto` NO entra acá — vaciarlo es una edición legítima.
        if (in_array($c, ['nombre', 'slug', 'activo'], true) && $p[$c] === null) continue;
        $sets[]          = "{$c} = :{$c}";
        $params[":{$c}"] = $p[$c];
    }

    if (empty($sets)) jsonError('No hay campos para actualizar.', 400);

    $sql = 'UPDATE ' . DREX_TABLA . ' SET ' . implode(', ', $sets) . ' WHERE id = :id';
    $pdo->prepare($sql)->execute($params);

    registrarSuceso($pdo, DREX_TABLA, 'info',
        "Modificación experto #{$id} — \"{$prev['nombre']}\"");

    handleGetOneExperto($pdo, $id);
}

function handleDeleteExperto(PDO $pdo, int $id): void {
    $st = $pdo->prepare('SELECT nombre, slug FROM ' . DREX_TABLA . ' WHERE id = :id LIMIT 1');
    $st->execute([':id' => $id]);
    $prev = $st->fetch();
    if (!$prev) jsonError('Experto no encontrado', 404);

    $pdo->prepare('DELETE FROM ' . DREX_TABLA . ' WHERE id = :id')->execute([':id' => $id]);

    registrarSuceso($pdo, DREX_TABLA, 'info',
        "Baja experto #{$id} — \"{$prev['nombre']}\" ({$prev['slug']})");

    jsonOk(['id' => $id]);
}
