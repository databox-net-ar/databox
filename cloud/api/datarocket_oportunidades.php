<?php
// api/datarocket_oportunidades.php
// ABM de oportunidades Datarocket. Lee/escribe sobre la tabla `datarocket_oportunidades`
// definida en db/schema.sql. Su contenido nacio importado del ABM legacy de
// Datasale, eliminado en la migracion 20260817_2600.
//
//   GET    api/datarocket_oportunidades.php               -> listado con filtros (query string)
//   GET    api/datarocket_oportunidades.php?id=N          -> registro individual
//   GET    api/datarocket_oportunidades.php?lookups=1     -> diccionarios para
//                                                        formularios (embudos,
//                                                        etapas, proyectos,
//                                                        usuarios, paises, y
//                                                        opciones de combos
//                                                        sentido/origen/moneda)
//   POST   api/datarocket_oportunidades.php               -> alta (JSON body)
//   POST   api/datarocket_oportunidades.php?id=N&action=cambiar_etapa
//                                                     -> movimiento entre
//                                                        etapas del kanban.
//                                                        Body: { etapa_id: N }.
//                                                        Setea `etapa_ingreso`
//                                                        = NOW() y refresca
//                                                        `actualizado`. La
//                                                        etapa destino tiene
//                                                        que pertenecer al
//                                                        embudo de la oportunidad.
//   PUT    api/datarocket_oportunidades.php?id=N          -> modificacion (JSON body)
//   DELETE api/datarocket_oportunidades.php?id=N          -> baja
// Respuesta siempre {ok: true, data: ...} u {ok: false, error: '...'} (STACK.md sec. 10).

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/lib/auth_check.php';

// Fase 3 del refactor "oportunidades = referencia a prospecto" COMPLETADA
// (migracion 20260816_1500): las 12 columnas de identidad de la oportunidad
// (nombre, contacto, celular, correo, web, organizacion, domicilio, ciudad,
// localidad, provincia, pais, ubicacion) ya NO existen en la tabla. La fuente
// de verdad es `datarocket_prospectos` via `prospecto_id`, y el frontend consume
// los `prospecto_*` derivados del JOIN (ver drOpoEnrichRows).
//
// `acciones` tampoco existe mas (migracion 20260817_1700): su log libre quedo
// reemplazado por `datarocket_interacciones`.
//
// `asunto` es el unico bloque de texto libre que queda. Se llamo `comentarios`
// entre la 20260817_1600 (que fusiono el `asunto` original adentro, como
// primera linea) y la 20260817_2800 (que le devolvio el nombre): el contenido
// sigue siendo asunto + notas en una sola columna.
//
// `tipo` se dropeo en la 20260817_1800: guardaba valores legacy (U/I/O/M) que
// ningun catalogo `estados` decodificaba, asi que el panel siempre mostro "—".
//
// `estado` (tinyint: 1 Esperando / 2 Atendido / 3 Despachado) se dropeo en la
// 20260817_2900. Era el antecesor de `etapa_id` — la 20260812_0400 creo las
// etapas derivandolas de el — y no aportaba una sola fila de informacion
// propia: 3 <=> etapa 'ganada', 2 <=> etapa activa con `atendido` cargado,
// 1 <=> etapa activa sin `atendido` y con interacciones entrantes sin responder.
const DR_OPO_COLS = "id, prospecto_id, ingreso, proyecto_id, sentido, origen, producto,
                     monto, moneda, cierre_esperado, calificacion,
                     embudo_id, etapa_id, etapa_ingreso, asignado, atendido,
                     actualizado, aplazado, asunto";

// Misma lista calificada con el alias `o`. El listado joinea con
// `datarocket_prospectos` (buscador + orden por identidad) y ahi `id`, `correo`
// y varias mas son ambiguas sin prefijo.
const DR_OPO_COLS_O = "o.id, o.prospecto_id, o.ingreso, o.proyecto_id, o.sentido, o.origen,
                       o.producto, o.monto, o.moneda, o.cierre_esperado,
                       o.calificacion, o.embudo_id, o.etapa_id, o.etapa_ingreso,
                       o.asignado, o.atendido, o.actualizado, o.aplazado, o.asunto";

// Combos resueltos contra el catalogo `estados`. Los cuatro comparten prefijo
// desde la migracion 20260817_2600: hasta ahi sentido / origen / producto
// vivian bajo `datasale_prospecto_` porque el catalogo era compartido con el
// ABM legacy de Datasale, y solo `moneda` (que el legacy no manejaba) usaba el
// propio. Eliminado Datasale, el catalogo entero es de Datarocket.
const DR_OPO_COMBO_CAMPOS = ['sentido', 'origen', 'producto', 'moneda'];
const DR_OPO_CAMPO_PREFIX = 'datarocket_oportunidad_';

// Monedas aceptadas en el payload (ISO-4217). Se valida contra esta lista y no
// contra `estados` para que un catalogo mal cargado no abra la puerta a basura
// en la columna.
const DR_OPO_MONEDAS = ['ARS', 'USD'];

header('Content-Type: application/json; charset=utf-8');

