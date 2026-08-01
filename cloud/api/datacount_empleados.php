<?php
// api/datacountempleados.php
// Empleados Datacount (CRUD). Lee/escribe sobre la tabla `datacount_empleados`
// definida en db/schema.sql — cada fila representa un empleado de una empresa
// con datos personales, de contacto y laborales (cuenta contable donde imputa
// el sueldo, sueldo mensual, CVU/CBU, estado y observaciones).
//
//   GET    api/datacountempleados.php[?q=...&empresa=N&cuenta_id=N&activo=si|no
//                                      &limite=100&orden=id&dir=desc]
//                                     -> listado + stats
//   GET    api/datacountempleados.php?id=N
//                                     -> registro individual
//   POST   api/datacountempleados.php  -> alta (JSON body)
//   PUT    api/datacountempleados.php?id=N
//                                     -> modificación (JSON body)
//   DELETE api/datacountempleados.php?id=N
//                                     -> baja
//
// Respuesta siempre {ok: true, data: ...} u {ok: false, error: '...'} (STACK.md sec. 10).

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/lib/auth_check.php';
require_once __DIR__ . '/lib/sucesos.php';

requireAuth();
header('Content-Type: application/json; charset=utf-8');

const DCE_ORDENES = ['id', 'nombre', 'documento', 'sueldo', 'activo', 'nacimiento'];
const DCE_COLS    = 'e.id, e.empresa_id, e.nombre, e.documento, e.nacimiento, e.domicilio, '
                  . 'e.celular, e.correo, e.cuenta_id, e.sueldo, e.cvu, e.activo, e.observaciones, '
                  . 'e.created_at, e.updated_at';

try {
    requirePermCrud('datacount.empleados');
    $pdo    = db();
    $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
    $id     = isset($_GET['id']) ? (int)$_GET['id'] : 0;

    if ($method === 'GET' && $id > 0) {
        handleGetOneEmpleado($pdo, $id);
    } elseif ($method === 'GET') {
        handleListEmpleados($pdo, $_GET);
    } elseif ($method === 'POST') {
        handleCreateEmpleado($pdo, readJsonBody());
    } elseif ($method === 'PUT') {
        if ($id <= 0) jsonError('Falta id', 400);
        handleUpdateEmpleado($pdo, $id, readJsonBody());
    } elseif ($method === 'DELETE') {
        if ($id <= 0) jsonError('Falta id', 400);
        handleDeleteEmpleado($pdo, $id);
    } else {
        jsonError('Método no soportado', 405);
    }
} catch (Throwable $e) {
    jsonError($e->getMessage(), 500);
}

// ----------------------------------------------------------------------------
// Helpers
// ----------------------------------------------------------------------------

function normalizarFilaEmpleado(array $r): array {
    return [
        'id'             => (int)($r['id'] ?? 0),
        'empresa_id'     => (int)($r['empresa_id'] ?? 0),
        'empresa_nombre' => $r['empresa_nombre'] ?? null,
        'nombre'         => (string)($r['nombre'] ?? ''),
        'documento'      => $r['documento'] ?? null,
        'nacimiento'     => $r['nacimiento'] ?? null,
        'domicilio'      => $r['domicilio'] ?? null,
        'celular'        => $r['celular'] ?? null,
        'correo'         => $r['correo'] ?? null,
        'cuenta_id'      => $r['cuenta_id'] !== null ? (int)$r['cuenta_id'] : null,
        'cuenta_codigo'  => $r['cuenta_codigo'] ?? null,
        'cuenta_nombre'  => $r['cuenta_nombre'] ?? null,
        'sueldo'         => (float)($r['sueldo'] ?? 0),
        'cvu'            => $r['cvu'] ?? null,
        'activo'         => (string)($r['activo'] ?? 'si'),
        'observaciones'  => $r['observaciones'] ?? null,
        'created_at'     => $r['created_at'] ?? null,
        'updated_at'     => $r['updated_at'] ?? null,
    ];
}

function normalizarActivoEmpleado($valor): string {
    // Acepta 'si'/'no', bool, 1/0 y devuelve siempre 'si' o 'no'.
    if ($valor === true)  return 'si';
    if ($valor === false) return 'no';
    $s = strtolower(trim((string)$valor));
    if ($s === 'si' || $s === 'sí' || $s === '1' || $s === 'true' || $s === 'yes') return 'si';
    if ($s === 'no' || $s === '0'  || $s === 'false' || $s === '')                 return 'no';
    return 'si';
}

