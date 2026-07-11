<?php
// api/datasaleprospectos.php
// ABM de prospectos Datasale. Lee/escribe sobre la tabla `datasaleprospectos`
// definida en db/schema.sql.
//   GET    api/datasaleprospectos.php               -> listado con filtros (query string)
//   GET    api/datasaleprospectos.php?id=N          -> registro individual
//   GET    api/datasaleprospectos.php?lookups=1     -> diccionarios para formularios
//                                                     (proyectos, usuarios, paises,
//                                                      opciones de sentido/origen/tipo/
//                                                      estado/producto desde `estados`)
//   GET    api/datasaleprospectos.php?provincias=1&pais=N   -> provincias del pais
//   GET    api/datasaleprospectos.php?localidades=1&provincia=N -> localidades de la provincia
//   POST   api/datasaleprospectos.php               -> alta (JSON body)
//   POST   api/datasaleprospectos.php?id=N&action=estado  -> transicion de estado
//           (body: { estado: 1|2|3 }). Setea `atendido` con el id del usuario
//           logueado y actualiza `actualizado`. Requiere sesion valida.
//   PUT    api/datasaleprospectos.php?id=N          -> modificacion (JSON body)
//   DELETE api/datasaleprospectos.php?id=N          -> baja
// Respuesta siempre {ok: true, data: ...} u {ok: false, error: '...'} (STACK.md sec. 10).
//
// Los campos `sentido`, `origen`, `tipo`, `estado` y `producto` guardan un
// valor de referencia; su etiqueta legible se resuelve contra la tabla
// `estados` filtrando por `campo = 'datasale_prospecto_<columna>'` (equivalente
// del `combos` del legacy). Convencion del grupo: la clave `campo` sigue el
// patron snake_case con el modelo en singular — al `$mDatasaleProspecto->estado`
// del legacy le corresponde `datasale_prospecto_estado` aca. Los campos
// `proyecto`, `asignado`, `atendido`, `pais`, `provincia` y `localidad` guardan
// un id que se resuelve contra las tablas correspondientes.

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/lib/auth_check.php';

const DS_PRO_COLS = "id, ingreso, proyecto, sentido, origen, tipo, producto, asunto,
                     organizacion, nombre, contacto, celular, correo, web, domicilio,
                     ciudad, localidad, provincia, pais, ubicacion, calificacion, estado,
                     asignado, atendido, actualizado, aplazado, comentarios, acciones";

const DS_PRO_COMBO_CAMPOS = ['sentido', 'origen', 'tipo', 'estado', 'producto'];

// Prefijo `campo` en la tabla `estados` para las columnas de este recurso.
// Convencion: snake_case + modelo en singular (equivalente a
// `$mDatasaleProspecto->` del legacy). Ejemplo: `datasale_prospecto_estado`.
const DS_PRO_CAMPO_PREFIX = 'datasale_prospecto_';

header('Content-Type: application/json; charset=utf-8');

