<?php
// api/datarocket_prospectos.php
// ABM de prospectos Datarocket. Lee/escribe sobre la tabla `datarocket_prospectos`
// definida en db/schema.sql — datos importados de la tabla legacy
// `datasaleprospectos` + los 3 campos nuevos del embudo (`embudo_id`,
// `etapa_id`, `etapa_ingreso`).
//
//   GET    api/datarocket_prospectos.php               -> listado con filtros (query string)
//   GET    api/datarocket_prospectos.php?id=N          -> registro individual
//   GET    api/datarocket_prospectos.php?lookups=1     -> diccionarios para
//                                                        formularios (embudos,
//                                                        etapas, proyectos,
//                                                        usuarios, paises, y
//                                                        opciones de combos
//                                                        sentido/origen/tipo/
//                                                        estado/producto)
//   POST   api/datarocket_prospectos.php               -> alta (JSON body)
//   POST   api/datarocket_prospectos.php?id=N&action=cambiar_etapa
//                                                     -> movimiento entre
//                                                        etapas del kanban.
//                                                        Body: { etapa_id: N }.
//                                                        Setea `etapa_ingreso`
//                                                        = NOW() y refresca
//                                                        `actualizado`. La
//                                                        etapa destino tiene
//                                                        que pertenecer al
//                                                        embudo del prospecto.
//   PUT    api/datarocket_prospectos.php?id=N          -> modificacion (JSON body)
//   DELETE api/datarocket_prospectos.php?id=N          -> baja
// Respuesta siempre {ok: true, data: ...} u {ok: false, error: '...'} (STACK.md sec. 10).
//
// Reutilizamos el catalogo `estados` con prefijo `datasale_prospecto_` para
// los combos sentido / origen / tipo / estado / producto — comparten valores
// con el ABM legacy `/prospectos` (mismo dominio, misma tabla origen). Cuando
// se decida deprecar `datasaleprospectos` se puede renombrar el prefijo en
// una unica migracion sin tocar codigo.

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/lib/auth_check.php';

// Nota fase 1->2 del refactor "prospectos = referencia a contacto":
// las 12 columnas de identidad del prospecto (nombre, contacto, celular, correo,
// web, organizacion, domicilio, ciudad, localidad, provincia, pais, ubicacion)
// SIGUEN en la tabla como respaldo hasta la fase 3 (DROP columns). Las dejamos
// en el SELECT pero el frontend ya deberia consumir los `contacto_*` derivados
// del JOIN (ver drProEnrichRows). Cuando la fase 3 corra, se sacan estas 12
// tanto del SELECT como del payload de create/update.
const DR_PRO_COLS = "id, contacto_id, ingreso, proyecto_id, sentido, origen, tipo, producto, asunto,
                     organizacion, nombre, contacto, celular, correo, web, domicilio,
                     ciudad, localidad, provincia, pais, ubicacion, calificacion, estado,
                     embudo_id, etapa_id, etapa_ingreso, asignado, atendido,
                     actualizado, aplazado, comentarios, acciones";

const DR_PRO_COMBO_CAMPOS = ['sentido', 'origen', 'tipo', 'estado', 'producto'];
const DR_PRO_CAMPO_PREFIX = 'datasale_prospecto_';

header('Content-Type: application/json; charset=utf-8');

try {
    requirePermCrud('datarocket.prospectos');
    $pdo    = db();
    $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
    $id     = isset($_GET['id']) ? (int)$_GET['id'] : 0;
    $action = (string)($_GET['action'] ?? '');

    if ($method === 'GET' && isset($_GET['lookups'])) {
        handleLookupsProspecto($pdo);
    } elseif ($method === 'GET' && $id > 0) {
        handleGetOneProspecto($pdo, $id);
    } elseif ($method === 'GET') {
        handleListProspectos($pdo, $_GET);
    } elseif ($method === 'POST' && $action === 'cambiar_etapa') {
        if ($id <= 0) jsonError('Falta id', 400);
        handleCambiarEtapaProspecto($pdo, $id, readJsonBody());
    } elseif ($method === 'POST') {
        handleCreateProspecto($pdo, readJsonBody());
    } elseif ($method === 'PUT') {
        if ($id <= 0) jsonError('Falta id', 400);
        handleUpdateProspecto($pdo, $id, readJsonBody());
    } elseif ($method === 'DELETE') {
        if ($id <= 0) jsonError('Falta id', 400);
        handleDeleteProspecto($pdo, $id);
    } else {
        jsonError('Metodo no soportado', 405);
    }
} catch (Throwable $e) {
    jsonError($e->getMessage(), 500);
}

