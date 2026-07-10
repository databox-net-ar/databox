<?php
// api/datacountrecurrentes.php
// Movimientos recurrentes Datacount (CRUD). Lee/escribe sobre la tabla
// `datacount_recurrentes` definida en db/schema.sql — cada fila representa
// un movimiento contable esperado que combina empresa + cuenta con montos
// previstos de ingreso y egreso y un flag `activo`.
//
//   GET    api/datacountrecurrentes.php[?q=...&empresa=N&cuenta=N&activo=0|1
//                                        &limite=100&orden=id&dir=desc]
//                                       -> listado + stats
//   GET    api/datacountrecurrentes.php?id=N
//                                       -> registro individual
//   POST   api/datacountrecurrentes.php  -> alta (JSON body)
//   PUT    api/datacountrecurrentes.php?id=N
//                                       -> modificación (JSON body)
//   DELETE api/datacountrecurrentes.php?id=N
//                                       -> baja
//
// Respuesta siempre {ok: true, data: ...} u {ok: false, error: '...'} (STACK.md sec. 10).

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/lib/auth_check.php';
require_once __DIR__ . '/lib/sucesos.php';

requireAuth();
header('Content-Type: application/json; charset=utf-8');

const DCR_ORDENES = ['id', 'nombre', 'empresa', 'cuenta', 'ingreso', 'egreso', 'activo'];
const DCR_COLS    = 'r.id, r.nombre, r.empresa, r.cuenta, r.ingreso, r.egreso, r.activo, r.created_at, r.updated_at';

try {
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
        jsonError('Método no soportado', 405);
    }
} catch (Throwable $e) {
    jsonError($e->getMessage(), 500);
}

// ----------------------------------------------------------------------------
// Helpers
// ----------------------------------------------------------------------------

function normalizarFila(array $r): array {
    return [
        'id'             => (int)($r['id'] ?? 0),
        'nombre'         => (string)($r['nombre'] ?? ''),
        'empresa'        => (int)($r['empresa'] ?? 0),
        'empresa_nombre' => $r['empresa_nombre'] ?? null,
        'cuenta'         => (int)($r['cuenta'] ?? 0),
        'cuenta_codigo'  => $r['cuenta_codigo'] ?? null,
        'cuenta_nombre'  => $r['cuenta_nombre'] ?? null,
        'ingreso'        => (float)($r['ingreso'] ?? 0),
        'egreso'         => (float)($r['egreso'] ?? 0),
        'activo'         => (int)($r['activo'] ?? 0),
        'created_at'     => $r['created_at'] ?? null,
        'updated_at'     => $r['updated_at'] ?? null,
    ];
}

function sanitizePayload(array $in, bool $esAlta): array {
    $nombre  = trim((string)($in['nombre'] ?? ''));
    if (mb_strlen($nombre) > 150) $nombre = mb_substr($nombre, 0, 150);
    $empresa = isset($in['empresa']) ? (int)$in['empresa'] : 0;
    $cuenta  = isset($in['cuenta'])  ? (int)$in['cuenta']  : 0;
    $ingreso = isset($in['ingreso']) ? round((float)$in['ingreso'], 2) : 0.0;
    $egreso  = isset($in['egreso'])  ? round((float)$in['egreso'],  2) : 0.0;
    $activo  = array_key_exists('activo', $in) ? (int)!!$in['activo'] : 1;

    if ($esAlta) {
        if ($nombre === '') jsonError('El nombre es obligatorio.', 400);
        if ($empresa <= 0)  jsonError('La empresa es obligatoria.', 400);
        if ($cuenta  <= 0)  jsonError('La cuenta es obligatoria.', 400);
    }

    if ($ingreso < 0) jsonError('El ingreso no puede ser negativo.', 400);
    if ($egreso  < 0) jsonError('El egreso no puede ser negativo.', 400);

    return [
        'nombre'  => $nombre,
        'empresa' => $empresa,
        'cuenta'  => $cuenta,
        'ingreso' => $ingreso,
        'egreso'  => $egreso,
        'activo'  => $activo,
    ];
}

function validarEmpresa(PDO $pdo, int $empresa): void {
    $st = $pdo->prepare('SELECT id FROM datacount_empresas WHERE id = :id LIMIT 1');
    $st->execute([':id' => $empresa]);
    if (!$st->fetch()) jsonError('Empresa no encontrada.', 400);
}

