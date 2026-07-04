<?php
// api/datasaleprospectos.php
// ABM de prospectos Datasale. Lee/escribe sobre la tabla `datasaleprospectos`
// definida en db/schema.sql.
//   GET    api/datasaleprospectos.php          -> listado con filtros (query string)
//   GET    api/datasaleprospectos.php?id=N     -> registro individual
//   POST   api/datasaleprospectos.php          -> alta (JSON body)
//   PUT    api/datasaleprospectos.php?id=N     -> modificacion (JSON body)
//   DELETE api/datasaleprospectos.php?id=N     -> baja
// Respuesta siempre {ok: true, data: ...} u {ok: false, error: '...'} (STACK.md sec. 10).

require_once __DIR__ . '/db.php';

const DS_PRO_COLS = "id, ingreso, proyecto, sentido, origen, tipo, producto, asunto,
                     organizacion, nombre, contacto, celular, correo, web, domicilio,
                     ciudad, localidad, provincia, pais, ubicacion, calificacion, estado,
                     asignado, atendido, actualizado, aplazado, comentarios, acciones";

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
    $codigo   = isset($q['codigo'])   && $q['codigo']   !== '' ? (int)$q['codigo']   : null;
    $proyecto = isset($q['proyecto']) && $q['proyecto'] !== '' ? (int)$q['proyecto'] : null;
    $asignado = isset($q['asignado']) && $q['asignado'] !== '' ? (int)$q['asignado'] : null;
    $atendido = isset($q['atendido']) && $q['atendido'] !== '' ? (int)$q['atendido'] : null;
    $estado   = isset($q['estado'])   && $q['estado']   !== '' ? (int)$q['estado']   : null;
    $sentido  = trim((string)($q['sentido']  ?? ''));
    $tipo     = trim((string)($q['tipo']     ?? ''));
    $origen   = trim((string)($q['origen']   ?? ''));
    $desde    = trim((string)($q['desde']    ?? ''));
    $hasta    = trim((string)($q['hasta']    ?? ''));
    $search   = trim((string)($q['q']        ?? ''));

    $orderBy = $q['order_by'] ?? 'id';
    $dir     = strtolower((string)($q['dir'] ?? 'desc'));
    $limite  = isset($q['limite']) ? (int)$q['limite'] : 100;
    if ($limite < 1)    $limite = 1;
    if ($limite > 1000) $limite = 1000;

    $allowedOrder = ['id', 'ingreso', 'proyecto', 'sentido', 'origen', 'tipo',
                     'producto', 'organizacion', 'nombre', 'estado', 'calificacion',
                     'asignado', 'atendido', 'actualizado', 'aplazado'];
    if (!in_array($orderBy, $allowedOrder, true)) $orderBy = 'id';
    $dirSql = $dir === 'asc' ? 'ASC' : 'DESC';

    $where  = [];
    $params = [];

    if ($codigo   !== null) { $where[] = 'id = :codigo';               $params[':codigo']   = $codigo; }
    if ($proyecto !== null) { $where[] = 'proyecto = :proyecto';       $params[':proyecto'] = $proyecto; }
    if ($asignado !== null) { $where[] = 'asignado = :asignado';       $params[':asignado'] = $asignado; }
    if ($atendido !== null) { $where[] = 'atendido = :atendido';       $params[':atendido'] = $atendido; }
    if ($estado   !== null) { $where[] = 'estado = :estado';           $params[':estado']   = $estado; }
    if ($sentido  !== '')   { $where[] = 'sentido = :sentido';         $params[':sentido']  = $sentido; }
    if ($tipo     !== '')   { $where[] = 'tipo = :tipo';               $params[':tipo']     = $tipo; }
    if ($origen   !== '')   { $where[] = 'origen = :origen';           $params[':origen']   = $origen; }
    if ($desde    !== '')   { $where[] = 'ingreso >= :desde';          $params[':desde']    = $desde . ' 00:00:00'; }
    if ($hasta    !== '')   { $where[] = 'ingreso <= :hasta';          $params[':hasta']    = $hasta . ' 23:59:59'; }

    if ($search !== '') {
        $where[] = '(nombre LIKE :s OR organizacion LIKE :s OR contacto LIKE :s
                     OR correo LIKE :s OR celular LIKE :s OR asunto LIKE :s
                     OR producto LIKE :s OR comentarios LIKE :s)';
        $params[':s'] = "%{$search}%";
    }

    $sqlWhere = $where ? ('WHERE ' . implode(' AND ', $where)) : '';

    // Stats globales (ignoran filtros — son indicadores del recurso).
    $stats = $pdo->query("
        SELECT
            COUNT(*)                                                          AS total,
            SUM(CASE WHEN atendido IS NULL OR atendido = 0 THEN 1 ELSE 0 END) AS sin_atender,
            SUM(CASE WHEN asignado IS NOT NULL AND asignado > 0 THEN 1 ELSE 0 END) AS asignados
        FROM datasaleprospectos
    ")->fetch();

    $sql = "
        SELECT " . DS_PRO_COLS . "
        FROM datasaleprospectos
        {$sqlWhere}
        ORDER BY {$orderBy} {$dirSql}
        LIMIT {$limite}
    ";
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $rows = $stmt->fetchAll();

    jsonOk([
        'stats' => [
            'total'       => (int)($stats['total']       ?? 0),
            'sin_atender' => (int)($stats['sin_atender'] ?? 0),
            'asignados'   => (int)($stats['asignados']   ?? 0),
        ],
        'items' => $rows,
    ]);
}

