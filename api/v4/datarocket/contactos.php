<?php
// api/v4/datarocket/contactos.php
// Microservicio CRUD del CRM Datarocket sobre la tabla `datarocket_contactos`.
//
//   GET    /v4/datarocket/contactos           -> listado con filtros (query string)
//   GET    /v4/datarocket/contactos?id=N      -> registro individual
//   POST   /v4/datarocket/contactos           (JSON body) -> alta, devuelve {id, uuid, registrado}
//   PUT    /v4/datarocket/contactos?id=N      (JSON body) -> modificacion, devuelve {id}
//   DELETE /v4/datarocket/contactos?id=N      -> baja definitiva, devuelve {id}
//
// Auth: Bearer con apikey de la tabla `aplicaciones` (mismo esquema que el resto
// del stack — ver cloud/api/lib/apikey_auth.php). Cualquier apikey habilitada pasa.
//
// Tabla destino: `datarocket_contactos` (schema en db/schema.sql).
//
// El ABM interno equivalente (usado por el panel cloud) es
// cloud/api/datarocketcontactos.php — mismas columnas, mismos filtros, misma
// forma de sanitizacion; la diferencia es la capa de auth (permisos de sesion
// vs. Bearer estatico) y que el listado v4 no publica el bloque `stats`.

header('Content-Type: application/json; charset=utf-8');

require_once dirname(__DIR__, 3) . '/env.php';
require_once dirname(__DIR__, 3) . '/cloud/api/db.php';

// ---------------------------------------------------------------------------
// Auth
// ---------------------------------------------------------------------------
// Apache no siempre propaga Authorization a $_SERVER (depende de mod_rewrite
// y CGIPassAuth). Chequeamos $_SERVER, REDIRECT_HTTP_AUTHORIZATION y como
// ultimo recurso getallheaders().

function readBearer(): string {
    $auth = trim((string)($_SERVER['HTTP_AUTHORIZATION']
                       ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION']
                       ?? ''));
    if ($auth === '' && function_exists('getallheaders')) {
        foreach (getallheaders() as $k => $v) {
            if (strcasecmp($k, 'Authorization') === 0) { $auth = trim((string)$v); break; }
        }
    }
    return stripos($auth, 'Bearer ') === 0 ? trim(substr($auth, 7)) : '';
}

function requireApp(): array {
    $token = readBearer();
    if ($token === '') jsonError('Bearer token ausente', 401);

    $pdo = db();
    $st  = $pdo->prepare("SELECT id, nombre, habilitada FROM aplicaciones WHERE apikey = :k LIMIT 1");
    $st->execute([':k' => $token]);
    $app = $st->fetch();
    if (!$app)                              jsonError('API key desconocida', 401);
    if ((string)$app['habilitada'] !== '1') jsonError('Aplicacion deshabilitada', 401);

    // Contador de uso — best effort, un fallo aca no debe tumbar el request.
    try {
        $pdo->prepare("UPDATE aplicaciones SET usos = COALESCE(usos,0)+1 WHERE id = :id")
            ->execute([':id' => (int)$app['id']]);
    } catch (Throwable) { /* ignore */ }

    return $app;
}

// ---------------------------------------------------------------------------
// Ruteo
// ---------------------------------------------------------------------------

const DR_CT_COLS = "id, uuid, origen, nombre, empresa, rubro, actividad, cargo,
                    persona, genero, nacimiento, dni, domicilio, ciudad, ubicacion,
                    localidad, provincia, pais, telefono, celular, whatsapp, correo,
                    web, facebook, instagram, tiktok, comentarios, tags, suscripciones,
                    listas, registrado, completado, error, estado, verificacion";

try {
    requireApp();
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
        if ($id <= 0) jsonError('Falta id (int > 0)', 400);
        handleUpdate($pdo, $id, readJsonBody());
    } elseif ($method === 'DELETE') {
        if ($id <= 0) jsonError('Falta id (int > 0)', 400);
        handleDelete($pdo, $id);
    } else {
        jsonError('Metodo no soportado', 405);
    }
} catch (Throwable $e) {
    jsonError($e->getMessage(), 500);
}

// ---------------------------------------------------------------------------
// Listado / consulta individual
// ---------------------------------------------------------------------------

