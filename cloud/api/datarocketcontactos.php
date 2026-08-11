<?php
// api/datarocketcontactos.php
// ABM de contactos Datarocket. Lee/escribe sobre la tabla `datarocket_contactos`
// definida en db/schema.sql.
//   GET    api/datarocketcontactos.php          -> listado con filtros (query string)
//   GET    api/datarocketcontactos.php?id=N     -> registro individual
//   POST   api/datarocketcontactos.php          -> alta (JSON body)
//   PUT    api/datarocketcontactos.php?id=N     -> modificacion (JSON body)
//   DELETE api/datarocketcontactos.php?id=N     -> baja
// Respuesta siempre {ok: true, data: ...} u {ok: false, error: '...'} (STACK.md sec. 10).

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/lib/auth_check.php';

const DR_CT_COLS = "id, uuid, tipo, origen, nombre, empresa, rubro, actividad, cargo,
                    persona, genero, nacimiento, dni, domicilio, ciudad, ubicacion,
                    localidad, provincia, pais, telefono, celular, whatsapp, correo,
                    web, facebook, instagram, tiktok, comentarios, suscripciones,
                    registrado, completado, error, estado, verificacion";

// Valores validos para `datarocket_contactos.tipo`. Filas historicas quedan
// en NULL hasta ser editadas (el ABM las obliga a elegir tipo al guardar).
const DR_CT_TIPOS_VALIDOS = ['persona', 'empresa'];

header('Content-Type: application/json; charset=utf-8');