function handleGetOne(PDO $pdo, int $id): void {
    $stmt = $pdo->prepare("SELECT " . DS_PRO_COLS . " FROM datasaleprospectos WHERE id = :id");
    $stmt->execute([':id' => $id]);
    $row = $stmt->fetch();
    if (!$row) jsonError('Prospecto no encontrado', 404);
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
        'ingreso'      => nullableDateTime($in['ingreso']      ?? null),
        'proyecto'     => nullableInt($in['proyecto']          ?? null),
        'sentido'      => nullableStr($in['sentido']           ?? null, 1),
        'origen'       => nullableStr($in['origen']            ?? null, 10),
        'tipo'         => nullableStr($in['tipo']              ?? null, 1),
        'producto'     => nullableStr($in['producto']          ?? null, 100),
        'asunto'       => nullableStr($in['asunto']            ?? null, 255),
        'organizacion' => nullableStr($in['organizacion']      ?? null, 255),
        'nombre'       => nullableStr($in['nombre']            ?? null, 255),
        'contacto'     => nullableStr($in['contacto']          ?? null, 255),
        'celular'      => nullableStr($in['celular']           ?? null, 255),
        'correo'       => nullableStr($in['correo']            ?? null, 255),
        'web'          => nullableStr($in['web']               ?? null, 255),
        'domicilio'    => nullableStr($in['domicilio']         ?? null, 255),
        'ciudad'       => nullableStr($in['ciudad']            ?? null, 255),
        'localidad'    => nullableStr($in['localidad']         ?? null, 255),
        'provincia'    => nullableStr($in['provincia']         ?? null, 255),
        'pais'         => nullableStr($in['pais']              ?? null, 255),
        'ubicacion'    => nullableStr($in['ubicacion']         ?? null, 255),
        'calificacion' => nullableInt($in['calificacion']      ?? null),
        'estado'       => nullableInt($in['estado']            ?? null),
        'asignado'     => nullableInt($in['asignado']          ?? null),
        'atendido'     => nullableInt($in['atendido']          ?? null),
        'actualizado'  => nullableDateTime($in['actualizado']  ?? null),
        'aplazado'     => nullableDateTime($in['aplazado']     ?? null),
        'comentarios'  => nullableStr($in['comentarios']       ?? null, 1000),
        'acciones'     => nullableStr($in['acciones']          ?? null),
    ];
}

function handleCreate(PDO $pdo, array $in): void {
    $p = sanitizePayload($in);
    if ($p['ingreso'] === null) {
        $p['ingreso'] = (new DateTime('now', new DateTimeZone('America/Argentina/Buenos_Aires')))
                        ->format('Y-m-d H:i:s');
    }
    // `actualizado` se refresca en cada alta / modificacion.
    $p['actualizado'] = (new DateTime('now', new DateTimeZone('America/Argentina/Buenos_Aires')))
                        ->format('Y-m-d H:i:s');

    $sql = "
        INSERT INTO datasaleprospectos
            (ingreso, proyecto, sentido, origen, tipo, producto, asunto, organizacion,
             nombre, contacto, celular, correo, web, domicilio, ciudad, localidad,
             provincia, pais, ubicacion, calificacion, estado, asignado, atendido,
             actualizado, aplazado, comentarios, acciones)
        VALUES
            (:ingreso, :proyecto, :sentido, :origen, :tipo, :producto, :asunto, :organizacion,
             :nombre, :contacto, :celular, :correo, :web, :domicilio, :ciudad, :localidad,
             :provincia, :pais, :ubicacion, :calificacion, :estado, :asignado, :atendido,
             :actualizado, :aplazado, :comentarios, :acciones)
    ";
    $stmt = $pdo->prepare($sql);
    $stmt->execute([
        ':ingreso'      => $p['ingreso'],
        ':proyecto'     => $p['proyecto'],
        ':sentido'      => $p['sentido'],
        ':origen'       => $p['origen'],
        ':tipo'         => $p['tipo'],
        ':producto'     => $p['producto'],
        ':asunto'       => $p['asunto'],
        ':organizacion' => $p['organizacion'],
        ':nombre'       => $p['nombre'],
        ':contacto'     => $p['contacto'],
        ':celular'      => $p['celular'],
        ':correo'       => $p['correo'],
        ':web'          => $p['web'],
        ':domicilio'    => $p['domicilio'],
        ':ciudad'       => $p['ciudad'],
        ':localidad'    => $p['localidad'],
        ':provincia'    => $p['provincia'],
        ':pais'         => $p['pais'],
        ':ubicacion'    => $p['ubicacion'],
        ':calificacion' => $p['calificacion'],
        ':estado'       => $p['estado'],
        ':asignado'     => $p['asignado'],
        ':atendido'     => $p['atendido'],
        ':actualizado'  => $p['actualizado'],
        ':aplazado'     => $p['aplazado'],
        ':comentarios'  => $p['comentarios'],
        ':acciones'     => $p['acciones'],
    ]);
    jsonOk(['id' => (int)$pdo->lastInsertId()], 201);
}