function handleList(PDO $pdo, array $q): void {
    $codigo       = isset($q['codigo']) && $q['codigo'] !== '' ? (int)$q['codigo'] : null;
    $estado       = trim((string)($q['estado']       ?? ''));
    $verificacion = trim((string)($q['verificacion'] ?? ''));
    $genero       = trim((string)($q['genero']       ?? ''));
    $origen       = trim((string)($q['origen']       ?? ''));
    $pais         = trim((string)($q['pais']         ?? ''));
    $provincia    = trim((string)($q['provincia']    ?? ''));
    $correo       = trim((string)($q['correo']       ?? ''));
    $celular      = trim((string)($q['celular']      ?? ''));
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

    if ($codigo       !== null) { $where[] = 'id = :codigo';                 $params[':codigo']       = $codigo; }
    if ($estado       !== '')   { $where[] = 'estado = :estado';             $params[':estado']       = $estado; }
    if ($verificacion !== '')   { $where[] = 'verificacion = :verificacion'; $params[':verificacion'] = $verificacion; }
    if ($genero       !== '')   { $where[] = 'genero = :genero';             $params[':genero']       = $genero; }
    if ($origen       !== '')   { $where[] = 'origen = :origen';             $params[':origen']       = $origen; }
    if ($pais         !== '')   { $where[] = 'pais = :pais';                 $params[':pais']         = $pais; }
    if ($provincia    !== '')   { $where[] = 'provincia = :provincia';       $params[':provincia']    = $provincia; }
    if ($correo       !== '')   { $where[] = 'correo LIKE :correo';          $params[':correo']       = '%' . $correo . '%'; }
    if ($celular      !== '')   { $where[] = 'celular LIKE :celular';        $params[':celular']      = '%' . $celular . '%'; }
    if ($desde        !== '')   { $where[] = 'registrado >= :desde';         $params[':desde']        = $desde . ' 00:00:00'; }
    if ($hasta        !== '')   { $where[] = 'registrado <= :hasta';         $params[':hasta']        = $hasta . ' 23:59:59'; }

    if ($search !== '') {
        $where[] = '(nombre LIKE :s1 OR empresa LIKE :s2 OR correo LIKE :s3
                     OR telefono LIKE :s4 OR celular LIKE :s5 OR whatsapp LIKE :s6
                     OR dni LIKE :s7 OR uuid LIKE :s8)';
        $like = "%{$search}%";
        $params[':s1'] = $like; $params[':s2'] = $like; $params[':s3'] = $like;
        $params[':s4'] = $like; $params[':s5'] = $like; $params[':s6'] = $like;
        $params[':s7'] = $like; $params[':s8'] = $like;
    }

    $sqlWhere = $where ? ('WHERE ' . implode(' AND ', $where)) : '';

    $sql = "SELECT " . DR_CT_COLS . "
              FROM datarocket_contactos
              {$sqlWhere}
              ORDER BY {$orderBy} {$dirSql}
              LIMIT {$limite}";
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $rows = $stmt->fetchAll();

    jsonOk([
        'total' => count($rows),
        'items' => $rows,
    ]);
}

function handleGetOne(PDO $pdo, int $id): void {
    $stmt = $pdo->prepare("SELECT " . DR_CT_COLS . " FROM datarocket_contactos WHERE id = :id");
    $stmt->execute([':id' => $id]);
    $row = $stmt->fetch();
    if (!$row) jsonError('Contacto no encontrado', 404);
    jsonOk($row);
}

// ---------------------------------------------------------------------------
// Alta / Modificacion / Baja
// ---------------------------------------------------------------------------

function drCtNullableStr(mixed $v, ?int $max = null): ?string {
    if ($v === null) return null;
    $s = trim((string)$v);
    if ($s === '') return null;
    if ($max !== null) $s = substr($s, 0, $max);
    return $s;
}

function drCtNullableInt(mixed $v): ?int {
    if ($v === null || $v === '') return null;
    return (int)$v;
}

// Normaliza telefonos: elimina cualquier caracter que no sea digito
// (espacios, guiones, parentesis, '+', etc.). Si queda vacio -> NULL.
// Aplica a telefono, celular y whatsapp — la tabla es unica canal-agnostica
// de contactos y queremos que los numeros queden comparables entre si.
function drCtDigitsOnly(mixed $v): ?string {
    $s = drCtNullableStr($v);
    if ($s === null) return null;
    $s = preg_replace('/\D+/', '', $s);
    return $s === '' ? null : substr($s, 0, 255);
}

