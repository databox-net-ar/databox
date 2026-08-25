<?php
// api/datacount_bancos_movimientos.php
// Datacount > Bancos > Movimientos (CRUD). Lee/escribe sobre la tabla
// `datacount_bancos_movimientos` definida en db/schema.sql — cada fila es un
// movimiento del extracto de una cuenta de fondos.
//
// El grueso de las filas entra por importacion de extractos
// (datacount_bancos_importar.php, `origen` = 'importado'); este endpoint cubre
// el listado, la consulta, la carga manual de lo que el extracto no trae y la
// edicion de los campos que se completan a mano (medio, contraparte,
// conciliado, observaciones).
//
//   GET    api/datacount_bancos_movimientos.php?cuenta=N[&q=..&tipo=..&medio=..&conciliado=..&desde=..&hasta=..&limite=100&orden=fecha&dir=desc]
//                                               -> listado + stats
//   GET    api/datacount_bancos_movimientos.php?id=N       -> registro individual
//   GET    api/datacount_bancos_movimientos.php?lookups=1  -> catalogos + medios por tipo
//   POST   api/datacount_bancos_movimientos.php            -> alta manual (JSON body)
//   PUT    api/datacount_bancos_movimientos.php?id=N       -> modificacion
//   DELETE api/datacount_bancos_movimientos.php?id=N       -> baja
//
// Respuesta siempre {ok: true, data: ...} u {ok: false, error: '...'} (STACK.md sec. 10).

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/lib/auth_check.php';
require_once __DIR__ . '/lib/sucesos.php';
require_once __DIR__ . '/lib/planilla.php';
require_once __DIR__ . '/lib/datacount_bancos.php';

requireAuth();
header('Content-Type: application/json; charset=utf-8');

const DCBM_ORDENES = ['id', 'fecha', 'importe', 'tipo', 'medio', 'conciliado'];
const DCBM_TIPOS   = ['ingreso', 'egreso'];

const DCBM_COLS = 'id, cuenta_id, fecha, fecha_valor, tipo, medio, descripcion, referencia,
                   importe, saldo, moneda, contraparte, cuit, conciliado, pago_id,
                   contrapartida_id, asiento_id, importacion_id, huella, origen,
                   observaciones, created_at, updated_at';

try {
    requirePermCrud('datacount.bancos.movimientos');
    $pdo    = db();
    $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
    $id     = isset($_GET['id']) ? (int) $_GET['id'] : 0;

    if ($method === 'GET' && ($_GET['lookups'] ?? '') !== '') {
        handleLookupsMovBanco($pdo);
    } elseif ($method === 'GET' && $id > 0) {
        handleGetOneMovBanco($pdo, $id);
    } elseif ($method === 'GET') {
        handleListMovBanco($pdo, $_GET);
    } elseif ($method === 'POST') {
        handleCreateMovBanco($pdo, readJsonBody());
    } elseif ($method === 'PUT') {
        if ($id <= 0) jsonError('Falta id', 400);
        handleUpdateMovBanco($pdo, $id, readJsonBody());
    } elseif ($method === 'DELETE') {
        if ($id <= 0) jsonError('Falta id', 400);
        handleDeleteMovBanco($pdo, $id);
    } else {
        jsonError('Método no soportado', 405);
    }
} catch (Throwable $e) {
    jsonError($e->getMessage(), 500);
}

// ----------------------------------------------------------------------------
// Helpers
// ----------------------------------------------------------------------------

