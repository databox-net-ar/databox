<?php
// api/datacount_comprobantes.php
// ABM de comprobantes Datacount. Lee/escribe sobre la tabla `datacount_comprobantes`
// definida en db/schema.sql.
//   GET    api/datacount_comprobantes.php          -> listado con filtros (query string)
//   GET    api/datacount_comprobantes.php?id=N     -> registro individual
//   POST   api/datacount_comprobantes.php          -> alta (JSON body)
//   PUT    api/datacount_comprobantes.php?id=N     -> modificacion (JSON body)
//   DELETE api/datacount_comprobantes.php?id=N     -> baja
// Respuesta siempre {ok: true, data: ...} u {ok: false, error: '...'} (STACK.md sec. 10).

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/lib/auth_check.php';
require_once __DIR__ . '/lib/datacount_comprobantes_autorizar.php';
require_once __DIR__ . '/lib/parametros.php';
require_once __DIR__ . '/lib/sucesos.php';
require_once __DIR__ . '/lib/s3.php';

// Prefijo S3 donde `datacount_talonarios_fondo.php` sube las imagenes de fondo
// de cada talonario. Debe matchear el de datacount_talonarios.php.
const DC_COMP_FONDO_PREFIX = 'datacount/talonarios/';

// Columnas SELECT comunes (debe declararse ANTES del dispatch porque PHP procesa
// los `const` a nivel de archivo secuencialmente).
const DC_COLS = "id, uuid, talonario, proyecto, empresa, tipo, punto, serie, fiscal,
                 caenro, caevto, caeres, emision, vencimiento, concepto,
                 asociado, contrato, cliente, razon, condicion, cuit, domicilio,
                 correo, celular, neto, iva, total, observaciones, comentarios,
                 medio, registrado, autorizado, estado";

// Columnas de datacount_comprobantes que estan bajo control del payload.
// uuid / registrado se setean aparte en el alta (no via payload).
const DC_PAYLOAD_COLS = [
    'talonario', 'proyecto', 'empresa', 'tipo', 'punto', 'serie', 'fiscal',
    'caenro', 'caevto', 'caeres', 'emision', 'vencimiento', 'concepto',
    'asociado', 'contrato', 'cliente', 'razon', 'condicion', 'cuit', 'domicilio',
    'correo', 'celular', 'neto', 'iva', 'total', 'observaciones',
    'comentarios', 'medio', 'autorizado', 'estado',
];

// Maquina de estados admitida por el endpoint PUT ?action=estado.
// Valores segun catalogo `estados` (campo = 'datacount_comprobante_estado'):
//   '1' Preparacion, '2' Pendiente, '3' Autorizado, '0' Rechazado,
//   '4' Anulado, '5' Aprobado (equivalente no-fiscal de Autorizado).
// La UI muestra u oculta las opciones del menu contextual segun este mismo mapa
// (ver DCCOMP_ACCIONES_POR_ESTADO en assets/js/app.js).
const DC_TRANSICIONES_ESTADO = [
    '1' => ['2', '4'],  // Preparacion -> Pendiente | Anulado
    '2' => ['1', '4'],  // Pendiente   -> Preparacion | Anulado
    '3' => [],          // Autorizado  -> (terminal, con CAE)
    '0' => [],          // Rechazado   -> (terminal)
    '4' => ['1'],       // Anulado     -> Preparacion
    '5' => ['4'],       // Aprobado    -> Anulado (unica salida; el nro ya se emitio)
];

header('Content-Type: application/json; charset=utf-8');

