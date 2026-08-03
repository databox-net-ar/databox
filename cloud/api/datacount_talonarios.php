<?php
// api/datacount_talonarios.php
// Talonarios Datacount (CRUD). Lee/escribe sobre la tabla
// `datacount_talonarios` definida en db/schema.sql — cada fila representa
// un talonario de comprobantes (facturas, notas, recibos, etc.) asociado
// a una empresa Datacount, con su punto de venta, serie corriente, tipo
// de comprobante, marcas fiscales/de discriminacion y datos de branding
// (correo, web, fondo, terminos).
//
//   GET    api/datacount_talonarios.php[?q=...&proyecto=...&empresa=...&tipo=...&estado=...&limite=100&orden=id&dir=desc]
//                                       -> listado + stats
//   GET    api/datacount_talonarios.php?id=N
//                                       -> registro individual
//   GET    api/datacount_talonarios.php?lookups=1
//                                       -> catalogos (empresas, proyectos, tipos)
//   POST   api/datacount_talonarios.php     -> alta (JSON body)
//   PUT    api/datacount_talonarios.php?id=N
//                                       -> modificacion (JSON body)
//   DELETE api/datacount_talonarios.php?id=N
//                                       -> baja
//
// Respuesta siempre {ok: true, data: ...} u {ok: false, error: '...'} (STACK.md sec. 10).

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/lib/auth_check.php';
require_once __DIR__ . '/lib/sucesos.php';
require_once __DIR__ . '/lib/s3.php';

requireAuth();
header('Content-Type: application/json; charset=utf-8');

const DCT_ORDENES     = ['id', 'nombre', 'empresa', 'tipo', 'punto', 'serie', 'estado'];
const DCT_COLS        = 'id, nombre, proyecto, empresa, tipo, punto, serie, discriminar, fiscal, correo, web, fondo, terminos, estado';
const DCT_FONDO_PREFIX = 'datacount/talonarios/';

try {
    requirePermCrud('datacount.talonarios');
    $pdo    = db();
    $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
    $id     = isset($_GET['id']) ? (int)$_GET['id'] : 0;

    if ($method === 'GET' && ($_GET['lookups'] ?? '') !== '') {
        handleLookupsTalonarios($pdo);
    } elseif ($method === 'GET' && $id > 0) {
        handleGetOneTalonario($pdo, $id);
    } elseif ($method === 'GET') {
        handleListTalonarios($pdo, $_GET);
    } elseif ($method === 'POST') {
        handleCreateTalonario($pdo, readJsonBody());
    } elseif ($method === 'PUT') {
        if ($id <= 0) jsonError('Falta id', 400);
        handleUpdateTalonario($pdo, $id, readJsonBody());
    } elseif ($method === 'DELETE') {
        if ($id <= 0) jsonError('Falta id', 400);
        handleDeleteTalonario($pdo, $id);
    } else {
        jsonError('Método no soportado', 405);
    }
} catch (Throwable $e) {
    jsonError($e->getMessage(), 500);
}

// ----------------------------------------------------------------------------
// Helpers
// ----------------------------------------------------------------------------

function normalizarFilaTalonario(array $r): array {
    $fondo = $r['fondo'] !== null ? (string)$r['fondo'] : null;
    return [
        'id'          => (int)($r['id'] ?? 0),
        'nombre'      => $r['nombre']   !== null ? (string)$r['nombre']   : null,
        'proyecto'    => $r['proyecto'] !== null ? (int)$r['proyecto']    : null,
        'empresa'     => $r['empresa']  !== null ? (int)$r['empresa']     : null,
        'tipo'        => $r['tipo']     !== null ? (string)$r['tipo']     : null,
        'punto'       => $r['punto']    !== null ? (int)$r['punto']       : null,
        'serie'       => $r['serie']    !== null ? (int)$r['serie']       : null,
        'discriminar' => $r['discriminar'] !== null ? (string)$r['discriminar'] : null,
        'fiscal'      => $r['fiscal']   !== null ? (string)$r['fiscal']   : null,
        'correo'      => $r['correo']   !== null ? (string)$r['correo']   : null,
        'web'         => $r['web']      !== null ? (string)$r['web']      : null,
        'fondo'       => $fondo,
        'fondo_url'   => ($fondo !== null && $fondo !== '')
                            ? s3_public_url(DCT_FONDO_PREFIX . $fondo)
                            : null,
        'terminos'    => $r['terminos'] !== null ? (string)$r['terminos'] : null,
        'estado'      => $r['estado']   !== null ? (int)$r['estado']      : null,
    ];
}