function normalizarFilaMovBanco(array $r): array {
    return [
        'id'               => (int) ($r['id'] ?? 0),
        'cuenta_id'        => (int) ($r['cuenta_id'] ?? 0),
        'fecha'            => (string) ($r['fecha'] ?? ''),
        'fecha_valor'      => $r['fecha_valor']      !== null ? (string) $r['fecha_valor']    : null,
        'tipo'             => (string) ($r['tipo'] ?? ''),
        'medio'            => $r['medio']            !== null ? (string) $r['medio']          : null,
        'descripcion'      => $r['descripcion']      !== null ? (string) $r['descripcion']    : null,
        'referencia'       => $r['referencia']       !== null ? (string) $r['referencia']     : null,
        'importe'          => (float) ($r['importe'] ?? 0),
        'saldo'            => $r['saldo']            !== null ? (float) $r['saldo']           : null,
        'moneda'           => (string) ($r['moneda'] ?? 'P'),
        'contraparte'      => $r['contraparte']      !== null ? (string) $r['contraparte']    : null,
        'cuit'             => $r['cuit']             !== null ? (string) $r['cuit']           : null,
        'conciliado'       => (int) ($r['conciliado'] ?? 0),
        'pago_id'          => $r['pago_id']          !== null ? (int) $r['pago_id']           : null,
        'contrapartida_id' => $r['contrapartida_id'] !== null ? (int) $r['contrapartida_id']  : null,
        'asiento_id'       => $r['asiento_id']       !== null ? (int) $r['asiento_id']        : null,
        'importacion_id'   => $r['importacion_id']   !== null ? (string) $r['importacion_id'] : null,
        'origen'           => (string) ($r['origen'] ?? 'importado'),
        'observaciones'    => $r['observaciones']    !== null ? (string) $r['observaciones']  : null,
        'created_at'       => $r['created_at'] ?? null,
        'updated_at'       => $r['updated_at'] ?? null,
    ];
}

function dcbmFecha(mixed $v, string $etiqueta, bool $obligatoria): ?string {
    $s = trim((string) ($v ?? ''));
    if ($s === '') {
        if ($obligatoria) jsonError("{$etiqueta} es obligatoria.", 400);
        return null;
    }
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $s)) {
        jsonError("{$etiqueta} debe tener formato YYYY-MM-DD.", 400);
    }
    [$y, $m, $d] = array_map('intval', explode('-', $s));
    if (!checkdate($m, $d, $y)) jsonError("{$etiqueta} no es una fecha valida.", 400);
    return $s;
}

function sanitizePayloadMovBanco(PDO $pdo, array $in, bool $esAlta): array {
    $cuentaId = (int) ($in['cuenta_id'] ?? 0);
    if ($esAlta) {
        if ($cuentaId <= 0) jsonError('La cuenta es obligatoria.', 400);
        $chk = $pdo->prepare('SELECT id FROM datacount_bancos_cuentas WHERE id = :id LIMIT 1');
        $chk->execute([':id' => $cuentaId]);
        if (!$chk->fetchColumn()) jsonError('La cuenta indicada no existe.', 400);
    }

    $tipo = trim((string) ($in['tipo'] ?? ''));
    if ($esAlta && $tipo === '') jsonError('El tipo (ingreso/egreso) es obligatorio.', 400);
    if ($tipo !== '' && !in_array($tipo, DCBM_TIPOS, true)) {
        jsonError('El tipo debe ser "ingreso" o "egreso".', 400);
    }

    // El importe se guarda SIEMPRE positivo: el signo lo lleva `tipo`. Si el
    // front manda un negativo lo tomamos como valor absoluto en vez de
    // rechazarlo — un extracto con la columna "importe" firmada es comun y el
    // tipo ya se dedujo de ese signo aguas arriba.
    $importe = null;
    if (array_key_exists('importe', $in) && $in['importe'] !== '' && $in['importe'] !== null) {
        $importe = round(abs((float) $in['importe']), 2);
    }
    if ($esAlta && ($importe === null || $importe <= 0)) {
        jsonError('El importe debe ser mayor a cero.', 400);
    }

    $medio = trim((string) ($in['medio'] ?? ''));
    if ($medio !== '') {
        // La validacion corre contra el tipo de LA cuenta del movimiento, no
        // contra el catalogo completo: es lo que impide cargar un "pago con QR"
        // en una cuenta bancaria.
        $idParaMedio = $cuentaId > 0 ? $cuentaId : (int) ($in['_cuenta_actual'] ?? 0);
        if ($idParaMedio > 0) {
            $validos = dcbMediosDeCuenta($pdo, $idParaMedio);
            if (!in_array($medio, $validos, true)) {
                jsonError('El medio "' . $medio . '" no aplica al tipo de esta cuenta.', 400);
            }
        }
    }

    $saldo = null;
    if (array_key_exists('saldo', $in) && $in['saldo'] !== '' && $in['saldo'] !== null) {
        $saldo = round((float) $in['saldo'], 2);
    }

    $cuit = preg_replace('/[^0-9]/', '', (string) ($in['cuit'] ?? ''));
    if ($cuit === '') {
        $cuit = null;
    } elseif (strlen($cuit) !== 11) {
        jsonError('El CUIT debe tener 11 digitos.', 400);
    }

    $moneda = strtoupper(trim((string) ($in['moneda'] ?? '')));
    if ($moneda !== '' && mb_strlen($moneda) > 1) jsonError('Moneda invalida.', 400);

    $descripcion = trim((string) ($in['descripcion'] ?? ''));
    if (mb_strlen($descripcion) > 500) $descripcion = mb_substr($descripcion, 0, 500);

    $referencia = trim((string) ($in['referencia'] ?? ''));
    if (mb_strlen($referencia) > 100) $referencia = mb_substr($referencia, 0, 100);

    $contraparte = trim((string) ($in['contraparte'] ?? ''));
    if (mb_strlen($contraparte) > 255) $contraparte = mb_substr($contraparte, 0, 255);

    return [
        'cuenta_id'     => $cuentaId > 0 ? $cuentaId : null,
        'fecha'         => dcbmFecha($in['fecha']       ?? null, 'La fecha',          $esAlta),
        'fecha_valor'   => dcbmFecha($in['fecha_valor'] ?? null, 'La fecha valor',    false),
        'tipo'          => $tipo === '' ? null : $tipo,
        'medio'         => $medio === '' ? null : $medio,
        'descripcion'   => $descripcion === '' ? null : $descripcion,
        'referencia'    => $referencia  === '' ? null : $referencia,
        'importe'       => $importe,
        'saldo'         => $saldo,
        'moneda'        => $moneda === '' ? null : $moneda,
        'contraparte'   => $contraparte === '' ? null : $contraparte,
        'cuit'          => $cuit,
        'conciliado'    => array_key_exists('conciliado', $in) ? (int) (bool) $in['conciliado'] : null,
        'pago_id'       => ($in['pago_id'] ?? null) ? (int) $in['pago_id'] : null,
        'observaciones' => trim((string) ($in['observaciones'] ?? '')) === ''
                             ? null : trim((string) $in['observaciones']),
    ];
}