try {
    requirePermCrud('datarocket.contactos');
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
    $codigo       = isset($q['codigo'])      && $q['codigo']      !== '' ? (int)$q['codigo']      : null;
    $listaId      = isset($q['lista_id'])    && $q['lista_id']    !== '' ? (int)$q['lista_id']    : null;
    $etiquetaId   = isset($q['etiqueta_id']) && $q['etiqueta_id'] !== '' ? (int)$q['etiqueta_id'] : null;
    $tipo         = trim((string)($q['tipo']         ?? ''));
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

    if ($codigo       !== null) { $where[] = 'id = :codigo';                     $params[':codigo']       = $codigo; }
    if ($listaId      !== null) {
        // Suscripcion a una lista de distribucion — la relacion vive en
        // datarocket_contactos_listas (PK compuesta contacto_id + lista_id,
        // FKs con CASCADE). EXISTS evita duplicados y no obliga a aliasar la
        // tabla principal.
        $where[] = 'EXISTS (SELECT 1 FROM datarocket_contactos_listas dcl
                            WHERE dcl.contacto_id = datarocket_contactos.id
                              AND dcl.lista_id    = :lista_id)';
        $params[':lista_id'] = $listaId;
    }
    if ($etiquetaId   !== null) {
        // Etiqueta asignada al contacto. Relacion via tabla puente
        // `datarocket_contactos_etiquetas` (PK compuesta contacto_id +
        // etiqueta_id, FKs con CASCADE). Mismo patron que `lista_id` arriba.
        $where[] = 'EXISTS (SELECT 1 FROM datarocket_contactos_etiquetas dce
                            WHERE dce.contacto_id = datarocket_contactos.id
                              AND dce.etiqueta_id = :etiqueta_id)';
        $params[':etiqueta_id'] = $etiquetaId;
    }
    if ($tipo === '_null') {
        // Centinela para "sin tipo asignado" — usado por el filtro del ABM
        // para listar contactos que todavia no fueron marcados como persona
        // o empresa (parte del proceso manual de completar tipos).
        $where[] = 'tipo IS NULL';
    } elseif ($tipo !== ''
        && in_array($tipo, DR_CT_TIPOS_VALIDOS, true)) {
        // Filtro por tipo. Se valida contra los tipos permitidos para no
        // exponer LIKE/patterns arbitrarios via query string; valores fuera
        // del whitelist se descartan silenciosamente (equivalen a "sin
        // filtro").
        $where[] = 'tipo = :tipo';
        $params[':tipo'] = $tipo;
    }
    if ($estado       !== '')   { $where[] = 'estado = :estado';                 $params[':estado']       = $estado; }
    if ($verificacion !== '')   { $where[] = 'verificacion = :verificacion';     $params[':verificacion'] = $verificacion; }
    if ($genero       !== '')   { $where[] = 'genero = :genero';                 $params[':genero']       = $genero; }
    if ($origen       !== '')   { $where[] = 'origen = :origen';                 $params[':origen']       = $origen; }
    if ($pais         !== '')   { $where[] = 'pais = :pais';                     $params[':pais']         = $pais; }
    if ($provincia    !== '')   { $where[] = 'provincia = :provincia';           $params[':provincia']    = $provincia; }
    if ($correo       !== '')   { $where[] = 'correo LIKE :correo';              $params[':correo']       = '%' . $correo . '%'; }
    if ($celular      !== '')   { $where[] = 'celular LIKE :celular';            $params[':celular']      = '%' . $celular . '%'; }
    if ($desde        !== '')   { $where[] = 'registrado >= :desde';             $params[':desde']        = $desde . ' 00:00:00'; }
    if ($hasta        !== '')   { $where[] = 'registrado <= :hasta';             $params[':hasta']        = $hasta . ' 23:59:59'; }

    if ($search !== '') {
        $where[] = '(nombre LIKE :s1 OR empresa LIKE :s2 OR correo LIKE :s3
                     OR telefono LIKE :s4 OR celular LIKE :s5 OR whatsapp LIKE :s6
                     OR dni LIKE :s7 OR uuid LIKE :s8)';
        $like = "%{$search}%";
        $params[':s1'] = $like;
        $params[':s2'] = $like;
        $params[':s3'] = $like;
        $params[':s4'] = $like;
        $params[':s5'] = $like;
        $params[':s6'] = $like;
        $params[':s7'] = $like;
        $params[':s8'] = $like;
    }

    $sqlWhere = $where ? ('WHERE ' . implode(' AND ', $where)) : '';

    // Stats globales (ignoran filtros — son indicadores del recurso).
    $stats = $pdo->query("
        SELECT
            COUNT(*)                                                                   AS total,
            SUM(CASE WHEN verificacion IS NOT NULL AND verificacion <> '' THEN 1 ELSE 0 END) AS verificados,
            SUM(CASE WHEN error IS NOT NULL AND error <> '' THEN 1 ELSE 0 END)         AS con_error
        FROM datarocket_contactos
    ")->fetch();

    // Total de contactos que matchean los filtros actuales, sin aplicar LIMIT
    // — sirve para la tarjeta "Total" del listado: dice cuantos hay realmente
    // que cumplen los filtros aunque en pantalla se vean solo `limite` filas.
    $stmtFiltrado = $pdo->prepare("SELECT COUNT(*) FROM datarocket_contactos {$sqlWhere}");
    $stmtFiltrado->execute($params);
    $filtrado = (int)$stmtFiltrado->fetchColumn();

    $sql = "
        SELECT " . DR_CT_COLS . "
        FROM datarocket_contactos
        {$sqlWhere}
        ORDER BY {$orderBy} {$dirSql}
        LIMIT {$limite}
    ";
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $rows = $stmt->fetchAll();

    // Anexa `lista_ids` (int[]) y `etiqueta_ids` (int[]) a cada contacto con
    // dos queries GROUP_CONCAT batch a las respectivas tablas puente — evita
    // N+1. Fuente de verdad de las relaciones desde las migraciones
    // 20260811_1400 (listas) y 20260811_1600 (etiquetas).
    attachListaIds($pdo, $rows);
    attachEtiquetaIds($pdo, $rows);

    jsonOk([
        'stats' => [
            'total'       => (int)($stats['total']       ?? 0),
            'verificados' => (int)($stats['verificados'] ?? 0),
            'con_error'   => (int)($stats['con_error']   ?? 0),
            'filtrado'    => $filtrado,
        ],
        'items' => $rows,
    ]);
}