function sanitizePayloadTalonario(array $in, bool $esAlta): array {
    $nombre      = trim((string)($in['nombre']      ?? ''));
    $tipo        = trim((string)($in['tipo']        ?? ''));
    $discriminar = trim((string)($in['discriminar'] ?? ''));
    $fiscal      = trim((string)($in['fiscal']      ?? ''));
    $correo      = trim((string)($in['correo']      ?? ''));
    $web         = trim((string)($in['web']         ?? ''));
    $fondo       = trim((string)($in['fondo']       ?? ''));
    $terminos    = trim((string)($in['terminos']    ?? ''));

    if ($esAlta) {
        if ($nombre === '') jsonError('El nombre es obligatorio.', 400);
    }

    if ($nombre   !== '' && mb_strlen($nombre)   > 255)  jsonError('El nombre no puede superar los 255 caracteres.', 400);
    if ($tipo     !== '' && mb_strlen($tipo)     > 2)    jsonError('El tipo no puede superar los 2 caracteres.', 400);
    if ($discriminar !== '' && !in_array($discriminar, ['0', '1'], true)) jsonError('Discriminar solo admite 0 (No) o 1 (Sí).', 400);
    if ($fiscal      !== '' && !in_array($fiscal,      ['0', '1'], true)) jsonError('Fiscal solo admite 0 (No) o 1 (Sí).', 400);
    if ($correo   !== '' && mb_strlen($correo)   > 100)  jsonError('El correo no puede superar los 100 caracteres.', 400);
    if ($web      !== '' && mb_strlen($web)      > 100)  jsonError('La web no puede superar los 100 caracteres.', 400);
    if ($fondo    !== '' && mb_strlen($fondo)    > 100)  jsonError('El fondo no puede superar los 100 caracteres.', 400);
    if ($terminos !== '' && mb_strlen($terminos) > 5000) jsonError('Los terminos no pueden superar los 5000 caracteres.', 400);

    if ($correo !== '' && !filter_var($correo, FILTER_VALIDATE_EMAIL)) {
        jsonError('El correo no es valido.', 400);
    }

    // Enteros opcionales — normalizamos '', null, 0 como null.
    $intOpt = function ($v) {
        if ($v === '' || $v === null || $v === false) return null;
        $n = (int)$v;
        return $n === 0 ? null : $n;
    };
    $proyecto = array_key_exists('proyecto', $in) ? $intOpt($in['proyecto']) : null;
    $empresa  = array_key_exists('empresa',  $in) ? $intOpt($in['empresa'])  : null;
    $punto    = array_key_exists('punto',    $in) ? $intOpt($in['punto'])    : null;
    $serie    = array_key_exists('serie',    $in) ? $intOpt($in['serie'])    : null;

    // Estado es smallint(1) — normalizamos a int o null.
    $estado = null;
    if (array_key_exists('estado', $in) && $in['estado'] !== '' && $in['estado'] !== null) {
        $estado = (int)$in['estado'];
    }

    return [
        'nombre'      => $nombre      === '' ? null : $nombre,
        'proyecto'    => $proyecto,
        'empresa'     => $empresa,
        'tipo'        => $tipo        === '' ? null : $tipo,
        'punto'       => $punto,
        'serie'       => $serie,
        'discriminar' => $discriminar === '' ? null : $discriminar,
        'fiscal'      => $fiscal      === '' ? null : $fiscal,
        'correo'      => $correo      === '' ? null : $correo,
        'web'         => $web         === '' ? null : $web,
        'fondo'       => $fondo       === '' ? null : $fondo,
        'terminos'    => $terminos    === '' ? null : $terminos,
        'estado'      => $estado,
    ];
}

// ----------------------------------------------------------------------------
// Handlers
// ----------------------------------------------------------------------------