// ----------------------------------------------------------------------------
// Handlers
// ----------------------------------------------------------------------------

function handleListMovBanco(PDO $pdo, array $q): void {
    $cuenta     = trim((string) ($q['cuenta']     ?? ''));
    $search     = trim((string) ($q['q']          ?? ''));
    $tipo       = trim((string) ($q['tipo']       ?? ''));
    $medio      = trim((string) ($q['medio']      ?? ''));
    $conciliado = trim((string) ($q['conciliado'] ?? ''));
    $origen     = trim((string) ($q['origen']     ?? ''));
    $desde      = trim((string) ($q['desde']      ?? ''));
    $hasta      = trim((string) ($q['hasta']      ?? ''));
    $limite     = max(1, min(1000, (int) ($q['limite'] ?? 100)));
    $orden      = in_array(($q['orden'] ?? ''), DCBM_ORDENES, true) ? $q['orden'] : 'fecha';
    $dir        = strtolower((string) ($q['dir'] ?? 'desc')) === 'asc' ? 'ASC' : 'DESC';

    $where  = [];
    $params = [];

    if ($cuenta !== '' && ctype_digit($cuenta)) {
        $where[] = 'cuenta_id = :cuenta';
        $params[':cuenta'] = (int) $cuenta;
    }
    if ($search !== '') {
        $where[] = '(descripcion LIKE :s1 OR referencia LIKE :s2 OR contraparte LIKE :s3)';
        $params[':s1'] = "%{$search}%";
        $params[':s2'] = "%{$search}%";
        $params[':s3'] = "%{$search}%";
    }
    if ($tipo !== '' && in_array($tipo, DCBM_TIPOS, true)) {
        $where[] = 'tipo = :tipo';
        $params[':tipo'] = $tipo;
    }
    if ($medio !== '') {
        $where[] = 'medio = :medio';
        $params[':medio'] = $medio;
    }
    if ($conciliado !== '' && ctype_digit($conciliado)) {
        $where[] = 'conciliado = :conc';
        $params[':conc'] = (int) $conciliado;
    }
    if ($origen === 'importado' || $origen === 'manual') {
        $where[] = 'origen = :origen';
        $params[':origen'] = $origen;
    }
    if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $desde)) {
        $where[] = 'fecha >= :desde';
        $params[':desde'] = $desde;
    }
    if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $hasta)) {
        $where[] = 'fecha <= :hasta';
        $params[':hasta'] = $hasta;
    }

    $sqlWhere = $where ? ('WHERE ' . implode(' AND ', $where)) : '';

    // Desempate por id: dentro del mismo dia el extracto trae varios
    // movimientos y sin segundo criterio el orden seria no determinista entre
    // requests, con filas saltando de lugar al refrescar.
    $st = $pdo->prepare(
        'SELECT ' . DCBM_COLS . " FROM datacount_bancos_movimientos {$sqlWhere}
          ORDER BY {$orden} {$dir}, id {$dir} LIMIT {$limite}"
    );
    $st->execute($params);
    $rows = array_map('normalizarFilaMovBanco', $st->fetchAll());

    // Las stats corren sobre TODO el filtro (no sobre el recorte de $limite):
    // el usuario quiere el neto del periodo consultado, no el de las 100 filas
    // que entraron en pantalla.
    $stSt = $pdo->prepare(
        "SELECT
            COUNT(*)                                                       AS total,
            COALESCE(SUM(CASE WHEN tipo = 'ingreso' THEN importe END), 0)  AS ingresos,
            COALESCE(SUM(CASE WHEN tipo = 'egreso'  THEN importe END), 0)  AS egresos,
            COALESCE(SUM(CASE WHEN conciliado = 0 THEN 1 END), 0)          AS sin_conciliar
           FROM datacount_bancos_movimientos {$sqlWhere}"
    );
    $stSt->execute($params);
    $s = $stSt->fetch() ?: [];

    $ingresos = (float) ($s['ingresos'] ?? 0);
    $egresos  = (float) ($s['egresos']  ?? 0);

    jsonOk([
        'items' => $rows,
        'stats' => [
            'total'         => (int) ($s['total'] ?? 0),
            'ingresos'      => $ingresos,
            'egresos'       => $egresos,
            'neto'          => round($ingresos - $egresos, 2),
            'sin_conciliar' => (int) ($s['sin_conciliar'] ?? 0),
        ],
    ]);
}