function handleUpdate(PDO $pdo, int $id, array $in): void {
    $exists = $pdo->prepare('SELECT id FROM datasaleprospectos WHERE id = :id');
    $exists->execute([':id' => $id]);
    if (!$exists->fetch()) jsonError('Prospecto no encontrado', 404);

    $p = sanitizePayload($in);
    // `actualizado` se refresca en cada modificacion, salvo que el cliente lo mande explicito.
    if ($p['actualizado'] === null) {
        $p['actualizado'] = (new DateTime('now', new DateTimeZone('America/Argentina/Buenos_Aires')))
                            ->format('Y-m-d H:i:s');
    }

    $sql = "
        UPDATE datasaleprospectos SET
            ingreso      = :ingreso,
            proyecto     = :proyecto,
            sentido      = :sentido,
            origen       = :origen,
            tipo         = :tipo,
            producto     = :producto,
            asunto       = :asunto,
            organizacion = :organizacion,
            nombre       = :nombre,
            contacto     = :contacto,
            celular      = :celular,
            correo       = :correo,
            web          = :web,
            domicilio    = :domicilio,
            ciudad       = :ciudad,
            localidad    = :localidad,
            provincia    = :provincia,
            pais         = :pais,
            ubicacion    = :ubicacion,
            calificacion = :calificacion,
            estado       = :estado,
            asignado     = :asignado,
            atendido     = :atendido,
            actualizado  = :actualizado,
            aplazado     = :aplazado,
            comentarios  = :comentarios,
            acciones     = :acciones
        WHERE id = :id
    ";
    $stmt = $pdo->prepare($sql);
    $stmt->execute([
        ':ingreso'      => $p['ingreso'],
        ':proyecto'     => $p['proyecto'],
        ':sentido'      => $p['sentido'],
        ':origen'       => $p['origen'],
        ':tipo'         => $p['tipo'],
        ':producto'     => $p['producto'],
        ':asunto'       => $p['asunto'],
        ':organizacion' => $p['organizacion'],
        ':nombre'       => $p['nombre'],
        ':contacto'     => $p['contacto'],
        ':celular'      => $p['celular'],
        ':correo'       => $p['correo'],
        ':web'          => $p['web'],
        ':domicilio'    => $p['domicilio'],
        ':ciudad'       => $p['ciudad'],
        ':localidad'    => $p['localidad'],
        ':provincia'    => $p['provincia'],
        ':pais'         => $p['pais'],
        ':ubicacion'    => $p['ubicacion'],
        ':calificacion' => $p['calificacion'],
        ':estado'       => $p['estado'],
        ':asignado'     => $p['asignado'],
        ':atendido'     => $p['atendido'],
        ':actualizado'  => $p['actualizado'],
        ':aplazado'     => $p['aplazado'],
        ':comentarios'  => $p['comentarios'],
        ':acciones'     => $p['acciones'],
        ':id'           => $id,
    ]);
    jsonOk(['id' => $id]);
}

function handleDelete(PDO $pdo, int $id): void {
    $stmt = $pdo->prepare('DELETE FROM datasaleprospectos WHERE id = :id');
    $stmt->execute([':id' => $id]);
    if ($stmt->rowCount() === 0) jsonError('Prospecto no encontrado', 404);
    jsonOk(['id' => $id]);
}
