<?php
// api/datacountcomprobantes.php
// ABM de comprobantes Datacount. Lee/escribe sobre la tabla `datacountcomprobantes`
// definida en db/schema.sql.
//   GET    api/datacountcomprobantes.php          -> listado con filtros (query string)
//   GET    api/datacountcomprobantes.php?id=N     -> registro individual
//   POST   api/datacountcomprobantes.php          -> alta (JSON body)
//   PUT    api/datacountcomprobantes.php?id=N     -> modificacion (JSON body)
//   DELETE api/datacountcomprobantes.php?id=N     -> baja
// Respuesta siempre {ok: true, data: ...} u {ok: false, error: '...'} (STACK.md sec. 10).

require_once __DIR__ . '/db.php';

// Columnas SELECT comunes (debe declararse ANTES del dispatch porque PHP procesa
// los `const` a nivel de archivo secuencialmente).
const DC_COLS = "id, uuid, talonario, proyecto, empresa, tipo, punto, serie, fiscal,
                 caenro, caevto, caeres, emision, vencimiento, asociado, contrato,
                 cliente, razon, condicion, cuit, domicilio, correo, celular,
                 neto, iva, total, observaciones, comentarios, medio,
                 registrado, autorizado, estado";

header('Content-Type: application/json; charset=utf-8');

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
        jsonError('Metodo no soportado', 405);
    }
} catch (Throwable $e) {
    jsonError($e->getMessage(), 500);
}

// ----------------------------------------------------------------------------
// Listado y stats
// ----------------------------------------------------------------------------

function handleList(PDO $pdo, array $q): void {
    $codigo  = isset($q['codigo']) && $q['codigo'] !== '' ? (int)$q['codigo'] : null;
    $tipo    = trim((string)($q['tipo']    ?? ''));
    $punto   = isset($q['punto']) && $q['punto'] !== '' ? (int)$q['punto'] : null;
    $serie   = isset($q['serie']) && $q['serie'] !== '' ? (int)$q['serie'] : null;
    $cliente = isset($q['cliente']) && $q['cliente'] !== '' ? (int)$q['cliente'] : null;
    $razon   = trim((string)($q['razon']   ?? ''));
    $cuit    = trim((string)($q['cuit']    ?? ''));
    $estado  = trim((string)($q['estado']  ?? ''));
    $search  = trim((string)($q['q']       ?? ''));

    $orderBy = $q['order_by'] ?? 'id';
    $dir     = strtolower((string)($q['dir'] ?? 'desc'));
    $limite  = isset($q['limite']) ? (int)$q['limite'] : 100;
    if ($limite < 1)    $limite = 1;
    if ($limite > 1000) $limite = 1000;

    $allowedOrder = ['id', 'tipo', 'punto', 'serie', 'emision', 'vencimiento',
                     'razon', 'cuit', 'total', 'registrado', 'estado'];
    if (!in_array($orderBy, $allowedOrder, true)) $orderBy = 'id';
    $dirSql = $dir === 'asc' ? 'ASC' : 'DESC';

    $where  = [];
    $params = [];

    if ($codigo  !== null) { $where[] = 'id = :codigo';            $params[':codigo']  = $codigo; }
    if ($tipo    !== '')   { $where[] = 'tipo = :tipo';            $params[':tipo']    = $tipo; }
    if ($punto   !== null) { $where[] = 'punto = :punto';          $params[':punto']   = $punto; }
    if ($serie   !== null) { $where[] = 'serie = :serie';          $params[':serie']   = $serie; }
    if ($cliente !== null) { $where[] = 'cliente = :cliente';      $params[':cliente'] = $cliente; }
    if ($razon   !== '')   { $where[] = 'razon LIKE :razon';       $params[':razon']   = "%{$razon}%"; }
    if ($cuit    !== '')   { $where[] = 'cuit LIKE :cuit';         $params[':cuit']    = "%{$cuit}%"; }
    if ($estado  !== '')   { $where[] = 'estado = :estado';        $params[':estado']  = $estado; }

    if ($search !== '') {
        $where[] = '(razon LIKE :s OR cuit LIKE :s OR correo LIKE :s
                     OR celular LIKE :s OR caenro LIKE :s)';
        $params[':s'] = "%{$search}%";
    }

    $sqlWhere = $where ? ('WHERE ' . implode(' AND ', $where)) : '';

    // Stats globales (ignoran filtros — son indicadores del recurso).
    $stats = $pdo->query("
        SELECT
            COUNT(*)          AS total,
            COALESCE(SUM(total), 0) AS importe_total
        FROM datacountcomprobantes
    ")->fetch();

    $sql = "
        SELECT " . DC_COLS . "
        FROM datacountcomprobantes
        {$sqlWhere}
        ORDER BY {$orderBy} {$dirSql}
        LIMIT {$limite}
    ";
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $rows = $stmt->fetchAll();

    jsonOk([
        'stats' => [
            'total'         => (int)($stats['total']         ?? 0),
            'importe_total' => (float)($stats['importe_total'] ?? 0),
        ],
        'items' => $rows,
    ]);
}

