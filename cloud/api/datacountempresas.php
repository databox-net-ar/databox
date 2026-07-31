<?php
// api/datacountempresas.php
// Empresas Datacount (CRUD). Lee/escribe sobre la tabla
// `datacount_empresas` definida en db/schema.sql — cada fila representa
// una empresa para la cual Datacount lleva la contabilidad, con datos
// identificatorios y fiscales (nombre, razón social, domicilio,
// condición ante AFIP, CUIT, IIBB e inicio de actividades).
//
//   GET    api/datacountempresas.php[?q=...&condicion=...&limite=100&orden=id&dir=desc]
//                                       -> listado + stats por condición
//   GET    api/datacountempresas.php?id=N
//                                       -> registro individual
//   POST   api/datacountempresas.php     -> alta (JSON body)
//   PUT    api/datacountempresas.php?id=N
//                                       -> modificación (JSON body)
//   DELETE api/datacountempresas.php?id=N
//                                       -> baja
//
// Respuesta siempre {ok: true, data: ...} u {ok: false, error: '...'} (STACK.md sec. 10).

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/lib/auth_check.php';
require_once __DIR__ . '/lib/sucesos.php';

requireAuth();
header('Content-Type: application/json; charset=utf-8');

const DCE_CONDICIONES = [
    'responsable_inscripto',
    'monotributista',
    'exento',
    'consumidor_final',
    'no_responsable',
    'no_categorizado',
];
const DCE_ORDENES = ['id', 'nombre', 'slug', 'razon', 'cuit', 'inicio'];
// Columnas con alias `e.` porque el listado y el detalle hacen LEFT JOIN con
// `arca_certificados` para traer el nombre del certificado asociado.
const DCE_COLS    = 'e.id, e.nombre, e.slug, e.razon, e.domicilio, e.condicion, e.cuit, e.iibb, e.inicio, e.certificado_id, e.created_at, e.updated_at, c.nombre AS certificado_nombre';
const DCE_FROM    = 'datacount_empresas e LEFT JOIN arca_certificados c ON c.id = e.certificado_id';

