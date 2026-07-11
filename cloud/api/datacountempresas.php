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
const DCE_ORDENES = ['id', 'nombre', 'razon', 'cuit', 'inicio'];
const DCE_COLS    = 'id, nombre, razon, domicilio, condicion, cuit, iibb, inicio, created_at, updated_at';

try {
    requirePermCrud('datacount.empresas');
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
// Helpers
// ----------------------------------------------------------------------------

function normalizarFila(array $r): array {
    return [
        'id'         => (int)($r['id'] ?? 0),
        'nombre'     => (string)($r['nombre'] ?? ''),
        'razon'      => (string)($r['razon'] ?? ''),
        'domicilio'  => $r['domicilio'] !== null ? (string)$r['domicilio'] : null,
        'condicion'  => (string)($r['condicion'] ?? ''),
        'cuit'       => $r['cuit'] !== null ? (string)$r['cuit'] : null,
        'iibb'       => $r['iibb'] !== null ? (string)$r['iibb'] : null,
        'inicio'     => $r['inicio'] !== null ? (string)$r['inicio'] : null,
        'created_at' => $r['created_at'] ?? null,
        'updated_at' => $r['updated_at'] ?? null,
    ];
}

function sanitizePayload(array $in, bool $esAlta): array {
    $nombre    = trim((string)($in['nombre']    ?? ''));
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

    return [
        'nombre'    => $nombre,
        'razon'     => $razon,
        'domicilio' => $domicilio === '' ? null : $domicilio,
        'condicion' => $condicion,
        'cuit'      => $cuit === '' ? null : $cuit,
        'iibb'      => $iibb === '' ? null : $iibb,
        'inicio'    => $inicio === '' ? null : $inicio,
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
        $where[] = '(nombre LIKE :s_nom OR razon LIKE :s_raz OR cuit LIKE :s_cui OR domicilio LIKE :s_dom)';
        $params[':s_nom'] = "%{$search}%";
        $params[':s_raz'] = "%{$search}%";
        $params[':s_cui'] = "%{$search}%";
        $params[':s_dom'] = "%{$search}%";
    }
    if ($condicion !== '' && in_array($condicion, DCE_CONDICIONES, true)) {
        $where[] = 'condicion = :condicion';
        $params[':condicion'] = $condicion;
    }

    $sqlWhere = $where ? ('WHERE ' . implode(' AND ', $where)) : '';
    $sql = 'SELECT ' . DCE_COLS . " FROM datacount_empresas {$sqlWhere} ORDER BY {$orden} {$dir} LIMIT {$limite}";
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
    $st = $pdo->prepare('SELECT ' . DCE_COLS . ' FROM datacount_empresas WHERE id = :id LIMIT 1');
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
                (nombre, razon, domicilio, condicion, cuit, iibb, inicio)
             VALUES
                (:nombre, :razon, :domicilio, :condicion, :cuit, :iibb, :inicio)'
        );
        $st->execute([
            ':nombre'    => $p['nombre'],
            ':razon'     => $p['razon'],
            ':domicilio' => $p['domicilio'],
            ':condicion' => $p['condicion'],
            ':cuit'      => $p['cuit'],
            ':iibb'      => $p['iibb'],
            ':inicio'    => $p['inicio'],
        ]);
    } catch (PDOException $e) {
        if ($e->getCode() === '23000') {
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
    $st = $pdo->prepare('SELECT ' . DCE_COLS . ' FROM datacount_empresas WHERE id = :id LIMIT 1');
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

    if (empty($sets)) jsonError('No hay campos para actualizar.', 400);

    try {
        $sql = 'UPDATE datacount_empresas SET ' . implode(', ', $sets) . ' WHERE id = :id';
        $st  = $pdo->prepare($sql);
        $st->execute($params);
    } catch (PDOException $e) {
        if ($e->getCode() === '23000') {
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
