<?php
// api/datacountclientes.php
// Clientes Datacount (CRUD). Lee/escribe sobre la tabla `datacount_clientes`
// definida en db/schema.sql — cada fila representa un cliente transversal
// (multiempresa) con datos identificatorios (nombre, razón social, condición
// fiscal AFIP, CUIT), de contacto (domicilio, celular, correo, web) y
// bancarios (CBU).
//
//   GET    api/datacountclientes.php[?q=...&condicion=...&limite=100&orden=id&dir=desc]
//                                      -> listado + stats por condición
//   GET    api/datacountclientes.php?id=N
//                                      -> registro individual
//   POST   api/datacountclientes.php     -> alta (JSON body)
//   PUT    api/datacountclientes.php?id=N
//                                      -> modificación (JSON body)
//   DELETE api/datacountclientes.php?id=N
//                                      -> baja
//
// Respuesta siempre {ok: true, data: ...} u {ok: false, error: '...'} (STACK.md sec. 10).

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/lib/auth_check.php';
require_once __DIR__ . '/lib/sucesos.php';

requireAuth();
header('Content-Type: application/json; charset=utf-8');

const DCCL_CONDICIONES = [
    'responsable_inscripto',
    'monotributista',
    'exento',
    'consumidor_final',
    'no_responsable',
    'no_categorizado',
];
const DCCL_ORDENES = ['id', 'nombre', 'razon', 'cuit'];
const DCCL_COLS    = 'id, nombre, razon, condicion, cuit, domicilio, celular, correo, web, cbu, created_at, updated_at';

try {
    requirePermCrud('datacount.clientes');
    $pdo    = db();
    $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
    $id     = isset($_GET['id']) ? (int)$_GET['id'] : 0;

    if ($method === 'GET' && $id > 0) {
        handleGetOneCliente($pdo, $id);
    } elseif ($method === 'GET') {
        handleListClientes($pdo, $_GET);
    } elseif ($method === 'POST') {
        handleCreateCliente($pdo, readJsonBody());
    } elseif ($method === 'PUT') {
        if ($id <= 0) jsonError('Falta id', 400);
        handleUpdateCliente($pdo, $id, readJsonBody());
    } elseif ($method === 'DELETE') {
        if ($id <= 0) jsonError('Falta id', 400);
        handleDeleteCliente($pdo, $id);
    } else {
        jsonError('Método no soportado', 405);
    }
} catch (Throwable $e) {
    jsonError($e->getMessage(), 500);
}

// ----------------------------------------------------------------------------
// Helpers
// ----------------------------------------------------------------------------

function normalizarFilaCliente(array $r): array {
    return [
        'id'         => (int)($r['id'] ?? 0),
        'nombre'     => (string)($r['nombre'] ?? ''),
        'razon'      => $r['razon']     !== null ? (string)$r['razon']     : null,
        'condicion'  => (string)($r['condicion'] ?? ''),
        'cuit'       => $r['cuit']      !== null ? (string)$r['cuit']      : null,
        'domicilio'  => $r['domicilio'] !== null ? (string)$r['domicilio'] : null,
        'celular'    => $r['celular']   !== null ? (string)$r['celular']   : null,
        'correo'     => $r['correo']    !== null ? (string)$r['correo']    : null,
        'web'        => $r['web']       !== null ? (string)$r['web']       : null,
        'cbu'        => $r['cbu']       !== null ? (string)$r['cbu']       : null,
        'created_at' => $r['created_at'] ?? null,
        'updated_at' => $r['updated_at'] ?? null,
    ];
}

