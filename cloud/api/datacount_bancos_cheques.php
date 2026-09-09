<?php
// api/datacount_bancos_cheques.php
// Cheques Datacount (CRUD). Lee/escribe sobre la tabla
// `datacount_bancos_cheques` definida en db/schema.sql — cada fila es un cheque
// concreto emitido de una chequera (`datacount_bancos_chequeras`).
//
// El cheque guarda lo que dice el papel: numero, fechas, importe, beneficiario,
// concepto, modo/caracter de libramiento y en que anda (`estado`). Lo que hereda
// de su origen NO se duplica: el tipo (comun/diferido) es de la chequera, y la
// cuenta, el banco y la moneda son de la cuenta de fondos. Todo eso viaja por
// JOIN.
//
// `empresa_id` es la unica excepcion: se guarda -- es el filtro mas usado del
// modulo y derivarlo cuesta tres saltos -- pero lo escribe el backend desde la
// chequera. El body del ABM no lo manda y si lo mandara se ignora.
//
//   GET    api/datacount_bancos_cheques.php[?q=..&empresa=..&chequera=..&estado=..&desde=..&hasta=..&limite=100&orden=fecha_pago&dir=desc]
//                                       -> listado + stats
//   GET    api/datacount_bancos_cheques.php?id=N
//                                       -> registro individual
//   GET    api/datacount_bancos_cheques.php?lookups=1
//                                       -> catalogos (chequeras, estados)
//   POST   api/datacount_bancos_cheques.php   -> alta (JSON body)
//   PUT    api/datacount_bancos_cheques.php?id=N
//                                       -> modificacion (JSON body)
//   DELETE api/datacount_bancos_cheques.php?id=N
//                                       -> baja
//
// Respuesta siempre {ok: true, data: ...} u {ok: false, error: '...'} (STACK.md sec. 10).

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/lib/auth_check.php';
require_once __DIR__ . '/lib/sucesos.php';

requireAuth();
header('Content-Type: application/json; charset=utf-8');

const DCQ_ORDENES  = ['id', 'numero', 'fecha_emision', 'fecha_pago', 'importe',
                      'beneficiario_razon', 'estado'];
const DCQ_ESTADOS  = ['emitido', 'entregado', 'depositado', 'pagado', 'rechazado', 'anulado'];
const DCQ_MODOS    = ['cruzado', 'no_cruzado'];
const DCQ_CARACTER = ['a_la_orden', 'no_a_la_orden'];

// La cadena cheque -> chequera -> cuenta -> banco/empresa. Es INNER hasta la
// cuenta (sin cuenta el cheque no existe) y LEFT de ahi para afuera: un banco o
// una empresa faltante no puede esconder un cheque del listado.
const DCQ_SELECT = "
    SELECT q.id, q.empresa_id, q.chequera_id, q.numero,
           q.fecha_emision, q.fecha_pago, q.importe,
           q.beneficiario_razon, q.beneficiario_cuit, q.beneficiario_correo,
           q.concepto, q.referencia, q.modo, q.caracter, q.estado,
           q.operacion_numero, q.observaciones, q.created_at, q.updated_at,
           ch.tipo       AS chequera_tipo,
           ch.cuenta_id  AS cuenta_id,
           cu.nombre     AS cuenta_nombre,
           cu.numero     AS cuenta_numero,
           cu.moneda     AS moneda,
           cu.banco_id   AS banco_id,
           NULLIF(TRIM(COALESCE(b.nombre, '')), '') AS banco_nombre,
           NULLIF(TRIM(COALESCE(e.nombre, '')), '') AS empresa_nombre
      FROM datacount_bancos_cheques    q
      INNER JOIN datacount_bancos_chequeras ch ON ch.id = q.chequera_id
      INNER JOIN datacount_bancos_cuentas   cu ON cu.id = ch.cuenta_id
      LEFT  JOIN datacountbancos            b  ON b.id  = cu.banco_id
      LEFT  JOIN datacount_empresas         e  ON e.id  = q.empresa_id
";