try {
    requirePermCrud('datasale.prospectos');
    $pdo    = db();
    $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
    $id     = isset($_GET['id']) ? (int)$_GET['id'] : 0;

    if ($method === 'GET' && isset($_GET['lookups'])) {
        handleLookups($pdo);
    } elseif ($method === 'GET' && isset($_GET['provincias'])) {
        handleProvincias($pdo, (int)($_GET['pais'] ?? 0));
    } elseif ($method === 'GET' && isset($_GET['localidades'])) {
        handleLocalidades($pdo, (int)($_GET['provincia'] ?? 0));
    } elseif ($method === 'GET' && $id > 0) {
        handleGetOne($pdo, $id);
    } elseif ($method === 'GET') {
        handleList($pdo, $_GET);
    } elseif ($method === 'POST' && ($_GET['action'] ?? '') === 'estado') {
        if ($id <= 0) jsonError('Falta id', 400);
        handleMarcarEstado($pdo, $id, readJsonBody());
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
// Diccionarios y enriquecimiento de etiquetas
// ----------------------------------------------------------------------------

// Devuelve un diccionario [id => nombre] para las filas de $table cuyos ids
// aparecen en $ids. Usado para resolver `proyecto`, `usuarios`, `paises`,
// `provincias` y `localidades` con un solo SELECT por tabla, sin traer las
// ~94k localidades en cada request de listado.
function fetchLookupByIds(PDO $pdo, string $table, array $ids): array {
    if (!$ids) return [];
    // Whitelist de tablas: el nombre nunca viene del usuario, pero por defensa
    // en profundidad no lo interpolamos si no esta en la lista.
    $whitelist = ['proyectos', 'usuarios', 'paises', 'provincias', 'localidades'];
    if (!in_array($table, $whitelist, true)) return [];

    $placeholders = implode(',', array_fill(0, count($ids), '?'));
    $stmt = $pdo->prepare("SELECT id, nombre FROM {$table} WHERE id IN ({$placeholders})");
    $stmt->execute(array_values($ids));
    $out = [];
    foreach ($stmt->fetchAll() as $r) {
        $out[(int)$r['id']] = (string)$r['nombre'];
    }
    return $out;
}

// Devuelve el mapa "campo|valor" => texto de todas las opciones del catalogo
// `estados` que corresponden a columnas de `datasaleprospectos`. Se cachea por
// request via una variable estatica para no repetirlo al enriquecer.
function estadosMap(PDO $pdo): array {
    static $cache = null;
    if ($cache !== null) return $cache;
    $cache = [];
    $stmt = $pdo->prepare("SELECT campo, valor, texto FROM estados WHERE campo LIKE :prefix");
    $stmt->execute([':prefix' => DS_PRO_CAMPO_PREFIX . '%']);
    foreach ($stmt->fetchAll() as $r) {
        $cache[$r['campo'] . '|' . (string)$r['valor']] = (string)$r['texto'];
    }
    return $cache;
}

// Agrega a cada fila las columnas `*_nombre` (proyecto/asignado/atendido/pais/
// provincia/localidad) y `*_texto` (sentido/origen/tipo/estado/producto). Si el
// valor original es nulo/vacio o no existe la referencia, la etiqueta queda en
// null para que el frontend muestre un fallback consistente ("—").
function enrichRows(PDO $pdo, array $rows): array {
    if (!$rows) return $rows;

    $projIds = $usrIds = $paisIds = $prvIds = $locIds = [];
    foreach ($rows as $r) {
        if (!empty($r['proyecto'])) $projIds[(int)$r['proyecto']] = true;
        if (!empty($r['asignado'])) $usrIds[(int)$r['asignado']]  = true;
        if (!empty($r['atendido'])) $usrIds[(int)$r['atendido']]  = true;
        if (isset($r['pais'])      && is_numeric($r['pais']))      $paisIds[(int)$r['pais']]      = true;
        if (isset($r['provincia']) && is_numeric($r['provincia'])) $prvIds[(int)$r['provincia']]  = true;
        if (isset($r['localidad']) && is_numeric($r['localidad'])) $locIds[(int)$r['localidad']]  = true;
    }

    $proyectos   = fetchLookupByIds($pdo, 'proyectos',   array_keys($projIds));
    $usuarios    = fetchLookupByIds($pdo, 'usuarios',    array_keys($usrIds));
    $paises      = fetchLookupByIds($pdo, 'paises',      array_keys($paisIds));
    $provincias  = fetchLookupByIds($pdo, 'provincias',  array_keys($prvIds));
    $localidades = fetchLookupByIds($pdo, 'localidades', array_keys($locIds));
    $estados     = estadosMap($pdo);

    $out = [];
    foreach ($rows as $r) {
        $r['proyecto_nombre']  = !empty($r['proyecto']) ? ($proyectos[(int)$r['proyecto']] ?? null) : null;
        $r['asignado_nombre']  = !empty($r['asignado']) ? ($usuarios[(int)$r['asignado']]  ?? null) : null;
        $r['atendido_nombre']  = !empty($r['atendido']) ? ($usuarios[(int)$r['atendido']]  ?? null) : null;
        $r['pais_nombre']      = (isset($r['pais'])      && is_numeric($r['pais']))      ? ($paises[(int)$r['pais']]           ?? null) : null;
        $r['provincia_nombre'] = (isset($r['provincia']) && is_numeric($r['provincia'])) ? ($provincias[(int)$r['provincia']]  ?? null) : null;
        $r['localidad_nombre'] = (isset($r['localidad']) && is_numeric($r['localidad'])) ? ($localidades[(int)$r['localidad']] ?? null) : null;

        foreach (DS_PRO_COMBO_CAMPOS as $c) {
            $v = $r[$c] ?? null;
            $r["{$c}_texto"] = ($v !== null && $v !== '')
                ? ($estados[DS_PRO_CAMPO_PREFIX . $c . '|' . (string)$v] ?? null)
                : null;
        }
        $out[] = $r;
    }
    return $out;
}

// ----------------------------------------------------------------------------
// Endpoints de lookup para poblar los selects del formulario
// ----------------------------------------------------------------------------

function handleLookups(PDO $pdo): void {
    // Proyectos: sin filtro por `tipo` (decision de producto — se muestran todos).
    $proyectos = $pdo->query('SELECT id, nombre FROM proyectos ORDER BY nombre')->fetchAll();
    // Usuarios: todos (igual que el legacy); el estado se maneja en `usuarios`
    // como '1'=habilitado / '0'=deshabilitado pero no se filtra aca para poder
    // seguir mostrando historicos.
    $usuarios  = $pdo->query('SELECT id, nombre FROM usuarios ORDER BY nombre')->fetchAll();
    $paises    = $pdo->query('SELECT id, nombre FROM paises ORDER BY nombre')->fetchAll();

    // Opciones de combos: leidas de la tabla `estados` filtrando por
    // `campo = 'datasale_prospecto_<columna>'`, agrupadas por columna y
    // ordenadas por `orden` (fallback a id).
    $stmt = $pdo->prepare("
        SELECT campo, valor, texto, orden
        FROM estados
        WHERE campo LIKE :prefix
        ORDER BY campo, COALESCE(orden, 0), id
    ");
    $stmt->execute([':prefix' => DS_PRO_CAMPO_PREFIX . '%']);
    $opciones = array_fill_keys(DS_PRO_COMBO_CAMPOS, []);
    foreach ($stmt->fetchAll() as $r) {
        $key = substr($r['campo'], strlen(DS_PRO_CAMPO_PREFIX));
        if (isset($opciones[$key])) {
            $opciones[$key][] = [
                'valor' => (string)$r['valor'],
                'texto' => (string)$r['texto'],
            ];
        }
    }

    $mapNombre = fn($r) => ['id' => (int)$r['id'], 'nombre' => (string)$r['nombre']];
    jsonOk([
        'proyectos' => array_map($mapNombre, $proyectos),
        'usuarios'  => array_map($mapNombre, $usuarios),
        'paises'    => array_map($mapNombre, $paises),
        'opciones'  => $opciones,
    ]);
}

function handleProvincias(PDO $pdo, int $pais): void {
    $stmt = $pdo->prepare('SELECT id, nombre FROM provincias WHERE pais = :p ORDER BY nombre');
    $stmt->execute([':p' => $pais]);
    $rows = array_map(
        fn($r) => ['id' => (int)$r['id'], 'nombre' => (string)$r['nombre']],
        $stmt->fetchAll()
    );
    jsonOk($rows);
}

function handleLocalidades(PDO $pdo, int $provincia): void {
    $stmt = $pdo->prepare('SELECT id, nombre FROM localidades WHERE provincia = :p ORDER BY nombre');
    $stmt->execute([':p' => $provincia]);
    $rows = array_map(
        fn($r) => ['id' => (int)$r['id'], 'nombre' => (string)$r['nombre']],
        $stmt->fetchAll()
    );
    jsonOk($rows);
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
        // PDO con emulate_prepares=false no permite reusar el mismo placeholder
        // en varias posiciones — hay que duplicar el bind, uno por columna.
        $where[] = '(nombre LIKE :s1 OR organizacion LIKE :s2 OR contacto LIKE :s3
                     OR correo LIKE :s4 OR celular LIKE :s5 OR asunto LIKE :s6
                     OR producto LIKE :s7 OR comentarios LIKE :s8)';
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
    $rows = enrichRows($pdo, $stmt->fetchAll());

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
    $enriched = enrichRows($pdo, [$row]);
    jsonOk($enriched[0]);
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

// Transicion rapida de estado desde el menu contextual del listado (equivalente
// a `editar?mod=pen|ate|des` del legacy). Actualiza tres campos:
//   estado       -> el nuevo valor solicitado (1=esperando, 2=atendido, 3=despachado)
//   atendido     -> id del usuario logueado si el nuevo estado es atendido/despachado;
//                   0 si vuelve a esperando (como el legacy)
//   actualizado  -> now() si atendido/despachado; se resetea a `ingreso` si vuelve
//                   a esperando (asi el "hace X" arranca de nuevo desde el ingreso)
function handleMarcarEstado(PDO $pdo, int $id, array $in): void {
    $auth = requireAuth();
    $userId = (int)($auth['sub'] ?? 0);
    if ($userId <= 0) jsonError('Sesion invalida', 401);

    $estado = isset($in['estado']) ? (int)$in['estado'] : 0;
    if (!in_array($estado, [1, 2, 3], true)) {
        jsonError('Estado invalido (esperado 1, 2 o 3)', 400);
    }

    $exists = $pdo->prepare('SELECT id, ingreso FROM datasaleprospectos WHERE id = :id');
    $exists->execute([':id' => $id]);
    $row = $exists->fetch();
    if (!$row) jsonError('Prospecto no encontrado', 404);

    if ($estado === 1) {
        // Volver a "esperando": libera el atendido y resetea actualizado al
        // ingreso, para que el "hace X" del listado vuelva a contar desde ahi.
        $atendido    = 0;
        $actualizado = $row['ingreso'];
    } else {
        // atendido / despachado: se marca al usuario logueado y se refresca la
        // marca de actualizacion a ahora.
        $atendido = $userId;
        $actualizado = (new DateTime('now', new DateTimeZone('America/Argentina/Buenos_Aires')))
                        ->format('Y-m-d H:i:s');
    }

    $stmt = $pdo->prepare(
        'UPDATE datasaleprospectos
            SET estado = :estado,
                atendido = :atendido,
                actualizado = :actualizado
          WHERE id = :id'
    );
    $stmt->execute([
        ':estado'      => $estado,
        ':atendido'    => $atendido,
        ':actualizado' => $actualizado,
        ':id'          => $id,
    ]);

    jsonOk([
        'id'          => $id,
        'estado'      => $estado,
        'atendido'    => $atendido,
        'actualizado' => $actualizado,
    ]);
}
