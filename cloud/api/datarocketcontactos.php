<?php
// api/datarocketcontactos.php
// ABM de contactos Datarocket. Lee/escribe sobre la tabla `datarocketcontactos`
// definida en db/schema.sql.
//   GET    api/datarocketcontactos.php          -> listado con filtros (query string)
//   GET    api/datarocketcontactos.php?id=N     -> registro individual
//   POST   api/datarocketcontactos.php          -> alta (JSON body)
//   PUT    api/datarocketcontactos.php?id=N     -> modificacion (JSON body)
//   DELETE api/datarocketcontactos.php?id=N     -> baja
// Respuesta siempre {ok: true, data: ...} u {ok: false, error: '...'} (STACK.md sec. 10).

require_once __DIR__ . '/db.php';

const DR_CT_COLS = "id, uuid, origen, nombre, empresa, rubro, actividad, cargo,
                    persona, genero, nacimiento, dni, domicilio, ciudad, ubicacion,
                    localidad, provincia, pais, telefono, celular, whatsapp, correo,
                    web, facebook, instagram, tiktok, comentarios, tags, suscripciones,
                    listas, registrado, completado, error, estado, verificacion";

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
    $codigo       = isset($q['codigo']) && $q['codigo'] !== '' ? (int)$q['codigo'] : null;
    $estado       = trim((string)($q['estado']       ?? ''));
    $verificacion = trim((string)($q['verificacion'] ?? ''));
    $genero       = trim((string)($q['genero']       ?? ''));
    $origen       = trim((string)($q['origen']       ?? ''));
    $pais         = trim((string)($q['pais']         ?? ''));
    $provincia    = trim((string)($q['provincia']    ?? ''));
    $desde        = trim((string)($q['desde']        ?? ''));
    $hasta        = trim((string)($q['hasta']        ?? ''));
    $search       = trim((string)($q['q']            ?? ''));

    $orderBy = $q['order_by'] ?? 'id';
    $dir     = strtolower((string)($q['dir'] ?? 'desc'));
    $limite  = isset($q['limite']) ? (int)$q['limite'] : 100;
    if ($limite < 1)    $limite = 1;
    if ($limite > 1000) $limite = 1000;

    $allowedOrder = ['id', 'nombre', 'empresa', 'correo', 'registrado', 'completado',
                     'estado', 'verificacion', 'pais', 'provincia', 'origen'];
    if (!in_array($orderBy, $allowedOrder, true)) $orderBy = 'id';
    $dirSql = $dir === 'asc' ? 'ASC' : 'DESC';

    $where  = [];
    $params = [];

    if ($codigo       !== null) { $where[] = 'id = :codigo';                     $params[':codigo']       = $codigo; }
    if ($estado       !== '')   { $where[] = 'estado = :estado';                 $params[':estado']       = $estado; }
    if ($verificacion !== '')   { $where[] = 'verificacion = :verificacion';     $params[':verificacion'] = $verificacion; }
    if ($genero       !== '')   { $where[] = 'genero = :genero';                 $params[':genero']       = $genero; }
    if ($origen       !== '')   { $where[] = 'origen = :origen';                 $params[':origen']       = $origen; }
    if ($pais         !== '')   { $where[] = 'pais = :pais';                     $params[':pais']         = $pais; }
    if ($provincia    !== '')   { $where[] = 'provincia = :provincia';           $params[':provincia']    = $provincia; }
    if ($desde        !== '')   { $where[] = 'registrado >= :desde';             $params[':desde']        = $desde . ' 00:00:00'; }
    if ($hasta        !== '')   { $where[] = 'registrado <= :hasta';             $params[':hasta']        = $hasta . ' 23:59:59'; }

    if ($search !== '') {
        $where[] = '(nombre LIKE :s OR empresa LIKE :s OR correo LIKE :s
                     OR telefono LIKE :s OR celular LIKE :s OR whatsapp LIKE :s
                     OR dni LIKE :s OR uuid LIKE :s)';
        $params[':s'] = "%{$search}%";
    }

    $sqlWhere = $where ? ('WHERE ' . implode(' AND ', $where)) : '';

    // Stats globales (ignoran filtros — son indicadores del recurso).
    $stats = $pdo->query("
        SELECT
            COUNT(*)                                                                   AS total,
            SUM(CASE WHEN verificacion IS NOT NULL AND verificacion <> '' THEN 1 ELSE 0 END) AS verificados,
            SUM(CASE WHEN error IS NOT NULL AND error <> '' THEN 1 ELSE 0 END)         AS con_error
        FROM datarocketcontactos
    ")->fetch();

    $sql = "
        SELECT " . DR_CT_COLS . "
        FROM datarocketcontactos
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
            'verificados' => (int)($stats['verificados'] ?? 0),
            'con_error'   => (int)($stats['con_error']   ?? 0),
        ],
        'items' => $rows,
    ]);
}