function handleListTalonarios(PDO $pdo, array $q): void {
    $search   = trim((string)($q['q']        ?? ''));
    $proyecto = trim((string)($q['proyecto'] ?? ''));
    $empresa  = trim((string)($q['empresa']  ?? ''));
    $tipo     = trim((string)($q['tipo']     ?? ''));
    $estado   = trim((string)($q['estado']   ?? ''));
    $limite   = max(1, min(1000, (int)($q['limite'] ?? 100)));
    $orden    = in_array(($q['orden'] ?? ''), DCT_ORDENES, true) ? $q['orden'] : 'id';
    $dir      = strtolower((string)($q['dir'] ?? 'desc')) === 'asc' ? 'ASC' : 'DESC';

    $where  = [];
    $params = [];

    if ($search !== '') {
        $where[] = '(nombre LIKE :s_nom OR correo LIKE :s_cor OR web LIKE :s_web)';
        $params[':s_nom'] = "%{$search}%";
        $params[':s_cor'] = "%{$search}%";
        $params[':s_web'] = "%{$search}%";
    }
    if ($proyecto !== '' && ctype_digit($proyecto)) {
        $where[] = 'proyecto = :proyecto';
        $params[':proyecto'] = (int)$proyecto;
    }
    if ($empresa !== '' && ctype_digit($empresa)) {
        $where[] = 'empresa = :empresa';
        $params[':empresa'] = (int)$empresa;
    }
    if ($tipo !== '') {
        $where[] = 'tipo = :tipo';
        $params[':tipo'] = $tipo;
    }
    if ($estado !== '' && ctype_digit($estado)) {
        $where[] = 'estado = :estado';
        $params[':estado'] = (int)$estado;
    }

    $sqlWhere = $where ? ('WHERE ' . implode(' AND ', $where)) : '';
    $sql = 'SELECT ' . DCT_COLS . " FROM datacount_talonarios {$sqlWhere} ORDER BY {$orden} {$dir} LIMIT {$limite}";
    $st = $pdo->prepare($sql);
    $st->execute($params);
    $rows = array_map('normalizarFilaTalonario', $st->fetchAll());

    // Stats — total, activos (estado=1), inactivos (estado<>1 o null).
    $stats = [
        'total'     => (int)$pdo->query('SELECT COUNT(*) FROM datacount_talonarios')->fetchColumn(),
        'activos'   => (int)$pdo->query('SELECT COUNT(*) FROM datacount_talonarios WHERE estado = 1')->fetchColumn(),
        'inactivos' => (int)$pdo->query('SELECT COUNT(*) FROM datacount_talonarios WHERE estado IS NULL OR estado <> 1')->fetchColumn(),
    ];

    jsonOk(['items' => $rows, 'stats' => $stats]);
}

function handleGetOneTalonario(PDO $pdo, int $id): void {
    $st = $pdo->prepare('SELECT ' . DCT_COLS . ' FROM datacount_talonarios WHERE id = :id LIMIT 1');
    $st->execute([':id' => $id]);
    $row = $st->fetch();
    if (!$row) jsonError('Talonario no encontrado', 404);
    jsonOk(normalizarFilaTalonario($row));
}

// Catalogos para poblar los <select> del formulario y del modal de filtros:
// - empresas: catalogo de datacount_empresas (para asignar el talonario).
// - proyectos: catalogo de proyectos (opcional).
// - tipos: catalogo `estados` con campo=`datacount_talonario_tipo`.
function handleLookupsTalonarios(PDO $pdo): void {
    $empresas = $pdo->query(
        "SELECT id, COALESCE(NULLIF(TRIM(nombre), ''), CONCAT('#', id)) AS nombre
         FROM datacount_empresas
         ORDER BY nombre ASC, id ASC"
    )->fetchAll();

    $proyectos = $pdo->query(
        "SELECT id, COALESCE(NULLIF(TRIM(nombre), ''), CONCAT('#', id)) AS nombre
         FROM proyectos
         WHERE tipo = 'I'
         ORDER BY nombre ASC, id ASC"
    )->fetchAll();

    $tipos = $pdo->query(
        "SELECT valor, texto
         FROM estados
         WHERE campo = 'datacount_talonario_tipo'
         ORDER BY orden ASC, id ASC"
    )->fetchAll();

    $mapSimple = fn($rows) => array_map(fn($r) => [
        'id'     => (int)$r['id'],
        'nombre' => (string)$r['nombre'],
    ], $rows);

    jsonOk([
        'empresas'  => $mapSimple($empresas),
        'proyectos' => $mapSimple($proyectos),
        'tipos'     => array_map(fn($r) => [
            'valor' => (string)($r['valor'] ?? ''),
            'texto' => (string)($r['texto'] ?? ''),
        ], $tipos),
    ]);
}