function handleGetOne(PDO $pdo, int $id): void {
    $stmt = $pdo->prepare("SELECT " . DC_COLS . " FROM datacountcomprobantes WHERE id = :id");
    $stmt->execute([':id' => $id]);
    $row = $stmt->fetch();
    if (!$row) jsonError('Comprobante no encontrado', 404);

    // Renglones (datacountcomprobantesrenglones) asociados al comprobante.
    $stmtR = $pdo->prepare("
        SELECT id, comprobante, orden, cantidad, articulo, detalle,
               iva, unitario, monto, estado
        FROM datacountcomprobantesrenglones
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

function sanitizePayload(array $in): array {
    return [
        'talonario'     => nullableInt($in['talonario']     ?? null),
        'proyecto'      => nullableInt($in['proyecto']      ?? null),
        'empresa'       => nullableInt($in['empresa']       ?? null),
        'tipo'          => nullableStr($in['tipo']          ?? null, 2),
        'punto'         => nullableInt($in['punto']         ?? null),
        'serie'         => nullableInt($in['serie']         ?? null),
        'fiscal'        => nullableStr($in['fiscal']        ?? null, 1),
        'caenro'        => nullableStr($in['caenro']        ?? null, 50),
        'caevto'        => nullableStr($in['caevto']        ?? null, 50),
        'caeres'        => nullableStr($in['caeres']        ?? null),
        'emision'       => nullableDate($in['emision']      ?? null),
        'vencimiento'   => nullableDate($in['vencimiento']  ?? null),
        'asociado'      => nullableInt($in['asociado']      ?? null),
        'contrato'      => nullableInt($in['contrato']      ?? null),
        'cliente'       => nullableInt($in['cliente']       ?? null),
        'razon'         => nullableStr($in['razon']         ?? null, 250),
        'condicion'     => nullableStr($in['condicion']     ?? null, 2),
        'cuit'          => nullableStr($in['cuit']          ?? null, 50),
        'domicilio'     => nullableStr($in['domicilio']     ?? null, 250),
        'correo'        => nullableStr($in['correo']        ?? null, 100),
        'celular'       => nullableStr($in['celular']       ?? null, 100),
        'neto'          => nullableDec($in['neto']          ?? null),
        'iva'           => nullableDec($in['iva']           ?? null),
        'total'         => nullableDec($in['total']         ?? null),
        'observaciones' => nullableStr($in['observaciones'] ?? null, 2000),
        'comentarios'   => nullableStr($in['comentarios']   ?? null, 2000),
        'medio'         => nullableInt($in['medio']         ?? null),
        'autorizado'    => nullableDateTime($in['autorizado'] ?? null),
        'estado'        => nullableStr($in['estado']        ?? null, 1),
    ];
}

function handleCreate(PDO $pdo, array $in): void {
    $p = sanitizePayload($in);
    $p['uuid']       = substr(bin2hex(random_bytes(8)), 0, 10);
    $p['registrado'] = (new DateTime('now', new DateTimeZone('America/Argentina/Buenos_Aires')))
                        ->format('Y-m-d H:i:s');

    $sql = "
        INSERT INTO datacountcomprobantes
            (uuid, talonario, proyecto, empresa, tipo, punto, serie, fiscal,
             caenro, caevto, caeres, emision, vencimiento, asociado, contrato,
             cliente, razon, condicion, cuit, domicilio, correo, celular,
             neto, iva, total, observaciones, comentarios, medio,
             registrado, autorizado, estado)
        VALUES
            (:uuid, :talonario, :proyecto, :empresa, :tipo, :punto, :serie, :fiscal,
             :caenro, :caevto, :caeres, :emision, :vencimiento, :asociado, :contrato,
             :cliente, :razon, :condicion, :cuit, :domicilio, :correo, :celular,
             :neto, :iva, :total, :observaciones, :comentarios, :medio,
             :registrado, :autorizado, :estado)
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
    jsonOk(['id' => (int)$pdo->lastInsertId()], 201);
}

function handleUpdate(PDO $pdo, int $id, array $in): void {
    $exists = $pdo->prepare('SELECT id FROM datacountcomprobantes WHERE id = :id');
    $exists->execute([':id' => $id]);
    if (!$exists->fetch()) jsonError('Comprobante no encontrado', 404);

    $p = sanitizePayload($in);

    $sql = "
        UPDATE datacountcomprobantes SET
            talonario     = :talonario,
            proyecto      = :proyecto,
            empresa       = :empresa,
            tipo          = :tipo,
            punto         = :punto,
            serie         = :serie,
            fiscal        = :fiscal,
            caenro        = :caenro,
            caevto        = :caevto,
            caeres        = :caeres,
            emision       = :emision,
            vencimiento   = :vencimiento,
            asociado      = :asociado,
            contrato      = :contrato,
            cliente       = :cliente,
            razon         = :razon,
            condicion     = :condicion,
            cuit          = :cuit,
            domicilio     = :domicilio,
            correo        = :correo,
            celular       = :celular,
            neto          = :neto,
            iva           = :iva,
            total         = :total,
            observaciones = :observaciones,
            comentarios   = :comentarios,
            medio         = :medio,
            autorizado    = :autorizado,
            estado        = :estado
        WHERE id = :id
    ";
    $stmt = $pdo->prepare($sql);
    $stmt->execute([
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
        ':autorizado'    => $p['autorizado'],
        ':estado'        => $p['estado'],
        ':id'            => $id,
    ]);
    jsonOk(['id' => $id]);
}

function handleDelete(PDO $pdo, int $id): void {
    $stmt = $pdo->prepare('DELETE FROM datacountcomprobantes WHERE id = :id');
    $stmt->execute([':id' => $id]);
    if ($stmt->rowCount() === 0) jsonError('Comprobante no encontrado', 404);
    jsonOk(['id' => $id]);
}