function handleGetOneMovBanco(PDO $pdo, int $id): void {
    $st = $pdo->prepare('SELECT ' . DCBM_COLS . ' FROM datacount_bancos_movimientos WHERE id = :id LIMIT 1');
    $st->execute([':id' => $id]);
    $row = $st->fetch();
    if (!$row) jsonError('Movimiento no encontrado', 404);
    jsonOk(normalizarFilaMovBanco($row));
}

// Devuelve el catalogo completo de medios mas el mapa tipo -> medios validos,
// para que el front filtre el combo al vuelo cuando cambia la cuenta elegida
// sin tener que volver a pegarle al server.
function handleLookupsMovBanco(PDO $pdo): void {
    $medios = $pdo->query(
        "SELECT valor, texto FROM estados
          WHERE campo = 'datacount_bancos_movimiento_medio' ORDER BY orden ASC, id ASC"
    )->fetchAll();

    // Se devuelven TODAS las cuentas con su `empresa_id`, no las de una empresa
    // puntual: el selector de empresa de la vista de movimientos filtra en el
    // front sobre esta lista, asi cambiar de empresa no dispara otro request.
    $cuentas = $pdo->query(
        "SELECT id, empresa_id, nombre, tipo, moneda, activa FROM datacount_bancos_cuentas
          ORDER BY activa DESC, nombre ASC"
    )->fetchAll();

    // Catalogo de empresas para poblar ese selector (mismo origen que el resto
    // de los modulos Datacount).
    $empresas = $pdo->query(
        "SELECT id, COALESCE(NULLIF(TRIM(nombre), ''), CONCAT('#', id)) AS nombre
           FROM datacount_empresas ORDER BY nombre ASC, id ASC"
    )->fetchAll();

    jsonOk([
        'medios' => array_map(fn($r) => [
            'valor' => (string) ($r['valor'] ?? ''),
            'texto' => (string) ($r['texto'] ?? ''),
        ], $medios),
        'medios_por_tipo' => DCB_MEDIOS_POR_TIPO,
        'cuentas' => array_map(fn($r) => [
            'id'         => (int) $r['id'],
            'empresa_id' => $r['empresa_id'] !== null ? (int) $r['empresa_id'] : null,
            'nombre'     => (string) $r['nombre'],
            'tipo'       => (string) $r['tipo'],
            'moneda'     => (string) $r['moneda'],
            'activa'     => (int) $r['activa'],
        ], $cuentas),
        'empresas' => array_map(fn($r) => [
            'id'     => (int) $r['id'],
            'nombre' => (string) $r['nombre'],
        ], $empresas),
    ]);
}