try {
    requirePermCrud('datacount.bancos.cheques');
    $pdo    = db();
    $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
    $id     = isset($_GET['id']) ? (int)$_GET['id'] : 0;

    if ($method === 'GET' && ($_GET['lookups'] ?? '') !== '') {
        handleLookupsCheque($pdo);
    } elseif ($method === 'GET' && $id > 0) {
        handleGetOneCheque($pdo, $id);
    } elseif ($method === 'GET') {
        handleListCheques($pdo, $_GET);
    } elseif ($method === 'POST') {
        handleCreateCheque($pdo, readJsonBody());
    } elseif ($method === 'PUT') {
        if ($id <= 0) jsonError('Falta id', 400);
        handleUpdateCheque($pdo, $id, readJsonBody());
    } elseif ($method === 'DELETE') {
        if ($id <= 0) jsonError('Falta id', 400);
        handleDeleteCheque($pdo, $id);
    } else {
        jsonError('Método no soportado', 405);
    }
} catch (Throwable $e) {
    jsonError($e->getMessage(), 500);
}

// ----------------------------------------------------------------------------
// Helpers
// ----------------------------------------------------------------------------

function normalizarFilaCheque(array $r): array {
    return [
        'id'                  => (int)($r['id'] ?? 0),
        'chequera_id'         => (int)($r['chequera_id'] ?? 0),
        'empresa_id'          => $r['empresa_id']          !== null ? (int)$r['empresa_id'] : null,
        'numero'              => (string)($r['numero'] ?? ''),
        'fecha_emision'       => $r['fecha_emision']       !== null ? (string)$r['fecha_emision'] : null,
        'fecha_pago'          => $r['fecha_pago']          !== null ? (string)$r['fecha_pago']    : null,
        'importe'             => (float)($r['importe'] ?? 0),
        'beneficiario_razon'  => (string)($r['beneficiario_razon'] ?? ''),
        'beneficiario_cuit'   => $r['beneficiario_cuit']   !== null ? (string)$r['beneficiario_cuit']   : null,
        'beneficiario_correo' => $r['beneficiario_correo'] !== null ? (string)$r['beneficiario_correo'] : null,
        'concepto'            => $r['concepto']            !== null ? (string)$r['concepto']            : null,
        'referencia'          => $r['referencia']          !== null ? (string)$r['referencia']          : null,
        'modo'                => (string)($r['modo'] ?? 'cruzado'),
        'caracter'            => (string)($r['caracter'] ?? 'a_la_orden'),
        'estado'              => (string)($r['estado'] ?? 'emitido'),
        'operacion_numero'    => $r['operacion_numero']    !== null ? (string)$r['operacion_numero']    : null,
        'observaciones'       => $r['observaciones']       !== null ? (string)$r['observaciones']       : null,
        // Heredados de la chequera y de su cuenta — solo lectura.
        'chequera_tipo'       => $r['chequera_tipo']       !== null ? (string)$r['chequera_tipo']       : null,
        'cuenta_id'           => $r['cuenta_id']           !== null ? (int)$r['cuenta_id']              : null,
        'cuenta_nombre'       => $r['cuenta_nombre']       !== null ? (string)$r['cuenta_nombre']       : null,
        'cuenta_numero'       => $r['cuenta_numero']       !== null ? (string)$r['cuenta_numero']       : null,
        'moneda'              => $r['moneda']              !== null ? (string)$r['moneda']              : null,
        'banco_id'            => $r['banco_id']            !== null ? (int)$r['banco_id']               : null,
        'banco_nombre'        => $r['banco_nombre']        !== null ? (string)$r['banco_nombre']        : null,
        'empresa_nombre'      => $r['empresa_nombre']      !== null ? (string)$r['empresa_nombre']      : null,
        'created_at'          => $r['created_at']          !== null ? (string)$r['created_at']          : null,
        'updated_at'          => $r['updated_at']          !== null ? (string)$r['updated_at']          : null,
    ];
}