try {
    requirePermCrud('datarocket.oportunidades');
    $pdo    = db();
    $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
    $id     = isset($_GET['id']) ? (int)$_GET['id'] : 0;
    $action = (string)($_GET['action'] ?? '');

    if ($method === 'GET' && isset($_GET['lookups'])) {
        handleLookupsOportunidad($pdo);
    } elseif ($method === 'GET' && $id > 0) {
        handleGetOneOportunidad($pdo, $id);
    } elseif ($method === 'GET') {
        handleListOportunidades($pdo, $_GET);
    } elseif ($method === 'POST' && $action === 'cambiar_etapa') {
        if ($id <= 0) jsonError('Falta id', 400);
        handleCambiarEtapaOportunidad($pdo, $id, readJsonBody());
    } elseif ($method === 'POST') {
        handleCreateOportunidad($pdo, readJsonBody());
    } elseif ($method === 'PUT') {
        if ($id <= 0) jsonError('Falta id', 400);
        handleUpdateOportunidad($pdo, $id, readJsonBody());
    } elseif ($method === 'DELETE') {
        if ($id <= 0) jsonError('Falta id', 400);
        handleDeleteOportunidad($pdo, $id);
    } else {
        jsonError('Metodo no soportado', 405);
    }
} catch (Throwable $e) {
    jsonError($e->getMessage(), 500);
}

// ----------------------------------------------------------------------------
// Enriquecimiento (etiquetas resueltas contra tablas de lookup)
// ----------------------------------------------------------------------------