function handleCreateMovBanco(PDO $pdo, array $body): void {
    $p = sanitizePayloadMovBanco($pdo, $body, true);

    // Los movimientos manuales tambien llevan huella: si mañana el extracto del
    // banco trae el mismo movimiento que se habia cargado a mano, el UNIQUE lo
    // frena y no queda duplicado.
    $huella = planillaHuella(
        $p['fecha'], $p['tipo'], number_format($p['importe'], 2, '.', ''),
        $p['referencia'], $p['descripcion']
    );

    $st = $pdo->prepare(
        'INSERT INTO datacount_bancos_movimientos
            (cuenta_id, fecha, fecha_valor, tipo, medio, descripcion, referencia, importe,
             saldo, moneda, contraparte, cuit, conciliado, pago_id, huella, origen, observaciones)
         VALUES
            (:cuenta_id, :fecha, :fecha_valor, :tipo, :medio, :descripcion, :referencia, :importe,
             :saldo, :moneda, :contraparte, :cuit, :conciliado, :pago_id, :huella, :origen, :observaciones)'
    );

    try {
        $st->execute([
            ':cuenta_id'     => $p['cuenta_id'],
            ':fecha'         => $p['fecha'],
            ':fecha_valor'   => $p['fecha_valor'],
            ':tipo'          => $p['tipo'],
            ':medio'         => $p['medio'],
            ':descripcion'   => $p['descripcion'],
            ':referencia'    => $p['referencia'],
            ':importe'       => $p['importe'],
            ':saldo'         => $p['saldo'],
            ':moneda'        => $p['moneda'] ?? 'P',
            ':contraparte'   => $p['contraparte'],
            ':cuit'          => $p['cuit'],
            ':conciliado'    => $p['conciliado'] ?? 0,
            ':pago_id'       => $p['pago_id'],
            ':huella'        => $huella,
            ':origen'        => 'manual',
            ':observaciones' => $p['observaciones'],
        ]);
    } catch (PDOException $e) {
        if (($e->errorInfo[1] ?? 0) === 1062) {
            jsonError('Ya existe un movimiento igual en esta cuenta (misma fecha, importe y referencia).', 409);
        }
        throw $e;
    }

    $id = (int) $pdo->lastInsertId();
    registrarSuceso($pdo, 'datacount_bancos_movimientos', 'info',
        "Alta manual movimiento #{$id} — cuenta #{$p['cuenta_id']}, {$p['tipo']} {$p['importe']}");

    handleGetOneMovBanco($pdo, $id);
}