function sanitizePayloadCliente(array $in, bool $esAlta): array {
    $nombre    = trim((string)($in['nombre']    ?? ''));
    $razon     = trim((string)($in['razon']     ?? ''));
    $condicion = trim((string)($in['condicion'] ?? ''));
    $cuit      = trim((string)($in['cuit']      ?? ''));
    $domicilio = trim((string)($in['domicilio'] ?? ''));
    $celular   = trim((string)($in['celular']   ?? ''));
    $correo    = trim((string)($in['correo']    ?? ''));
    $web       = trim((string)($in['web']       ?? ''));
    $cbu       = trim((string)($in['cbu']       ?? ''));

    if ($esAlta) {
        if ($nombre === '')    jsonError('El nombre es obligatorio.', 400);
        if ($condicion === '') $condicion = 'consumidor_final';
    }

    if ($nombre    !== '' && mb_strlen($nombre)    > 160) jsonError('El nombre no puede superar los 160 caracteres.', 400);
    if ($razon     !== '' && mb_strlen($razon)     > 200) jsonError('La razón social no puede superar los 200 caracteres.', 400);
    if ($domicilio !== '' && mb_strlen($domicilio) > 255) jsonError('El domicilio no puede superar los 255 caracteres.', 400);
    if ($celular   !== '' && mb_strlen($celular)   > 20)  jsonError('El celular no puede superar los 20 caracteres.', 400);
    if ($correo    !== '' && mb_strlen($correo)    > 120) jsonError('El correo no puede superar los 120 caracteres.', 400);
    if ($web       !== '' && mb_strlen($web)       > 200) jsonError('La web no puede superar los 200 caracteres.', 400);
    if ($cbu       !== '' && mb_strlen($cbu)       > 50)  jsonError('El CBU no puede superar los 50 caracteres.', 400);

    if ($condicion !== '' && !in_array($condicion, DCCL_CONDICIONES, true)) {
        jsonError('Condición fiscal inválida.', 400);
    }
    if ($cuit !== '') {
        $cuitDigits = preg_replace('/\D+/', '', $cuit);
        if (strlen($cuitDigits) !== 11) jsonError('El CUIT debe tener 11 dígitos.', 400);
        $cuit = $cuitDigits;
    }
    if ($correo !== '' && !filter_var($correo, FILTER_VALIDATE_EMAIL)) {
        jsonError('El correo no es válido.', 400);
    }

    return [
        'nombre'    => $nombre,
        'razon'     => $razon     === '' ? null : $razon,
        'condicion' => $condicion,
        'cuit'      => $cuit      === '' ? null : $cuit,
        'domicilio' => $domicilio === '' ? null : $domicilio,
        'celular'   => $celular   === '' ? null : $celular,
        'correo'    => $correo    === '' ? null : $correo,
        'web'       => $web       === '' ? null : $web,
        'cbu'       => $cbu       === '' ? null : $cbu,
    ];
}

// ----------------------------------------------------------------------------
// Handlers
// ----------------------------------------------------------------------------

function handleListClientes(PDO $pdo, array $q): void {
    $search    = trim((string)($q['q'] ?? ''));
    $condicion = trim((string)($q['condicion'] ?? ''));
    $limite    = max(1, min(1000, (int)($q['limite'] ?? 100)));
    $orden     = in_array(($q['orden'] ?? ''), DCCL_ORDENES, true) ? $q['orden'] : 'id';
    $dir       = strtolower((string)($q['dir'] ?? 'desc')) === 'asc' ? 'ASC' : 'DESC';

    $where  = [];
    $params = [];

    if ($search !== '') {
        $where[] = '(nombre LIKE :s_nom OR razon LIKE :s_raz OR cuit LIKE :s_cui '
                 . 'OR correo LIKE :s_cor OR celular LIKE :s_cel OR domicilio LIKE :s_dom)';
        $params[':s_nom'] = "%{$search}%";
        $params[':s_raz'] = "%{$search}%";
        $params[':s_cui'] = "%{$search}%";
        $params[':s_cor'] = "%{$search}%";
        $params[':s_cel'] = "%{$search}%";
        $params[':s_dom'] = "%{$search}%";
    }
    if ($condicion !== '' && in_array($condicion, DCCL_CONDICIONES, true)) {
        $where[] = 'condicion = :condicion';
        $params[':condicion'] = $condicion;
    }

    $sqlWhere = $where ? ('WHERE ' . implode(' AND ', $where)) : '';
    $sql = 'SELECT ' . DCCL_COLS . " FROM datacount_clientes {$sqlWhere} ORDER BY {$orden} {$dir} LIMIT {$limite}";
    $st = $pdo->prepare($sql);
    $st->execute($params);
    $rows = array_map('normalizarFilaCliente', $st->fetchAll());

    $stats = [];
    foreach (DCCL_CONDICIONES as $c) {
        $s = $pdo->prepare('SELECT COUNT(*) FROM datacount_clientes WHERE condicion = :c');
        $s->execute([':c' => $c]);
        $stats[$c] = (int)$s->fetchColumn();
    }
    $stats['total'] = (int)$pdo->query('SELECT COUNT(*) FROM datacount_clientes')->fetchColumn();

    jsonOk(['items' => $rows, 'stats' => $stats]);
}