function drOpoFetchLookupByIds(PDO $pdo, string $table, array $ids): array {
    if (!$ids) return [];
    $whitelist = ['proyectos', 'usuarios', 'datarocket_embudos', 'datarocket_etapas',
                  'paises', 'provincias', 'localidades'];
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

function drOpoEstadosMap(PDO $pdo): array {
    static $cache = null;
    if ($cache !== null) return $cache;
    $cache = [];
    $stmt = $pdo->prepare(
        "SELECT campo, valor, texto FROM estados WHERE campo LIKE :prefix"
    );
    $stmt->execute([':prefix' => DR_OPO_CAMPO_PREFIX . '%']);
    foreach ($stmt->fetchAll() as $r) {
        $cache[$r['campo'] . '|' . (string)$r['valor']] = (string)$r['texto'];
    }
    return $cache;
}

// Trae los datos de identidad del prospecto vinculado para enriquecer las filas
// de la oportunidad. Es un SELECT dedicado (no reusa drOpoFetchLookupByIds porque
// necesitamos mas de una columna). El resultado se indexa por id para lookup
// O(1) al mergearlo con los rows.
function drOpoFetchProspectosByIds(PDO $pdo, array $ids): array {
    if (!$ids) return [];
    $placeholders = implode(',', array_fill(0, count($ids), '?'));
    $stmt = $pdo->prepare(
        "SELECT id, tipo, nombre, empresa_nombre, telefono, celular, whatsapp, correo,
                web, domicilio, ciudad, localidad_id, provincia_id, pais_id, ubicacion
           FROM datarocket_prospectos
          WHERE id IN ({$placeholders})"
    );
    $stmt->execute(array_values($ids));
    $out = [];
    foreach ($stmt->fetchAll() as $r) {
        $out[(int)$r['id']] = $r;
    }
    return $out;
}

// Etapas con las dos columnas relevantes. No reusa drOpoFetchLookupByIds (que
// solo trae `nombre`) porque las filas exponen tambien `tipo`
// (activa / ganada / perdida). `probabilidad` no se trae: la unica ponderacion
// que quedo es la de drOpoForecast, que la lee en su propio SQL.
function drOpoFetchEtapasByIds(PDO $pdo, array $ids): array {
    if (!$ids) return [];
    $placeholders = implode(',', array_fill(0, count($ids), '?'));
    $stmt = $pdo->prepare(
        "SELECT id, nombre, tipo
           FROM datarocket_etapas
          WHERE id IN ({$placeholders})"
    );
    $stmt->execute(array_values($ids));
    $out = [];
    foreach ($stmt->fetchAll() as $r) {
        $out[(int)$r['id']] = $r;
    }
    return $out;
}

function drOpoEnrichRows(PDO $pdo, array $rows): array {
    if (!$rows) return $rows;

    $projIds = $usrIds = $embIds = $etaIds = $ctIds = [];
    foreach ($rows as $r) {
        if (!empty($r['proyecto_id'])) $projIds[(int)$r['proyecto_id']] = true;
        if (!empty($r['asignado']))    $usrIds[(int)$r['asignado']]     = true;
        if (!empty($r['atendido']))    $usrIds[(int)$r['atendido']]     = true;
        if (!empty($r['embudo_id']))   $embIds[(int)$r['embudo_id']]    = true;
        if (!empty($r['etapa_id']))    $etaIds[(int)$r['etapa_id']]     = true;
        if (!empty($r['prospecto_id'])) $ctIds[(int)$r['prospecto_id']]   = true;
    }

    $proyectos = drOpoFetchLookupByIds($pdo, 'proyectos',          array_keys($projIds));
    $usuarios  = drOpoFetchLookupByIds($pdo, 'usuarios',           array_keys($usrIds));
    $embudos   = drOpoFetchLookupByIds($pdo, 'datarocket_embudos', array_keys($embIds));
    $etapas    = drOpoFetchEtapasByIds($pdo,                        array_keys($etaIds));
    $prospectos = drOpoFetchProspectosByIds($pdo,                     array_keys($ctIds));
    $estados   = drOpoEstadosMap($pdo);

    // La ubicacion del prospecto ahora son FKs a los catalogos (migracion
    // 20260815_1000). Se resuelven a nombre con un SELECT por catalogo, acotado
    // a los ids que realmente aparecen en estas filas.
    $ctPaisIds = $ctProvIds = $ctLocIds = [];
    foreach ($prospectos as $c) {
        if (!empty($c['pais_id']))      $ctPaisIds[(int)$c['pais_id']]      = true;
        if (!empty($c['provincia_id'])) $ctProvIds[(int)$c['provincia_id']] = true;
        if (!empty($c['localidad_id'])) $ctLocIds[(int)$c['localidad_id']]  = true;
    }
    $ctPaises      = drOpoFetchLookupByIds($pdo, 'paises',      array_keys($ctPaisIds));
    $ctProvincias  = drOpoFetchLookupByIds($pdo, 'provincias',  array_keys($ctProvIds));
    $ctLocalidades = drOpoFetchLookupByIds($pdo, 'localidades', array_keys($ctLocIds));

    $out = [];
    foreach ($rows as $r) {
        $r['proyecto_nombre'] = !empty($r['proyecto_id']) ? ($proyectos[(int)$r['proyecto_id']] ?? null) : null;
        $r['asignado_nombre'] = !empty($r['asignado'])    ? ($usuarios[(int)$r['asignado']]     ?? null) : null;
        $r['atendido_nombre'] = !empty($r['atendido'])    ? ($usuarios[(int)$r['atendido']]     ?? null) : null;
        $r['embudo_nombre']   = !empty($r['embudo_id'])   ? ($embudos[(int)$r['embudo_id']]     ?? null) : null;

        // Etapa: nombre para mostrar y `tipo` (activa / ganada / perdida), que
        // el frontend usa al pintar la fila. `etapa_probabilidad` y el
        // `monto_ponderado` que se derivaba de el ya no se exponen por fila: la
        // ponderacion vive solo en el agregado de drOpoForecast.
        $e = !empty($r['etapa_id']) ? ($etapas[(int)$r['etapa_id']] ?? null) : null;
        $r['etapa_nombre']       = $e ? (string)$e['nombre'] : null;
        $r['etapa_tipo']         = $e ? (string)$e['tipo']   : null;

        $r['monto'] = $r['monto'] !== null ? (float)$r['monto'] : null;

        // Datos derivados del prospecto vinculado. Prefijo `prospecto_*` para
        // que el frontend los muestre como read-only (la fuente de verdad es
        // datarocket_prospectos, no las columnas legacy en la oportunidad).
        $c = !empty($r['prospecto_id']) ? ($prospectos[(int)$r['prospecto_id']] ?? null) : null;
        $r['prospecto_nombre']    = $c ? ($c['nombre']    !== '' ? (string)$c['nombre']    : null) : null;
        // `prospecto_empresa` conserva el nombre de la clave expuesta al front
        // aunque la columna origen pase a llamarse `empresa_nombre` (migracion
        // 20260817_1900): el prefijo `prospecto_` es el contrato de este endpoint.
        $r['prospecto_empresa']   = $c ? ($c['empresa_nombre'] !== null ? (string)$c['empresa_nombre'] : null) : null;
        $r['prospecto_tipo']      = $c ? ($c['tipo']      !== null ? (string)$c['tipo']    : null) : null;
        $r['prospecto_telefono']  = $c ? ($c['telefono']  !== '' ? (string)$c['telefono']  : null) : null;
        $r['prospecto_celular']   = $c ? ($c['celular']   !== null ? (string)$c['celular'] : null) : null;
        $r['prospecto_whatsapp']  = $c ? ($c['whatsapp']  !== null ? (string)$c['whatsapp']: null) : null;
        $r['prospecto_correo']    = $c ? ($c['correo']    !== '' ? (string)$c['correo']    : null) : null;
        $r['prospecto_web']       = $c ? ($c['web']       !== '' ? (string)$c['web']       : null) : null;
        $r['prospecto_domicilio'] = $c ? ($c['domicilio'] !== '' ? (string)$c['domicilio'] : null) : null;
        $r['prospecto_ciudad']    = $c ? ($c['ciudad']    !== null ? (string)$c['ciudad']  : null) : null;
        // Se exponen resueltos a nombre (antes viajaba el ID crudo, que el
        // frontend pintaba tal cual en la tarjeta de detalle).
        $r['prospecto_localidad'] = $c && !empty($c['localidad_id']) ? ($ctLocalidades[(int)$c['localidad_id']] ?? null) : null;
        $r['prospecto_provincia'] = $c && !empty($c['provincia_id']) ? ($ctProvincias[(int)$c['provincia_id']]  ?? null) : null;
        $r['prospecto_pais']      = $c && !empty($c['pais_id'])      ? ($ctPaises[(int)$c['pais_id']]           ?? null) : null;
        $r['prospecto_ubicacion'] = $c ? ($c['ubicacion'] !== '' ? (string)$c['ubicacion'] : null) : null;

        foreach (DR_OPO_COMBO_CAMPOS as $c2) {
            $v = $r[$c2] ?? null;
            $r["{$c2}_texto"] = ($v !== null && $v !== '')
                ? ($estados[DR_OPO_CAMPO_PREFIX . $c2 . '|' . (string)$v] ?? null)
                : null;
        }
        $out[] = $r;
    }
    return $out;
}

// ----------------------------------------------------------------------------
// Lookups (formularios y filtros)
// ----------------------------------------------------------------------------

function handleLookupsOportunidad(PDO $pdo): void {
    // Filtramos por `tipo = 'I'` (interno): una oportunidad de Datarocket solo
    // puede pertenecer a un proyecto interno del grupo Databox (Vigicom, Vigia,
    // Reactor, etc.). Los proyectos externos (clientes) no tienen pipeline
    // propio en este CRM. Alineado con la misma regla del ABM de Embudos.
    $proyectos = $pdo->query("SELECT id, nombre FROM proyectos WHERE tipo = 'I' ORDER BY nombre")->fetchAll();
    $usuarios  = $pdo->query('SELECT id, nombre FROM usuarios ORDER BY nombre')->fetchAll();
    $paises    = $pdo->query('SELECT id, nombre FROM paises ORDER BY nombre')->fetchAll();

    // Embudos activos, ordenados por su campo `orden`.
    //
    // Ojo: `datarocket_embudos` NO tiene columnas `color` ni `orden` — pedirlas
    // reventaba el endpoint entero con "Unknown column 'color'", y como
    // dpCargarLookups() se come la excepcion, el modulo venia corriendo con
    // TODOS los combos del formulario vacios sin avisar. El color se sigue
    // exponiendo como null porque el frontend lo lee (dpEnriquecerConColores).
    $embudos = $pdo->query(
        'SELECT id, nombre, activo
           FROM datarocket_embudos
       ORDER BY activo DESC, nombre ASC'
    )->fetchAll();

    // Etapas de todos los embudos — el frontend las filtra por embudo_id al
    // renderizar el select dependiente, y usa `color` / `tipo` para pintar las
    // filas del listado (opEnriquecerConColores). `probabilidad` no viaja: el
    // select muestra solo el nombre y el modal ya no la expone.
    $etapas = $pdo->query(
        'SELECT id, embudo_id, nombre, orden, color, tipo
           FROM datarocket_etapas
       ORDER BY embudo_id ASC, orden ASC, id ASC'
    )->fetchAll();

    // Opciones de combos, todas del catalogo `datarocket_oportunidad_*`.
    $stmt = $pdo->prepare("
        SELECT campo, valor, texto, orden
          FROM estados
         WHERE campo LIKE :prefix
      ORDER BY campo, COALESCE(orden, 0), id
    ");
    $stmt->execute([':prefix' => DR_OPO_CAMPO_PREFIX . '%']);
    $opciones = array_fill_keys(DR_OPO_COMBO_CAMPOS, []);
    foreach ($stmt->fetchAll() as $r) {
        $key = substr($r['campo'], strlen(DR_OPO_CAMPO_PREFIX));
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
            'color'  => null,
            'activo' => (int)$r['activo'],
        ], $embudos),
        'etapas'    => array_map(fn($r) => [
            'id'           => (int)$r['id'],
            'embudo_id'    => (int)$r['embudo_id'],
            'nombre'       => (string)$r['nombre'],
            'orden'        => (int)$r['orden'],
            'color'        => $r['color'] !== null ? (string)$r['color'] : null,
            'tipo'         => (string)$r['tipo'],
        ], $etapas),
        'opciones'  => $opciones,
    ]);
}