// Fecha en formato ISO (YYYY-MM-DD) o null. `checkdate` ataja el 2026-02-31,
// que MySQL aceptaria como '0000-00-00' o rechazaria segun el sql_mode.
function fechaOpcionalCheque($v, string $etiqueta): ?string {
    $s = trim((string)($v ?? ''));
    if ($s === '') return null;
    if (!preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $s, $m)) {
        jsonError("La {$etiqueta} debe tener formato AAAA-MM-DD.", 400);
    }
    if (!checkdate((int)$m[2], (int)$m[3], (int)$m[1])) {
        jsonError("La {$etiqueta} no es una fecha válida.", 400);
    }
    return $s;
}

function sanitizePayloadCheque(array $in, bool $esAlta): array {
    $numero   = trim((string)($in['numero']              ?? ''));
    $razon    = trim((string)($in['beneficiario_razon']  ?? ''));
    $correo   = trim((string)($in['beneficiario_correo'] ?? ''));
    $concepto = trim((string)($in['concepto']            ?? ''));
    $refer    = trim((string)($in['referencia']          ?? ''));
    $operNum  = trim((string)($in['operacion_numero']    ?? ''));
    $observ   = trim((string)($in['observaciones']       ?? ''));
    $modo     = trim((string)($in['modo']                ?? ''));
    $caracter = trim((string)($in['caracter']            ?? ''));
    $estado   = trim((string)($in['estado']              ?? ''));

    // El CUIT se guarda solo con digitos: es lo que permite comparar contra
    // `datacount_proveedores.cuit` sin pelear con guiones.
    $cuit = preg_replace('/\D+/', '', (string)($in['beneficiario_cuit'] ?? ''));

    $chequeraId = null;
    if (array_key_exists('chequera_id', $in)) {
        $chequeraId = (int)$in['chequera_id'];
        if ($chequeraId <= 0) $chequeraId = null;
    }

    $emision = array_key_exists('fecha_emision', $in)
        ? fechaOpcionalCheque($in['fecha_emision'], 'fecha de emisión') : null;
    $pago = array_key_exists('fecha_pago', $in)
        ? fechaOpcionalCheque($in['fecha_pago'], 'fecha de pago') : null;

    $importe = null;
    if (array_key_exists('importe', $in) && trim((string)$in['importe']) !== '') {
        $importe = round((float)$in['importe'], 2);
    }

    if ($esAlta) {
        if ($chequeraId === null) jsonError('La chequera es obligatoria.', 400);
        if ($numero    === '')    jsonError('El número de cheque es obligatorio.', 400);
        if ($emision   === null)  jsonError('La fecha de emisión es obligatoria.', 400);
        if ($pago      === null)  jsonError('La fecha de pago es obligatoria.', 400);
        if ($importe   === null)  jsonError('El importe es obligatorio.', 400);
        if ($razon     === '')    jsonError('La razón social del beneficiario es obligatoria.', 400);
        if ($modo      === '')    $modo     = 'cruzado';
        if ($caracter  === '')    $caracter = 'a_la_orden';
        if ($estado    === '')    $estado   = 'emitido';
    }

    // Un cheque no se puede presentar antes de librarse. Solo se compara cuando
    // vienen las dos: en un PUT parcial el chequeo se rehace contra lo guardado.
    if ($emision !== null && $pago !== null && $pago < $emision) {
        jsonError('La fecha de pago no puede ser anterior a la de emisión.', 400);
    }

    if ($importe !== null && $importe <= 0) jsonError('El importe debe ser mayor a cero.', 400);

    if ($numero   !== '' && mb_strlen($numero)   > 30)  jsonError('El número de cheque no puede superar los 30 caracteres.', 400);
    if ($razon    !== '' && mb_strlen($razon)    > 255) jsonError('La razón social no puede superar los 255 caracteres.', 400);
    if ($cuit     !== '' && (mb_strlen($cuit) < 8 || mb_strlen($cuit) > 20)) jsonError('El CUIT del beneficiario no es válido.', 400);
    if ($correo   !== '' && mb_strlen($correo)   > 100) jsonError('El correo no puede superar los 100 caracteres.', 400);
    if ($concepto !== '' && mb_strlen($concepto) > 255) jsonError('El concepto no puede superar los 255 caracteres.', 400);
    if ($refer    !== '' && mb_strlen($refer)    > 100) jsonError('La referencia no puede superar los 100 caracteres.', 400);
    if ($operNum  !== '' && mb_strlen($operNum)  > 64)  jsonError('El número de operación no puede superar los 64 caracteres.', 400);

    if ($correo !== '' && !filter_var($correo, FILTER_VALIDATE_EMAIL)) {
        jsonError('El correo del beneficiario no es válido.', 400);
    }
    if ($modo     !== '' && !in_array($modo,     DCQ_MODOS,    true)) jsonError('Modo de cheque no válido.', 400);
    if ($caracter !== '' && !in_array($caracter, DCQ_CARACTER, true)) jsonError('Carácter de cheque no válido.', 400);
    if ($estado   !== '' && !in_array($estado,   DCQ_ESTADOS,  true)) jsonError('Estado de cheque no válido.', 400);

    return [
        'chequera_id'         => $chequeraId,
        'numero'              => $numero   === '' ? null : $numero,
        'fecha_emision'       => $emision,
        'fecha_pago'          => $pago,
        'importe'             => $importe,
        'beneficiario_razon'  => $razon    === '' ? null : $razon,
        'beneficiario_cuit'   => $cuit     === '' ? null : $cuit,
        'beneficiario_correo' => $correo   === '' ? null : $correo,
        'concepto'            => $concepto === '' ? null : $concepto,
        'referencia'          => $refer    === '' ? null : $refer,
        'modo'                => $modo     === '' ? null : $modo,
        'caracter'            => $caracter === '' ? null : $caracter,
        'estado'              => $estado   === '' ? null : $estado,
        'operacion_numero'    => $operNum  === '' ? null : $operNum,
        'observaciones'       => $observ   === '' ? null : $observ,
    ];
}