function handleGetOne(PDO $pdo, int $id): void {
    $stmt = $pdo->prepare("SELECT " . DR_CT_COLS . " FROM datarocket_contactos WHERE id = :id");
    $stmt->execute([':id' => $id]);
    $row = $stmt->fetch();
    if (!$row) jsonError('Contacto no encontrado', 404);

    // `lista_ids` = ids de listas suscriptas; `lista_nombres` = nombres en el
    // mismo orden — el modal de detalle los muestra sin tener que golpear un
    // segundo endpoint. Idem `etiqueta_ids`/`etiqueta_nombres`.
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

// Anexa la columna virtual `lista_ids` (int[]) a un array de contactos, con
// una unica query GROUP_CONCAT contra la tabla puente. Compatible MySQL 8 /
// MariaDB 10.11 (ambas soportan GROUP_CONCAT sin limite razonable).
function attachListaIds(PDO $pdo, array &$rows): void {
    if (!$rows) return;
    $ids = array_map(fn($r) => (int)$r['id'], $rows);
    $in  = implode(',', $ids); // ids ya castedos a int — safe para el SQL
    // Devolvemos ids Y nombres para que el listado pueda pintar pills sin un
    // fetch extra del catalogo. Separador `||~||` — literal imprimible que
    // GROUP_CONCAT acepta (a diferencia de CHAR(31)/UNHEX que MySQL rechaza
    // en SEPARATOR) y que no puede aparecer de forma natural en un nombre.
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

// Idem `attachListaIds` pero contra `datarocket_contactos_etiquetas`. Se
// mantiene como funcion aparte (en vez de generalizar) para que la SQL sea
// literal y facil de leer/optimizar por indice.
function attachEtiquetaIds(PDO $pdo, array &$rows): void {
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

// Normaliza telefonos: elimina cualquier caracter que no sea digito
// (espacios, guiones, parentesis, '+', etc.). Si queda vacio -> NULL.
// Aplica a telefono, celular y whatsapp — misma regla que el endpoint v4
// (api/v4/datarocket/contactos.php) para que ambos escriban comparable.
function digitsOnly(mixed $v): ?string {
    $s = nullableStr($v);
    if ($s === null) return null;
    $s = preg_replace('/\D+/', '', $s);
    return $s === '' ? null : substr($s, 0, 255);
}

// Genera un UUID v4 RFC 4122 (36 chars con guiones) alineado con el formato
// que ya persiste `datarocket_contactos.uuid` (regenerado por la migracion
// 20260727_2000). Antes usabamos bin2hex(random_bytes(16)) que producia 32
// chars hex sin guiones — no era UUID estandar.
function uuidV4(): string {
    $d = random_bytes(16);
    $d[6] = chr((ord($d[6]) & 0x0f) | 0x40);
    $d[8] = chr((ord($d[8]) & 0x3f) | 0x80);
    return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($d), 4));
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
        'tipo'          => nullableStr($in['tipo']          ?? null, 20),
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
        'telefono'      => digitsOnly($in['telefono']      ?? null),
        'celular'       => digitsOnly($in['celular']       ?? null),
        'whatsapp'      => digitsOnly($in['whatsapp']      ?? null),
        'correo'        => nullableStr($in['correo']        ?? null, 255),
        'web'           => nullableStr($in['web']           ?? null, 255),
        'facebook'      => nullableStr($in['facebook']      ?? null, 255),
        'instagram'     => nullableStr($in['instagram']     ?? null, 255),
        'tiktok'        => nullableStr($in['tiktok']        ?? null, 255),
        'comentarios'   => nullableStr($in['comentarios']   ?? null, 500),
        'suscripciones' => nullableInt($in['suscripciones'] ?? null),
        'registrado'    => nullableDateTime($in['registrado'] ?? null),
        'completado'    => nullableDateTime($in['completado'] ?? null),
        'error'         => nullableStr($in['error']         ?? null, 255),
        'estado'        => nullableStr($in['estado']        ?? null, 1),
        'verificacion'  => nullableStr($in['verificacion']  ?? null, 1),
    ];
}

function handleCreate(PDO $pdo, array $in): void {
    $p = sanitizePayload($in);
    // `tipo` obligatorio en alta — el ABM (y cualquier cliente del endpoint)
    // debe elegir persona o empresa. Ver DR_CT_TIPOS_VALIDOS.
    if (!in_array($p['tipo'], DR_CT_TIPOS_VALIDOS, true)) {
        jsonError('El tipo es obligatorio (persona o empresa).', 400);
    }
    $p['uuid'] = nullableStr($in['uuid'] ?? null, 255) ?? uuidV4();
    if ($p['registrado'] === null) {
        $p['registrado'] = (new DateTime('now', new DateTimeZone('America/Argentina/Buenos_Aires')))
                           ->format('Y-m-d H:i:s');
    }
    $listaIds    = sanitizeListaIds($in['lista_ids']    ?? null);
    $etiquetaIds = sanitizeEtiquetaIds($in['etiqueta_ids'] ?? null);

    $pdo->beginTransaction();
    try {
        $sql = "
            INSERT INTO datarocket_contactos
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
                 :registrado, :completado, :error, :estado, :verificacion)
        ";
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
        syncListas($pdo, $newId, $listaIds);
        syncEtiquetas($pdo, $newId, $etiquetaIds);
        $pdo->commit();
        jsonOk(['id' => $newId], 201);
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }
}