// ----------------------------------------------------------------------------
// Listado y stats
// ----------------------------------------------------------------------------

function handleListOportunidades(PDO $pdo, array $q): void {
    $codigo     = isset($q['codigo'])      && $q['codigo']      !== '' ? (int)$q['codigo']      : null;
    $prospectoId = isset($q['prospecto_id']) && $q['prospecto_id'] !== '' ? (int)$q['prospecto_id'] : null;
    $proyecto   = isset($q['proyecto_id']) && $q['proyecto_id'] !== '' ? (int)$q['proyecto_id'] : null;
    $embudo    = isset($q['embudo_id'])   && $q['embudo_id']   !== '' ? (int)$q['embudo_id']   : null;
    $etapa     = isset($q['etapa_id'])    && $q['etapa_id']    !== '' ? (int)$q['etapa_id']    : null;
    $asignado  = isset($q['asignado'])    && $q['asignado']    !== '' ? (int)$q['asignado']    : null;
    $atendido  = isset($q['atendido'])    && $q['atendido']    !== '' ? (int)$q['atendido']    : null;
    $sentido   = trim((string)($q['sentido']  ?? ''));
    $origen    = trim((string)($q['origen']   ?? ''));
    $desde     = trim((string)($q['desde']    ?? ''));
    $hasta     = trim((string)($q['hasta']    ?? ''));
    $search    = trim((string)($q['q']        ?? ''));

    $orderBy = $q['order_by'] ?? 'id';
    $dir     = strtolower((string)($q['dir'] ?? 'desc'));
    $limite  = isset($q['limite']) ? (int)$q['limite'] : 100;
    if ($limite < 1)    $limite = 1;
    if ($limite > 1000) $limite = 1000;

    // `organizacion` y `nombre` salieron del listado de ordenables: las columnas
    // ya no existen (migracion 20260816_1500). Para ordenar por identidad ahora
    // hay que usar el nombre del prospecto, que vive en el JOIN — se expone como
    // `prospecto_nombre`. `tipo` salio por el mismo motivo (migracion 20260817_1800).
    $allowedOrder = ['id', 'ingreso', 'proyecto_id', 'embudo_id', 'etapa_id', 'etapa_ingreso',
                     'sentido', 'origen', 'producto', 'monto', 'cierre_esperado',
                     'calificacion', 'asignado', 'atendido', 'actualizado', 'aplazado',
                     'prospecto_nombre'];
    if (!in_array($orderBy, $allowedOrder, true)) $orderBy = 'id';
    $dirSql = $dir === 'asc' ? 'ASC' : 'DESC';
    // Todas las columnas ordenables son de `o` salvo la del prospecto joineado.
    $orderCol = $orderBy === 'prospecto_nombre' ? 'c.nombre' : ('o.' . $orderBy);

    $where  = [];
    $params = [];

    if ($codigo     !== null) { $where[] = 'o.id = :codigo';               $params[':codigo']      = $codigo; }
    if ($prospectoId !== null) { $where[] = 'o.prospecto_id = :prospecto_id'; $params[':prospecto_id'] = $prospectoId; }
    if ($proyecto   !== null) { $where[] = 'o.proyecto_id = :proyecto_id'; $params[':proyecto_id'] = $proyecto; }
    if ($embudo   !== null) { $where[] = 'o.embudo_id = :embudo_id';     $params[':embudo_id'] = $embudo; }
    if ($etapa    !== null) { $where[] = 'o.etapa_id = :etapa_id';       $params[':etapa_id']  = $etapa; }
    if ($asignado !== null) { $where[] = 'o.asignado = :asignado';       $params[':asignado']  = $asignado; }
    if ($atendido !== null) { $where[] = 'o.atendido = :atendido';       $params[':atendido']  = $atendido; }
    if ($sentido  !== '')   { $where[] = 'o.sentido = :sentido';         $params[':sentido']   = $sentido; }
    if ($origen   !== '')   { $where[] = 'o.origen = :origen';           $params[':origen']    = $origen; }
    if ($desde    !== '')   { $where[] = 'o.ingreso >= :desde';          $params[':desde']     = $desde . ' 00:00:00'; }
    if ($hasta    !== '')   { $where[] = 'o.ingreso <= :hasta';          $params[':hasta']     = $hasta . ' 23:59:59'; }

    if ($search !== '') {
        // El buscador cubria nombre / organizacion / prospecto / correo / celular
        // sobre las columnas legacy de la oportunidad. Ahora esos cuatro campos se
        // buscan sobre `datarocket_prospectos` via JOIN — misma cobertura para el
        // usuario, pero contra la fuente de verdad.
        //
        // PDO con emulate_prepares=false no permite reusar el mismo placeholder
        // en varias posiciones — duplicamos el bind, uno por columna.
        //
        // `o.asunto` sigue cubierto por el buscador: entre la 20260817_1600 y la
        // 20260817_2800 la columna se llamo `comentarios`, y su contenido (asunto
        // fusionado + notas) es el mismo.
        $where[] = '(c.nombre LIKE :s1 OR c.empresa_nombre LIKE :s2 OR c.correo LIKE :s3
                     OR c.celular LIKE :s4
                     OR o.producto LIKE :s5 OR o.asunto LIKE :s6)';
        $like = "%{$search}%";
        $params[':s1'] = $like;  $params[':s2'] = $like;  $params[':s3'] = $like;
        $params[':s4'] = $like;  $params[':s5'] = $like;  $params[':s6'] = $like;
    }

    $sqlWhere = $where ? ('WHERE ' . implode(' AND ', $where)) : '';

    // Stats globales (ignoran filtros — son indicadores del recurso).
    $stats = $pdo->query("
        SELECT
            COUNT(*)                                                            AS total,
            SUM(CASE WHEN atendido IS NULL OR atendido = 0 THEN 1 ELSE 0 END)   AS sin_atender,
            SUM(CASE WHEN asignado IS NOT NULL AND asignado > 0 THEN 1 ELSE 0 END) AS asignados
          FROM datarocket_oportunidades
    ")->fetch();

    jsonOk([
        'stats'    => [
            'total'       => (int)($stats['total']       ?? 0),
            'sin_atender' => (int)($stats['sin_atender'] ?? 0),
            'asignados'   => (int)($stats['asignados']   ?? 0),
        ],
        'forecast' => drOpoForecast($pdo),
        'items'    => drOpoEnrichRows($pdo, drOpoFetchList($pdo, $sqlWhere, $params, $orderCol, $dirSql, $limite)),
    ]);
}