// Devuelve la chequera con la empresa de su cuenta, o corta con 400. Es lo que
// alimenta `empresa_id`: el ABM elige una chequera y la empresa se deduce.
function chequeraDeCheque(PDO $pdo, int $chequeraId): array {
    $st = $pdo->prepare(
        'SELECT ch.id, ch.tipo, cu.empresa_id
           FROM datacount_bancos_chequeras ch
           INNER JOIN datacount_bancos_cuentas cu ON cu.id = ch.cuenta_id
          WHERE ch.id = :id LIMIT 1'
    );
    $st->execute([':id' => $chequeraId]);
    $row = $st->fetch();
    if (!$row) jsonError('La chequera seleccionada no existe.', 400);
    return $row;
}

// ----------------------------------------------------------------------------
// Handlers
// ----------------------------------------------------------------------------

function handleListCheques(PDO $pdo, array $q): void {
    $search   = trim((string)($q['q']        ?? ''));
    $empresa  = trim((string)($q['empresa']  ?? ''));
    $chequera = trim((string)($q['chequera'] ?? ''));
    $estado   = trim((string)($q['estado']   ?? ''));
    $desde    = trim((string)($q['desde']    ?? ''));
    $hasta    = trim((string)($q['hasta']    ?? ''));
    $limite   = max(1, min(1000, (int)($q['limite'] ?? 100)));
    $orden    = in_array(($q['orden'] ?? ''), DCQ_ORDENES, true) ? $q['orden'] : 'fecha_pago';
    $dir      = strtolower((string)($q['dir'] ?? 'desc')) === 'asc' ? 'ASC' : 'DESC';

    $where  = [];
    $params = [];

    // `utf8mb4_general_ci` ya pliega mayusculas y acentos: el LIKE crudo alcanza
    // para que "cerro" matchee "CERRO DIGITAL".
    if ($search !== '') {
        $where[] = '(q.numero LIKE :s_num OR q.beneficiario_razon LIKE :s_raz
                     OR q.beneficiario_cuit LIKE :s_cui OR q.concepto LIKE :s_con
                     OR q.referencia LIKE :s_ref)';
        foreach (['s_num', 's_raz', 's_cui', 's_con', 's_ref'] as $k) {
            $params[":{$k}"] = "%{$search}%";
        }
    }
    if ($empresa !== '' && ctype_digit($empresa)) {
        $where[] = 'q.empresa_id = :empresa';
        $params[':empresa'] = (int)$empresa;
    }
    if ($chequera !== '' && ctype_digit($chequera)) {
        $where[] = 'q.chequera_id = :chequera';
        $params[':chequera'] = (int)$chequera;
    }
    if ($estado !== '' && in_array($estado, DCQ_ESTADOS, true)) {
        $where[] = 'q.estado = :estado';
        $params[':estado'] = $estado;
    }
    // El rango es sobre `fecha_pago`: es la fecha por la que se mira un cheque
    // emitido ("que me vence este mes"), no por cuando se libro.
    if ($desde !== '') {
        $where[] = 'q.fecha_pago >= :desde';
        $params[':desde'] = fechaOpcionalCheque($desde, 'fecha desde');
    }
    if ($hasta !== '') {
        $where[] = 'q.fecha_pago <= :hasta';
        $params[':hasta'] = fechaOpcionalCheque($hasta, 'fecha hasta');
    }

    $sqlWhere = $where ? ('WHERE ' . implode(' AND ', $where)) : '';
    $st = $pdo->prepare(DCQ_SELECT . " {$sqlWhere} ORDER BY q.{$orden} {$dir}, q.id DESC LIMIT {$limite}");
    $st->execute($params);
    $rows = array_map('normalizarFilaCheque', $st->fetchAll());

    // Stats acotadas a la empresa y la chequera activas -- el listado tambien lo
    // esta, y un total global al lado de un listado filtrado se lee como si
    // faltaran filas. `pendiente` = lo emitido que todavia no se debito ni murio.
    $sw = [];
    $sp = [];
    if ($empresa !== '' && ctype_digit($empresa)) {
        $sw[] = 'empresa_id = :empresa';
        $sp[':empresa'] = (int)$empresa;
    }
    if ($chequera !== '' && ctype_digit($chequera)) {
        $sw[] = 'chequera_id = :chequera';
        $sp[':chequera'] = (int)$chequera;
    }
    $swSql = $sw ? ('WHERE ' . implode(' AND ', $sw)) : '';
    $cond = fn($extra) => $swSql === ''
        ? ($extra === '' ? '' : "WHERE {$extra}")
        : ($extra === '' ? $swSql : "{$swSql} AND {$extra}");

    $pendiente = "estado IN ('emitido','entregado','depositado')";
    $stats = [];
    foreach ([
        'total'      => ['COUNT(*)',       ''],
        'pendientes' => ['COUNT(*)',       $pendiente],
        'importe'    => ['COALESCE(SUM(importe), 0)', "estado <> 'anulado'"],
        'a_pagar'    => ['COALESCE(SUM(importe), 0)', $pendiente],
        'rechazados' => ['COUNT(*)',       "estado = 'rechazado'"],
    ] as $clave => [$agg, $extra]) {
        $s = $pdo->prepare("SELECT {$agg} FROM datacount_bancos_cheques " . $cond($extra));
        $s->execute($sp);
        $val = $s->fetchColumn();
        $stats[$clave] = str_starts_with($agg, 'COUNT') ? (int)$val : (float)$val;
    }

    jsonOk(['items' => $rows, 'stats' => $stats]);
}