// ----------------------------------------------------------------------------
// Enriquecimiento (etiquetas resueltas contra tablas de lookup)
// ----------------------------------------------------------------------------

function drProFetchLookupByIds(PDO $pdo, string $table, array $ids): array {
    if (!$ids) return [];
    $whitelist = ['proyectos', 'usuarios', 'datarocket_embudos', 'datarocket_etapas'];
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

function drProEstadosMap(PDO $pdo): array {
    static $cache = null;
    if ($cache !== null) return $cache;
    $cache = [];
    $stmt = $pdo->prepare("SELECT campo, valor, texto FROM estados WHERE campo LIKE :prefix");
    $stmt->execute([':prefix' => DR_PRO_CAMPO_PREFIX . '%']);
    foreach ($stmt->fetchAll() as $r) {
        $cache[$r['campo'] . '|' . (string)$r['valor']] = (string)$r['texto'];
    }
    return $cache;
}

// Trae los datos de identidad del contacto vinculado para enriquecer las filas
// del prospecto. Es un SELECT dedicado (no reusa drProFetchLookupByIds porque
// necesitamos mas de una columna). El resultado se indexa por id para lookup
// O(1) al mergearlo con los rows.
function drProFetchContactosByIds(PDO $pdo, array $ids): array {
    if (!$ids) return [];
    $placeholders = implode(',', array_fill(0, count($ids), '?'));
    $stmt = $pdo->prepare(
        "SELECT id, tipo, nombre, empresa, telefono, celular, whatsapp, correo,
                web, domicilio, ciudad, localidad, provincia, pais, ubicacion
           FROM datarocket_contactos
          WHERE id IN ({$placeholders})"
    );
    $stmt->execute(array_values($ids));
    $out = [];
    foreach ($stmt->fetchAll() as $r) {
        $out[(int)$r['id']] = $r;
    }
    return $out;
}

function drProEnrichRows(PDO $pdo, array $rows): array {
    if (!$rows) return $rows;

    $projIds = $usrIds = $embIds = $etaIds = $ctIds = [];
    foreach ($rows as $r) {
        if (!empty($r['proyecto_id'])) $projIds[(int)$r['proyecto_id']] = true;
        if (!empty($r['asignado']))    $usrIds[(int)$r['asignado']]     = true;
        if (!empty($r['atendido']))    $usrIds[(int)$r['atendido']]     = true;
        if (!empty($r['embudo_id']))   $embIds[(int)$r['embudo_id']]    = true;
        if (!empty($r['etapa_id']))    $etaIds[(int)$r['etapa_id']]     = true;
        if (!empty($r['contacto_id'])) $ctIds[(int)$r['contacto_id']]   = true;
    }

    $proyectos = drProFetchLookupByIds($pdo, 'proyectos',          array_keys($projIds));
    $usuarios  = drProFetchLookupByIds($pdo, 'usuarios',           array_keys($usrIds));
    $embudos   = drProFetchLookupByIds($pdo, 'datarocket_embudos', array_keys($embIds));
    $etapas    = drProFetchLookupByIds($pdo, 'datarocket_etapas',  array_keys($etaIds));
    $contactos = drProFetchContactosByIds($pdo,                     array_keys($ctIds));
    $estados   = drProEstadosMap($pdo);

    $out = [];
    foreach ($rows as $r) {
        $r['proyecto_nombre'] = !empty($r['proyecto_id']) ? ($proyectos[(int)$r['proyecto_id']] ?? null) : null;
        $r['asignado_nombre'] = !empty($r['asignado'])    ? ($usuarios[(int)$r['asignado']]     ?? null) : null;
        $r['atendido_nombre'] = !empty($r['atendido'])    ? ($usuarios[(int)$r['atendido']]     ?? null) : null;
        $r['embudo_nombre']   = !empty($r['embudo_id'])   ? ($embudos[(int)$r['embudo_id']]     ?? null) : null;
        $r['etapa_nombre']    = !empty($r['etapa_id'])    ? ($etapas[(int)$r['etapa_id']]       ?? null) : null;

        // Datos derivados del contacto vinculado. Prefijo `contacto_*` para
        // que el frontend los muestre como read-only (la fuente de verdad es
        // datarocket_contactos, no las columnas legacy en el prospecto).
        $c = !empty($r['contacto_id']) ? ($contactos[(int)$r['contacto_id']] ?? null) : null;
        $r['contacto_nombre']    = $c ? ($c['nombre']    !== '' ? (string)$c['nombre']    : null) : null;
        $r['contacto_empresa']   = $c ? ($c['empresa']   !== null ? (string)$c['empresa'] : null) : null;
        $r['contacto_tipo']      = $c ? ($c['tipo']      !== null ? (string)$c['tipo']    : null) : null;
        $r['contacto_telefono']  = $c ? ($c['telefono']  !== '' ? (string)$c['telefono']  : null) : null;
        $r['contacto_celular']   = $c ? ($c['celular']   !== null ? (string)$c['celular'] : null) : null;
        $r['contacto_whatsapp']  = $c ? ($c['whatsapp']  !== null ? (string)$c['whatsapp']: null) : null;
        $r['contacto_correo']    = $c ? ($c['correo']    !== '' ? (string)$c['correo']    : null) : null;
        $r['contacto_web']       = $c ? ($c['web']       !== '' ? (string)$c['web']       : null) : null;
        $r['contacto_domicilio'] = $c ? ($c['domicilio'] !== '' ? (string)$c['domicilio'] : null) : null;
        $r['contacto_ciudad']    = $c ? ($c['ciudad']    !== null ? (string)$c['ciudad']  : null) : null;
        $r['contacto_localidad'] = $c ? ($c['localidad'] !== '' ? (string)$c['localidad'] : null) : null;
        $r['contacto_provincia'] = $c ? ($c['provincia'] !== '' ? (string)$c['provincia'] : null) : null;
        $r['contacto_pais']      = $c ? ($c['pais']      !== '' ? (string)$c['pais']      : null) : null;
        $r['contacto_ubicacion'] = $c ? ($c['ubicacion'] !== '' ? (string)$c['ubicacion'] : null) : null;

        foreach (DR_PRO_COMBO_CAMPOS as $c2) {
            $v = $r[$c2] ?? null;
            $r["{$c2}_texto"] = ($v !== null && $v !== '')
                ? ($estados[DR_PRO_CAMPO_PREFIX . $c2 . '|' . (string)$v] ?? null)
                : null;
        }
        $out[] = $r;
    }
    return $out;
}

// ----------------------------------------------------------------------------
// Lookups (formularios y filtros)
// ----------------------------------------------------------------------------

function handleLookupsProspecto(PDO $pdo): void {
    // Filtramos por `tipo = 'I'` (interno): un prospecto de Datarocket solo
    // puede pertenecer a un proyecto interno del grupo Databox (Vigicom, Vigia,
    // Reactor, etc.). Los proyectos externos (clientes) no tienen pipeline
    // propio en este CRM. Alineado con la misma regla del ABM de Embudos.
    $proyectos = $pdo->query("SELECT id, nombre FROM proyectos WHERE tipo = 'I' ORDER BY nombre")->fetchAll();
    $usuarios  = $pdo->query('SELECT id, nombre FROM usuarios ORDER BY nombre')->fetchAll();
    $paises    = $pdo->query('SELECT id, nombre FROM paises ORDER BY nombre')->fetchAll();

    // Embudos activos, ordenados por su campo `orden`.
    $embudos = $pdo->query(
        'SELECT id, nombre, color, activo
           FROM datarocket_embudos
       ORDER BY activo DESC, orden ASC, nombre ASC'
    )->fetchAll();

    // Etapas de todos los embudos — el frontend las filtra por embudo_id al
    // renderizar el select dependiente. Trae `tipo` y `probabilidad` para que
    // el modal pueda mostrarlas al lado del nombre.
    $etapas = $pdo->query(
        'SELECT id, embudo_id, nombre, orden, color, tipo, probabilidad
           FROM datarocket_etapas
       ORDER BY embudo_id ASC, orden ASC, id ASC'
    )->fetchAll();

    // Opciones de combos: reutilizamos el catalogo `datasale_prospecto_*`.
    $stmt = $pdo->prepare("
        SELECT campo, valor, texto, orden
          FROM estados
         WHERE campo LIKE :prefix
      ORDER BY campo, COALESCE(orden, 0), id
    ");
    $stmt->execute([':prefix' => DR_PRO_CAMPO_PREFIX . '%']);
    $opciones = array_fill_keys(DR_PRO_COMBO_CAMPOS, []);
    foreach ($stmt->fetchAll() as $r) {
        $key = substr($r['campo'], strlen(DR_PRO_CAMPO_PREFIX));
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
        'embudos'   => array_map(fn($r) => [
            'id'     => (int)$r['id'],
            'nombre' => (string)$r['nombre'],
            'color'  => $r['color'] !== null ? (string)$r['color'] : null,
            'activo' => (int)$r['activo'],
        ], $embudos),
        'etapas'    => array_map(fn($r) => [
            'id'           => (int)$r['id'],
            'embudo_id'    => (int)$r['embudo_id'],
            'nombre'       => (string)$r['nombre'],
            'orden'        => (int)$r['orden'],
            'color'        => $r['color'] !== null ? (string)$r['color'] : null,
            'tipo'         => (string)$r['tipo'],
            'probabilidad' => $r['probabilidad'] !== null ? (int)$r['probabilidad'] : null,
        ], $etapas),
        'opciones'  => $opciones,
    ]);
}

// ----------------------------------------------------------------------------
// Listado y stats
// ----------------------------------------------------------------------------

function handleListProspectos(PDO $pdo, array $q): void {
    $codigo     = isset($q['codigo'])      && $q['codigo']      !== '' ? (int)$q['codigo']      : null;
    $contactoId = isset($q['contacto_id']) && $q['contacto_id'] !== '' ? (int)$q['contacto_id'] : null;
    $proyecto   = isset($q['proyecto_id']) && $q['proyecto_id'] !== '' ? (int)$q['proyecto_id'] : null;
    $embudo    = isset($q['embudo_id'])   && $q['embudo_id']   !== '' ? (int)$q['embudo_id']   : null;
    $etapa     = isset($q['etapa_id'])    && $q['etapa_id']    !== '' ? (int)$q['etapa_id']    : null;
    $asignado  = isset($q['asignado'])    && $q['asignado']    !== '' ? (int)$q['asignado']    : null;
    $atendido  = isset($q['atendido'])    && $q['atendido']    !== '' ? (int)$q['atendido']    : null;
    $estado    = isset($q['estado'])      && $q['estado']      !== '' ? (int)$q['estado']      : null;
    $sentido   = trim((string)($q['sentido']  ?? ''));
    $tipo      = trim((string)($q['tipo']     ?? ''));
    $origen    = trim((string)($q['origen']   ?? ''));
    $desde     = trim((string)($q['desde']    ?? ''));
    $hasta     = trim((string)($q['hasta']    ?? ''));
    $search    = trim((string)($q['q']        ?? ''));

    $orderBy = $q['order_by'] ?? 'id';
    $dir     = strtolower((string)($q['dir'] ?? 'desc'));
    $limite  = isset($q['limite']) ? (int)$q['limite'] : 100;
    if ($limite < 1)    $limite = 1;
    if ($limite > 1000) $limite = 1000;

    $allowedOrder = ['id', 'ingreso', 'proyecto_id', 'embudo_id', 'etapa_id', 'etapa_ingreso',
                     'sentido', 'origen', 'tipo', 'producto', 'organizacion', 'nombre',
                     'estado', 'calificacion', 'asignado', 'atendido', 'actualizado', 'aplazado'];
    if (!in_array($orderBy, $allowedOrder, true)) $orderBy = 'id';
    $dirSql = $dir === 'asc' ? 'ASC' : 'DESC';

    $where  = [];
    $params = [];

    if ($codigo     !== null) { $where[] = 'id = :codigo';               $params[':codigo']      = $codigo; }
    if ($contactoId !== null) { $where[] = 'contacto_id = :contacto_id'; $params[':contacto_id'] = $contactoId; }
    if ($proyecto   !== null) { $where[] = 'proyecto_id = :proyecto_id'; $params[':proyecto_id'] = $proyecto; }
    if ($embudo   !== null) { $where[] = 'embudo_id = :embudo_id';     $params[':embudo_id'] = $embudo; }
    if ($etapa    !== null) { $where[] = 'etapa_id = :etapa_id';       $params[':etapa_id']  = $etapa; }
    if ($asignado !== null) { $where[] = 'asignado = :asignado';       $params[':asignado']  = $asignado; }
    if ($atendido !== null) { $where[] = 'atendido = :atendido';       $params[':atendido']  = $atendido; }
    if ($estado   !== null) { $where[] = 'estado = :estado';           $params[':estado']    = $estado; }
    if ($sentido  !== '')   { $where[] = 'sentido = :sentido';         $params[':sentido']   = $sentido; }
    if ($tipo     !== '')   { $where[] = 'tipo = :tipo';               $params[':tipo']      = $tipo; }
    if ($origen   !== '')   { $where[] = 'origen = :origen';           $params[':origen']    = $origen; }
    if ($desde    !== '')   { $where[] = 'ingreso >= :desde';          $params[':desde']     = $desde . ' 00:00:00'; }
    if ($hasta    !== '')   { $where[] = 'ingreso <= :hasta';          $params[':hasta']     = $hasta . ' 23:59:59'; }

    if ($search !== '') {
        // PDO con emulate_prepares=false no permite reusar el mismo placeholder
        // en varias posiciones — duplicamos el bind, uno por columna.
        $where[] = '(nombre LIKE :s1 OR organizacion LIKE :s2 OR contacto LIKE :s3
                     OR correo LIKE :s4 OR celular LIKE :s5 OR asunto LIKE :s6
                     OR producto LIKE :s7 OR comentarios LIKE :s8)';
        $like = "%{$search}%";
        $params[':s1'] = $like;  $params[':s2'] = $like;  $params[':s3'] = $like;
        $params[':s4'] = $like;  $params[':s5'] = $like;  $params[':s6'] = $like;
        $params[':s7'] = $like;  $params[':s8'] = $like;
    }

    $sqlWhere = $where ? ('WHERE ' . implode(' AND ', $where)) : '';

    // Stats globales (ignoran filtros — son indicadores del recurso).
    $stats = $pdo->query("
        SELECT
            COUNT(*)                                                            AS total,
            SUM(CASE WHEN atendido IS NULL OR atendido = 0 THEN 1 ELSE 0 END)   AS sin_atender,
            SUM(CASE WHEN asignado IS NOT NULL AND asignado > 0 THEN 1 ELSE 0 END) AS asignados
          FROM datarocket_prospectos
    ")->fetch();

    $sql = "
        SELECT " . DR_PRO_COLS . "
          FROM datarocket_prospectos
          {$sqlWhere}
      ORDER BY {$orderBy} {$dirSql}
         LIMIT {$limite}
    ";
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $rows = drProEnrichRows($pdo, $stmt->fetchAll());

    jsonOk([
        'stats' => [
            'total'       => (int)($stats['total']       ?? 0),
            'sin_atender' => (int)($stats['sin_atender'] ?? 0),
            'asignados'   => (int)($stats['asignados']   ?? 0),
        ],
        'items' => $rows,
    ]);
}

function handleGetOneProspecto(PDO $pdo, int $id): void {
    $stmt = $pdo->prepare("SELECT " . DR_PRO_COLS . " FROM datarocket_prospectos WHERE id = :id");
    $stmt->execute([':id' => $id]);
    $row = $stmt->fetch();
    if (!$row) jsonError('Prospecto no encontrado', 404);
    $enriched = drProEnrichRows($pdo, [$row]);
    jsonOk($enriched[0]);
}

// ----------------------------------------------------------------------------
// Alta / Modificacion / Baja
// ----------------------------------------------------------------------------

function drProNullableStr(mixed $v, ?int $max = null): ?string {
    if ($v === null) return null;
    $s = trim((string)$v);
    if ($s === '') return null;
    if ($max !== null) $s = substr($s, 0, $max);
    return $s;
}

function drProNullableInt(mixed $v): ?int {
    if ($v === null || $v === '') return null;
    return (int)$v;
}

function drProNullableDateTime(mixed $v): ?string {
    $s = drProNullableStr($v);
    if ($s === null) return null;
    $s = str_replace('T', ' ', $s);
    if (preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}$/', $s)) $s .= ':00';
    if (!preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $s)) return null;
    return $s;
}