// SELECT del listado. El JOIN a `datarocket_prospectos` existe para que el
// buscador y el ORDER BY por identidad puedan mirar la fuente de verdad; las
// columnas del prospecto NO se traen aca (las resuelve drOpoEnrichRows con un
// unico SELECT por lote, sin multiplicar filas).
function drOpoFetchList(PDO $pdo, string $sqlWhere, array $params,
                        string $orderCol, string $dirSql, int $limite): array {
    $sql = "
        SELECT " . DR_OPO_COLS_O . "
          FROM datarocket_oportunidades o
          LEFT JOIN datarocket_prospectos c ON c.id = o.prospecto_id
          {$sqlWhere}
      ORDER BY {$orderCol} {$dirSql}
         LIMIT {$limite}
    ";
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchAll();
}

// Valor del embudo, agrupado por moneda. NUNCA se suman monedas distintas: un
// total que mezcle pesos y dolares es un numero sin significado, asi que el
// front recibe una fila por moneda y las muestra por separado.
//
//   abierto    suma de `monto` en etapas activas (pipeline bruto)
//   ponderado  suma de `monto * probabilidad / 100` en etapas activas (forecast)
//   ganado     suma de `monto` en etapas tipo 'ganada'
//   perdido    suma de `monto` en etapas tipo 'perdida'
//
// Las oportunidades sin `monto` o sin etapa quedan afuera — no aportan valor y
// contarlas como 0 ensuciaria el promedio. `probabilidad` NULL pondera 0.
function drOpoForecast(PDO $pdo): array {
    $rows = $pdo->query("
        SELECT
            COALESCE(o.moneda, 'ARS') AS moneda,
            SUM(CASE WHEN e.tipo = 'activa'  THEN o.monto ELSE 0 END) AS abierto,
            SUM(CASE WHEN e.tipo = 'activa'
                     THEN o.monto * COALESCE(e.probabilidad, 0) / 100 ELSE 0 END) AS ponderado,
            SUM(CASE WHEN e.tipo = 'ganada'  THEN o.monto ELSE 0 END) AS ganado,
            SUM(CASE WHEN e.tipo = 'perdida' THEN o.monto ELSE 0 END) AS perdido,
            SUM(CASE WHEN e.tipo = 'activa'  THEN 1 ELSE 0 END) AS abiertas,
            SUM(CASE WHEN e.tipo = 'ganada'  THEN 1 ELSE 0 END) AS ganadas,
            SUM(CASE WHEN e.tipo = 'perdida' THEN 1 ELSE 0 END) AS perdidas
          FROM datarocket_oportunidades o
          JOIN datarocket_etapas e ON e.id = o.etapa_id
         WHERE o.monto IS NOT NULL
      GROUP BY COALESCE(o.moneda, 'ARS')
      ORDER BY abierto DESC
    ")->fetchAll();

    return array_map(static fn(array $r): array => [
        'moneda'    => (string)$r['moneda'],
        'abierto'   => (float)$r['abierto'],
        'ponderado' => round((float)$r['ponderado'], 2),
        'ganado'    => (float)$r['ganado'],
        'perdido'   => (float)$r['perdido'],
        'abiertas'  => (int)$r['abiertas'],
        'ganadas'   => (int)$r['ganadas'],
        'perdidas'  => (int)$r['perdidas'],
    ], $rows);
}

function handleGetOneOportunidad(PDO $pdo, int $id): void {
    $stmt = $pdo->prepare("SELECT " . DR_OPO_COLS . " FROM datarocket_oportunidades WHERE id = :id");
    $stmt->execute([':id' => $id]);
    $row = $stmt->fetch();
    if (!$row) jsonError('Oportunidad no encontrada', 404);
    $enriched = drOpoEnrichRows($pdo, [$row]);
    jsonOk($enriched[0]);
}

// ----------------------------------------------------------------------------
// Alta / Modificacion / Baja
// ----------------------------------------------------------------------------

function drOpoNullableStr(mixed $v, ?int $max = null): ?string {
    if ($v === null) return null;
    $s = trim((string)$v);
    if ($s === '') return null;
    if ($max !== null) $s = substr($s, 0, $max);
    return $s;
}

function drOpoNullableInt(mixed $v): ?int {
    if ($v === null || $v === '') return null;
    return (int)$v;
}

// Monto: acepta numero o string. Se toleran los separadores que escribe un
// humano en el form (miles con punto, decimales con coma) porque el input es
// `type="text"` justamente para poder tipear "1.250.000,50". Negativos y basura
// caen a NULL — un negocio no vale menos que cero.
function drOpoNullableMonto(mixed $v): ?float {
    if ($v === null || $v === '') return null;
    if (is_int($v) || is_float($v)) return $v >= 0 ? round((float)$v, 2) : null;
    $s = trim((string)$v);
    if ($s === '') return null;
    // El signo se evalua ANTES de limpiar separadores: la limpieza borra el '-'
    // y "-50" terminaria guardandose como 50.
    if (strpos($s, '-') !== false) return null;
    // "1.250.000,50" -> "1250000.50" ; "1250000.50" queda igual.
    if (strpos($s, ',') !== false) {
        $s = str_replace('.', '', $s);
        $s = str_replace(',', '.', $s);
    }
    $s = preg_replace('/[^0-9.]/', '', $s);
    if ($s === '' || !is_numeric($s)) return null;
    return round((float)$s, 2);
}

// Moneda: se normaliza a mayusculas y se valida contra la whitelist. Un valor
// fuera de la lista cae al default en vez de rechazar el guardado — el campo es
// accesorio y no vale perder el resto del formulario por el.
function drOpoMoneda(mixed $v): string {
    $s = strtoupper(trim((string)($v ?? '')));
    return in_array($s, DR_OPO_MONEDAS, true) ? $s : DR_OPO_MONEDAS[0];
}

// Fecha sola (sin hora) para `cierre_esperado`. Acepta 'YYYY-MM-DD' y tolera
// que venga un datetime completo, del que se queda con la parte de fecha.
function drOpoNullableFecha(mixed $v): ?string {
    $s = drOpoNullableStr($v);
    if ($s === null) return null;
    $s = substr($s, 0, 10);
    $d = DateTime::createFromFormat('Y-m-d', $s);
    return ($d && $d->format('Y-m-d') === $s) ? $s : null;
}

function drOpoNullableDateTime(mixed $v): ?string {
    $s = drOpoNullableStr($v);
    if ($s === null) return null;
    $s = str_replace('T', ' ', $s);
    if (preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}$/', $s)) $s .= ':00';
    if (!preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $s)) return null;
    return $s;
}