function handleGetOneCheque(PDO $pdo, int $id): void {
    $st = $pdo->prepare(DCQ_SELECT . ' WHERE q.id = :id LIMIT 1');
    $st->execute([':id' => $id]);
    $row = $st->fetch();
    if (!$row) jsonError('Cheque no encontrado', 404);
    jsonOk(normalizarFilaCheque($row));
}

// Catalogos para los <select> del formulario y del modal de filtros:
// - chequeras: con su cuenta, banco y empresa ya resueltos, para que el ABM
//   arme la etiqueta del combo y filtre por la empresa activa sin invalidar el
//   cache al cambiar de empresa.
// - estados: catalogo `estados` con campo=`datacount_bancos_cheque_estado`.
function handleLookupsCheque(PDO $pdo): void {
    $chequeras = $pdo->query(
        "SELECT ch.id, ch.tipo, ch.activa,
                cu.id AS cuenta_id, cu.empresa_id, cu.numero AS cuenta_numero, cu.moneda,
                COALESCE(NULLIF(TRIM(cu.nombre), ''), CONCAT('Cuenta #', cu.id)) AS cuenta_nombre,
                NULLIF(TRIM(COALESCE(b.nombre, '')), '') AS banco_nombre
           FROM datacount_bancos_chequeras ch
           INNER JOIN datacount_bancos_cuentas cu ON cu.id = ch.cuenta_id
           LEFT  JOIN datacountbancos          b  ON b.id  = cu.banco_id
          ORDER BY cu.nombre ASC, ch.tipo ASC, ch.id ASC"
    )->fetchAll();

    $estados = $pdo->query(
        "SELECT valor, texto FROM estados
          WHERE campo = 'datacount_bancos_cheque_estado'
          ORDER BY orden ASC, id ASC"
    )->fetchAll();

    jsonOk([
        'chequeras' => array_map(fn($r) => [
            'id'            => (int)$r['id'],
            'tipo'          => (string)$r['tipo'],
            'activa'        => (int)$r['activa'],
            'cuenta_id'     => (int)$r['cuenta_id'],
            'cuenta_nombre' => (string)$r['cuenta_nombre'],
            'cuenta_numero' => $r['cuenta_numero'] !== null ? (string)$r['cuenta_numero'] : null,
            'moneda'        => $r['moneda']        !== null ? (string)$r['moneda']        : null,
            'empresa_id'    => $r['empresa_id']    !== null ? (int)$r['empresa_id']       : null,
            'banco_nombre'  => $r['banco_nombre']  !== null ? (string)$r['banco_nombre']  : null,
        ], $chequeras),
        'estados' => array_map(fn($r) => [
            'valor' => (string)($r['valor'] ?? ''),
            'texto' => (string)($r['texto'] ?? ''),
        ], $estados),
    ]);
}