function handleCreateTalonario(PDO $pdo, array $body): void {
    $p = sanitizePayloadTalonario($body, true);

    $st = $pdo->prepare(
        'INSERT INTO datacount_talonarios
            (nombre, proyecto, empresa, tipo, punto, serie, discriminar, fiscal, correo, web, fondo, terminos, estado)
         VALUES
            (:nombre, :proyecto, :empresa, :tipo, :punto, :serie, :discriminar, :fiscal, :correo, :web, :fondo, :terminos, :estado)'
    );
    $st->execute([
        ':nombre'      => $p['nombre'],
        ':proyecto'    => $p['proyecto'],
        ':empresa'     => $p['empresa'],
        ':tipo'        => $p['tipo'],
        ':punto'       => $p['punto'],
        ':serie'       => $p['serie'],
        ':discriminar' => $p['discriminar'],
        ':fiscal'      => $p['fiscal'],
        ':correo'      => $p['correo'],
        ':web'         => $p['web'],
        ':fondo'       => $p['fondo'],
        ':terminos'    => $p['terminos'],
        ':estado'      => $p['estado'],
    ]);

    $id = (int)$pdo->lastInsertId();
    registrarSuceso($pdo, 'datacount_talonarios', 'info',
        "Alta talonario #{$id} — \"{$p['nombre']}\"");

    handleGetOneTalonario($pdo, $id);
}

function handleUpdateTalonario(PDO $pdo, int $id, array $body): void {
    $st = $pdo->prepare('SELECT ' . DCT_COLS . ' FROM datacount_talonarios WHERE id = :id LIMIT 1');
    $st->execute([':id' => $id]);
    $prev = $st->fetch();
    if (!$prev) jsonError('Talonario no encontrado', 404);

    $p = sanitizePayloadTalonario($body, false);

    $sets   = [];
    $params = [':id' => $id];

    if (array_key_exists('nombre', $body) && $p['nombre'] !== null) {
        $sets[] = 'nombre = :nombre';
        $params[':nombre'] = $p['nombre'];
    }
    if (array_key_exists('proyecto', $body)) {
        $sets[] = 'proyecto = :proyecto';
        $params[':proyecto'] = $p['proyecto'];
    }
    if (array_key_exists('empresa', $body)) {
        $sets[] = 'empresa = :empresa';
        $params[':empresa'] = $p['empresa'];
    }
    if (array_key_exists('tipo', $body)) {
        $sets[] = 'tipo = :tipo';
        $params[':tipo'] = $p['tipo'];
    }
    if (array_key_exists('punto', $body)) {
        $sets[] = 'punto = :punto';
        $params[':punto'] = $p['punto'];
    }
    if (array_key_exists('serie', $body)) {
        $sets[] = 'serie = :serie';
        $params[':serie'] = $p['serie'];
    }
    if (array_key_exists('discriminar', $body)) {
        $sets[] = 'discriminar = :discriminar';
        $params[':discriminar'] = $p['discriminar'];
    }
    if (array_key_exists('fiscal', $body)) {
        $sets[] = 'fiscal = :fiscal';
        $params[':fiscal'] = $p['fiscal'];
    }
    if (array_key_exists('correo', $body)) {
        $sets[] = 'correo = :correo';
        $params[':correo'] = $p['correo'];
    }
    if (array_key_exists('web', $body)) {
        $sets[] = 'web = :web';
        $params[':web'] = $p['web'];
    }
    if (array_key_exists('fondo', $body)) {
        $sets[] = 'fondo = :fondo';
        $params[':fondo'] = $p['fondo'];
    }
    if (array_key_exists('terminos', $body)) {
        $sets[] = 'terminos = :terminos';
        $params[':terminos'] = $p['terminos'];
    }
    if (array_key_exists('estado', $body)) {
        $sets[] = 'estado = :estado';
        $params[':estado'] = $p['estado'];
    }

    if (empty($sets)) jsonError('No hay campos para actualizar.', 400);

    $sql = 'UPDATE datacount_talonarios SET ' . implode(', ', $sets) . ' WHERE id = :id';
    $st  = $pdo->prepare($sql);
    $st->execute($params);

    registrarSuceso($pdo, 'datacount_talonarios', 'info',
        "Modificación talonario #{$id} — \"{$prev['nombre']}\"");

    handleGetOneTalonario($pdo, $id);
}

function handleDeleteTalonario(PDO $pdo, int $id): void {
    $st = $pdo->prepare('SELECT nombre FROM datacount_talonarios WHERE id = :id LIMIT 1');
    $st->execute([':id' => $id]);
    $prev = $st->fetch();
    if (!$prev) jsonError('Talonario no encontrado', 404);

    $sd = $pdo->prepare('DELETE FROM datacount_talonarios WHERE id = :id');
    $sd->execute([':id' => $id]);

    registrarSuceso($pdo, 'datacount_talonarios', 'info',
        "Baja talonario #{$id} — \"{$prev['nombre']}\"");

    jsonOk(['id' => $id]);
}
