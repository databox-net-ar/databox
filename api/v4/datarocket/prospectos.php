<?php
// api/v4/datarocket/prospectos.php
// Microservicio CRUD del CRM Datarocket sobre la tabla `datarocket_prospectos`.
//
//   GET    /v4/datarocket/prospectos           -> listado con filtros (query string)
//   GET    /v4/datarocket/prospectos?id=N      -> registro individual
//   POST   /v4/datarocket/prospectos           (JSON body) -> alta, devuelve {id, uuid, registrado}
//   PUT    /v4/datarocket/prospectos?id=N      (JSON body) -> modificacion, devuelve {id}
//   DELETE /v4/datarocket/prospectos?id=N      -> baja definitiva, devuelve {id}
//
// Auth: Bearer con apikey de la tabla `aplicaciones` (mismo esquema que el resto
// del stack — ver cloud/api/lib/apikey_auth.php). Cualquier apikey habilitada pasa.
//
// Tabla destino: `datarocket_prospectos` (schema en db/schema.sql).
//
// El ABM interno equivalente (usado por el panel cloud) es
// cloud/api/datarocketprospectos.php — mismas columnas, mismos filtros, misma
// forma de sanitizacion; la diferencia es la capa de auth (permisos de sesion
// vs. Bearer estatico) y que el listado v4 no publica el bloque `stats`.
//
// Normalizacion: `telefono` / `celular` / `whatsapp` se guardan como 10
// digitos argentinos, `correo` en minuscula validada y `web` como host + path
// sin esquema (`bna.com.ar/sucursales`) — reglas en
// cloud/api/lib/prospectos_normalizar.php. Un telefono que no se pueda llevar a
// 10 digitos se guarda igual, en digitos crudos (hay prospectos del exterior en
// la tabla); un correo del que no se pueda extraer una direccion valida se
// rechaza con 400; una `web` que no sea una URL va a NULL, salvo que sea un
// correo y `correo` este vacio (ahi se rescata). Ojo los consumidores: `web`
// ya no incluye `http://` — hay que anteponerlo al armar el link.

header('Content-Type: application/json; charset=utf-8');

require_once dirname(__DIR__, 3) . '/env.php';
require_once dirname(__DIR__, 3) . '/cloud/api/db.php';
require_once dirname(__DIR__, 3) . '/cloud/api/lib/prospectos_normalizar.php';

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

// `localidad_id` / `provincia_id` / `pais_id` son FK a los catalogos desde la
// migracion 20260815_1000; antes eran VARCHAR con el mismo ID adentro. Se
// aliasan a los nombres viejos para NO romper el contrato JSON publico de v4:
// los consumidores externos siguen recibiendo `localidad` / `provincia` /
// `pais` con exactamente el mismo valor que antes. La respuesta suma ademas
// las claves `*_id` para los clientes nuevos.
const DR_CT_COLS = "id, uuid, tipo, nombre,
                    empresa_nombre, empresa_rubro, empresa_actividad, empresa_cargo,
                    persona_nombre, persona_genero, persona_nacimiento, persona_dni,
                    domicilio, ciudad, ubicacion,
                    localidad_id, provincia_id, pais_id,
                    localidad_id AS localidad, provincia_id AS provincia, pais_id AS pais,
                    telefono, celular, whatsapp, correo,
                    web, facebook, instagram, tiktok, comentarios, registrado";