function drProSanitizePayload(array $in): array {
    return [
        'contacto_id'   => drProNullableInt($in['contacto_id']        ?? null),
        'ingreso'       => drProNullableDateTime($in['ingreso']       ?? null),
        'proyecto_id'   => drProNullableInt($in['proyecto_id']        ?? null),
        'sentido'       => drProNullableStr($in['sentido']            ?? null, 1),
        'origen'        => drProNullableStr($in['origen']             ?? null, 10),
        'tipo'          => drProNullableStr($in['tipo']               ?? null, 1),
        'producto'      => drProNullableStr($in['producto']           ?? null, 100),
        'asunto'        => drProNullableStr($in['asunto']             ?? null, 255),
        'organizacion'  => drProNullableStr($in['organizacion']       ?? null, 255),
        'nombre'        => drProNullableStr($in['nombre']             ?? null, 255),
        'contacto'      => drProNullableStr($in['contacto']           ?? null, 255),
        'celular'       => drProNullableStr($in['celular']            ?? null, 255),
        'correo'        => drProNullableStr($in['correo']             ?? null, 255),
        'web'           => drProNullableStr($in['web']                ?? null, 255),
        'domicilio'     => drProNullableStr($in['domicilio']          ?? null, 255),
        'ciudad'        => drProNullableStr($in['ciudad']             ?? null, 255),
        'localidad'     => drProNullableStr($in['localidad']          ?? null, 255),
        'provincia'     => drProNullableStr($in['provincia']          ?? null, 255),
        'pais'          => drProNullableStr($in['pais']               ?? null, 255),
        'ubicacion'     => drProNullableStr($in['ubicacion']          ?? null, 255),
        'calificacion'  => drProNullableInt($in['calificacion']       ?? null),
        'estado'        => drProNullableInt($in['estado']             ?? null),
        'embudo_id'     => drProNullableInt($in['embudo_id']          ?? null),
        'etapa_id'      => drProNullableInt($in['etapa_id']           ?? null),
        'etapa_ingreso' => drProNullableDateTime($in['etapa_ingreso'] ?? null),
        'asignado'      => drProNullableInt($in['asignado']           ?? null),
        'atendido'      => drProNullableInt($in['atendido']           ?? null),
        'actualizado'   => drProNullableDateTime($in['actualizado']   ?? null),
        'aplazado'      => drProNullableDateTime($in['aplazado']      ?? null),
        'comentarios'   => drProNullableStr($in['comentarios']        ?? null, 1000),
        'acciones'      => drProNullableStr($in['acciones']           ?? null),
    ];
}