function handleGetOneCliente(PDO $pdo, int $id): void {
    $st = $pdo->prepare('SELECT ' . DCCL_COLS . ' FROM datacount_clientes WHERE id = :id LIMIT 1');
    $st->execute([':id' => $id]);
    $row = $st->fetch();
    if (!$row) jsonError('Cliente no encontrado', 404);
    jsonOk(normalizarFilaCliente($row));
}

function handleCreateCliente(PDO $pdo, array $body): void {
    $p = sanitizePayloadCliente($body, true);

    $st = $pdo->prepare(
        'INSERT INTO datacount_clientes
            (nombre, razon, condicion, cuit, domicilio, celular, correo, web, cbu)
         VALUES
            (:nombre, :razon, :condicion, :cuit, :domicilio, :celular, :correo, :web, :cbu)'
    );
    $st->execute([
        ':nombre'    => $p['nombre'],
        ':razon'     => $p['razon'],
        ':condicion' => $p['condicion'],
        ':cuit'      => $p['cuit'],
        ':domicilio' => $p['domicilio'],
        ':celular'   => $p['celular'],
        ':correo'    => $p['correo'],
        ':web'       => $p['web'],
        ':cbu'       => $p['cbu'],
    ]);

    $id = (int)$pdo->lastInsertId();
    registrarSuceso($pdo, 'datacountclientes', 'info',
        "Alta cliente #{$id} — \"{$p['nombre']}\"");

    handleGetOneCliente($pdo, $id);
}

function handleUpdateCliente(PDO $pdo, int $id, array $body): void {
    $st = $pdo->prepare('SELECT ' . DCCL_COLS . ' FROM datacount_clientes WHERE id = :id LIMIT 1');
    $st->execute([':id' => $id]);
    $prev = $st->fetch();
    if (!$prev) jsonError('Cliente no encontrado', 404);

    $p = sanitizePayloadCliente($body, false);

    $sets   = [];
    $params = [':id' => $id];

    if (array_key_exists('nombre', $body) && $p['nombre'] !== '') {
        $sets[] = 'nombre = :nombre';
        $params[':nombre'] = $p['nombre'];
    }
    if (array_key_exists('razon', $body)) {
        $sets[] = 'razon = :razon';
        $params[':razon'] = $p['razon'];
    }
    if (array_key_exists('condicion', $body) && $p['condicion'] !== '') {
        $sets[] = 'condicion = :condicion';
        $params[':condicion'] = $p['condicion'];
    }
    if (array_key_exists('cuit', $body)) {
        $sets[] = 'cuit = :cuit';
        $params[':cuit'] = $p['cuit'];
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
    if (array_key_exists('web', $body)) {
        $sets[] = 'web = :web';
        $params[':web'] = $p['web'];
    }
    if (array_key_exists('cbu', $body)) {
        $sets[] = 'cbu = :cbu';
        $params[':cbu'] = $p['cbu'];
    }

    if (empty($sets)) jsonError('No hay campos para actualizar.', 400);

    $sql = 'UPDATE datacount_clientes SET ' . implode(', ', $sets) . ' WHERE id = :id';
    $st  = $pdo->prepare($sql);
    $st->execute($params);

    registrarSuceso($pdo, 'datacountclientes', 'info',
        "Modificación cliente #{$id} — \"{$prev['nombre']}\"");

    handleGetOneCliente($pdo, $id);
}

function handleDeleteCliente(PDO $pdo, int $id): void {
    $st = $pdo->prepare('SELECT nombre FROM datacount_clientes WHERE id = :id LIMIT 1');
    $st->execute([':id' => $id]);
    $prev = $st->fetch();
    if (!$prev) jsonError('Cliente no encontrado', 404);

    $sd = $pdo->prepare('DELETE FROM datacount_clientes WHERE id = :id');
    $sd->execute([':id' => $id]);

    registrarSuceso($pdo, 'datacountclientes', 'info',
        "Baja cliente #{$id} — \"{$prev['nombre']}\"");

    jsonOk(['id' => $id]);
}