// Valores validos para `datarocket_prospectos.tipo`. Se rechazan alta y
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
    $genero       = trim((string)($q['persona_genero']       ?? ''));
    // Se aceptan tanto `pais_id` (nuevo) como `pais` (legacy) — el valor era y
    // sigue siendo el ID del catalogo.
    $paisId       = drPrFiltroId($q['pais_id']      ?? $q['pais']      ?? null);
    $provinciaId  = drPrFiltroId($q['provincia_id'] ?? $q['provincia'] ?? null);
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

    // `pais` / `provincia` siguen aceptandose como criterio de orden (contrato
    // publico) pero se traducen a la columna renombrada.
    if ($orderBy === 'pais')      $orderBy = 'pais_id';
    if ($orderBy === 'provincia') $orderBy = 'provincia_id';
    $allowedOrder = ['id', 'nombre', 'empresa_nombre', 'correo', 'registrado',
                     'pais_id', 'provincia_id'];
    if (!in_array($orderBy, $allowedOrder, true)) $orderBy = 'id';
    $dirSql = $dir === 'asc' ? 'ASC' : 'DESC';

    $where  = [];
    $params = [];

    if ($codigo       !== null) { $where[] = 'id = :codigo';                 $params[':codigo']       = $codigo; }
    if ($genero       !== '')   { $where[] = 'persona_genero = :persona_genero'; $params[':persona_genero'] = $genero; }
    if ($paisId      !== null)  { $where[] = 'pais_id = :pais_id';           $params[':pais_id']      = $paisId; }
    if ($provinciaId !== null)  { $where[] = 'provincia_id = :provincia_id'; $params[':provincia_id'] = $provinciaId; }
    if ($correo       !== '')   { $where[] = 'correo LIKE :correo';          $params[':correo']       = '%' . $correo . '%'; }
    if ($celular      !== '')   { $where[] = 'celular LIKE :celular';        $params[':celular']      = '%' . $celular . '%'; }
    if ($desde        !== '')   { $where[] = 'registrado >= :desde';         $params[':desde']        = $desde . ' 00:00:00'; }
    if ($hasta        !== '')   { $where[] = 'registrado <= :hasta';         $params[':hasta']        = $hasta . ' 23:59:59'; }

    if ($search !== '') {
        $where[] = '(nombre LIKE :s1 OR empresa_nombre LIKE :s2 OR correo LIKE :s3
                     OR telefono LIKE :s4 OR celular LIKE :s5 OR whatsapp LIKE :s6
                     OR persona_dni LIKE :s7 OR uuid LIKE :s8)';
        $like = "%{$search}%";
        $params[':s1'] = $like; $params[':s2'] = $like; $params[':s3'] = $like;
        $params[':s4'] = $like; $params[':s5'] = $like; $params[':s6'] = $like;
        $params[':s7'] = $like; $params[':s8'] = $like;
    }

    $sqlWhere = $where ? ('WHERE ' . implode(' AND ', $where)) : '';

    $sql = "SELECT " . DR_CT_COLS . "
              FROM datarocket_prospectos
              {$sqlWhere}
              ORDER BY {$orderBy} {$dirSql}
              LIMIT {$limite}";
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $rows = $stmt->fetchAll();

    // Anexa `lista_ids` y `etiqueta_ids` (int[]) a cada prospecto — batch
    // queries contra las puentes `datarocket_prospectos_listas` (20260811_1400)
    // y `datarocket_prospectos_etiquetas` (20260811_1600). Sin N+1.
    drPrAttachListaIds($pdo, $rows);
    drPrAttachEtiquetaIds($pdo, $rows);

    jsonOk([
        'total' => count($rows),
        'items' => $rows,
    ]);
}