// Verifica que la etapa pertenezca al embudo indicado. Levanta jsonError
// (que corta el request) si no. Devuelve el registro de la etapa con
// `embudo_id` para reuso del caller.
function drProValidarEtapaEnEmbudo(PDO $pdo, int $etapaId, int $embudoId): array {
    $stmt = $pdo->prepare('SELECT id, embudo_id, nombre FROM datarocket_etapas WHERE id = :id LIMIT 1');
    $stmt->execute([':id' => $etapaId]);
    $row = $stmt->fetch();
    if (!$row) jsonError('La etapa indicada no existe.', 400);
    if ((int)$row['embudo_id'] !== $embudoId) {
        jsonError('La etapa indicada no pertenece al embudo del prospecto.', 400);
    }
    return $row;
}

function handleCreateProspecto(PDO $pdo, array $in): void {
    $p = drProSanitizePayload($in);

    // Regla dura del refactor "prospecto = referencia a contacto": el alta
    // requiere contacto_id existente. Sin contacto_id el prospecto no tiene
    // identidad (nombre, correo, celular, empresa) — vive solo en las tabs
    // General/Seguimiento/Notas. La UI de "Nuevo prospecto" fuerza al usuario
    // a elegir/crear un contacto antes de guardar.
    if ($p['contacto_id'] === null || $p['contacto_id'] <= 0) {
        jsonError('Falta el contacto vinculado. Selecciona uno existente o crea uno nuevo antes de guardar.', 400);
    }
    $stmt = $pdo->prepare('SELECT id FROM datarocket_contactos WHERE id = :id LIMIT 1');
    $stmt->execute([':id' => $p['contacto_id']]);
    if (!$stmt->fetch()) jsonError('El contacto indicado no existe.', 400);

    if ($p['ingreso'] === null) {
        $p['ingreso'] = (new DateTime('now', new DateTimeZone('America/Argentina/Buenos_Aires')))
                        ->format('Y-m-d H:i:s');
    }
    $p['actualizado'] = (new DateTime('now', new DateTimeZone('America/Argentina/Buenos_Aires')))
                        ->format('Y-m-d H:i:s');

    // Consistencia embudo/etapa: si vienen los dos, validamos que la etapa
    // pertenezca al embudo. Si solo viene la etapa, resolvemos el embudo a
    // partir de ella (para no forzar al cliente a mandarla dos veces).
    if ($p['embudo_id'] && $p['etapa_id']) {
        drProValidarEtapaEnEmbudo($pdo, $p['etapa_id'], $p['embudo_id']);
    } elseif ($p['etapa_id'] && !$p['embudo_id']) {
        $stmt = $pdo->prepare('SELECT embudo_id FROM datarocket_etapas WHERE id = :id LIMIT 1');
        $stmt->execute([':id' => $p['etapa_id']]);
        $row = $stmt->fetch();
        if (!$row) jsonError('La etapa indicada no existe.', 400);
        $p['embudo_id'] = (int)$row['embudo_id'];
    }

    // etapa_ingreso: si viene etapa y no vino explicito, se marca now().
    if ($p['etapa_id'] && $p['etapa_ingreso'] === null) {
        $p['etapa_ingreso'] = $p['actualizado'];
    }

    // Las 12 columnas de identidad legacy (nombre / contacto / celular / etc.)
    // NO se escriben mas desde nuevos altas — quedan NULL. Se dropean en la
    // fase 3 del refactor.
    $sql = "
        INSERT INTO datarocket_prospectos
            (contacto_id, ingreso, proyecto_id, sentido, origen, tipo, producto, asunto,
             calificacion, estado, embudo_id, etapa_id, etapa_ingreso,
             asignado, atendido, actualizado, aplazado, comentarios, acciones)
        VALUES
            (:contacto_id, :ingreso, :proyecto_id, :sentido, :origen, :tipo, :producto, :asunto,
             :calificacion, :estado, :embudo_id, :etapa_id, :etapa_ingreso,
             :asignado, :atendido, :actualizado, :aplazado, :comentarios, :acciones)
    ";
    $stmt = $pdo->prepare($sql);
    $stmt->execute([
        ':contacto_id'   => $p['contacto_id'],
        ':ingreso'       => $p['ingreso'],
        ':proyecto_id'   => $p['proyecto_id'],
        ':sentido'       => $p['sentido'],
        ':origen'        => $p['origen'],
        ':tipo'          => $p['tipo'],
        ':producto'      => $p['producto'],
        ':asunto'        => $p['asunto'],
        ':calificacion'  => $p['calificacion'],
        ':estado'        => $p['estado'],
        ':embudo_id'     => $p['embudo_id'],
        ':etapa_id'      => $p['etapa_id'],
        ':etapa_ingreso' => $p['etapa_ingreso'],
        ':asignado'      => $p['asignado'],
        ':atendido'      => $p['atendido'],
        ':actualizado'   => $p['actualizado'],
        ':aplazado'      => $p['aplazado'],
        ':comentarios'   => $p['comentarios'],
        ':acciones'      => $p['acciones'],
    ]);
    jsonOk(['id' => (int)$pdo->lastInsertId()], 201);
}