try {
    $pdo    = db();
    $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
    $id     = isset($_GET['id']) ? (int)$_GET['id'] : 0;
    $action = (string)($_GET['action'] ?? '');

    // La accion `autorizar` NO usa el permiso CRUD (agregar) -- obtener un
    // CAE contra AFIP es una capacidad propia: `datacount.comprobantes.autorizar_manual`.
    // Se autoriza aca antes de entrar al dispatch normal.
    if ($method === 'POST' && $action === 'autorizar') {
        requirePermission('datacount.comprobantes.autorizar_manual');
        if ($id <= 0) jsonError('Falta id', 400);
        handleAutorizar($pdo, $id);
        exit;
    }

    // La accion `aprobar` es el equivalente no-fiscal de `autorizar`: asigna
    // el proximo correlativo del talonario y cierra el comprobante en estado
    // '5' Aprobado, sin AFIP. Permiso propio: `datacount.comprobantes.aprobar_manual`.
    if ($method === 'POST' && $action === 'aprobar') {
        requirePermission('datacount.comprobantes.aprobar_manual');
        if ($id <= 0) jsonError('Falta id', 400);
        handleAprobar($pdo, $id);
        exit;
    }

    requirePermCrud('datacount.comprobantes');

    if ($method === 'GET' && ($_GET['lookups'] ?? '') !== '') {
        handleLookups($pdo);
    } elseif ($method === 'GET' && $id > 0) {
        handleGetOne($pdo, $id);
    } elseif ($method === 'GET') {
        handleList($pdo, $_GET);
    } elseif ($method === 'POST' && $action === 'clonar') {
        if ($id <= 0) jsonError('Falta id', 400);
        handleClonar($pdo, $id);
    } elseif ($method === 'POST') {
        handleCreate($pdo, readJsonBody());
    } elseif ($method === 'PUT' && $action === 'estado') {
        if ($id <= 0) jsonError('Falta id', 400);
        handleCambiarEstado($pdo, $id, readJsonBody());
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
    $codigo    = isset($q['codigo']) && $q['codigo'] !== '' ? (int)$q['codigo'] : null;
    $talonario = isset($q['talonario']) && $q['talonario'] !== '' ? (int)$q['talonario'] : null;
    $proyecto  = isset($q['proyecto'])  && $q['proyecto']  !== '' ? (int)$q['proyecto']  : null;
    $empresa   = isset($q['empresa'])   && $q['empresa']   !== '' ? (int)$q['empresa']   : null;
    $tipo      = trim((string)($q['tipo']    ?? ''));
    $punto     = isset($q['punto']) && $q['punto'] !== '' ? (int)$q['punto'] : null;
    $serie     = isset($q['serie']) && $q['serie'] !== '' ? (int)$q['serie'] : null;
    $fiscal    = trim((string)($q['fiscal']  ?? ''));
    $cliente   = isset($q['cliente']) && $q['cliente'] !== '' ? (int)$q['cliente'] : null;
    $razon     = trim((string)($q['razon']   ?? ''));
    $celular   = trim((string)($q['celular'] ?? ''));
    $correo    = trim((string)($q['correo']  ?? ''));
    $cuit      = trim((string)($q['cuit']    ?? ''));
    $estado    = trim((string)($q['estado']  ?? ''));
    $search    = trim((string)($q['q']       ?? ''));

    // Rangos: aceptamos YYYY-MM-DD; ignoramos si vacio o mal formado.
    $reFecha    = '/^\d{4}-\d{2}-\d{2}$/';
    $emiDesde   = trim((string)($q['emi_desde'] ?? ''));
    $emiHasta   = trim((string)($q['emi_hasta'] ?? ''));
    $vtoDesde   = trim((string)($q['vto_desde'] ?? ''));
    $vtoHasta   = trim((string)($q['vto_hasta'] ?? ''));
    if (!preg_match($reFecha, $emiDesde)) $emiDesde = '';
    if (!preg_match($reFecha, $emiHasta)) $emiHasta = '';
    if (!preg_match($reFecha, $vtoDesde)) $vtoDesde = '';
    if (!preg_match($reFecha, $vtoHasta)) $vtoHasta = '';

    $totalDesde = ($q['total_desde'] ?? '') !== '' && is_numeric($q['total_desde'])
                    ? (float)$q['total_desde'] : null;
    $totalHasta = ($q['total_hasta'] ?? '') !== '' && is_numeric($q['total_hasta'])
                    ? (float)$q['total_hasta'] : null;

    $orderBy = $q['order_by'] ?? 'id';
    $dir     = strtolower((string)($q['dir'] ?? 'desc'));
    $limite  = isset($q['limite']) ? (int)$q['limite'] : 50;
    if ($limite < 1)    $limite = 1;
    if ($limite > 1000) $limite = 1000;

    $allowedOrder = ['id', 'tipo', 'punto', 'serie', 'emision', 'vencimiento',
                     'razon', 'cuit', 'total', 'registrado', 'estado'];
    if (!in_array($orderBy, $allowedOrder, true)) $orderBy = 'id';
    $dirSql = $dir === 'asc' ? 'ASC' : 'DESC';

    $where  = [];
    $params = [];

    // Prefijo `c.` obligatorio en todas las condiciones: el SELECT hace JOIN
    // con datacount_talonarios y muchas columnas (id/proyecto/empresa/tipo/
    // punto/serie/fiscal/correo/estado) existen en ambas tablas.
    if ($codigo    !== null) { $where[] = 'c.id = :codigo';               $params[':codigo']    = $codigo; }
    if ($talonario !== null) { $where[] = 'c.talonario = :talonario';     $params[':talonario'] = $talonario; }
    if ($proyecto  !== null) { $where[] = 'c.proyecto = :proyecto';       $params[':proyecto']  = $proyecto; }
    if ($empresa   !== null) { $where[] = 'c.empresa = :empresa';         $params[':empresa']   = $empresa; }
    if ($tipo      !== '')   { $where[] = 'c.tipo = :tipo';               $params[':tipo']      = $tipo; }
    if ($punto     !== null) { $where[] = 'c.punto = :punto';             $params[':punto']     = $punto; }
    if ($serie     !== null) { $where[] = 'c.serie = :serie';             $params[':serie']     = $serie; }
    if ($fiscal    !== '')   { $where[] = 'c.fiscal = :fiscal';           $params[':fiscal']    = $fiscal; }
    if ($cliente   !== null) { $where[] = 'c.cliente = :cliente';         $params[':cliente']   = $cliente; }
    if ($razon     !== '')   { $where[] = 'c.razon LIKE :razon';          $params[':razon']     = "%{$razon}%"; }
    if ($celular   !== '')   { $where[] = 'c.celular LIKE :celular';      $params[':celular']   = "%{$celular}%"; }
    if ($correo    !== '')   { $where[] = 'c.correo LIKE :correo';        $params[':correo']    = "%{$correo}%"; }
    if ($cuit      !== '')   { $where[] = 'c.cuit LIKE :cuit';            $params[':cuit']      = "%{$cuit}%"; }
    if ($estado    !== '')   { $where[] = 'c.estado = :estado';           $params[':estado']    = $estado; }

    if ($emiDesde !== '') { $where[] = 'c.emision >= :emi_desde';     $params[':emi_desde'] = $emiDesde; }
    if ($emiHasta !== '') { $where[] = 'c.emision <= :emi_hasta';     $params[':emi_hasta'] = $emiHasta; }
    if ($vtoDesde !== '') { $where[] = 'c.vencimiento >= :vto_desde'; $params[':vto_desde'] = $vtoDesde; }
    if ($vtoHasta !== '') { $where[] = 'c.vencimiento <= :vto_hasta'; $params[':vto_hasta'] = $vtoHasta; }

    if ($totalDesde !== null) { $where[] = 'c.total >= :total_desde'; $params[':total_desde'] = $totalDesde; }
    if ($totalHasta !== null) { $where[] = 'c.total <= :total_hasta'; $params[':total_hasta'] = $totalHasta; }

    if ($search !== '') {
        $where[] = '(c.razon LIKE :s1 OR c.cuit LIKE :s2 OR c.correo LIKE :s3
                     OR c.celular LIKE :s4 OR c.caenro LIKE :s5)';
        $like = "%{$search}%";
        $params[':s1'] = $like;
        $params[':s2'] = $like;
        $params[':s3'] = $like;
        $params[':s4'] = $like;
        $params[':s5'] = $like;
    }

    $sqlWhere = $where ? ('WHERE ' . implode(' AND ', $where)) : '';

    // Stats globales (ignoran filtros — son indicadores del recurso).
    $stats = $pdo->query("
        SELECT
            COUNT(*)          AS total,
            COALESCE(SUM(total), 0) AS importe_total
        FROM datacount_comprobantes
    ")->fetch();

    // Estado del motor de autorizacion automatica (mismo flag que consume el
    // visor de Arca > Autorizaciones y el cron cloud/jobs/datacount_comprobantes_autorizar.php).
    // Booleano: '1' = habilitado; otro = detenido (pausa manual).
    $motor = getParametro('datacount.comprobantes.autorizar', '1');

    // JOIN con datacount_talonarios para traer el nombre del talonario y
    // pintar la columna "Talonario" del listado sin round-trip extra.
    $selectCols = 'c.' . preg_replace('/,\s*/', ', c.', DC_COLS);
    $sql = "
        SELECT {$selectCols},
               t.nombre AS talonario_nombre
        FROM datacount_comprobantes c
        LEFT JOIN datacount_talonarios t ON t.id = c.talonario
        {$sqlWhere}
        ORDER BY c.{$orderBy} {$dirSql}
        LIMIT {$limite}
    ";
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $rows = $stmt->fetchAll();

    jsonOk([
        'stats' => [
            'total'         => (int)($stats['total']         ?? 0),
            'importe_total' => (float)($stats['importe_total'] ?? 0),
            'motor'         => (string)($motor ?? '1'),
        ],
        'items' => $rows,
    ]);
}

// Catalogos para poblar los `<select>` del modal de filtros. Van bajo el mismo
// permiso `datacount.comprobantes.consultar` — el operador que ya lista
// comprobantes puede ver id+nombre de talonarios, proyectos y empresas.
//
// Notas:
//   - `proyectos` se filtra por `tipo='I'` (internos). Los externos ('E') no
//     participan de la facturacion propia y no aparecen en los <select>.
//     Collation utf8mb4_general_ci es case-insensitive, asi que matchea 'i'/'I'.
//   - Cada talonario incluye su `proyecto` y su `empresa` (int) para que la UI
//     pueda armar la cascada proyecto+empresa -> talonarios sin round-trip extra.
function handleLookups(PDO $pdo): void {
    $talonarios = $pdo->query(
        "SELECT id, proyecto, empresa,
                COALESCE(NULLIF(TRIM(nombre), ''), CONCAT('#', id)) AS nombre
         FROM datacount_talonarios
         ORDER BY nombre ASC, id ASC"
    )->fetchAll();

    $proyectos = $pdo->query(
        "SELECT id, COALESCE(NULLIF(TRIM(nombre), ''), CONCAT('#', id)) AS nombre
         FROM proyectos
         WHERE tipo = 'I'
         ORDER BY nombre ASC, id ASC"
    )->fetchAll();

    $empresas = $pdo->query(
        "SELECT id, COALESCE(NULLIF(TRIM(nombre), ''), CONCAT('#', id)) AS nombre
         FROM datacount_empresas
         ORDER BY nombre ASC, id ASC"
    )->fetchAll();

    $mapSimple = fn($rows) => array_map(fn($r) => [
        'id'     => (int)$r['id'],
        'nombre' => (string)$r['nombre'],
    ], $rows);

    jsonOk([
        'talonarios' => array_map(fn($r) => [
            'id'       => (int)$r['id'],
            'nombre'   => (string)$r['nombre'],
            'proyecto' => $r['proyecto'] === null ? null : (int)$r['proyecto'],
            'empresa'  => $r['empresa']  === null ? null : (int)$r['empresa'],
        ], $talonarios),
        'proyectos'  => $mapSimple($proyectos),
        'empresas'   => $mapSimple($empresas),
    ]);
}

function handleGetOne(PDO $pdo, int $id): void {
    // Columnas con alias `c.` porque los JOIN traen tablas con columnas
    // homónimas (uuid/tipo/punto/serie/fiscal/correo/condicion/cuit/etc).
    $stmt = $pdo->prepare("
        SELECT c.id, c.uuid, c.talonario, c.proyecto, c.empresa, c.tipo, c.punto,
               c.serie, c.fiscal, c.caenro, c.caevto, c.caeres, c.emision,
               c.vencimiento, c.concepto, c.asociado, c.contrato, c.cliente, c.razon,
               c.condicion, c.cuit, c.domicilio, c.correo, c.celular, c.neto,
               c.iva, c.total, c.observaciones, c.comentarios, c.medio,
               c.registrado, c.autorizado, c.estado, c.webhook_url, c.webhook_estado,
               t.nombre AS talonario_nombre,
               t.fondo  AS talonario_fondo,
               p.nombre AS proyecto_nombre,
               e.nombre    AS empresa_nombre,
               e.razon     AS emisor_razon,
               e.domicilio AS emisor_domicilio,
               e.condicion AS emisor_condicion,
               e.cuit      AS emisor_cuit,
               e.iibb      AS emisor_iibb,
               e.inicio    AS emisor_inicio
        FROM datacount_comprobantes c
        LEFT JOIN datacount_talonarios t ON t.id = c.talonario
        LEFT JOIN proyectos           p ON p.id = c.proyecto
        LEFT JOIN datacount_empresas  e ON e.id = c.empresa
        WHERE c.id = :id
    ");
    $stmt->execute([':id' => $id]);
    $row = $stmt->fetch();
    if (!$row) jsonError('Comprobante no encontrado', 404);

    $fondo = $row['talonario_fondo'] !== null ? trim((string)$row['talonario_fondo']) : '';
    $row['talonario_fondo_url'] = $fondo !== ''
        ? s3_public_url(DC_COMP_FONDO_PREFIX . $fondo)
        : null;

    // Renglones (datacount_comprobantes_renglones) asociados al comprobante.
    $stmtR = $pdo->prepare("
        SELECT id, comprobante, orden, cantidad, articulo, detalle,
               iva, unitario, monto, estado
        FROM datacount_comprobantes_renglones
        WHERE comprobante = :id
        ORDER BY orden ASC, id ASC
    ");
    $stmtR->execute([':id' => $id]);
    $row['renglones'] = $stmtR->fetchAll();

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

function nullableDec(mixed $v): ?string {
    // Devuelve string para preservar precision con decimal(11,2) en PDO.
    if ($v === null || $v === '') return null;
    $s = str_replace(',', '.', trim((string)$v));
    if (!is_numeric($s)) return null;
    return $s;
}

function nullableDate(mixed $v): ?string {
    $s = nullableStr($v);
    if ($s === null) return null;
    // Acepta YYYY-MM-DD o ISO con tiempo; toma solo la parte de fecha.
    if (preg_match('/^(\d{4}-\d{2}-\d{2})/', $s, $m)) return $m[1];
    return null;
}

function nullableDateTime(mixed $v): ?string {
    $s = nullableStr($v);
    if ($s === null) return null;
    // Normaliza 'YYYY-MM-DDTHH:MM' (input datetime-local) a 'YYYY-MM-DD HH:MM:SS'.
    $s = str_replace('T', ' ', $s);
    if (preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}$/', $s)) $s .= ':00';
    if (!preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $s)) return null;
    return $s;
}

// Sanitiza el payload en modo *partial update*: solo devuelve las claves que
// vinieron efectivamente en el body. Los campos ausentes NO aparecen en el
// resultado — el llamador puede distinguir "no lo mando" (dejar como esta)
// de "lo mando explicitamente en null" (limpiarlo).
//
// Esto es lo que permite que el modal editable oculte campos como caenro,
// autorizado, asociado, etc. sin borrar los valores existentes: al no
// enviarlos, el UPDATE simplemente no toca esas columnas.
function sanitizePayload(array $in): array {
    $rules = [
        'talonario'     => ['int'],
        'proyecto'      => ['int'],
        'empresa'       => ['int'],
        'tipo'          => ['str', 2],
        'punto'         => ['int'],
        'serie'         => ['int'],
        'fiscal'        => ['str', 1],
        'caenro'        => ['str', 50],
        'caevto'        => ['str', 50],
        'caeres'        => ['str', null],
        'emision'       => ['date'],
        'vencimiento'   => ['date'],
        'concepto'      => ['int'],
        'asociado'      => ['int'],
        'contrato'      => ['int'],
        'cliente'       => ['int'],
        'razon'         => ['str', 250],
        'condicion'     => ['str', 2],
        'cuit'          => ['str', 50],
        'domicilio'     => ['str', 250],
        'correo'        => ['str', 100],
        'celular'       => ['str', 100],
        'neto'          => ['dec'],
        'iva'           => ['dec'],
        'total'         => ['dec'],
        'observaciones' => ['str', 2000],
        'comentarios'   => ['str', 2000],
        'medio'         => ['int'],
        'autorizado'    => ['datetime'],
        'estado'        => ['str', 1],
    ];
    $out = [];
    foreach ($rules as $k => $r) {
        if (!array_key_exists($k, $in)) continue;
        $type = $r[0];
        switch ($type) {
            case 'int':      $out[$k] = nullableInt($in[$k]);            break;
            case 'dec':      $out[$k] = nullableDec($in[$k]);            break;
            case 'date':     $out[$k] = nullableDate($in[$k]);           break;
            case 'datetime': $out[$k] = nullableDateTime($in[$k]);       break;
            case 'str':      $out[$k] = nullableStr($in[$k], $r[1] ?? null); break;
        }
    }
    return $out;
}

// Normaliza y valida el array `renglones` del payload. Devuelve la lista de
// renglones listos para INSERT + los totales derivados (neto = SUM(cant*unit),
// iva = SUM(cant*unit*alicuota/100), total = neto+iva). Si `renglones` no
// viene en el body devolvemos null en `lineas` para indicarle al caller que
// no debe tocar la tabla de renglones ni sobreescribir neto/iva/total.
function sanitizeRenglones(mixed $raw): array {
    if ($raw === null) return ['lineas' => null, 'neto' => null, 'iva' => null, 'total' => null];
    if (!is_array($raw)) return ['lineas' => [], 'neto' => '0.00', 'iva' => '0.00', 'total' => '0.00'];
    $lineas = [];
    $neto   = 0.0;
    $ivaTot = 0.0;
    $orden  = 0;
    foreach ($raw as $r) {
        if (!is_array($r)) continue;
        $orden++;
        $cant = (float)(nullableDec($r['cantidad'] ?? null) ?? '0');
        $unit = (float)(nullableDec($r['unitario'] ?? null) ?? '0');
        $ali  = (float)(nullableDec($r['iva']      ?? null) ?? '0');
        $sub  = round($cant * $unit, 2);
        $ivaL = round($sub * $ali / 100, 2);
        $neto  += $sub;
        $ivaTot += $ivaL;
        $lineas[] = [
            'orden'    => (int)($r['orden'] ?? $orden),
            'cantidad' => number_format($cant, 2, '.', ''),
            'articulo' => nullableInt($r['articulo'] ?? null),
            'detalle'  => nullableStr($r['detalle'] ?? null),
            'iva'      => number_format($ali, 2, '.', ''),
            'unitario' => number_format($unit, 2, '.', ''),
            'monto'    => number_format($sub, 2, '.', ''),
            'estado'   => nullableStr($r['estado'] ?? null, 1),
        ];
    }
    return [
        'lineas' => $lineas,
        'neto'   => number_format($neto, 2, '.', ''),
        'iva'    => number_format($ivaTot, 2, '.', ''),
        'total'  => number_format($neto + $ivaTot, 2, '.', ''),
    ];
}

function insertarRenglones(PDO $pdo, int $comprobanteId, array $lineas): void {
    if (!$lineas) return;
    $sql = "INSERT INTO datacount_comprobantes_renglones
              (comprobante, orden, cantidad, articulo, detalle, iva, unitario, monto, estado)
            VALUES
              (:comprobante, :orden, :cantidad, :articulo, :detalle, :iva, :unitario, :monto, :estado)";
    $stmt = $pdo->prepare($sql);
    foreach ($lineas as $l) {
        $stmt->execute([
            ':comprobante' => $comprobanteId,
            ':orden'       => $l['orden'],
            ':cantidad'    => $l['cantidad'],
            ':articulo'    => $l['articulo'],
            ':detalle'     => $l['detalle'],
            ':iva'         => $l['iva'],
            ':unitario'    => $l['unitario'],
            ':monto'       => $l['monto'],
            ':estado'      => $l['estado'],
        ]);
    }
}

function handleCreate(PDO $pdo, array $in): void {
    $p = sanitizePayload($in);

    // Autocompleto desde el talonario: tipo/punto/fiscal son atributos del
    // talonario (no editables desde el modal), asi que si el payload no los
    // trae los tomamos del talonario elegido — evita filas con esos campos
    // en NULL cuando se da de alta un comprobante desde la UI.
    // OJO: `serie` NO se autocompleta aca — se setea al autorizar contra
    // AFIP con el `cbte_nro` que devuelve el WS (ver jobs/datacount_
    // comprobantes_autorizar.php); antes de autorizar debe quedar NULL.
    if (!empty($p['talonario'])) {
        $tSt = $pdo->prepare(
            'SELECT tipo, punto, fiscal FROM datacount_talonarios WHERE id = :id'
        );
        $tSt->execute([':id' => (int)$p['talonario']]);
        $tal = $tSt->fetch();
        if ($tal) {
            if (!array_key_exists('tipo',   $p) || $p['tipo']   === null) $p['tipo']   = $tal['tipo'];
            if (!array_key_exists('punto',  $p) || $p['punto']  === null) $p['punto']  = $tal['punto']  !== null ? (int)$tal['punto']  : null;
            if (!array_key_exists('fiscal', $p) || $p['fiscal'] === null) $p['fiscal'] = $tal['fiscal'];
        }
    }

    // En alta, las columnas ausentes del body van como NULL: el INSERT
    // necesita un valor para cada columna, no admite "no me pises esto".
    foreach (DC_PAYLOAD_COLS as $k) {
        if (!array_key_exists($k, $p)) $p[$k] = null;
    }
    // Regla de negocio: todo comprobante nuevo arranca en estado 'Preparacion'
    // (valor '1' del catalogo `datacount_comprobante_estado`). Es el unico
    // estado desde el que el modal permite editar; los demas los setea el
    // sistema al mandar a AFIP o al cerrar el comprobante.
    if ($p['estado'] === null) $p['estado'] = '1';

    // Si vinieron renglones, recalculamos neto/iva/total en el server para
    // que la UI no pueda mandar valores inconsistentes con las lineas.
    $r = sanitizeRenglones($in['renglones'] ?? null);
    if ($r['lineas'] !== null) {
        $p['neto']  = $r['neto'];
        $p['iva']   = $r['iva'];
        $p['total'] = $r['total'];
    }

    $p['uuid']       = substr(bin2hex(random_bytes(8)), 0, 10);
    $p['registrado'] = (new DateTime('now', new DateTimeZone('America/Argentina/Buenos_Aires')))
                        ->format('Y-m-d H:i:s');

    $pdo->beginTransaction();
    try {
        $sql = "
            INSERT INTO datacount_comprobantes
                (uuid, talonario, proyecto, empresa, tipo, punto, serie, fiscal,
                 caenro, caevto, caeres, emision, vencimiento, concepto,
                 asociado, contrato, cliente, razon, condicion, cuit, domicilio,
                 correo, celular, neto, iva, total, observaciones, comentarios,
                 medio, registrado, autorizado, estado)
            VALUES
                (:uuid, :talonario, :proyecto, :empresa, :tipo, :punto, :serie, :fiscal,
                 :caenro, :caevto, :caeres, :emision, :vencimiento, :concepto,
                 :asociado, :contrato, :cliente, :razon, :condicion, :cuit, :domicilio,
                 :correo, :celular, :neto, :iva, :total, :observaciones, :comentarios,
                 :medio, :registrado, :autorizado, :estado)
        ";
        $stmt = $pdo->prepare($sql);
        $stmt->execute([
            ':uuid'          => $p['uuid'],
            ':talonario'     => $p['talonario'],
            ':proyecto'      => $p['proyecto'],
            ':empresa'       => $p['empresa'],
            ':tipo'          => $p['tipo'],
            ':punto'         => $p['punto'],
            ':serie'         => $p['serie'],
            ':fiscal'        => $p['fiscal'],
            ':caenro'        => $p['caenro'],
            ':caevto'        => $p['caevto'],
            ':caeres'        => $p['caeres'],
            ':emision'       => $p['emision'],
            ':vencimiento'   => $p['vencimiento'],
            ':concepto'      => $p['concepto'],
            ':asociado'      => $p['asociado'],
            ':contrato'      => $p['contrato'],
            ':cliente'       => $p['cliente'],
            ':razon'         => $p['razon'],
            ':condicion'     => $p['condicion'],
            ':cuit'          => $p['cuit'],
            ':domicilio'     => $p['domicilio'],
            ':correo'        => $p['correo'],
            ':celular'       => $p['celular'],
            ':neto'          => $p['neto'],
            ':iva'           => $p['iva'],
            ':total'         => $p['total'],
            ':observaciones' => $p['observaciones'],
            ':comentarios'   => $p['comentarios'],
            ':medio'         => $p['medio'],
            ':registrado'    => $p['registrado'],
            ':autorizado'    => $p['autorizado'],
            ':estado'        => $p['estado'],
        ]);
        $id = (int)$pdo->lastInsertId();
        if ($r['lineas'] !== null) insertarRenglones($pdo, $id, $r['lineas']);
        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }
    jsonOk(['id' => $id], 201);
}

// Clona un comprobante existente creando uno nuevo en estado 'Preparacion'
// ('1'). Copia atributos comerciales (talonario/proyecto/empresa/tipo/punto/
// serie/fiscal/cliente/razon/etc.) y todos los renglones. Descarta lo que
// pertenece a la vida del comprobante original: uuid, fechas de sistema y los
// campos de autorizacion AFIP (caenro/caevto/caeres/autorizado).
function handleClonar(PDO $pdo, int $id): void {
    $stmt = $pdo->prepare('SELECT ' . DC_COLS . ' FROM datacount_comprobantes WHERE id = :id');
    $stmt->execute([':id' => $id]);
    $src = $stmt->fetch();
    if (!$src) jsonError('Comprobante no encontrado', 404);

    $stmtR = $pdo->prepare(
        'SELECT orden, cantidad, articulo, detalle, iva, unitario, monto, estado
         FROM datacount_comprobantes_renglones
         WHERE comprobante = :id
         ORDER BY orden ASC, id ASC'
    );
    $stmtR->execute([':id' => $id]);
    $renglones = $stmtR->fetchAll();

    // id: auto-increment (nuevo). uuid: nuevo aleatorio. registrado: timestamp
    // de ahora en Buenos Aires. emision: hoy. vencimiento: hoy + 7 dias.
    $nuevoUuid  = substr(bin2hex(random_bytes(8)), 0, 10);
    $ahora      = new DateTime('now', new DateTimeZone('America/Argentina/Buenos_Aires'));
    $registrado = $ahora->format('Y-m-d H:i:s');
    $emision    = $ahora->format('Y-m-d');
    $vencimiento = (clone $ahora)->modify('+7 days')->format('Y-m-d');

    $pdo->beginTransaction();
    try {
        $sql = "
            INSERT INTO datacount_comprobantes
                (uuid, talonario, proyecto, empresa, tipo, punto, serie, fiscal,
                 caenro, caevto, caeres, emision, vencimiento, concepto,
                 asociado, contrato, cliente, razon, condicion, cuit, domicilio,
                 correo, celular, neto, iva, total, observaciones, comentarios,
                 medio, registrado, autorizado, estado)
            VALUES
                (:uuid, :talonario, :proyecto, :empresa, :tipo, :punto, NULL, :fiscal,
                 NULL, NULL, NULL, :emision, :vencimiento, :concepto,
                 :asociado, :contrato, :cliente, :razon, :condicion, :cuit, :domicilio,
                 :correo, :celular, :neto, :iva, :total, :observaciones, :comentarios,
                 :medio, :registrado, NULL, '1')
        ";
        $ins = $pdo->prepare($sql);
        $ins->execute([
            ':uuid'          => $nuevoUuid,
            ':talonario'     => $src['talonario'],
            ':proyecto'      => $src['proyecto'],
            ':empresa'       => $src['empresa'],
            ':tipo'          => $src['tipo'],
            ':punto'         => $src['punto'],
            ':fiscal'        => $src['fiscal'],
            ':emision'       => $emision,
            ':vencimiento'   => $vencimiento,
            ':concepto'      => $src['concepto'],
            ':asociado'      => $src['asociado'],
            ':contrato'      => $src['contrato'],
            ':cliente'       => $src['cliente'],
            ':razon'         => $src['razon'],
            ':condicion'     => $src['condicion'],
            ':cuit'          => $src['cuit'],
            ':domicilio'     => $src['domicilio'],
            ':correo'        => $src['correo'],
            ':celular'       => $src['celular'],
            ':neto'          => $src['neto'],
            ':iva'           => $src['iva'],
            ':total'         => $src['total'],
            ':observaciones' => $src['observaciones'],
            ':comentarios'   => $src['comentarios'],
            ':medio'         => $src['medio'],
            ':registrado'    => $registrado,
        ]);
        $nuevoId = (int)$pdo->lastInsertId();

        if ($renglones) {
            $lineas = array_map(fn($r) => [
                'orden'    => (int)$r['orden'],
                'cantidad' => $r['cantidad'],
                'articulo' => $r['articulo'] === null ? null : (int)$r['articulo'],
                'detalle'  => $r['detalle'],
                'iva'      => $r['iva'],
                'unitario' => $r['unitario'],
                'monto'    => $r['monto'],
                'estado'   => $r['estado'],
            ], $renglones);
            insertarRenglones($pdo, $nuevoId, $lineas);
        }

        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }

    jsonOk(['id' => $nuevoId], 201);
}

// Autoriza manualmente UN comprobante contra AFIP (accion "Autorizar" del
// menu contextual del listado). Comparte el 100% de la logica de autorizacion
// con el cron via la lib `datacount_comprobantes_autorizar.php` -- no hay
// dos caminos para obtener un CAE.
//
// Circuit breaker: si el error es PERMANENTE (cert corrupto, empresa mal
// configurada, rechazo semantico AFIP), detiene el motor automatico igual
// que el cron -- razon: un fallo sistemico afecta tambien a las corridas
// automatizadas y no queremos que el cron siga golpeando AFIP con la misma
// causa. Si el error es TRANSITORIO (AFIP/Apache caidos, TA stale), NO
// se toca el motor -- el cron reintentara solo cuando AFIP se recupere.
//
// En cualquier error graba `caeres` (incluso transitorios) para que el
// operador vea el mensaje en el modal "Respuesta CAE" del panel.
//
// Validaciones previas: existe, fiscal='1', estado='2'. Si no, 400/404/409.
function handleAutorizar(PDO $pdo, int $id): void {
    // 1) Cargar y validar el comprobante contra los criterios del pipeline.
    $c = dccAutLoadComprobante($pdo, $id);
    if ($c === null) jsonError('Comprobante no encontrado', 404);
    if ((string)($c['fiscal'] ?? '') !== '1') {
        jsonError('El comprobante no es fiscal, no se autoriza contra AFIP', 409);
    }
    if ((string)($c['estado'] ?? '') !== '2') {
        jsonError('Solo se autorizan comprobantes en estado Pendiente', 409);
    }

    // 2) Apikey para el loopback al v4 (mismo patron que el cron).
    $apikey = dccAutObtenerApikey($pdo);
    if ($apikey === null) {
        jsonError('No hay ninguna aplicacion habilitada con apikey para el loopback', 500);
    }

    // 3) Delegar en la lib (canal unico compartido con el cron).
    $r = dccAutAutorizar($pdo, $apikey, $c);

    if (!$r['ok']) {
        $errMsg = (string)$r['error'];
        // Grabamos caeres para que el mensaje quede visible en el modal del
        // panel, independientemente de si fue transitorio o permanente.
        dccAutMarcarCaeres($pdo, $id, $errMsg);

        // Circuit breaker igual que el cron: los permanentes detienen el
        // motor automatico. Un error sistemico afecta a ambos caminos por
        // igual -- si el manual choco con cert corrupto o rechazo AFIP, el
        // cron del proximo minuto va a chocar con lo mismo.
        if (!dccAutEsTransitorio($errMsg)) {
            parametroEscribir($pdo, 'datacount.comprobantes.autorizar', '0');
            try {
                registrarSuceso($pdo, 'panel/datacount_comprobantes.autorizar', 'error',
                    "cbte#{$id}: MOTOR DETENIDO tras autorizacion manual con error permanente ({$r['fuente']}): {$errMsg}");
            } catch (Throwable $_) { /* no romper el flujo */ }
        } else {
            try {
                registrarSuceso($pdo, 'panel/datacount_comprobantes.autorizar', 'alerta',
                    "cbte#{$id}: autorizacion manual fallo transitoriamente ({$r['fuente']}): {$errMsg}");
            } catch (Throwable $_) { /* no romper el flujo */ }
        }
        jsonError($errMsg, 422);
    }

    try {
        registrarSuceso($pdo, 'panel/datacount_comprobantes.autorizar', 'info',
            "cbte#{$id} autorizado manualmente - CAE={$r['cae']} nro={$r['cbte_nro']} vto={$r['cae_vto']}");
    } catch (Throwable $_) { /* no romper el flujo */ }

    jsonOk([
        'id'       => $id,
        'cae'      => $r['cae'],
        'cae_vto'  => $r['cae_vto'],
        'cbte_nro' => $r['cbte_nro'],
    ]);
}

// Aprueba manualmente UN comprobante NO fiscal (accion "Aprobar" del menu
// contextual del listado). Es el equivalente no-fiscal de handleAutorizar:
// asigna el proximo correlativo del talonario y transiciona a estado '5'
// Aprobado. NO llama a AFIP (no hay CAE ni caenro/caevto que grabar).
//
// Serie asignada: MAX de la serie mas alta ya usada por el talonario, tomando
// el maximo entre `datacount_talonarios.serie` (high water mark) y el MAX(serie)
// de los comprobantes del mismo talonario, + 1. GET_LOCK por talonario para
// que dos aprobaciones simultaneas no colisionen en el mismo numero.
//
// Validaciones previas: existe, fiscal <> '1', estado='2'. Si no, 400/404/409.
function handleAprobar(PDO $pdo, int $id): void {
    $st = $pdo->prepare(
        'SELECT id, talonario, fiscal, estado, serie
           FROM datacount_comprobantes
          WHERE id = :id LIMIT 1'
    );
    $st->execute([':id' => $id]);
    $c = $st->fetch();
    if (!$c) jsonError('Comprobante no encontrado', 404);
    if ((string)($c['fiscal'] ?? '') === '1') {
        jsonError('El comprobante es fiscal, corresponde autorizar contra AFIP', 409);
    }
    if ((string)($c['estado'] ?? '') !== '2') {
        jsonError('Solo se aprueban comprobantes en estado Pendiente', 409);
    }
    $talId = (int)($c['talonario'] ?? 0);
    if ($talId <= 0) {
        jsonError('El comprobante no tiene talonario asignado', 409);
    }

    // Lock por talonario: dos aprobaciones simultaneas contra el mismo
    // talonario podrian calcular el mismo "proximo numero" antes de que el
    // UPDATE del primero se aplique. Semantica identica al lock por-cbte del
    // flujo AFIP (dccAutAutorizar), pero granularidad talonario porque el
    // recurso escaso aca es la serie.
    $lockName = "dcc_apr_tal:{$talId}";
    $lockSt   = $pdo->prepare('SELECT GET_LOCK(?, ?)');
    $lockSt->execute([$lockName, 30]);
    if ((int)$lockSt->fetchColumn() !== 1) {
        jsonError('Talonario ocupado por otra aprobacion en curso, reintente', 503);
    }

    try {
        // Re-check bajo lock por si otro proceso ya aprobo este cbte mientras
        // esperabamos el lock (race con otro operador).
        $post = $pdo->prepare(
            'SELECT estado, serie FROM datacount_comprobantes WHERE id = :id LIMIT 1'
        );
        $post->execute([':id' => $id]);
        $r2 = $post->fetch();
        if (!$r2) jsonError('Comprobante desaparecio antes de aprobar', 409);
        if ((string)($r2['estado'] ?? '') === '5' && (int)($r2['serie'] ?? 0) > 0) {
            jsonOk(['id' => $id, 'cbte_nro' => (int)$r2['serie']]);
            return;
        }
        if ((string)($r2['estado'] ?? '') !== '2') {
            jsonError('El comprobante ya no esta en estado Pendiente', 409);
        }

        // Proximo numero: GREATEST(high water mark del talonario, MAX(serie)
        // de los comprobantes del talonario) + 1. Cubre el caso en que el
        // high water mark del talonario haya quedado desactualizado.
        $q = $pdo->prepare("
            SELECT GREATEST(
                COALESCE(t.serie, 0),
                COALESCE((SELECT MAX(c.serie) FROM datacount_comprobantes c
                          WHERE c.talonario = t.id AND c.serie IS NOT NULL), 0)
            ) + 1 AS proximo
            FROM datacount_talonarios t
            WHERE t.id = :tal
        ");
        $q->execute([':tal' => $talId]);
        $rowN = $q->fetch();
        if (!$rowN) jsonError('Talonario no encontrado', 404);
        $proximo = (int)$rowN['proximo'];

        $pdo->beginTransaction();
        try {
            $pdo->prepare("
                UPDATE datacount_comprobantes
                   SET estado     = '5',
                       serie      = :nro,
                       autorizado = NOW()
                 WHERE id = :id
            ")->execute([':nro' => $proximo, ':id' => $id]);

            // Adelantamos el high water mark del talonario. GREATEST protege
            // contra concurrencia por si otro camino subio el numero primero.
            $pdo->prepare(
                'UPDATE datacount_talonarios SET serie = GREATEST(COALESCE(serie, 0), :nro) WHERE id = :tal'
            )->execute([':nro' => $proximo, ':tal' => $talId]);

            $pdo->commit();
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            throw $e;
        }

        try {
            registrarSuceso($pdo, 'panel/datacount_comprobantes.aprobar', 'info',
                "cbte#{$id} aprobado manualmente - nro={$proximo} (talonario#{$talId})");
        } catch (Throwable $_) { /* no romper el flujo */ }

        jsonOk(['id' => $id, 'cbte_nro' => $proximo]);
    } finally {
        try {
            $pdo->prepare('SELECT RELEASE_LOCK(?)')->execute([$lockName]);
        } catch (Throwable $_) { /* ignore */ }
    }
}

function handleUpdate(PDO $pdo, int $id, array $in): void {
    // Traemos estado actual para (a) validar existencia y (b) gate de edicion:
    // solo se puede editar mientras el comprobante este en Preparacion ('1').
    $exists = $pdo->prepare('SELECT id, estado FROM datacount_comprobantes WHERE id = :id');
    $exists->execute([':id' => $id]);
    $row = $exists->fetch();
    if (!$row) jsonError('Comprobante no encontrado', 404);
    if ((string)($row['estado'] ?? '') !== '1') {
        jsonError('Solo se pueden editar comprobantes en estado Preparacion', 409);
    }

    $p = sanitizePayload($in);

    // Renglones: si vienen en el body hacemos replace-all y recalculamos totales.
    // Si NO vienen, dejamos la tabla de renglones intacta y respetamos los
    // valores neto/iva/total que el cliente haya mandado (si los mando).
    $r = sanitizeRenglones($in['renglones'] ?? null);
    if ($r['lineas'] !== null) {
        $p['neto']  = $r['neto'];
        $p['iva']   = $r['iva'];
        $p['total'] = $r['total'];
    }

    $pdo->beginTransaction();
    try {
        // SET dinamico: solo tocamos las columnas que el cliente mando (partial
        // update). Asi el modal puede ocultar caenro/autorizado/asociado/etc.
        // sin borrar los valores existentes.
        if ($p) {
            $sets   = [];
            $params = [':id' => $id];
            foreach ($p as $k => $v) {
                $sets[] = "`{$k}` = :{$k}";
                $params[":{$k}"] = $v;
            }
            $sql = 'UPDATE datacount_comprobantes SET ' . implode(', ', $sets) . ' WHERE id = :id';
            $pdo->prepare($sql)->execute($params);
        }
        if ($r['lineas'] !== null) {
            $del = $pdo->prepare('DELETE FROM datacount_comprobantes_renglones WHERE comprobante = :id');
            $del->execute([':id' => $id]);
            insertarRenglones($pdo, $id, $r['lineas']);
        }
        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }
    jsonOk(['id' => $id]);
}

// Transicion de estado del comprobante. Se usa desde el menu contextual del
// listado para llevar un comprobante entre los estados admitidos por la maquina
// DC_TRANSICIONES_ESTADO — no permite editar ningun otro campo (ese flujo es
// el handleUpdate, restringido a estado Preparacion).
function handleCambiarEstado(PDO $pdo, int $id, array $in): void {
    $nuevo = isset($in['estado']) ? (string)$in['estado'] : '';
    if ($nuevo === '') jsonError('Falta estado destino', 400);

    $stmt = $pdo->prepare('SELECT id, estado FROM datacount_comprobantes WHERE id = :id');
    $stmt->execute([':id' => $id]);
    $row = $stmt->fetch();
    if (!$row) jsonError('Comprobante no encontrado', 404);

    $actual   = (string)($row['estado'] ?? '');
    $permitidos = DC_TRANSICIONES_ESTADO[$actual] ?? [];
    if (!in_array($nuevo, $permitidos, true)) {
        jsonError("Transicion no permitida: {$actual} -> {$nuevo}", 409);
    }

    $upd = $pdo->prepare('UPDATE datacount_comprobantes SET estado = :estado WHERE id = :id');
    $upd->execute([':estado' => $nuevo, ':id' => $id]);
    jsonOk(['id' => $id, 'estado' => $nuevo]);
}

function handleDelete(PDO $pdo, int $id): void {
    $pdo->beginTransaction();
    try {
        $pdo->prepare('DELETE FROM datacount_comprobantes_renglones WHERE comprobante = :id')
            ->execute([':id' => $id]);
        $stmt = $pdo->prepare('DELETE FROM datacount_comprobantes WHERE id = :id');
        $stmt->execute([':id' => $id]);
        if ($stmt->rowCount() === 0) {
            $pdo->rollBack();
            jsonError('Comprobante no encontrado', 404);
        }
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    }
    jsonOk(['id' => $id]);
}