// Acepta 'YYYY-MM-DDTHH:MM', 'YYYY-MM-DD HH:MM' y 'YYYY-MM-DD HH:MM:SS'.
// Cualquier otro formato devuelve NULL (que dispara el default en handleCreate).
function drCtNullableDateTime(mixed $v): ?string {
    $s = drCtNullableStr($v);
    if ($s === null) return null;
    $s = str_replace('T', ' ', $s);
    if (preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}$/', $s)) $s .= ':00';
    if (!preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $s)) return null;
    return $s;
}

function drCtSanitize(array $in): array {
    return [
        'origen'        => drCtNullableStr($in['origen']        ?? null, 255),
        'nombre'        => drCtNullableStr($in['nombre']        ?? null, 255),
        'empresa'       => drCtNullableStr($in['empresa']       ?? null, 255),
        'rubro'         => drCtNullableStr($in['rubro']         ?? null, 255),
        'actividad'     => drCtNullableStr($in['actividad']     ?? null, 255),
        'cargo'         => drCtNullableStr($in['cargo']         ?? null, 255),
        'persona'       => drCtNullableStr($in['persona']       ?? null, 255),
        'genero'        => drCtNullableStr($in['genero']        ?? null, 1),
        'nacimiento'    => drCtNullableStr($in['nacimiento']    ?? null, 255),
        'dni'           => drCtNullableStr($in['dni']           ?? null, 255),
        'domicilio'     => drCtNullableStr($in['domicilio']     ?? null, 255),
        'ciudad'        => drCtNullableStr($in['ciudad']        ?? null, 255),
        'ubicacion'     => drCtNullableStr($in['ubicacion']     ?? null, 255),
        'localidad'     => drCtNullableStr($in['localidad']     ?? null, 255),
        'provincia'     => drCtNullableStr($in['provincia']     ?? null, 255),
        'pais'          => drCtNullableStr($in['pais']          ?? null, 255),
        'telefono'      => drCtDigitsOnly($in['telefono']      ?? null),
        'celular'       => drCtDigitsOnly($in['celular']       ?? null),
        'whatsapp'      => drCtDigitsOnly($in['whatsapp']      ?? null),
        'correo'        => drCtNullableStr($in['correo']        ?? null, 255),
        'web'           => drCtNullableStr($in['web']           ?? null, 255),
        'facebook'      => drCtNullableStr($in['facebook']      ?? null, 255),
        'instagram'     => drCtNullableStr($in['instagram']     ?? null, 255),
        'tiktok'        => drCtNullableStr($in['tiktok']        ?? null, 255),
        'comentarios'   => drCtNullableStr($in['comentarios']   ?? null, 500),
        'tags'          => drCtNullableStr($in['tags']          ?? null, 500),
        'suscripciones' => drCtNullableInt($in['suscripciones'] ?? null),
        'listas'        => drCtNullableStr($in['listas']        ?? null, 500),
        'registrado'    => drCtNullableDateTime($in['registrado'] ?? null),
        'completado'    => drCtNullableDateTime($in['completado'] ?? null),
        'error'         => drCtNullableStr($in['error']         ?? null, 255),
        'estado'        => drCtNullableStr($in['estado']        ?? null, 1),
        'verificacion'  => drCtNullableStr($in['verificacion']  ?? null, 1),
    ];
}

function handleCreate(PDO $pdo, array $in): void {
    $p = drCtSanitize($in);
    $p['uuid'] = drCtNullableStr($in['uuid'] ?? null, 255) ?? bin2hex(random_bytes(16));
    if ($p['registrado'] === null) {
        $p['registrado'] = (new DateTime('now', new DateTimeZone('America/Argentina/Buenos_Aires')))
                           ->format('Y-m-d H:i:s');
    }

    $sql = "INSERT INTO datarocket_contactos
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
                 :registrado, :completado, :error, :estado, :verificacion)";
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

    jsonOk([
        'id'         => (int)$pdo->lastInsertId(),
        'uuid'       => $p['uuid'],
        'registrado' => $p['registrado'],
    ], 201);
}

function handleUpdate(PDO $pdo, int $id, array $in): void {
    $exists = $pdo->prepare('SELECT id FROM datarocket_contactos WHERE id = :id');
    $exists->execute([':id' => $id]);
    if (!$exists->fetch()) jsonError('Contacto no encontrado', 404);

    $p = drCtSanitize($in);

    $sql = "UPDATE datarocket_contactos SET
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
            WHERE id = :id";
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
    $stmt = $pdo->prepare('DELETE FROM datarocket_contactos WHERE id = :id');
    $stmt->execute([':id' => $id]);
    if ($stmt->rowCount() === 0) jsonError('Contacto no encontrado', 404);
    jsonOk(['id' => $id]);
}