function handleGetOne(PDO $pdo, int $id): void {
    $stmt = $pdo->prepare("SELECT " . DR_CT_COLS . " FROM datarocketcontactos WHERE id = :id");
    $stmt->execute([':id' => $id]);
    $row = $stmt->fetch();
    if (!$row) jsonError('Contacto no encontrado', 404);
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
        'origen'        => nullableStr($in['origen']        ?? null, 255),
        'nombre'        => nullableStr($in['nombre']        ?? null, 255),
        'empresa'       => nullableStr($in['empresa']       ?? null, 255),
        'rubro'         => nullableStr($in['rubro']         ?? null, 255),
        'actividad'     => nullableStr($in['actividad']     ?? null, 255),
        'cargo'         => nullableStr($in['cargo']         ?? null, 255),
        'persona'       => nullableStr($in['persona']       ?? null, 255),
        'genero'        => nullableStr($in['genero']        ?? null, 1),
        'nacimiento'    => nullableStr($in['nacimiento']    ?? null, 255),
        'dni'           => nullableStr($in['dni']           ?? null, 255),
        'domicilio'     => nullableStr($in['domicilio']     ?? null, 255),
        'ciudad'        => nullableStr($in['ciudad']        ?? null, 255),
        'ubicacion'     => nullableStr($in['ubicacion']     ?? null, 255),
        'localidad'     => nullableStr($in['localidad']     ?? null, 255),
        'provincia'     => nullableStr($in['provincia']     ?? null, 255),
        'pais'          => nullableStr($in['pais']          ?? null, 255),
        'telefono'      => nullableStr($in['telefono']      ?? null, 255),
        'celular'       => nullableStr($in['celular']       ?? null, 255),
        'whatsapp'      => nullableStr($in['whatsapp']      ?? null, 255),
        'correo'        => nullableStr($in['correo']        ?? null, 255),
        'web'           => nullableStr($in['web']           ?? null, 255),
        'facebook'      => nullableStr($in['facebook']      ?? null, 255),
        'instagram'     => nullableStr($in['instagram']     ?? null, 255),
        'tiktok'        => nullableStr($in['tiktok']        ?? null, 255),
        'comentarios'   => nullableStr($in['comentarios']   ?? null, 500),
        'tags'          => nullableStr($in['tags']          ?? null, 500),
        'suscripciones' => nullableInt($in['suscripciones'] ?? null),
        'listas'        => nullableStr($in['listas']        ?? null, 500),
        'registrado'    => nullableDateTime($in['registrado'] ?? null),
        'completado'    => nullableDateTime($in['completado'] ?? null),
        'error'         => nullableStr($in['error']         ?? null, 255),
        'estado'        => nullableStr($in['estado']        ?? null, 1),
        'verificacion'  => nullableStr($in['verificacion']  ?? null, 1),
    ];
}

function handleCreate(PDO $pdo, array $in): void {
    $p = sanitizePayload($in);
    $p['uuid'] = nullableStr($in['uuid'] ?? null, 255) ?? bin2hex(random_bytes(16));
    if ($p['registrado'] === null) {
        $p['registrado'] = (new DateTime('now', new DateTimeZone('America/Argentina/Buenos_Aires')))
                           ->format('Y-m-d H:i:s');
    }

    $sql = "
        INSERT INTO datarocketcontactos
            (uuid, origen, nombre, empresa, rubro, actividad, cargo, persona,
             genero, nacimiento, dni, domicilio, ciudad, ubicacion, localidad,
             provincia, pais, telefono, celular, whatsapp, correo, web, facebook,
             instagram, tiktok, comentarios, tags, suscripciones, listas,
             registrado, completado, error, estado, verificacion)
        VALUES
            (:uuid, :origen, :nombre, :empresa, :rubro, :actividad, :cargo, :persona,
             :genero, :nacimiento, :dni, :domicilio, :ciudad, :ubicacion, :localidad,
             :provincia, :pais, :telefono, :celular, :whatsapp, :correo, :web, :facebook,
             :instagram, :tiktok, :comentarios, :tags, :suscripciones, :listas,
             :registrado, :completado, :error, :estado, :verificacion)
    ";
    $stmt = $pdo->prepare($sql);
    $stmt->execute([
        ':uuid'          => $p['uuid'],
        ':origen'        => $p['origen'],
        ':nombre'        => $p['nombre'],
        ':empresa'       => $p['empresa'],
        ':rubro'         => $p['rubro'],
        ':actividad'     => $p['actividad'],
        ':cargo'         => $p['cargo'],
        ':persona'       => $p['persona'],
        ':genero'        => $p['genero'],
        ':nacimiento'    => $p['nacimiento'],
        ':dni'           => $p['dni'],
        ':domicilio'     => $p['domicilio'],
        ':ciudad'        => $p['ciudad'],
        ':ubicacion'     => $p['ubicacion'],
        ':localidad'     => $p['localidad'],
        ':provincia'     => $p['provincia'],
        ':pais'          => $p['pais'],
        ':telefono'      => $p['telefono'],
        ':celular'       => $p['celular'],
        ':whatsapp'      => $p['whatsapp'],
        ':correo'        => $p['correo'],
        ':web'           => $p['web'],
        ':facebook'      => $p['facebook'],
        ':instagram'     => $p['instagram'],
        ':tiktok'        => $p['tiktok'],
        ':comentarios'   => $p['comentarios'],
        ':tags'          => $p['tags'],
        ':suscripciones' => $p['suscripciones'],
        ':listas'        => $p['listas'],
        ':registrado'    => $p['registrado'],
        ':completado'    => $p['completado'],
        ':error'         => $p['error'],
        ':estado'        => $p['estado'],
        ':verificacion'  => $p['verificacion'],
    ]);
    jsonOk(['id' => (int)$pdo->lastInsertId()], 201);
}

