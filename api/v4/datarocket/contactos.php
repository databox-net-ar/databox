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

const DR_CT_COLS = "id, uuid, tipo, origen, nombre, empresa, rubro, actividad, cargo,
                    persona, genero, nacimiento, dni, domicilio, ciudad, ubicacion,
                    localidad, provincia, pais, telefono, celular, whatsapp, correo,
                    web, facebook, instagram, tiktok, comentarios, suscripciones,
                    registrado, completado, error, estado, verificacion";

// Valores validos para `datarocket_contactos.tipo`. Se rechazan alta y
// modificacion que no traigan uno de estos valores; las filas historicas
// quedan en NULL hasta ser editadas.
const DR_CT_TIPOS_VALIDOS = ['persona', 'empresa'];

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

    // Anexa `lista_ids` y `etiqueta_ids` (int[]) a cada contacto — batch
    // queries contra las puentes `datarocket_contactos_listas` (20260811_1400)
    // y `datarocket_contactos_etiquetas` (20260811_1600). Sin N+1.
    drCtAttachListaIds($pdo, $rows);
    drCtAttachEtiquetaIds($pdo, $rows);

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

    $lists = $pdo->prepare("
        SELECT dl.id, dl.nombre
          FROM datarocket_contactos_listas dcl
          JOIN datarocket_listas dl ON dl.id = dcl.lista_id
         WHERE dcl.contacto_id = :id
      ORDER BY dl.nombre
    ");
    $lists->execute([':id' => $id]);
    $rowsLi = $lists->fetchAll();
    $row['lista_ids']     = array_map(fn($r) => (int)$r['id'],     $rowsLi);
    $row['lista_nombres'] = array_map(fn($r) => (string)$r['nombre'], $rowsLi);

    $etiqs = $pdo->prepare("
        SELECT de.id, de.nombre
          FROM datarocket_contactos_etiquetas dce
          JOIN datarocket_etiquetas de ON de.id = dce.etiqueta_id
         WHERE dce.contacto_id = :id
      ORDER BY de.nombre
    ");
    $etiqs->execute([':id' => $id]);
    $rowsEt = $etiqs->fetchAll();
    $row['etiqueta_ids']     = array_map(fn($r) => (int)$r['id'],     $rowsEt);
    $row['etiqueta_nombres'] = array_map(fn($r) => (string)$r['nombre'], $rowsEt);

    jsonOk($row);
}

// Batch: anexa `lista_ids` (int[]) a cada fila con una unica query
// GROUP_CONCAT contra la puente. Evita N+1. MySQL 8 / MariaDB 10.11 OK.
function drCtAttachListaIds(PDO $pdo, array &$rows): void {
    if (!$rows) return;
    $ids = array_map(fn($r) => (int)$r['id'], $rows);
    $in  = implode(',', $ids);
    // Nombres viajan junto a los ids para que los clientes del microservicio
    // puedan pintar pills sin un fetch extra del catalogo. Separador
    // `||~||` — literal imprimible que GROUP_CONCAT acepta y que no puede
    // aparecer de forma natural en un nombre.
    $mapIds = $mapNombres = [];
    foreach ($pdo->query("
        SELECT dcl.contacto_id,
               GROUP_CONCAT(dcl.lista_id ORDER BY dl.nombre)                       AS lista_ids,
               GROUP_CONCAT(dl.nombre    ORDER BY dl.nombre SEPARATOR '||~||')    AS lista_nombres
          FROM datarocket_contactos_listas dcl
          JOIN datarocket_listas dl ON dl.id = dcl.lista_id
         WHERE dcl.contacto_id IN ({$in})
      GROUP BY dcl.contacto_id
    ") as $r) {
        $cid = (int)$r['contacto_id'];
        $mapIds[$cid]     = array_map('intval', explode(',',     (string)$r['lista_ids']));
        $mapNombres[$cid] = explode('||~||', (string)$r['lista_nombres']);
    }
    foreach ($rows as &$row) {
        $cid = (int)$row['id'];
        $row['lista_ids']     = $mapIds[$cid]     ?? [];
        $row['lista_nombres'] = $mapNombres[$cid] ?? [];
    }
}

// Idem para `etiqueta_ids` contra `datarocket_contactos_etiquetas`.
function drCtAttachEtiquetaIds(PDO $pdo, array &$rows): void {
    if (!$rows) return;
    $ids = array_map(fn($r) => (int)$r['id'], $rows);
    $in  = implode(',', $ids);
    $mapIds = $mapNombres = [];
    foreach ($pdo->query("
        SELECT dce.contacto_id,
               GROUP_CONCAT(dce.etiqueta_id ORDER BY de.nombre)                       AS etiqueta_ids,
               GROUP_CONCAT(de.nombre       ORDER BY de.nombre SEPARATOR '||~||')    AS etiqueta_nombres
          FROM datarocket_contactos_etiquetas dce
          JOIN datarocket_etiquetas de ON de.id = dce.etiqueta_id
         WHERE dce.contacto_id IN ({$in})
      GROUP BY dce.contacto_id
    ") as $r) {
        $cid = (int)$r['contacto_id'];
        $mapIds[$cid]     = array_map('intval', explode(',',     (string)$r['etiqueta_ids']));
        $mapNombres[$cid] = explode('||~||', (string)$r['etiqueta_nombres']);
    }
    foreach ($rows as &$row) {
        $cid = (int)$row['id'];
        $row['etiqueta_ids']     = $mapIds[$cid]     ?? [];
        $row['etiqueta_nombres'] = $mapNombres[$cid] ?? [];
    }
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

// Genera un UUID v4 RFC 4122 (36 chars con guiones) alineado con el formato
// que ya persiste `datarocket_contactos.uuid` (regenerado por la migracion
// 20260727_2000). Antes usabamos bin2hex(random_bytes(16)) que producia 32
// chars hex sin guiones — no era UUID estandar.
function drCtUuidV4(): string {
    $d = random_bytes(16);
    $d[6] = chr((ord($d[6]) & 0x0f) | 0x40);
    $d[8] = chr((ord($d[8]) & 0x3f) | 0x80);
    return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($d), 4));
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
        'tipo'          => drCtNullableStr($in['tipo']          ?? null, 20),
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
        'suscripciones' => drCtNullableInt($in['suscripciones'] ?? null),
        'registrado'    => drCtNullableDateTime($in['registrado'] ?? null),
        'completado'    => drCtNullableDateTime($in['completado'] ?? null),
        'error'         => drCtNullableStr($in['error']         ?? null, 255),
        'estado'        => drCtNullableStr($in['estado']        ?? null, 1),
        'verificacion'  => drCtNullableStr($in['verificacion']  ?? null, 1),
    ];
}

function handleCreate(PDO $pdo, array $in): void {
    $p = drCtSanitize($in);
    // `tipo` obligatorio en alta — cualquier cliente del microservicio v4
    // debe indicar persona o empresa. Ver DR_CT_TIPOS_VALIDOS.
    if (!in_array($p['tipo'], DR_CT_TIPOS_VALIDOS, true)) {
        jsonError('El tipo es obligatorio (persona o empresa).', 400);
    }
    $p['uuid'] = drCtNullableStr($in['uuid'] ?? null, 255) ?? drCtUuidV4();
    if ($p['registrado'] === null) {
        $p['registrado'] = (new DateTime('now', new DateTimeZone('America/Argentina/Buenos_Aires')))
                           ->format('Y-m-d H:i:s');
    }
    $listaIds    = drCtSanitizeListaIds($in['lista_ids']    ?? null);
    $etiquetaIds = drCtSanitizeEtiquetaIds($in['etiqueta_ids'] ?? null);

    $pdo->beginTransaction();
    try {
        $sql = "INSERT INTO datarocket_contactos
                    (uuid, tipo, origen, nombre, empresa, rubro, actividad, cargo, persona,
                     genero, nacimiento, dni, domicilio, ciudad, ubicacion, localidad,
                     provincia, pais, telefono, celular, whatsapp, correo, web, facebook,
                     instagram, tiktok, comentarios, suscripciones,
                     registrado, completado, error, estado, verificacion)
                VALUES
                    (:uuid, :tipo, :origen, :nombre, :empresa, :rubro, :actividad, :cargo, :persona,
                     :genero, :nacimiento, :dni, :domicilio, :ciudad, :ubicacion, :localidad,
                     :provincia, :pais, :telefono, :celular, :whatsapp, :correo, :web, :facebook,
                     :instagram, :tiktok, :comentarios, :suscripciones,
                     :registrado, :completado, :error, :estado, :verificacion)";
        $stmt = $pdo->prepare($sql);
        $stmt->execute([
            ':uuid'          => $p['uuid'],
            ':tipo'          => $p['tipo'],
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
            ':suscripciones' => $p['suscripciones'],
            ':registrado'    => $p['registrado'],
            ':completado'    => $p['completado'],
            ':error'         => $p['error'],
            ':estado'        => $p['estado'],
            ':verificacion'  => $p['verificacion'],
        ]);
        $newId = (int)$pdo->lastInsertId();
        drCtSyncListas($pdo, $newId, $listaIds);
        drCtSyncEtiquetas($pdo, $newId, $etiquetaIds);
        $pdo->commit();
        jsonOk([
            'id'         => $newId,
            'uuid'       => $p['uuid'],
            'registrado' => $p['registrado'],
        ], 201);
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }
}

function handleUpdate(PDO $pdo, int $id, array $in): void {
    $exists = $pdo->prepare('SELECT id FROM datarocket_contactos WHERE id = :id');
    $exists->execute([':id' => $id]);
    if (!$exists->fetch()) jsonError('Contacto no encontrado', 404);

    $p = drCtSanitize($in);
    // `tipo` obligatorio en edicion — mismas reglas que en el ABM cloud.
    if (!in_array($p['tipo'], DR_CT_TIPOS_VALIDOS, true)) {
        jsonError('El tipo es obligatorio (persona o empresa).', 400);
    }
    // `lista_ids` / `etiqueta_ids` opcionales en PUT: si no vienen, no se
    // toca la puente. Solo cuando el cliente los manda explicitamente (aun
    // `[]` para desasignar de todo) se sincroniza cada una.
    $listaIds    = array_key_exists('lista_ids', $in)
        ? drCtSanitizeListaIds($in['lista_ids'])
        : null;
    $etiquetaIds = array_key_exists('etiqueta_ids', $in)
        ? drCtSanitizeEtiquetaIds($in['etiqueta_ids'])
        : null;

    $pdo->beginTransaction();
    try {
        $sql = "UPDATE datarocket_contactos SET
                    tipo          = :tipo,
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
                    suscripciones = :suscripciones,
                    registrado    = :registrado,
                    completado    = :completado,
                    error         = :error,
                    estado        = :estado,
                    verificacion  = :verificacion
                WHERE id = :id";
        $stmt = $pdo->prepare($sql);
        $stmt->execute([
            ':tipo'          => $p['tipo'],
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
            ':suscripciones' => $p['suscripciones'],
            ':registrado'    => $p['registrado'],
            ':completado'    => $p['completado'],
            ':error'         => $p['error'],
            ':estado'        => $p['estado'],
            ':verificacion'  => $p['verificacion'],
            ':id'            => $id,
        ]);
        if ($listaIds    !== null) drCtSyncListas($pdo, $id, $listaIds);
        if ($etiquetaIds !== null) drCtSyncEtiquetas($pdo, $id, $etiquetaIds);
        $pdo->commit();
        jsonOk(['id' => $id]);
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }
}

function handleDelete(PDO $pdo, int $id): void {
    // Las filas de `datarocket_contactos_listas` y `datarocket_contactos_
    // etiquetas` se borran solas por el ON DELETE CASCADE de sus FKs.
    $stmt = $pdo->prepare('DELETE FROM datarocket_contactos WHERE id = :id');
    $stmt->execute([':id' => $id]);
    if ($stmt->rowCount() === 0) jsonError('Contacto no encontrado', 404);
    jsonOk(['id' => $id]);
}

// ---------------------------------------------------------------------------
// Sincronizacion de suscripciones a listas (tabla puente)
// ---------------------------------------------------------------------------

function drCtSanitizeListaIds(mixed $raw): array {
    if (!is_array($raw)) return [];
    $out = [];
    foreach ($raw as $v) {
        $n = (int)$v;
        if ($n > 0) $out[$n] = true;
    }
    return array_keys($out);
}

// Full replace en `datarocket_contactos_listas` para `$contactoId`. Los ids
// inexistentes en `datarocket_listas` se descartan (defensa en profundidad)
// antes del INSERT IGNORE para no violar la FK.
function drCtSyncListas(PDO $pdo, int $contactoId, array $listaIds): void {
    $del = $pdo->prepare('DELETE FROM datarocket_contactos_listas WHERE contacto_id = :cid');
    $del->execute([':cid' => $contactoId]);
    if (!$listaIds) return;
    $ph  = implode(',', array_fill(0, count($listaIds), '?'));
    $val = $pdo->prepare("SELECT id FROM datarocket_listas WHERE id IN ({$ph})");
    $val->execute($listaIds);
    $validIds = array_map('intval', array_column($val->fetchAll(), 'id'));
    if (!$validIds) return;
    $ins = $pdo->prepare('INSERT IGNORE INTO datarocket_contactos_listas
                          (contacto_id, lista_id) VALUES (:cid, :lid)');
    foreach ($validIds as $lid) {
        $ins->execute([':cid' => $contactoId, ':lid' => $lid]);
    }
}

// ---------------------------------------------------------------------------
// Sincronizacion de etiquetas asignadas (tabla puente)
// ---------------------------------------------------------------------------

function drCtSanitizeEtiquetaIds(mixed $raw): array {
    if (!is_array($raw)) return [];
    $out = [];
    foreach ($raw as $v) {
        $n = (int)$v;
        if ($n > 0) $out[$n] = true;
    }
    return array_keys($out);
}

// Full replace en `datarocket_contactos_etiquetas` para `$contactoId`. Mismo
// patron que `drCtSyncListas`.
function drCtSyncEtiquetas(PDO $pdo, int $contactoId, array $etiquetaIds): void {
    $del = $pdo->prepare('DELETE FROM datarocket_contactos_etiquetas WHERE contacto_id = :cid');
    $del->execute([':cid' => $contactoId]);
    if (!$etiquetaIds) return;
    $ph  = implode(',', array_fill(0, count($etiquetaIds), '?'));
    $val = $pdo->prepare("SELECT id FROM datarocket_etiquetas WHERE id IN ({$ph})");
    $val->execute($etiquetaIds);
    $validIds = array_map('intval', array_column($val->fetchAll(), 'id'));
    if (!$validIds) return;
    $ins = $pdo->prepare('INSERT IGNORE INTO datarocket_contactos_etiquetas
                          (contacto_id, etiqueta_id) VALUES (:cid, :eid)');
    foreach ($validIds as $eid) {
        $ins->execute([':cid' => $contactoId, ':eid' => $eid]);
    }
}