function validarCuentaDeEmpresa(PDO $pdo, int $cuenta, int $empresa): void {
    $st = $pdo->prepare('SELECT empresa_id FROM datacount_cuentas WHERE id = :id LIMIT 1');
    $st->execute([':id' => $cuenta]);
    $row = $st->fetch();
    if (!$row) jsonError('Cuenta no encontrada.', 400);
    if ((int)$row['empresa_id'] !== $empresa) {
        jsonError('La cuenta pertenece a otra empresa.', 400);
    }
}

// ----------------------------------------------------------------------------
// Handlers
// ----------------------------------------------------------------------------

function handleList(PDO $pdo, array $q): void {
    $search  = trim((string)($q['q'] ?? ''));
    $empresa = isset($q['empresa']) ? (int)$q['empresa'] : 0;
    $cuenta  = isset($q['cuenta'])  ? (int)$q['cuenta']  : 0;
    $activo  = (string)($q['activo'] ?? '');
    $limite  = max(1, min(1000, (int)($q['limite'] ?? 100)));
    $orden   = in_array(($q['orden'] ?? ''), DCR_ORDENES, true) ? $q['orden'] : 'id';
    $dir     = strtolower((string)($q['dir'] ?? 'desc')) === 'asc' ? 'ASC' : 'DESC';

    $where  = [];
    $params = [];

    if ($search !== '') {
        // EMULATE_PREPARES=false → placeholders distintos por columna.
        $where[] = '(r.nombre LIKE :s_nom OR e.nombre LIKE :s_emp OR c.codigo LIKE :s_cod OR c.nombre LIKE :s_cta)';
        $params[':s_nom'] = "%{$search}%";
        $params[':s_emp'] = "%{$search}%";
        $params[':s_cod'] = "%{$search}%";
        $params[':s_cta'] = "%{$search}%";
    }
    if ($empresa > 0) {
        $where[] = 'r.empresa = :empresa';
        $params[':empresa'] = $empresa;
    }
    if ($cuenta > 0) {
        $where[] = 'r.cuenta = :cuenta';
        $params[':cuenta'] = $cuenta;
    }
    if ($activo === '0' || $activo === '1') {
        $where[] = 'r.activo = :activo';
        $params[':activo'] = (int)$activo;
    }

    $sqlWhere = $where ? ('WHERE ' . implode(' AND ', $where)) : '';
    $ordenSql = $orden === 'id' ? 'r.id' : "r.{$orden}";
    $sql = 'SELECT ' . DCR_COLS . ",
                   e.nombre AS empresa_nombre,
                   c.codigo AS cuenta_codigo,
                   c.nombre AS cuenta_nombre
            FROM datacount_recurrentes r
            LEFT JOIN datacount_empresas e ON e.id = r.empresa
            LEFT JOIN datacount_cuentas  c ON c.id = r.cuenta
            {$sqlWhere}
            ORDER BY {$ordenSql} {$dir}
            LIMIT {$limite}";
    $st = $pdo->prepare($sql);
    $st->execute($params);
    $rows = array_map('normalizarFila', $st->fetchAll());

    $total    = (int)$pdo->query('SELECT COUNT(*) FROM datacount_recurrentes')->fetchColumn();
    $activos  = (int)$pdo->query('SELECT COUNT(*) FROM datacount_recurrentes WHERE activo = 1')->fetchColumn();
    $ingresos = (float)$pdo->query('SELECT COALESCE(SUM(ingreso),0) FROM datacount_recurrentes WHERE activo = 1')->fetchColumn();
    $egresos  = (float)$pdo->query('SELECT COALESCE(SUM(egreso),0)  FROM datacount_recurrentes WHERE activo = 1')->fetchColumn();

    jsonOk([
        'items' => $rows,
        'stats' => [
            'total'    => $total,
            'activos'  => $activos,
            'ingresos' => $ingresos,
            'egresos'  => $egresos,
        ],
    ]);
}

function handleGetOne(PDO $pdo, int $id): void {
    $sql = 'SELECT ' . DCR_COLS . ",
                   e.nombre AS empresa_nombre,
                   c.codigo AS cuenta_codigo,
                   c.nombre AS cuenta_nombre
            FROM datacount_recurrentes r
            LEFT JOIN datacount_empresas e ON e.id = r.empresa
            LEFT JOIN datacount_cuentas  c ON c.id = r.cuenta
            WHERE r.id = :id
            LIMIT 1";
    $st = $pdo->prepare($sql);
    $st->execute([':id' => $id]);
    $row = $st->fetch();
    if (!$row) jsonError('Movimiento recurrente no encontrado', 404);
    jsonOk(normalizarFila($row));
}