function handleUpdate(PDO $pdo, int $id, array $in): void {
    $exists = $pdo->prepare('SELECT id FROM datarocket_contactos WHERE id = :id');
    $exists->execute([':id' => $id]);
    if (!$exists->fetch()) jsonError('Contacto no encontrado', 404);

    $p = sanitizePayload($in);
    // `tipo` obligatorio en edicion — filas historicas con `tipo` NULL deben
    // recibir un valor la primera vez que las editen (parte del proceso
    // manual de asignar tipo a los contactos existentes).
    if (!in_array($p['tipo'], DR_CT_TIPOS_VALIDOS, true)) {
        jsonError('El tipo es obligatorio (persona o empresa).', 400);
    }
    // `lista_ids` / `etiqueta_ids` son opcionales en el PUT — si el cliente
    // no los envia (o envia null), la relacion actual NO se toca. Solo
    // cuando el cliente manda explicitamente un array (incluso vacio para
    // "desuscribir/desasignar de todo") se sincroniza la puente respectiva.
    $listaIds    = array_key_exists('lista_ids', $in)
        ? sanitizeListaIds($in['lista_ids'])
        : null;
    $etiquetaIds = array_key_exists('etiqueta_ids', $in)
        ? sanitizeEtiquetaIds($in['etiqueta_ids'])
        : null;

    $pdo->beginTransaction();
    try {
        $sql = "
            UPDATE datarocket_contactos SET
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
            WHERE id = :id
        ";
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
        if ($listaIds    !== null) syncListas($pdo, $id, $listaIds);
        if ($etiquetaIds !== null) syncEtiquetas($pdo, $id, $etiquetaIds);
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

// ----------------------------------------------------------------------------
// Sincronizacion de suscripciones a listas (tabla puente)
// ----------------------------------------------------------------------------

// Normaliza el payload `lista_ids` a int[] deduplicado sin ceros. Acepta
// array u objeto vacio; cualquier otra cosa -> [].
function sanitizeListaIds(mixed $raw): array {
    if (!is_array($raw)) return [];
    $out = [];
    foreach ($raw as $v) {
        $n = (int)$v;
        if ($n > 0) $out[$n] = true;
    }
    return array_keys($out);
}

// Deja la puente `datarocket_contactos_listas` con exactamente `$listaIds`
// para `$contactoId`. Estrategia "full replace" (DELETE + INSERT IGNORE) —
// es suficiente porque el volumen por contacto es chico (decenas) y evita
// tener que diffear. Los ids inexistentes en `datarocket_listas` se
// descartan via INNER JOIN antes de insertar para no violar la FK.
function syncListas(PDO $pdo, int $contactoId, array $listaIds): void {
    $del = $pdo->prepare('DELETE FROM datarocket_contactos_listas WHERE contacto_id = :cid');
    $del->execute([':cid' => $contactoId]);
    if (!$listaIds) return;
    // Validamos los ids contra `datarocket_listas` para no depender de que la
    // capa cliente haya elegido de la lista real (defensa en profundidad).
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

// ----------------------------------------------------------------------------
// Sincronizacion de etiquetas asignadas (tabla puente)
// ----------------------------------------------------------------------------

// Mismo contrato que `sanitizeListaIds` pero contra `etiqueta_ids`.
function sanitizeEtiquetaIds(mixed $raw): array {
    if (!is_array($raw)) return [];
    $out = [];
    foreach ($raw as $v) {
        $n = (int)$v;
        if ($n > 0) $out[$n] = true;
    }
    return array_keys($out);
}

// Full replace en `datarocket_contactos_etiquetas` para `$contactoId`. Mismo
// patron que `syncListas`: valida los ids contra `datarocket_etiquetas` para
// descartar los inexistentes antes del INSERT IGNORE y no romper la FK.
function syncEtiquetas(PDO $pdo, int $contactoId, array $etiquetaIds): void {
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