function sanitizePayloadEmpleado(array $in, bool $esAlta): array {
    $nombre        = trim((string)($in['nombre'] ?? ''));
    if (mb_strlen($nombre) > 100) $nombre = mb_substr($nombre, 0, 100);
    $documento     = trim((string)($in['documento'] ?? ''));
    if ($documento === '') $documento = null;
    elseif (mb_strlen($documento) > 15) $documento = mb_substr($documento, 0, 15);
    $nacimiento    = trim((string)($in['nacimiento'] ?? ''));
    if ($nacimiento === '') $nacimiento = null;
    $domicilio     = trim((string)($in['domicilio'] ?? ''));
    if ($domicilio === '') $domicilio = null;
    elseif (mb_strlen($domicilio) > 200) $domicilio = mb_substr($domicilio, 0, 200);
    $celular       = trim((string)($in['celular'] ?? ''));
    if ($celular === '') $celular = null;
    elseif (mb_strlen($celular) > 20) $celular = mb_substr($celular, 0, 20);
    $correo        = trim((string)($in['correo'] ?? ''));
    if ($correo === '') $correo = null;
    elseif (mb_strlen($correo) > 120) $correo = mb_substr($correo, 0, 120);
    $cvu           = trim((string)($in['cvu'] ?? ''));
    if ($cvu === '') $cvu = null;
    elseif (mb_strlen($cvu) > 50) $cvu = mb_substr($cvu, 0, 50);
    $observaciones = trim((string)($in['observaciones'] ?? ''));
    if ($observaciones === '') $observaciones = null;
    elseif (mb_strlen($observaciones) > 1000) $observaciones = mb_substr($observaciones, 0, 1000);

    $empresa_id = isset($in['empresa_id']) ? (int)$in['empresa_id'] : 0;
    $cuenta_id  = isset($in['cuenta_id']) && $in['cuenta_id'] !== '' && $in['cuenta_id'] !== null
                    ? (int)$in['cuenta_id'] : null;
    $sueldo     = isset($in['sueldo']) ? round((float)$in['sueldo'], 2) : 0.0;
    $activo     = array_key_exists('activo', $in) ? normalizarActivoEmpleado($in['activo']) : 'si';

    if ($esAlta) {
        if ($nombre === '')   jsonError('El nombre es obligatorio.', 400);
        if ($empresa_id <= 0) jsonError('La empresa es obligatoria.', 400);
    }

    if ($sueldo < 0) jsonError('El sueldo no puede ser negativo.', 400);
    if ($nacimiento !== null && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $nacimiento)) {
        jsonError('La fecha de nacimiento debe tener formato YYYY-MM-DD.', 400);
    }
    if ($correo !== null && !filter_var($correo, FILTER_VALIDATE_EMAIL)) {
        jsonError('El correo no es válido.', 400);
    }

    return [
        'nombre'        => $nombre,
        'documento'     => $documento,
        'nacimiento'    => $nacimiento,
        'domicilio'     => $domicilio,
        'celular'       => $celular,
        'correo'        => $correo,
        'empresa_id'    => $empresa_id,
        'cuenta_id'     => $cuenta_id,
        'sueldo'        => $sueldo,
        'cvu'           => $cvu,
        'activo'        => $activo,
        'observaciones' => $observaciones,
    ];
}

function validarEmpresaEmpleado(PDO $pdo, int $empresa): void {
    $st = $pdo->prepare('SELECT id FROM datacount_empresas WHERE id = :id LIMIT 1');
    $st->execute([':id' => $empresa]);
    if (!$st->fetch()) jsonError('Empresa no encontrada.', 400);
}

function validarCuentaDeEmpresaEmpleado(PDO $pdo, ?int $cuenta_id, int $empresa): void {
    if ($cuenta_id === null) return;
    $st = $pdo->prepare('SELECT empresa_id FROM datacount_cuentas WHERE id = :id LIMIT 1');
    $st->execute([':id' => $cuenta_id]);
    $row = $st->fetch();
    if (!$row) jsonError('Cuenta no encontrada.', 400);
    if ((int)$row['empresa_id'] !== $empresa) {
        jsonError('La cuenta pertenece a otra empresa.', 400);
    }
}

// ----------------------------------------------------------------------------
// Handlers
// ----------------------------------------------------------------------------