function drOpoSanitizePayload(array $in): array {
    return [
        'prospecto_id'   => drOpoNullableInt($in['prospecto_id']        ?? null),
        'ingreso'       => drOpoNullableDateTime($in['ingreso']       ?? null),
        'proyecto_id'   => drOpoNullableInt($in['proyecto_id']        ?? null),
        'sentido'       => drOpoNullableStr($in['sentido']            ?? null, 1),
        'origen'        => drOpoNullableStr($in['origen']             ?? null, 10),
        'producto'      => drOpoNullableStr($in['producto']           ?? null, 100),
        'monto'           => drOpoNullableMonto($in['monto']          ?? null),
        'moneda'          => drOpoMoneda($in['moneda']                ?? null),
        'cierre_esperado' => drOpoNullableFecha($in['cierre_esperado'] ?? null),
        'calificacion'  => drOpoNullableInt($in['calificacion']       ?? null),
        'embudo_id'     => drOpoNullableInt($in['embudo_id']          ?? null),
        'etapa_id'      => drOpoNullableInt($in['etapa_id']           ?? null),
        'etapa_ingreso' => drOpoNullableDateTime($in['etapa_ingreso'] ?? null),
        'asignado'      => drOpoNullableInt($in['asignado']           ?? null),
        'atendido'      => drOpoNullableInt($in['atendido']           ?? null),
        'actualizado'   => drOpoNullableDateTime($in['actualizado']   ?? null),
        'aplazado'      => drOpoNullableDateTime($in['aplazado']      ?? null),
        'asunto'        => drOpoNullableStr($in['asunto']             ?? null, 1000),
    ];
}