function handleUpdate(PDO $pdo, int $id, array $in): void {
    $exists = $pdo->prepare('SELECT id FROM datarocketcontactos WHERE id = :id');
    $exists->execute([':id' => $id]);
    if (!$exists->fetch()) jsonError('Contacto no encontrado', 404);

    $p = sanitizePayload($in);

    $sql = "
        UPDATE datarocketcontactos SET
            origen        = :origen,
            nombre        = :nombre,
            empresa       = :empresa,
            rubro         = :rubro,
            actividad     = :actividad,
            cargo         = :cargo,
            persona       = :persona,
            genero        = :genero,
            nacimiento    = :nacimiento,
            dni           = :dni,
            domicilio     = :domicilio,
            ciudad        = :ciudad,
            ubicacion     = :ubicacion,
            localidad     = :localidad,
            provincia     = :provincia,
            pais          = :pais,
            telefono      = :telefono,
            celular       = :celular,
            whatsapp      = :whatsapp,
            correo        = :correo,
            web           = :web,
            facebook      = :facebook,
            instagram     = :instagram,
            tiktok        = :tiktok,
            comentarios   = :comentarios,
            tags          = :tags,
            suscripciones = :suscripciones,
            listas        = :listas,
            registrado    = :registrado,
            completado    = :completado,
            error         = :error,
            estado        = :estado,
            verificacion  = :verificacion
        WHERE id = :id
    ";
    $stmt = $pdo->prepare($sql);
    $stmt->execute([
        ':origen'        => $p['origen'],
        ':nombre'        => $p['nombre'],
        ':empresa'       => $p['empresa'],
        ':rubro'         => $p['rubro'],
        ':actividad'     => $p['actividad'],
        ':cargo'         => $p['cargo'],
        ':persona'       => $p['persona'],
        ':genero'        => $p['genero'],
        ':nacimiento'    => $p['nacimiento'],
        ':dni'           => $p['dni'],
        ':domicilio'     => $p['domicilio'],
        ':ciudad'        => $p['ciudad'],
        ':ubicacion'     => $p['ubicacion'],
        ':localidad'     => $p['localidad'],
        ':provincia'     => $p['provincia'],
        ':pais'          => $p['pais'],
        ':telefono'      => $p['telefono'],
        ':celular'       => $p['celular'],
        ':whatsapp'      => $p['whatsapp'],
        ':correo'        => $p['correo'],
        ':web'           => $p['web'],
        ':facebook'      => $p['facebook'],
        ':instagram'     => $p['instagram'],
        ':tiktok'        => $p['tiktok'],
        ':comentarios'   => $p['comentarios'],
        ':tags'          => $p['tags'],
        ':suscripciones' => $p['suscripciones'],
        ':listas'        => $p['listas'],
        ':registrado'    => $p['registrado'],
        ':completado'    => $p['completado'],
        ':error'         => $p['error'],
        ':estado'        => $p['estado'],
        ':verificacion'  => $p['verificacion'],
        ':id'            => $id,
    ]);
    jsonOk(['id' => $id]);
}

function handleDelete(PDO $pdo, int $id): void {
    $stmt = $pdo->prepare('DELETE FROM datarocketcontactos WHERE id = :id');
    $stmt->execute([':id' => $id]);
    if ($stmt->rowCount() === 0) jsonError('Contacto no encontrado', 404);
    jsonOk(['id' => $id]);
}