function handleCreate(PDO $pdo, array $body): void {
    $p = sanitizePayload($body, true);
    validarEmpresa($pdo, $p['empresa']);
    validarCuentaDeEmpresa($pdo, $p['cuenta'], $p['empresa']);

    $st = $pdo->prepare(
        'INSERT INTO datacount_recurrentes
            (nombre, empresa, cuenta, ingreso, egreso, activo)
         VALUES
            (:nombre, :empresa, :cuenta, :ingreso, :egreso, :activo)'
    );
    $st->execute([
        ':nombre'  => $p['nombre'],
        ':empresa' => $p['empresa'],
        ':cuenta'  => $p['cuenta'],
        ':ingreso' => $p['ingreso'],
        ':egreso'  => $p['egreso'],
        ':activo'  => $p['activo'],
    ]);

    $id = (int)$pdo->lastInsertId();
    registrarSuceso($pdo, 'datacountrecurrentes', 'info',
        "Alta movimiento recurrente #{$id} — empresa {$p['empresa']} cuenta {$p['cuenta']}");

    handleGetOne($pdo, $id);
}

function handleUpdate(PDO $pdo, int $id, array $body): void {
    $st = $pdo->prepare('SELECT id, empresa, cuenta FROM datacount_recurrentes WHERE id = :id LIMIT 1');
    $st->execute([':id' => $id]);
    $prev = $st->fetch();
    if (!$prev) jsonError('Movimiento recurrente no encontrado', 404);

    $p = sanitizePayload($body, false);

    // Empresa final tras la actualización: la que viene en el body o la que ya tenía.
    $empresaFinal = (array_key_exists('empresa', $body) && $p['empresa'] > 0)
        ? $p['empresa']
        : (int)$prev['empresa'];
    $cuentaFinal  = (array_key_exists('cuenta', $body) && $p['cuenta'] > 0)
        ? $p['cuenta']
        : (int)$prev['cuenta'];

    $sets   = [];
    $params = [':id' => $id];

    if (array_key_exists('nombre', $body)) {
        if ($p['nombre'] === '') jsonError('El nombre es obligatorio.', 400);
        $sets[] = 'nombre = :nombre';
        $params[':nombre'] = $p['nombre'];
    }
    if (array_key_exists('empresa', $body) && $p['empresa'] > 0) {
        validarEmpresa($pdo, $p['empresa']);
        $sets[] = 'empresa = :empresa';
        $params[':empresa'] = $p['empresa'];
    }
    if (array_key_exists('cuenta', $body) && $p['cuenta'] > 0) {
        $sets[] = 'cuenta = :cuenta';
        $params[':cuenta'] = $p['cuenta'];
    }
    // Validar coherencia empresa <-> cuenta con el estado final.
    validarCuentaDeEmpresa($pdo, $cuentaFinal, $empresaFinal);
    if (array_key_exists('ingreso', $body)) {
        $sets[] = 'ingreso = :ingreso';
        $params[':ingreso'] = $p['ingreso'];
    }
    if (array_key_exists('egreso', $body)) {
        $sets[] = 'egreso = :egreso';
        $params[':egreso'] = $p['egreso'];
    }
    if (array_key_exists('activo', $body)) {
        $sets[] = 'activo = :activo';
        $params[':activo'] = $p['activo'];
    }

    if (empty($sets)) jsonError('No hay campos para actualizar.', 400);

    $sql = 'UPDATE datacount_recurrentes SET ' . implode(', ', $sets) . ' WHERE id = :id';
    $st  = $pdo->prepare($sql);
    $st->execute($params);

    registrarSuceso($pdo, 'datacountrecurrentes', 'info',
        "Modificación movimiento recurrente #{$id}");

    handleGetOne($pdo, $id);
}

function handleDelete(PDO $pdo, int $id): void {
    $st = $pdo->prepare('SELECT id FROM datacount_recurrentes WHERE id = :id LIMIT 1');
    $st->execute([':id' => $id]);
    if (!$st->fetch()) jsonError('Movimiento recurrente no encontrado', 404);

    $sd = $pdo->prepare('DELETE FROM datacount_recurrentes WHERE id = :id');
    $sd->execute([':id' => $id]);

    registrarSuceso($pdo, 'datacountrecurrentes', 'info',
        "Baja movimiento recurrente #{$id}");

    jsonOk(['id' => $id]);
}