// Verifica que la etapa pertenezca al embudo indicado. Levanta jsonError
// (que corta el request) si no. Devuelve el registro de la etapa con
// `embudo_id` para reuso del caller.
function drOpoValidarEtapaEnEmbudo(PDO $pdo, int $etapaId, int $embudoId): array {
    $stmt = $pdo->prepare('SELECT id, embudo_id, nombre FROM datarocket_etapas WHERE id = :id LIMIT 1');
    $stmt->execute([':id' => $etapaId]);
    $row = $stmt->fetch();
    if (!$row) jsonError('La etapa indicada no existe.', 400);
    if ((int)$row['embudo_id'] !== $embudoId) {
        jsonError('La etapa indicada no pertenece al embudo de la oportunidad.', 400);
    }
    return $row;
}

function handleCreateOportunidad(PDO $pdo, array $in): void {
    $p = drOpoSanitizePayload($in);

    // Regla dura del refactor "oportunidad = referencia a prospecto": el alta
    // requiere prospecto_id existente. Sin prospecto_id la oportunidad no tiene
    // identidad (nombre, correo, celular, empresa) — vive solo en las tabs
    // General/Seguimiento/Notas. La UI de "Nueva oportunidad" fuerza al usuario
    // a elegir/crear un prospecto antes de guardar.
    if ($p['prospecto_id'] === null || $p['prospecto_id'] <= 0) {
        jsonError('Falta el prospecto vinculado. Selecciona uno existente o crea uno nuevo antes de guardar.', 400);
    }
    $stmt = $pdo->prepare('SELECT id FROM datarocket_prospectos WHERE id = :id LIMIT 1');
    $stmt->execute([':id' => $p['prospecto_id']]);
    if (!$stmt->fetch()) jsonError('El prospecto indicado no existe.', 400);

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
        drOpoValidarEtapaEnEmbudo($pdo, $p['etapa_id'], $p['embudo_id']);
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

    // Las 12 columnas de identidad legacy ya no existen (migracion 20260816_1500):
    // la identidad se lee del prospecto vinculado. `acciones` tampoco
    // (migracion 20260817_1700).
    $sql = "
        INSERT INTO datarocket_oportunidades
            (prospecto_id, ingreso, proyecto_id, sentido, origen, producto,
             monto, moneda, cierre_esperado,
             calificacion, embudo_id, etapa_id, etapa_ingreso,
             asignado, atendido, actualizado, aplazado, asunto)
        VALUES
            (:prospecto_id, :ingreso, :proyecto_id, :sentido, :origen, :producto,
             :monto, :moneda, :cierre_esperado,
             :calificacion, :embudo_id, :etapa_id, :etapa_ingreso,
             :asignado, :atendido, :actualizado, :aplazado, :asunto)
    ";
    $stmt = $pdo->prepare($sql);
    $stmt->execute([
        ':prospecto_id'   => $p['prospecto_id'],
        ':ingreso'       => $p['ingreso'],
        ':proyecto_id'   => $p['proyecto_id'],
        ':sentido'       => $p['sentido'],
        ':origen'        => $p['origen'],
        ':producto'      => $p['producto'],
        ':monto'           => $p['monto'],
        ':moneda'          => $p['moneda'],
        ':cierre_esperado' => $p['cierre_esperado'],
        ':calificacion'  => $p['calificacion'],
        ':embudo_id'     => $p['embudo_id'],
        ':etapa_id'      => $p['etapa_id'],
        ':etapa_ingreso' => $p['etapa_ingreso'],
        ':asignado'      => $p['asignado'],
        ':atendido'      => $p['atendido'],
        ':actualizado'   => $p['actualizado'],
        ':aplazado'      => $p['aplazado'],
        ':asunto'        => $p['asunto'],
    ]);
    jsonOk(['id' => (int)$pdo->lastInsertId()], 201);
}