function handleUpdateMovBanco(PDO $pdo, int $id, array $body): void {
    $st = $pdo->prepare('SELECT cuenta_id, tipo, importe FROM datacount_bancos_movimientos WHERE id = :id LIMIT 1');
    $st->execute([':id' => $id]);
    $prev = $st->fetch();
    if (!$prev) jsonError('Movimiento no encontrado', 404);

    // La cuenta del movimiento no se puede cambiar desde el PUT (mover una fila
    // de cuenta invalidaria su huella contra la cuenta destino), pero el
    // sanitizador la necesita para validar `medio` contra el tipo correcto.
    $body['_cuenta_actual'] = (int) $prev['cuenta_id'];
    $p = sanitizePayloadMovBanco($pdo, $body, false);

    $campos = [
        'fecha', 'fecha_valor', 'tipo', 'medio', 'descripcion', 'referencia',
        'importe', 'saldo', 'moneda', 'contraparte', 'cuit', 'conciliado',
        'pago_id', 'observaciones',
    ];

    $sets   = [];
    $params = [':id' => $id];
    foreach ($campos as $c) {
        if (!array_key_exists($c, $body)) continue;
        // Columnas NOT NULL: si el body las manda vacias se ignoran.
        if (in_array($c, ['fecha', 'tipo', 'importe'], true) && $p[$c] === null) continue;
        $sets[]          = "{$c} = :{$c}";
        $params[":{$c}"] = $p[$c];
    }
    if (empty($sets)) jsonError('No hay campos para actualizar.', 400);

    // Si cambio algo que entra en la huella hay que recalcularla, si no dos
    // ediciones distintas podrian converger al mismo movimiento y el UNIQUE
    // dejaria de protegernos del duplicado.
    $tocaHuella = array_intersect(['fecha', 'tipo', 'importe', 'referencia', 'descripcion'], array_keys($body));
    if ($tocaHuella) {
        $cur = $pdo->prepare(
            'SELECT fecha, tipo, importe, referencia, descripcion
               FROM datacount_bancos_movimientos WHERE id = :id LIMIT 1'
        );
        $cur->execute([':id' => $id]);
        $c = $cur->fetch() ?: [];
        $nuevo = [
            'fecha'       => $p['fecha']       ?? $c['fecha'],
            'tipo'        => $p['tipo']        ?? $c['tipo'],
            'importe'     => $p['importe']     ?? (float) $c['importe'],
            'referencia'  => array_key_exists('referencia',  $body) ? $p['referencia']  : $c['referencia'],
            'descripcion' => array_key_exists('descripcion', $body) ? $p['descripcion'] : $c['descripcion'],
        ];
        $sets[]             = 'huella = :huella';
        $params[':huella']  = planillaHuella(
            (string) $nuevo['fecha'], (string) $nuevo['tipo'],
            number_format((float) $nuevo['importe'], 2, '.', ''),
            $nuevo['referencia'], $nuevo['descripcion']
        );
    }

    $sql = 'UPDATE datacount_bancos_movimientos SET ' . implode(', ', $sets) . ' WHERE id = :id';
    try {
        $pdo->prepare($sql)->execute($params);
    } catch (PDOException $e) {
        if (($e->errorInfo[1] ?? 0) === 1062) {
            jsonError('El cambio deja el movimiento identico a otro ya existente en la cuenta.', 409);
        }
        throw $e;
    }

    registrarSuceso($pdo, 'datacount_bancos_movimientos', 'info',
        "Modificación movimiento #{$id} — cuenta #{$prev['cuenta_id']}");

    handleGetOneMovBanco($pdo, $id);
}

function handleDeleteMovBanco(PDO $pdo, int $id): void {
    $st = $pdo->prepare(
        'SELECT cuenta_id, fecha, tipo, importe, contrapartida_id
           FROM datacount_bancos_movimientos WHERE id = :id LIMIT 1'
    );
    $st->execute([':id' => $id]);
    $prev = $st->fetch();
    if (!$prev) jsonError('Movimiento no encontrado', 404);

    // Si el movimiento era una pata de una transferencia interna, la otra pata
    // queda huerfana: se le limpia el puntero para que no apunte a un id que ya
    // no existe.
    if ($prev['contrapartida_id'] !== null) {
        $pdo->prepare(
            'UPDATE datacount_bancos_movimientos SET contrapartida_id = NULL WHERE id = :c'
        )->execute([':c' => (int) $prev['contrapartida_id']]);
    }

    $pdo->prepare('DELETE FROM datacount_bancos_movimientos WHERE id = :id')->execute([':id' => $id]);

    registrarSuceso($pdo, 'datacount_bancos_movimientos', 'info',
        "Baja movimiento #{$id} — cuenta #{$prev['cuenta_id']}, {$prev['tipo']} {$prev['importe']} del {$prev['fecha']}");

    jsonOk(['id' => $id]);
}