function handleGetOne(PDO $pdo, int $id): void {
    $stmt = $pdo->prepare("SELECT " . DR_CT_COLS . " FROM datarocket_prospectos WHERE id = :id");
    $stmt->execute([':id' => $id]);
    $row = $stmt->fetch();
    if (!$row) jsonError('Prospecto no encontrado', 404);

    $lists = $pdo->prepare("
        SELECT dl.id, dl.nombre
          FROM datarocket_prospectos_listas dcl
          JOIN datarocket_listas dl ON dl.id = dcl.lista_id
         WHERE dcl.prospecto_id = :id
      ORDER BY dl.nombre
    ");
    $lists->execute([':id' => $id]);
    $rowsLi = $lists->fetchAll();
    $row['lista_ids']     = array_map(fn($r) => (int)$r['id'],     $rowsLi);
    $row['lista_nombres'] = array_map(fn($r) => (string)$r['nombre'], $rowsLi);

    $etiqs = $pdo->prepare("
        SELECT de.id, de.nombre
          FROM datarocket_prospectos_etiquetas dce
          JOIN datarocket_etiquetas de ON de.id = dce.etiqueta_id
         WHERE dce.prospecto_id = :id
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
function drPrAttachListaIds(PDO $pdo, array &$rows): void {
    if (!$rows) return;
    $ids = array_map(fn($r) => (int)$r['id'], $rows);
    $in  = implode(',', $ids);
    // Nombres viajan junto a los ids para que los clientes del microservicio
    // puedan pintar pills sin un fetch extra del catalogo. Separador
    // `||~||` — literal imprimible que GROUP_CONCAT acepta y que no puede
    // aparecer de forma natural en un nombre.
    $mapIds = $mapNombres = [];
    foreach ($pdo->query("
        SELECT dcl.prospecto_id,
               GROUP_CONCAT(dcl.lista_id ORDER BY dl.nombre)                       AS lista_ids,
               GROUP_CONCAT(dl.nombre    ORDER BY dl.nombre SEPARATOR '||~||')    AS lista_nombres
          FROM datarocket_prospectos_listas dcl
          JOIN datarocket_listas dl ON dl.id = dcl.lista_id
         WHERE dcl.prospecto_id IN ({$in})
      GROUP BY dcl.prospecto_id
    ") as $r) {
        $cid = (int)$r['prospecto_id'];
        $mapIds[$cid]     = array_map('intval', explode(',',     (string)$r['lista_ids']));
        $mapNombres[$cid] = explode('||~||', (string)$r['lista_nombres']);
    }
    foreach ($rows as &$row) {
        $cid = (int)$row['id'];
        $row['lista_ids']     = $mapIds[$cid]     ?? [];
        $row['lista_nombres'] = $mapNombres[$cid] ?? [];
    }
}

// Idem para `etiqueta_ids` contra `datarocket_prospectos_etiquetas`.
function drPrAttachEtiquetaIds(PDO $pdo, array &$rows): void {
    if (!$rows) return;
    $ids = array_map(fn($r) => (int)$r['id'], $rows);
    $in  = implode(',', $ids);
    $mapIds = $mapNombres = [];
    foreach ($pdo->query("
        SELECT dce.prospecto_id,
               GROUP_CONCAT(dce.etiqueta_id ORDER BY de.nombre)                       AS etiqueta_ids,
               GROUP_CONCAT(de.nombre       ORDER BY de.nombre SEPARATOR '||~||')    AS etiqueta_nombres
          FROM datarocket_prospectos_etiquetas dce
          JOIN datarocket_etiquetas de ON de.id = dce.etiqueta_id
         WHERE dce.prospecto_id IN ({$in})
      GROUP BY dce.prospecto_id
    ") as $r) {
        $cid = (int)$r['prospecto_id'];
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

function drPrNullableStr(mixed $v, ?int $max = null): ?string {
    if ($v === null) return null;
    $s = trim((string)$v);
    if ($s === '') return null;
    if ($max !== null) $s = substr($s, 0, $max);
    return $s;
}

function drPrNullableInt(mixed $v): ?int {
    if ($v === null || $v === '') return null;
    return (int)$v;
}

// Normaliza un ID de catalogo que llega por query string: vacio / no numerico
// / <= 0 -> null (equivale a "sin filtro", no a filtrar por 0).
function drPrFiltroId(mixed $v): ?int {
    if ($v === null) return null;
    $s = trim((string)$v);
    if ($s === '' || !ctype_digit($s)) return null;
    $n = (int)$s;
    return $n > 0 ? $n : null;
}

// Rechaza con 400 los ids de ubicacion que no existan en su catalogo. Sin esto
// la violacion de FK sube como excepcion PDO y el cliente recibe un 500 con el
// mensaje crudo de InnoDB.
function drPrAssertUbicacion(PDO $pdo, array $p): void {
    $checks = [
        ['pais_id',      'paises',      'El país indicado no existe.'],
        ['provincia_id', 'provincias',  'La provincia indicada no existe.'],
        ['localidad_id', 'localidades', 'La localidad indicada no existe.'],
    ];
    foreach ($checks as [$campo, $tabla, $msg]) {
        if ($p[$campo] === null) continue;
        $stmt = $pdo->prepare("SELECT 1 FROM {$tabla} WHERE id = :id");
        $stmt->execute([':id' => $p[$campo]]);
        if (!$stmt->fetchColumn()) jsonError($msg, 400);
    }
}

// Rechaza con 400 un `correo` que venga con algo escrito pero del que no se
// pueda extraer ninguna direccion valida. Se chequea sobre el payload crudo
// porque prospectoNormalizarCorreo() devuelve null tanto para "campo vacio"
// como para "campo con basura", y solo el segundo es un error. Un cliente que
// manda basura merece enterarse, no que se la descartemos en silencio.
// Mismo criterio en el ABM cloud (cloud/api/datarocketprospectos.php).
function drPrAssertCorreo(array $in): void {
    if (!array_key_exists('correo', $in)) return;
    $raw = trim((string)($in['correo'] ?? ''));
    if ($raw === '') return;
    if (prospectoNormalizarCorreo($raw) === null) {
        jsonError('El correo no es válido.', 400);
    }
}

// Invariante de identidad: el campo de nombre que corresponde al `tipo` tiene
// que venir cargado, porque es el que alimenta a `nombre` (ver drPrDerivarNombre).
// Un prospecto persona sin `persona_nombre` no tiene de donde sacar el nombre
// con el que se lista, se busca y se saluda en una plantilla.
//
// Solo se exige el campo del tipo. El del OTRO lado sigue siendo opcional y es
// legitimo tenerlo cargado: en un prospecto persona `empresa_nombre` es donde
// trabaja, y en un prospecto empresa `persona_nombre` es quien atiende.
//
// Este endpoint importa lotes, y fue justamente un importador el que dejo 989
// filas con `tipo='persona'` y `persona_nombre` NULL (ver la migracion
// 20260817_2100). Rechazar el alta aca es lo que evita que se repita.
// Mismo criterio en el ABM cloud (cloud/api/datarocketprospectos.php).
function drPrAssertIdentidad(array $p): void {
    if ($p['tipo'] === 'persona' && (string)($p['persona_nombre'] ?? '') === '') {
        jsonError('El nombre de la persona es obligatorio para un prospecto de tipo persona.', 400);
    }
    if ($p['tipo'] === 'empresa' && (string)($p['empresa_nombre'] ?? '') === '') {
        jsonError('El nombre de la empresa es obligatorio para un prospecto de tipo empresa.', 400);
    }
}

// `nombre` es DERIVADO, no un campo que el cliente elija: sale del campo de
// identidad que corresponde al `tipo`. Se pisa lo que haya mandado el cliente
// a proposito — asi la columna no puede volver a divergir de `persona_nombre`
// / `empresa_nombre`, que es justamente como se ensucio la tabla antes de la
// backfill 20260817_2100.
//
// OJO si venis de un importador que trae el formato compuesto de
// datamarketcontactos (`EMPRESA - Persona` en un solo campo): partilo ANTES de
// postear, mandando `empresa_nombre` y `persona_nombre` por separado. Si mandas
// el compuesto entero en uno de los dos, se guarda entero.
//
// Se asume drPrAssertIdentidad() ya corrido: el campo de origen no es vacio.
function drPrDerivarNombre(array $p): string {
    return $p['tipo'] === 'persona'
        ? (string)$p['persona_nombre']
        : (string)$p['empresa_nombre'];
}

// Genera un UUID v4 RFC 4122 (36 chars con guiones) alineado con el formato
// que ya persiste `datarocket_prospectos.uuid` (regenerado por la migracion
// 20260727_2000). Antes usabamos bin2hex(random_bytes(16)) que producia 32
// chars hex sin guiones — no era UUID estandar.
function drPrUuidV4(): string {
    $d = random_bytes(16);
    $d[6] = chr((ord($d[6]) & 0x0f) | 0x40);
    $d[8] = chr((ord($d[8]) & 0x3f) | 0x80);
    return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($d), 4));
}

// Acepta 'YYYY-MM-DDTHH:MM', 'YYYY-MM-DD HH:MM' y 'YYYY-MM-DD HH:MM:SS'.
// Cualquier otro formato devuelve NULL (que dispara el default en handleCreate).
function drPrNullableDateTime(mixed $v): ?string {
    $s = drPrNullableStr($v);
    if ($s === null) return null;
    $s = str_replace('T', ' ', $s);
    if (preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}$/', $s)) $s .= ':00';
    if (!preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $s)) return null;
    return $s;
}

function drPrSanitize(array $in): array {
    $p = [
        'tipo'               => drPrNullableStr($in['tipo']                ?? null, 20),
        'nombre'             => drPrNullableStr($in['nombre']              ?? null, 255),
        'empresa_nombre'     => drPrNullableStr($in['empresa_nombre']      ?? null, 255),
        'empresa_rubro'      => drPrNullableStr($in['empresa_rubro']       ?? null, 255),
        'empresa_actividad'  => drPrNullableStr($in['empresa_actividad']   ?? null, 255),
        'empresa_cargo'      => drPrNullableStr($in['empresa_cargo']       ?? null, 255),
        'persona_nombre'     => drPrNullableStr($in['persona_nombre']      ?? null, 255),
        'persona_genero'     => drPrNullableStr($in['persona_genero']      ?? null, 1),
        'persona_nacimiento' => drPrNullableStr($in['persona_nacimiento']  ?? null, 255),
        'persona_dni'        => drPrNullableStr($in['persona_dni']         ?? null, 255),
        'domicilio'          => drPrNullableStr($in['domicilio']           ?? null, 255),
        'ciudad'             => drPrNullableStr($in['ciudad']              ?? null, 255),
        'ubicacion'          => drPrNullableStr($in['ubicacion']           ?? null, 255),
        // FK a los catalogos. Se acepta la clave nueva y la legacy (misma
        // semantica: el ID). Un valor no numerico deja de escribirse como texto
        // y pasa a NULL — con la FK puesta no hay otra opcion valida.
        'localidad_id' => drPrNullableInt($in['localidad_id']        ?? $in['localidad'] ?? null),
        'provincia_id' => drPrNullableInt($in['provincia_id']        ?? $in['provincia'] ?? null),
        'pais_id'      => drPrNullableInt($in['pais_id']             ?? $in['pais']      ?? null),
        // Telefonos a 10 digitos argentinos y correo a minuscula validada —
        // reglas en cloud/api/lib/prospectos_normalizar.php, compartidas con el
        // ABM cloud y con la migracion 20260816_1700.
        'telefono' => prospectoNormalizarTelefono($in['telefono'] ?? null),
        'celular'  => prospectoNormalizarTelefono($in['celular']  ?? null),
        'whatsapp' => prospectoNormalizarTelefono($in['whatsapp'] ?? null),
        'correo'   => prospectoNormalizarCorreo($in['correo']     ?? null),
        // `web` se guarda como host + path sin esquema; lo que no es una URL
        // va a NULL. Mismas reglas, mismo lib, migracion 20260816_1800.
        'web'         => prospectoNormalizarWeb($in['web']           ?? null),
        'facebook'    => drPrNullableStr($in['facebook']            ?? null, 255),
        'instagram'   => drPrNullableStr($in['instagram']           ?? null, 255),
        'tiktok'      => drPrNullableStr($in['tiktok']              ?? null, 255),
        'comentarios' => drPrNullableStr($in['comentarios']         ?? null, 500),
        'registrado'  => drPrNullableDateTime($in['registrado']     ?? null),
    ];
    // Un correo cargado por error en `web` se rescata a `correo` cuando ese
    // campo viene vacio; si el prospecto ya trae correo, se descarta con el
    // resto de los no-URL. Mismo criterio en el ABM cloud.
    if ($p['correo'] === null) {
        $p['correo'] = prospectoWebComoCorreo($in['web'] ?? null);
    }
    return $p;
}

function handleCreate(PDO $pdo, array $in): void {
    drPrAssertCorreo($in);
    $p = drPrSanitize($in);
    // `tipo` obligatorio en alta — cualquier cliente del microservicio v4
    // debe indicar persona o empresa. Ver DR_CT_TIPOS_VALIDOS.
    if (!in_array($p['tipo'], DR_CT_TIPOS_VALIDOS, true)) {
        jsonError('El tipo es obligatorio (persona o empresa).', 400);
    }
    drPrAssertIdentidad($p);
    $p['nombre'] = drPrDerivarNombre($p);
    drPrAssertUbicacion($pdo, $p);
    $p['uuid'] = drPrNullableStr($in['uuid'] ?? null, 255) ?? drPrUuidV4();
    if ($p['registrado'] === null) {
        $p['registrado'] = (new DateTime('now', new DateTimeZone('America/Argentina/Buenos_Aires')))
                           ->format('Y-m-d H:i:s');
    }
    $listaIds    = drPrSanitizeListaIds($in['lista_ids']    ?? null);
    $etiquetaIds = drPrSanitizeEtiquetaIds($in['etiqueta_ids'] ?? null);

    $pdo->beginTransaction();
    try {
        $sql = "INSERT INTO datarocket_prospectos
                    (uuid, tipo, nombre,
                     empresa_nombre, empresa_rubro, empresa_actividad, empresa_cargo,
                     persona_nombre, persona_genero, persona_nacimiento, persona_dni,
                     domicilio, ciudad, ubicacion, localidad_id,
                     provincia_id, pais_id, telefono, celular, whatsapp, correo, web, facebook,
                     instagram, tiktok, comentarios, registrado)
                VALUES
                    (:uuid, :tipo, :nombre,
                     :empresa_nombre, :empresa_rubro, :empresa_actividad, :empresa_cargo,
                     :persona_nombre, :persona_genero, :persona_nacimiento, :persona_dni,
                     :domicilio, :ciudad, :ubicacion, :localidad_id,
                     :provincia_id, :pais_id, :telefono, :celular, :whatsapp, :correo, :web, :facebook,
                     :instagram, :tiktok, :comentarios, :registrado)";
        $stmt = $pdo->prepare($sql);
        $stmt->execute([
            ':uuid'               => $p['uuid'],
            ':tipo'               => $p['tipo'],
            ':nombre'             => $p['nombre'],
            ':empresa_nombre'     => $p['empresa_nombre'],
            ':empresa_rubro'      => $p['empresa_rubro'],
            ':empresa_actividad'  => $p['empresa_actividad'],
            ':empresa_cargo'      => $p['empresa_cargo'],
            ':persona_nombre'     => $p['persona_nombre'],
            ':persona_genero'     => $p['persona_genero'],
            ':persona_nacimiento' => $p['persona_nacimiento'],
            ':persona_dni'        => $p['persona_dni'],
            ':domicilio'          => $p['domicilio'],
            ':ciudad'             => $p['ciudad'],
            ':ubicacion'          => $p['ubicacion'],
            ':localidad_id'       => $p['localidad_id'],
            ':provincia_id'       => $p['provincia_id'],
            ':pais_id'            => $p['pais_id'],
            ':telefono'           => $p['telefono'],
            ':celular'            => $p['celular'],
            ':whatsapp'           => $p['whatsapp'],
            ':correo'             => $p['correo'],
            ':web'                => $p['web'],
            ':facebook'           => $p['facebook'],
            ':instagram'          => $p['instagram'],
            ':tiktok'             => $p['tiktok'],
            ':comentarios'        => $p['comentarios'],
            ':registrado'         => $p['registrado'],
        ]);
        $newId = (int)$pdo->lastInsertId();
        drPrSyncListas($pdo, $newId, $listaIds);
        drPrSyncEtiquetas($pdo, $newId, $etiquetaIds);
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
    $exists = $pdo->prepare('SELECT id FROM datarocket_prospectos WHERE id = :id');
    $exists->execute([':id' => $id]);
    if (!$exists->fetch()) jsonError('Prospecto no encontrado', 404);

    drPrAssertCorreo($in);
    $p = drPrSanitize($in);
    // `tipo` obligatorio en edicion — mismas reglas que en el ABM cloud.
    if (!in_array($p['tipo'], DR_CT_TIPOS_VALIDOS, true)) {
        jsonError('El tipo es obligatorio (persona o empresa).', 400);
    }
    drPrAssertIdentidad($p);
    $p['nombre'] = drPrDerivarNombre($p);
    drPrAssertUbicacion($pdo, $p);
    // `lista_ids` / `etiqueta_ids` opcionales en PUT: si no vienen, no se
    // toca la puente. Solo cuando el cliente los manda explicitamente (aun
    // `[]` para desasignar de todo) se sincroniza cada una.
    $listaIds    = array_key_exists('lista_ids', $in)
        ? drPrSanitizeListaIds($in['lista_ids'])
        : null;
    $etiquetaIds = array_key_exists('etiqueta_ids', $in)
        ? drPrSanitizeEtiquetaIds($in['etiqueta_ids'])
        : null;

    $pdo->beginTransaction();
    try {
        $sql = "UPDATE datarocket_prospectos SET
                    tipo               = :tipo,
                    nombre             = :nombre,
                    empresa_nombre     = :empresa_nombre,
                    empresa_rubro      = :empresa_rubro,
                    empresa_actividad  = :empresa_actividad,
                    empresa_cargo      = :empresa_cargo,
                    persona_nombre     = :persona_nombre,
                    persona_genero     = :persona_genero,
                    persona_nacimiento = :persona_nacimiento,
                    persona_dni        = :persona_dni,
                    domicilio          = :domicilio,
                    ciudad             = :ciudad,
                    ubicacion          = :ubicacion,
                    localidad_id       = :localidad_id,
                    provincia_id       = :provincia_id,
                    pais_id            = :pais_id,
                    telefono           = :telefono,
                    celular            = :celular,
                    whatsapp           = :whatsapp,
                    correo             = :correo,
                    web                = :web,
                    facebook           = :facebook,
                    instagram          = :instagram,
                    tiktok             = :tiktok,
                    comentarios        = :comentarios,
                    registrado         = :registrado
                WHERE id = :id";
        $stmt = $pdo->prepare($sql);
        $stmt->execute([
            ':tipo'               => $p['tipo'],
            ':nombre'             => $p['nombre'],
            ':empresa_nombre'     => $p['empresa_nombre'],
            ':empresa_rubro'      => $p['empresa_rubro'],
            ':empresa_actividad'  => $p['empresa_actividad'],
            ':empresa_cargo'      => $p['empresa_cargo'],
            ':persona_nombre'     => $p['persona_nombre'],
            ':persona_genero'     => $p['persona_genero'],
            ':persona_nacimiento' => $p['persona_nacimiento'],
            ':persona_dni'        => $p['persona_dni'],
            ':domicilio'          => $p['domicilio'],
            ':ciudad'             => $p['ciudad'],
            ':ubicacion'          => $p['ubicacion'],
            ':localidad_id'       => $p['localidad_id'],
            ':provincia_id'       => $p['provincia_id'],
            ':pais_id'            => $p['pais_id'],
            ':telefono'           => $p['telefono'],
            ':celular'            => $p['celular'],
            ':whatsapp'           => $p['whatsapp'],
            ':correo'             => $p['correo'],
            ':web'                => $p['web'],
            ':facebook'           => $p['facebook'],
            ':instagram'          => $p['instagram'],
            ':tiktok'             => $p['tiktok'],
            ':comentarios'        => $p['comentarios'],
            ':registrado'         => $p['registrado'],
            ':id'                 => $id,
        ]);
        if ($listaIds    !== null) drPrSyncListas($pdo, $id, $listaIds);
        if ($etiquetaIds !== null) drPrSyncEtiquetas($pdo, $id, $etiquetaIds);
        $pdo->commit();
        jsonOk(['id' => $id]);
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }
}

function handleDelete(PDO $pdo, int $id): void {
    // Las filas de `datarocket_prospectos_listas` y `datarocket_prospectos_
    // etiquetas` se borran solas por el ON DELETE CASCADE de sus FKs.
    $stmt = $pdo->prepare('DELETE FROM datarocket_prospectos WHERE id = :id');
    $stmt->execute([':id' => $id]);
    if ($stmt->rowCount() === 0) jsonError('Prospecto no encontrado', 404);
    jsonOk(['id' => $id]);
}

// ---------------------------------------------------------------------------
// Sincronizacion de suscripciones a listas (tabla puente)
// ---------------------------------------------------------------------------

function drPrSanitizeListaIds(mixed $raw): array {
    if (!is_array($raw)) return [];
    $out = [];
    foreach ($raw as $v) {
        $n = (int)$v;
        if ($n > 0) $out[$n] = true;
    }
    return array_keys($out);
}

// Full replace en `datarocket_prospectos_listas` para `$prospectoId`. Los ids
// inexistentes en `datarocket_listas` se descartan (defensa en profundidad)
// antes del INSERT IGNORE para no violar la FK.
function drPrSyncListas(PDO $pdo, int $prospectoId, array $listaIds): void {
    $del = $pdo->prepare('DELETE FROM datarocket_prospectos_listas WHERE prospecto_id = :cid');
    $del->execute([':cid' => $prospectoId]);
    if (!$listaIds) return;
    $ph  = implode(',', array_fill(0, count($listaIds), '?'));
    $val = $pdo->prepare("SELECT id FROM datarocket_listas WHERE id IN ({$ph})");
    $val->execute($listaIds);
    $validIds = array_map('intval', array_column($val->fetchAll(), 'id'));
    if (!$validIds) return;
    $ins = $pdo->prepare('INSERT IGNORE INTO datarocket_prospectos_listas
                          (prospecto_id, lista_id) VALUES (:cid, :lid)');
    foreach ($validIds as $lid) {
        $ins->execute([':cid' => $prospectoId, ':lid' => $lid]);
    }
}

// ---------------------------------------------------------------------------
// Sincronizacion de etiquetas asignadas (tabla puente)
// ---------------------------------------------------------------------------

function drPrSanitizeEtiquetaIds(mixed $raw): array {
    if (!is_array($raw)) return [];
    $out = [];
    foreach ($raw as $v) {
        $n = (int)$v;
        if ($n > 0) $out[$n] = true;
    }
    return array_keys($out);
}

// Full replace en `datarocket_prospectos_etiquetas` para `$prospectoId`. Mismo
// patron que `drPrSyncListas`.
function drPrSyncEtiquetas(PDO $pdo, int $prospectoId, array $etiquetaIds): void {
    $del = $pdo->prepare('DELETE FROM datarocket_prospectos_etiquetas WHERE prospecto_id = :cid');
    $del->execute([':cid' => $prospectoId]);
    if (!$etiquetaIds) return;
    $ph  = implode(',', array_fill(0, count($etiquetaIds), '?'));
    $val = $pdo->prepare("SELECT id FROM datarocket_etiquetas WHERE id IN ({$ph})");
    $val->execute($etiquetaIds);
    $validIds = array_map('intval', array_column($val->fetchAll(), 'id'));
    if (!$validIds) return;
    $ins = $pdo->prepare('INSERT IGNORE INTO datarocket_prospectos_etiquetas
                          (prospecto_id, etiqueta_id) VALUES (:cid, :eid)');
    foreach ($validIds as $eid) {
        $ins->execute([':cid' => $prospectoId, ':eid' => $eid]);
    }
}