function handleCreateCheque(PDO $pdo, array $body): void {
    $p  = sanitizePayloadCheque($body, true);
    $ch = chequeraDeCheque($pdo, $p['chequera_id']);

    $st = $pdo->prepare(
        'INSERT INTO datacount_bancos_cheques
            (empresa_id, chequera_id, numero, fecha_emision, fecha_pago, importe,
             beneficiario_razon, beneficiario_cuit, beneficiario_correo,
             concepto, referencia, modo, caracter, estado,
             operacion_numero, observaciones)
         VALUES
            (:empresa_id, :chequera_id, :numero, :fecha_emision, :fecha_pago, :importe,
             :beneficiario_razon, :beneficiario_cuit, :beneficiario_correo,
             :concepto, :referencia, :modo, :caracter, :estado,
             :operacion_numero, :observaciones)'
    );

    try {
        $st->execute([
            ':empresa_id'          => $ch['empresa_id'],
            ':chequera_id'         => $p['chequera_id'],
            ':numero'              => $p['numero'],
            ':fecha_emision'       => $p['fecha_emision'],
            ':fecha_pago'          => $p['fecha_pago'],
            ':importe'             => $p['importe'],
            ':beneficiario_razon'  => $p['beneficiario_razon'],
            ':beneficiario_cuit'   => $p['beneficiario_cuit'],
            ':beneficiario_correo' => $p['beneficiario_correo'],
            ':concepto'            => $p['concepto'],
            ':referencia'          => $p['referencia'],
            ':modo'                => $p['modo'],
            ':caracter'            => $p['caracter'],
            ':estado'              => $p['estado'],
            ':operacion_numero'    => $p['operacion_numero'],
            ':observaciones'       => $p['observaciones'],
        ]);
    } catch (PDOException $e) {
        // uk_chequera_numero: el mismo cheque cargado dos veces desde el ticket.
        if (($e->errorInfo[1] ?? 0) === 1062) {
            jsonError("El cheque N.º {$p['numero']} ya está cargado en esa chequera.", 409);
        }
        throw $e;
    }

    $id = (int)$pdo->lastInsertId();
    registrarSuceso($pdo, 'datacount_bancos_cheques', 'info',
        "Alta cheque #{$id} — N.º {$p['numero']} a \"{$p['beneficiario_razon']}\"");

    handleGetOneCheque($pdo, $id);
}