function handleUpdateProspecto(PDO $pdo, int $id, array $in): void {
    $exists = $pdo->prepare('SELECT id, embudo_id, etapa_id FROM datarocket_prospectos WHERE id = :id');
    $exists->execute([':id' => $id]);
    $prev = $exists->fetch();
    if (!$prev) jsonError('Prospecto no encontrado', 404);

    $p = drProSanitizePayload($in);
    if ($p['actualizado'] === null) {
        $p['actualizado'] = (new DateTime('now', new DateTimeZone('America/Argentina/Buenos_Aires')))
                            ->format('Y-m-d H:i:s');
    }

    // Consistencia embudo/etapa contra el estado final (con lo que quede
    // despues de aplicar el payload).
    $embudoFinal = array_key_exists('embudo_id', $in) ? $p['embudo_id'] : (int)$prev['embudo_id'];
    $etapaFinal  = array_key_exists('etapa_id',  $in) ? $p['etapa_id']  : (int)$prev['etapa_id'];
    if ($embudoFinal && $etapaFinal) {
        drProValidarEtapaEnEmbudo($pdo, $etapaFinal, $embudoFinal);
    }

    // Si cambio la etapa, refrescamos etapa_ingreso (a menos que el cliente lo
    // haya seteado explicitamente en el payload).
    if (array_key_exists('etapa_id', $in) && (int)$prev['etapa_id'] !== (int)$p['etapa_id']
        && !array_key_exists('etapa_ingreso', $in)) {
        $p['etapa_ingreso'] = $p['actualizado'];
    }

    // NO permitimos re-vincular contacto_id en un UPDATE. Decision de producto:
    // "este prospecto ahora es de otra persona" no tiene sentido comercial;
    // si el vendedor detecta el error debe borrar y crear de nuevo. Cualquier
    // contacto_id en el payload se ignora.
    //
    // Tampoco escribimos mas las 12 columnas de identidad legacy (nombre /
    // contacto / celular / correo / web / organizacion / domicilio / ciudad /
    // localidad / provincia / pais / ubicacion) — la fuente de verdad son las
    // columnas equivalentes de `datarocket_contactos`. Para modificar esa
    // data hay que editar el contacto vinculado.
    $sql = "
        UPDATE datarocket_prospectos SET
            ingreso       = :ingreso,
            proyecto_id   = :proyecto_id,
            sentido       = :sentido,
            origen        = :origen,
            tipo          = :tipo,
            producto      = :producto,
            asunto        = :asunto,
            calificacion  = :calificacion,
            estado        = :estado,
            embudo_id     = :embudo_id,
            etapa_id      = :etapa_id,
            etapa_ingreso = :etapa_ingreso,
            asignado      = :asignado,
            atendido      = :atendido,
            actualizado   = :actualizado,
            aplazado      = :aplazado,
            comentarios   = :comentarios,
            acciones      = :acciones
        WHERE id = :id
    ";
    $stmt = $pdo->prepare($sql);
    $stmt->execute([
        ':ingreso'       => $p['ingreso'],
        ':proyecto_id'   => $p['proyecto_id'],
        ':sentido'       => $p['sentido'],
        ':origen'        => $p['origen'],
        ':tipo'          => $p['tipo'],
        ':producto'      => $p['producto'],
        ':asunto'        => $p['asunto'],
        ':calificacion'  => $p['calificacion'],
        ':estado'        => $p['estado'],
        ':embudo_id'     => $p['embudo_id'],
        ':etapa_id'      => $p['etapa_id'],
        ':etapa_ingreso' => $p['etapa_ingreso'],
        ':asignado'      => $p['asignado'],
        ':atendido'      => $p['atendido'],
        ':actualizado'   => $p['actualizado'],
        ':aplazado'      => $p['aplazado'],
        ':comentarios'   => $p['comentarios'],
        ':acciones'      => $p['acciones'],
        ':id'            => $id,
    ]);
    jsonOk(['id' => $id]);
}