function handleUpdateOportunidad(PDO $pdo, int $id, array $in): void {
    $exists = $pdo->prepare('SELECT id, embudo_id, etapa_id FROM datarocket_oportunidades WHERE id = :id');
    $exists->execute([':id' => $id]);
    $prev = $exists->fetch();
    if (!$prev) jsonError('Oportunidad no encontrada', 404);

    $p = drOpoSanitizePayload($in);
    if ($p['actualizado'] === null) {
        $p['actualizado'] = (new DateTime('now', new DateTimeZone('America/Argentina/Buenos_Aires')))
                            ->format('Y-m-d H:i:s');
    }

    // Consistencia embudo/etapa contra el estado final (con lo que quede
    // despues de aplicar el payload).
    $embudoFinal = array_key_exists('embudo_id', $in) ? $p['embudo_id'] : (int)$prev['embudo_id'];
    $etapaFinal  = array_key_exists('etapa_id',  $in) ? $p['etapa_id']  : (int)$prev['etapa_id'];
    if ($embudoFinal && $etapaFinal) {
        drOpoValidarEtapaEnEmbudo($pdo, $etapaFinal, $embudoFinal);
    }

    // Si cambio la etapa, refrescamos etapa_ingreso (a menos que el cliente lo
    // haya seteado explicitamente en el payload).
    if (array_key_exists('etapa_id', $in) && (int)$prev['etapa_id'] !== (int)$p['etapa_id']
        && !array_key_exists('etapa_ingreso', $in)) {
        $p['etapa_ingreso'] = $p['actualizado'];
    }

    // NO permitimos re-vincular prospecto_id en un UPDATE. Decision de producto:
    // "esta oportunidad ahora es de otra persona" no tiene sentido comercial;
    // si el vendedor detecta el error debe borrar y crear de nuevo. Cualquier
    // prospecto_id en el payload se ignora.
    //
    // Las 12 columnas de identidad legacy ya no existen (migracion 20260816_1500)
    // — la fuente de verdad son las columnas equivalentes de
    // `datarocket_prospectos`. Para modificar esa data hay que editar el prospecto
    // vinculado.
    $sql = "
        UPDATE datarocket_oportunidades SET
            ingreso         = :ingreso,
            proyecto_id     = :proyecto_id,
            sentido         = :sentido,
            origen          = :origen,
            producto        = :producto,
            monto           = :monto,
            moneda          = :moneda,
            cierre_esperado = :cierre_esperado,
            calificacion  = :calificacion,
            embudo_id     = :embudo_id,
            etapa_id      = :etapa_id,
            etapa_ingreso = :etapa_ingreso,
            asignado      = :asignado,
            atendido      = :atendido,
            actualizado   = :actualizado,
            aplazado      = :aplazado,
            asunto        = :asunto
        WHERE id = :id
    ";
    $stmt = $pdo->prepare($sql);
    $stmt->execute([
        ':ingreso'       => $p['ingreso'],
        ':proyecto_id'   => $p['proyecto_id'],
        ':sentido'       => $p['sentido'],
        ':origen'        => $p['origen'],
        ':producto'      => $p['producto'],
        ':monto'           => $p['monto'],
        ':moneda'          => $p['moneda'],
        ':cierre_esperado' => $p['cierre_esperado'],
        ':calificacion'  => $p['calificacion'],
        ':embudo_id'     => $p['embudo_id'],
        ':etapa_id'      => $p['etapa_id'],
        ':etapa_ingreso' => $p['etapa_ingreso'],
        ':asignado'      => $p['asignado'],
        ':atendido'      => $p['atendido'],
        ':actualizado'   => $p['actualizado'],
        ':aplazado'      => $p['aplazado'],
        ':asunto'        => $p['asunto'],
        ':id'            => $id,
    ]);
    jsonOk(['id' => $id]);
}

function handleDeleteOportunidad(PDO $pdo, int $id): void {
    $stmt = $pdo->prepare('DELETE FROM datarocket_oportunidades WHERE id = :id');
    $stmt->execute([':id' => $id]);
    if ($stmt->rowCount() === 0) jsonError('Oportunidad no encontrada', 404);
    jsonOk(['id' => $id]);
}

// Movimiento de la oportunidad entre etapas del kanban (equivalente a arrastrar
// una tarjeta de una columna a otra). Solo requiere `etapa_id`; el embudo se
// mantiene y se valida que la etapa destino pertenezca a el. Setea
// `etapa_ingreso = NOW()` para poder medir tiempo-en-etapa.
function handleCambiarEtapaOportunidad(PDO $pdo, int $id, array $in): void {
    $etapaId = isset($in['etapa_id']) ? (int)$in['etapa_id'] : 0;
    if ($etapaId <= 0) jsonError('Falta etapa_id', 400);

    $stmt = $pdo->prepare('SELECT id, embudo_id, etapa_id FROM datarocket_oportunidades WHERE id = :id');
    $stmt->execute([':id' => $id]);
    $prev = $stmt->fetch();
    if (!$prev) jsonError('Oportunidad no encontrada', 404);
    if (empty($prev['embudo_id'])) jsonError('La oportunidad no tiene embudo asignado.', 409);

    drOpoValidarEtapaEnEmbudo($pdo, $etapaId, (int)$prev['embudo_id']);

    if ((int)$prev['etapa_id'] === $etapaId) {
        // Idempotente: si ya esta en la etapa destino, no hacemos nada mas
        // que devolver el estado actual.
        jsonOk(['id' => $id, 'etapa_id' => $etapaId, 'cambio' => false]);
        return;
    }

    $now = (new DateTime('now', new DateTimeZone('America/Argentina/Buenos_Aires')))
            ->format('Y-m-d H:i:s');

    $upd = $pdo->prepare(
        'UPDATE datarocket_oportunidades
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