function handleListEmpleados(PDO $pdo, array $q): void {
    $search    = trim((string)($q['q'] ?? ''));
    $empresa   = isset($q['empresa']) ? (int)$q['empresa'] : 0;
    $cuenta_id = isset($q['cuenta_id']) ? (int)$q['cuenta_id']
               : (isset($q['cuenta']) ? (int)$q['cuenta'] : 0);
    $activoIn  = (string)($q['activo'] ?? '');
    $limite    = max(1, min(1000, (int)($q['limite'] ?? 100)));
    $orden     = in_array(($q['orden'] ?? ''), DCE_ORDENES, true) ? $q['orden'] : 'id';
    $dir       = strtolower((string)($q['dir'] ?? 'desc')) === 'asc' ? 'ASC' : 'DESC';

    $where  = [];
    $params = [];

    if ($search !== '') {
        $where[] = '(e.nombre LIKE :s_nom OR e.documento LIKE :s_doc '
                 . 'OR e.correo LIKE :s_cor OR e.celular LIKE :s_cel)';
        $params[':s_nom'] = "%{$search}%";
        $params[':s_doc'] = "%{$search}%";
        $params[':s_cor'] = "%{$search}%";
        $params[':s_cel'] = "%{$search}%";
    }
    if ($empresa > 0) {
        $where[] = 'e.empresa_id = :empresa';
        $params[':empresa'] = $empresa;
    }
    if ($cuenta_id > 0) {
        $where[] = 'e.cuenta_id = :cuenta_id';
        $params[':cuenta_id'] = $cuenta_id;
    }
    if ($activoIn === 'si' || $activoIn === 'no') {
        $where[] = 'e.activo = :activo';
        $params[':activo'] = $activoIn;
    }

    $sqlWhere = $where ? ('WHERE ' . implode(' AND ', $where)) : '';
    $ordenSql = $orden === 'id' ? 'e.id' : "e.{$orden}";
    $sql = 'SELECT ' . DCE_COLS . ",
                   emp.nombre AS empresa_nombre,
                   c.codigo   AS cuenta_codigo,
                   c.nombre   AS cuenta_nombre
            FROM datacount_empleados e
            LEFT JOIN datacount_empresas emp ON emp.id = e.empresa_id
            LEFT JOIN datacount_cuentas  c   ON c.id   = e.cuenta_id
            {$sqlWhere}
            ORDER BY {$ordenSql} {$dir}
            LIMIT {$limite}";
    $st = $pdo->prepare($sql);
    $st->execute($params);
    $rows = array_map('normalizarFilaEmpleado', $st->fetchAll());

    $total        = (int)$pdo->query('SELECT COUNT(*) FROM datacount_empleados')->fetchColumn();
    $activos      = (int)$pdo->query("SELECT COUNT(*) FROM datacount_empleados WHERE activo = 'si'")->fetchColumn();
    $masaSalarial = (float)$pdo->query(
        "SELECT COALESCE(SUM(sueldo),0) FROM datacount_empleados WHERE activo = 'si'"
    )->fetchColumn();

    jsonOk([
        'items' => $rows,
        'stats' => [
            'total'         => $total,
            'activos'       => $activos,
            'masa_salarial' => $masaSalarial,
        ],
    ]);
}

function handleGetOneEmpleado(PDO $pdo, int $id): void {
    $sql = 'SELECT ' . DCE_COLS . ",
                   emp.nombre AS empresa_nombre,
                   c.codigo   AS cuenta_codigo,
                   c.nombre   AS cuenta_nombre
            FROM datacount_empleados e
            LEFT JOIN datacount_empresas emp ON emp.id = e.empresa_id
            LEFT JOIN datacount_cuentas  c   ON c.id   = e.cuenta_id
            WHERE e.id = :id
            LIMIT 1";
    $st = $pdo->prepare($sql);
    $st->execute([':id' => $id]);
    $row = $st->fetch();
    if (!$row) jsonError('Empleado no encontrado', 404);
    jsonOk(normalizarFilaEmpleado($row));
}

function handleCreateEmpleado(PDO $pdo, array $body): void {
    $p = sanitizePayloadEmpleado($body, true);
    validarEmpresaEmpleado($pdo, $p['empresa_id']);
    validarCuentaDeEmpresaEmpleado($pdo, $p['cuenta_id'], $p['empresa_id']);

    $st = $pdo->prepare(
        'INSERT INTO datacount_empleados
            (empresa_id, nombre, documento, nacimiento, domicilio, celular, correo,
             cuenta_id, sueldo, cvu, activo, observaciones)
         VALUES
            (:empresa_id, :nombre, :documento, :nacimiento, :domicilio, :celular, :correo,
             :cuenta_id, :sueldo, :cvu, :activo, :observaciones)'
    );
    $st->execute([
        ':empresa_id'    => $p['empresa_id'],
        ':nombre'        => $p['nombre'],
        ':documento'     => $p['documento'],
        ':nacimiento'    => $p['nacimiento'],
        ':domicilio'     => $p['domicilio'],
        ':celular'       => $p['celular'],
        ':correo'        => $p['correo'],
        ':cuenta_id'     => $p['cuenta_id'],
        ':sueldo'        => $p['sueldo'],
        ':cvu'           => $p['cvu'],
        ':activo'        => $p['activo'],
        ':observaciones' => $p['observaciones'],
    ]);

    $id = (int)$pdo->lastInsertId();
    registrarSuceso($pdo, 'datacountempleados', 'info',
        "Alta empleado #{$id} — empresa {$p['empresa_id']} nombre \"{$p['nombre']}\"");

    handleGetOneEmpleado($pdo, $id);
}