function handleDeleteProspecto(PDO $pdo, int $id): void {
    $stmt = $pdo->prepare('DELETE FROM datarocket_prospectos WHERE id = :id');
    $stmt->execute([':id' => $id]);
    if ($stmt->rowCount() === 0) jsonError('Prospecto no encontrado', 404);
    jsonOk(['id' => $id]);
}

// Movimiento del prospecto entre etapas del kanban (equivalente a arrastrar
// una tarjeta de una columna a otra). Solo requiere `etapa_id`; el embudo se
// mantiene y se valida que la etapa destino pertenezca a el. Setea
// `etapa_ingreso = NOW()` para poder medir tiempo-en-etapa.
function handleCambiarEtapaProspecto(PDO $pdo, int $id, array $in): void {
    $etapaId = isset($in['etapa_id']) ? (int)$in['etapa_id'] : 0;
    if ($etapaId <= 0) jsonError('Falta etapa_id', 400);

    $stmt = $pdo->prepare('SELECT id, embudo_id, etapa_id FROM datarocket_prospectos WHERE id = :id');
    $stmt->execute([':id' => $id]);
    $prev = $stmt->fetch();
    if (!$prev) jsonError('Prospecto no encontrado', 404);
    if (empty($prev['embudo_id'])) jsonError('El prospecto no tiene embudo asignado.', 409);

    drProValidarEtapaEnEmbudo($pdo, $etapaId, (int)$prev['embudo_id']);

    if ((int)$prev['etapa_id'] === $etapaId) {
        // Idempotente: si ya esta en la etapa destino, no hacemos nada mas
        // que devolver el estado actual.
        jsonOk(['id' => $id, 'etapa_id' => $etapaId, 'cambio' => false]);
        return;
    }

    $now = (new DateTime('now', new DateTimeZone('America/Argentina/Buenos_Aires')))
            ->format('Y-m-d H:i:s');

    $upd = $pdo->prepare(
        'UPDATE datarocket_prospectos
            SET etapa_id      = :etapa_id,
                etapa_ingreso = :etapa_ingreso,
                actualizado   = :actualizado
          WHERE id = :id'
    );
    $upd->execute([
        ':etapa_id'      => $etapaId,
        ':etapa_ingreso' => $now,
        ':actualizado'   => $now,
        ':id'            => $id,
    ]);

    jsonOk([
        'id'            => $id,
        'etapa_id'      => $etapaId,
        'etapa_ingreso' => $now,
        'actualizado'   => $now,
        'cambio'        => true,
    ]);
}