try {
    requirePermCrud('datacount.empresas');
    $pdo    = db();
    $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
    $id     = isset($_GET['id']) ? (int)$_GET['id'] : 0;

    // Catalogo de certificados de Arca para el select del formulario
    // (mismo permiso — el operador que ya edita empresas puede ver los
    // nombres de los certificados disponibles).
    if ($method === 'GET' && ($_GET['listar'] ?? '') === 'certificados') {
        handleListarCertificados($pdo);
    } elseif ($method === 'GET' && $id > 0) {
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

function handleListarCertificados(PDO $pdo): void {
    $rows = $pdo->query(
        'SELECT id, nombre FROM arca_certificados ORDER BY nombre ASC, id ASC'
    )->fetchAll();
    $out  = array_map(fn($r) => [
        'id'     => (int)$r['id'],
        'nombre' => $r['nombre'] !== null ? (string)$r['nombre'] : '',
    ], $rows);
    jsonOk(['items' => $out]);
}

// ----------------------------------------------------------------------------
// Helpers
// ----------------------------------------------------------------------------

function normalizarFila(array $r): array {
    return [
        'id'                 => (int)($r['id'] ?? 0),
        'nombre'             => (string)($r['nombre'] ?? ''),
        'slug'               => (string)($r['slug']   ?? ''),
        'razon'              => (string)($r['razon']  ?? ''),
        'domicilio'          => $r['domicilio'] !== null ? (string)$r['domicilio'] : null,
        'condicion'          => (string)($r['condicion'] ?? ''),
        'cuit'               => $r['cuit'] !== null ? (string)$r['cuit'] : null,
        'iibb'               => $r['iibb'] !== null ? (string)$r['iibb'] : null,
        'inicio'             => $r['inicio'] !== null ? (string)$r['inicio'] : null,
        'certificado_id'     => $r['certificado_id'] !== null ? (int)$r['certificado_id'] : null,
        'certificado_nombre' => $r['certificado_nombre'] !== null ? (string)$r['certificado_nombre'] : null,
        'created_at'         => $r['created_at'] ?? null,
        'updated_at'         => $r['updated_at'] ?? null,
    ];
}

// Normaliza un string a un slug kebab-case: [a-z0-9-]+, sin acentos, sin
// caracteres raros, sin guiones al borde, colapsando corridas de separadores.
// Se usa como fallback cuando el operador no llena el campo `slug` a mano.
function dceSlugify(string $s): string {
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
    $s = strtolower($s);
    $s = preg_replace('/[^a-z0-9]+/', '-', $s);
    $s = trim($s, '-');
    return substr($s, 0, 40);
}

function sanitizePayload(array $in, bool $esAlta): array {
    $nombre    = trim((string)($in['nombre']    ?? ''));
    $slug      = trim((string)($in['slug']      ?? ''));
    $razon     = trim((string)($in['razon']     ?? ''));
    $domicilio = trim((string)($in['domicilio'] ?? ''));
    $condicion = trim((string)($in['condicion'] ?? ''));
    $cuit      = trim((string)($in['cuit']      ?? ''));
    $iibb      = trim((string)($in['iibb']      ?? ''));
    $inicio    = trim((string)($in['inicio']    ?? ''));

    if ($esAlta) {
        if ($nombre === '')    jsonError('El nombre es obligatorio.', 400);
        if ($razon === '')     jsonError('La razón social es obligatoria.', 400);
        if ($condicion === '') $condicion = 'responsable_inscripto';
        // Slug en alta: si vino vacio, derivarlo del nombre.
        if ($slug === '') $slug = dceSlugify($nombre);
        if ($slug === '') jsonError('No se pudo derivar un slug a partir del nombre. Cargalo manualmente.', 400);
    } else {
        // En update, si vino explicito pero vacio, error (no permitimos "borrar" el slug).
        if (array_key_exists('slug', $in) && $slug === '') {
            jsonError('El slug no puede quedar vacio.', 400);
        }
    }

    if ($slug !== '' && !preg_match('/^[a-z0-9]+(?:-[a-z0-9]+)*$/', $slug)) {
        jsonError('El slug solo admite minusculas, digitos y guiones (kebab-case).', 400);
    }
    if ($slug !== '' && strlen($slug) > 40) {
        jsonError('El slug no puede superar los 40 caracteres.', 400);
    }

    if ($nombre !== '' && strlen($nombre) > 160) {
        jsonError('El nombre no puede superar los 160 caracteres.', 400);
    }
    if ($razon !== '' && strlen($razon) > 200) {
        jsonError('La razón social no puede superar los 200 caracteres.', 400);
    }
    if ($domicilio !== '' && strlen($domicilio) > 255) {
        jsonError('El domicilio no puede superar los 255 caracteres.', 400);
    }
    if ($condicion !== '' && !in_array($condicion, DCE_CONDICIONES, true)) {
        jsonError('Condición fiscal inválida.', 400);
    }
    if ($cuit !== '') {
        $cuitDigits = preg_replace('/\D+/', '', $cuit);
        if (strlen($cuitDigits) !== 11) {
            jsonError('El CUIT debe tener 11 dígitos.', 400);
        }
        $cuit = $cuitDigits;
    }
    if ($iibb !== '' && strlen($iibb) > 30) {
        jsonError('El IIBB no puede superar los 30 caracteres.', 400);
    }
    if ($inicio !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $inicio)) {
        jsonError('La fecha de inicio debe estar en formato AAAA-MM-DD.', 400);
    }

    // certificado_id opcional; se acepta '', null, 0 como "sin certificado".
    $certificadoId = null;
    if (array_key_exists('certificado_id', $in)) {
        $raw = $in['certificado_id'];
        if ($raw !== '' && $raw !== null && $raw !== false) {
            $certificadoId = (int)$raw;
            if ($certificadoId <= 0) $certificadoId = null;
        }
    }

    return [
        'nombre'         => $nombre,
        'slug'           => $slug,
        'razon'          => $razon,
        'domicilio'      => $domicilio === '' ? null : $domicilio,
        'condicion'      => $condicion,
        'cuit'           => $cuit === '' ? null : $cuit,
        'iibb'           => $iibb === '' ? null : $iibb,
        'inicio'         => $inicio === '' ? null : $inicio,
        'certificado_id' => $certificadoId,
    ];
}

// ----------------------------------------------------------------------------
// Handlers
// ----------------------------------------------------------------------------

function handleList(PDO $pdo, array $q): void {
    $search    = trim((string)($q['q'] ?? ''));
    $condicion = trim((string)($q['condicion'] ?? ''));
    $limite    = max(1, min(1000, (int)($q['limite'] ?? 100)));
    $orden     = in_array(($q['orden'] ?? ''), DCE_ORDENES, true) ? $q['orden'] : 'id';
    $dir       = strtolower((string)($q['dir'] ?? 'desc')) === 'asc' ? 'ASC' : 'DESC';

    $where  = [];
    $params = [];

    if ($search !== '') {
        // EMULATE_PREPARES=false → placeholders distintos por columna.
        $where[] = '(e.nombre LIKE :s_nom OR e.slug LIKE :s_slg OR e.razon LIKE :s_raz OR e.cuit LIKE :s_cui OR e.domicilio LIKE :s_dom)';
        $params[':s_nom'] = "%{$search}%";
        $params[':s_slg'] = "%{$search}%";
        $params[':s_raz'] = "%{$search}%";
        $params[':s_cui'] = "%{$search}%";
        $params[':s_dom'] = "%{$search}%";
    }
    if ($condicion !== '' && in_array($condicion, DCE_CONDICIONES, true)) {
        $where[] = 'e.condicion = :condicion';
        $params[':condicion'] = $condicion;
    }

    $sqlWhere = $where ? ('WHERE ' . implode(' AND ', $where)) : '';
    $ordenCol = 'e.' . $orden;
    $sql = 'SELECT ' . DCE_COLS . ' FROM ' . DCE_FROM . " {$sqlWhere} ORDER BY {$ordenCol} {$dir} LIMIT {$limite}";
    $st = $pdo->prepare($sql);
    $st->execute($params);
    $rows = array_map('normalizarFila', $st->fetchAll());

    // Stats por condición (ignoran filtros — indicadores del recurso completo).
    $stats = [];
    foreach (DCE_CONDICIONES as $c) {
        $s = $pdo->prepare('SELECT COUNT(*) FROM datacount_empresas WHERE condicion = :c');
        $s->execute([':c' => $c]);
        $stats[$c] = (int)$s->fetchColumn();
    }
    $stats['total'] = (int)$pdo->query('SELECT COUNT(*) FROM datacount_empresas')->fetchColumn();

    jsonOk(['items' => $rows, 'stats' => $stats]);
}

function handleGetOne(PDO $pdo, int $id): void {
    $st = $pdo->prepare('SELECT ' . DCE_COLS . ' FROM ' . DCE_FROM . ' WHERE e.id = :id LIMIT 1');
    $st->execute([':id' => $id]);
    $row = $st->fetch();
    if (!$row) jsonError('Empresa no encontrada', 404);
    jsonOk(normalizarFila($row));
}

function handleCreate(PDO $pdo, array $body): void {
    $p = sanitizePayload($body, true);

    try {
        $st = $pdo->prepare(
            'INSERT INTO datacount_empresas
                (nombre, slug, razon, domicilio, condicion, cuit, iibb, inicio, certificado_id)
             VALUES
                (:nombre, :slug, :razon, :domicilio, :condicion, :cuit, :iibb, :inicio, :certificado_id)'
        );
        $st->execute([
            ':nombre'         => $p['nombre'],
            ':slug'           => $p['slug'],
            ':razon'          => $p['razon'],
            ':domicilio'      => $p['domicilio'],
            ':condicion'      => $p['condicion'],
            ':cuit'           => $p['cuit'],
            ':iibb'           => $p['iibb'],
            ':inicio'         => $p['inicio'],
            ':certificado_id' => $p['certificado_id'],
        ]);
    } catch (PDOException $e) {
        if ($e->getCode() === '23000') {
            // Puede colisionar por razon social o por slug; el mensaje del driver
            // lleva el nombre del indice si el operador lo necesita depurar.
            $msg = $e->getMessage();
            if (stripos($msg, 'uk_datacount_empresas_slug') !== false) {
                jsonError('Ya existe una empresa con ese slug.', 409);
            }
            jsonError('Ya existe una empresa con esa razón social.', 409);
        }
        throw $e;
    }

    $id = (int)$pdo->lastInsertId();
    registrarSuceso($pdo, 'datacountempresas', 'info',
        "Alta empresa #{$id} — {$p['nombre']} ({$p['razon']})");

    handleGetOne($pdo, $id);
}

function handleUpdate(PDO $pdo, int $id, array $body): void {
    $st = $pdo->prepare('SELECT ' . DCE_COLS . ' FROM ' . DCE_FROM . ' WHERE e.id = :id LIMIT 1');
    $st->execute([':id' => $id]);
    $prev = $st->fetch();
    if (!$prev) jsonError('Empresa no encontrada', 404);

    $p = sanitizePayload($body, false);

    $sets   = [];
    $params = [':id' => $id];

    if (array_key_exists('nombre', $body) && $p['nombre'] !== '') {
        $sets[] = 'nombre = :nombre';
        $params[':nombre'] = $p['nombre'];
    }
    if (array_key_exists('slug', $body) && $p['slug'] !== '') {
        $sets[] = 'slug = :slug';
        $params[':slug'] = $p['slug'];
    }
    if (array_key_exists('razon', $body) && $p['razon'] !== '') {
        $sets[] = 'razon = :razon';
        $params[':razon'] = $p['razon'];
    }
    if (array_key_exists('domicilio', $body)) {
        $sets[] = 'domicilio = :domicilio';
        $params[':domicilio'] = $p['domicilio'];
    }
    if (array_key_exists('condicion', $body) && $p['condicion'] !== '') {
        $sets[] = 'condicion = :condicion';
        $params[':condicion'] = $p['condicion'];
    }
    if (array_key_exists('cuit', $body)) {
        $sets[] = 'cuit = :cuit';
        $params[':cuit'] = $p['cuit'];
    }
    if (array_key_exists('iibb', $body)) {
        $sets[] = 'iibb = :iibb';
        $params[':iibb'] = $p['iibb'];
    }
    if (array_key_exists('inicio', $body)) {
        $sets[] = 'inicio = :inicio';
        $params[':inicio'] = $p['inicio'];
    }
    if (array_key_exists('certificado_id', $body)) {
        $sets[] = 'certificado_id = :certificado_id';
        $params[':certificado_id'] = $p['certificado_id'];
    }

    if (empty($sets)) jsonError('No hay campos para actualizar.', 400);

    try {
        $sql = 'UPDATE datacount_empresas SET ' . implode(', ', $sets) . ' WHERE id = :id';
        $st  = $pdo->prepare($sql);
        $st->execute($params);
    } catch (PDOException $e) {
        if ($e->getCode() === '23000') {
            $msg = $e->getMessage();
            if (stripos($msg, 'uk_datacount_empresas_slug') !== false) {
                jsonError('Ya existe una empresa con ese slug.', 409);
            }
            jsonError('Ya existe una empresa con esa razón social.', 409);
        }
        throw $e;
    }

    registrarSuceso($pdo, 'datacountempresas', 'info',
        "Modificación empresa #{$id} — {$prev['nombre']} ({$prev['razon']})");

    handleGetOne($pdo, $id);
}

function handleDelete(PDO $pdo, int $id): void {
    $st = $pdo->prepare('SELECT nombre, razon FROM datacount_empresas WHERE id = :id LIMIT 1');
    $st->execute([':id' => $id]);
    $prev = $st->fetch();
    if (!$prev) jsonError('Empresa no encontrada', 404);

    $sd = $pdo->prepare('DELETE FROM datacount_empresas WHERE id = :id');
    $sd->execute([':id' => $id]);

    registrarSuceso($pdo, 'datacountempresas', 'info',
        "Baja empresa #{$id} — {$prev['nombre']} ({$prev['razon']})");

    jsonOk(['id' => $id]);
}