function handleUpdateEmpleado(PDO $pdo, int $id, array $body): void {
    $st = $pdo->prepare('SELECT id, empresa_id, cuenta_id FROM datacount_empleados WHERE id = :id LIMIT 1');
    $st->execute([':id' => $id]);
    $prev = $st->fetch();
    if (!$prev) jsonError('Empleado no encontrado', 404);

    $p = sanitizePayloadEmpleado($body, false);

    $empresaFinal   = (array_key_exists('empresa_id', $body) && $p['empresa_id'] > 0)
        ? $p['empresa_id']
        : (int)$prev['empresa_id'];
    $cuentaIdFinal  = array_key_exists('cuenta_id', $body)
        ? $p['cuenta_id']
        : ($prev['cuenta_id'] !== null ? (int)$prev['cuenta_id'] : null);

    $sets   = [];
    $params = [':id' => $id];

    if (array_key_exists('nombre', $body)) {
        if ($p['nombre'] === '') jsonError('El nombre es obligatorio.', 400);
        $sets[] = 'nombre = :nombre';
        $params[':nombre'] = $p['nombre'];
    }
    if (array_key_exists('empresa_id', $body) && $p['empresa_id'] > 0) {
        validarEmpresaEmpleado($pdo, $p['empresa_id']);
        $sets[] = 'empresa_id = :empresa_id';
        $params[':empresa_id'] = $p['empresa_id'];
    }
    if (array_key_exists('cuenta_id', $body)) {
        $sets[] = 'cuenta_id = :cuenta_id';
        $params[':cuenta_id'] = $p['cuenta_id'];
    }
    validarCuentaDeEmpresaEmpleado($pdo, $cuentaIdFinal, $empresaFinal);
    if (array_key_exists('documento', $body)) {
        $sets[] = 'documento = :documento';
        $params[':documento'] = $p['documento'];
    }
    if (array_key_exists('nacimiento', $body)) {
        $sets[] = 'nacimiento = :nacimiento';
        $params[':nacimiento'] = $p['nacimiento'];
    }
    if (array_key_exists('domicilio', $body)) {
        $sets[] = 'domicilio = :domicilio';
        $params[':domicilio'] = $p['domicilio'];
    }
    if (array_key_exists('celular', $body)) {
        $sets[] = 'celular = :celular';
        $params[':celular'] = $p['celular'];
    }
    if (array_key_exists('correo', $body)) {
        $sets[] = 'correo = :correo';
        $params[':correo'] = $p['correo'];
    }
    if (array_key_exists('sueldo', $body)) {
        $sets[] = 'sueldo = :sueldo';
        $params[':sueldo'] = $p['sueldo'];
    }
    if (array_key_exists('cvu', $body)) {
        $sets[] = 'cvu = :cvu';
        $params[':cvu'] = $p['cvu'];
    }
    if (array_key_exists('activo', $body)) {
        $sets[] = 'activo = :activo';
        $params[':activo'] = $p['activo'];
    }
    if (array_key_exists('observaciones', $body)) {
        $sets[] = 'observaciones = :observaciones';
        $params[':observaciones'] = $p['observaciones'];
    }

    if (empty($sets)) jsonError('No hay campos para actualizar.', 400);

    $sql = 'UPDATE datacount_empleados SET ' . implode(', ', $sets) . ' WHERE id = :id';
    $st  = $pdo->prepare($sql);
    $st->execute($params);

    registrarSuceso($pdo, 'datacountempleados', 'info',
        "Modificación empleado #{$id}");

    handleGetOneEmpleado($pdo, $id);
}

function handleDeleteEmpleado(PDO $pdo, int $id): void {
    $st = $pdo->prepare('SELECT id, nombre FROM datacount_empleados WHERE id = :id LIMIT 1');
    $st->execute([':id' => $id]);
    $row = $st->fetch();
    if (!$row) jsonError('Empleado no encontrado', 404);

    $sd = $pdo->prepare('DELETE FROM datacount_empleados WHERE id = :id');
    $sd->execute([':id' => $id]);

    registrarSuceso($pdo, 'datacountempleados', 'info',
        "Baja empleado #{$id} — \"{$row['nombre']}\"");

    jsonOk(['id' => $id]);
}