function handleUpdateCheque(PDO $pdo, int $id, array $body): void {
    $st = $pdo->prepare(
        'SELECT id, chequera_id, numero, fecha_emision, fecha_pago
           FROM datacount_bancos_cheques WHERE id = :id LIMIT 1'
    );
    $st->execute([':id' => $id]);
    $prev = $st->fetch();
    if (!$prev) jsonError('Cheque no encontrado', 404);

    $p = sanitizePayloadCheque($body, false);

    // En un PUT parcial puede venir una sola fecha; la otra sale de lo guardado.
    // Sin esto se podría mover la emisión más allá de la fecha de pago vigente.
    $emisionFinal = $p['fecha_emision'] ?? (string)$prev['fecha_emision'];
    $pagoFinal    = $p['fecha_pago']    ?? (string)$prev['fecha_pago'];
    if ($emisionFinal !== '' && $pagoFinal !== '' && $pagoFinal < $emisionFinal) {
        jsonError('La fecha de pago no puede ser anterior a la de emisión.', 400);
    }

    $sets   = [];
    $params = [':id' => $id];

    // Reasignar la chequera arrastra `empresa_id`: si no, el cheque quedaría
    // filtrado bajo la empresa vieja aunque su cuenta sea de otra.
    if (array_key_exists('chequera_id', $body) && $p['chequera_id'] !== null) {
        $ch = chequeraDeCheque($pdo, $p['chequera_id']);
        $sets[] = 'chequera_id = :chequera_id';
        $sets[] = 'empresa_id = :empresa_id';
        $params[':chequera_id'] = $p['chequera_id'];
        $params[':empresa_id']  = $ch['empresa_id'];
    }

    // Columnas NOT NULL: un '' del formulario no puede vaciarlas.
    foreach (['numero', 'fecha_emision', 'fecha_pago', 'importe',
              'beneficiario_razon', 'modo', 'caracter', 'estado'] as $campo) {
        if (array_key_exists($campo, $body) && $p[$campo] !== null) {
            $sets[] = "{$campo} = :{$campo}";
            $params[":{$campo}"] = $p[$campo];
        }
    }
    // Columnas nullables: se pisan tal cual vengan, '' incluido (= limpiar).
    foreach (['beneficiario_cuit', 'beneficiario_correo', 'concepto',
              'referencia', 'operacion_numero', 'observaciones'] as $campo) {
        if (array_key_exists($campo, $body)) {
            $sets[] = "{$campo} = :{$campo}";
            $params[":{$campo}"] = $p[$campo];
        }
    }

    if (empty($sets)) jsonError('No hay campos para actualizar.', 400);

    $sql = 'UPDATE datacount_bancos_cheques SET ' . implode(', ', $sets) . ' WHERE id = :id';
    $st  = $pdo->prepare($sql);
    try {
        $st->execute($params);
    } catch (PDOException $e) {
        if (($e->errorInfo[1] ?? 0) === 1062) {
            jsonError('Ya hay un cheque con ese número en esa chequera.', 409);
        }
        throw $e;
    }

    registrarSuceso($pdo, 'datacount_bancos_cheques', 'info',
        "Modificación cheque #{$id} — N.º {$prev['numero']}");

    handleGetOneCheque($pdo, $id);
}

function handleDeleteCheque(PDO $pdo, int $id): void {
    $st = $pdo->prepare('SELECT numero, beneficiario_razon FROM datacount_bancos_cheques WHERE id = :id LIMIT 1');
    $st->execute([':id' => $id]);
    $prev = $st->fetch();
    if (!$prev) jsonError('Cheque no encontrado', 404);

    $sd = $pdo->prepare('DELETE FROM datacount_bancos_cheques WHERE id = :id');
    $sd->execute([':id' => $id]);

    registrarSuceso($pdo, 'datacount_bancos_cheques', 'info',
        "Baja cheque #{$id} — N.º {$prev['numero']} a \"{$prev['beneficiario_razon']}\"");

    jsonOk(['id' => $id]);
}
