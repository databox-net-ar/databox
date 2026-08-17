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

// Fase 3 del refactor "prospectos = referencia a contacto" COMPLETADA
// (migracion 20260816_1500): las 12 columnas de identidad del prospecto
// (nombre, contacto, celular, correo, web, organizacion, domicilio, ciudad,
// localidad, provincia, pais, ubicacion) ya NO existen en la tabla. La fuente
// de verdad es `datarocket_contactos` via `contacto_id`, y el frontend consume
// los `contacto_*` derivados del JOIN (ver drProEnrichRows).
//
// `asunto` y `acciones` tampoco existen mas (migracion 20260817_1700): el
// asunto se fusiono como primera linea de `comentarios` (20260817_1600) y el
// log libre de `acciones` quedo reemplazado por `datarocket_interacciones`.
const DR_PRO_COLS = "id, contacto_id, ingreso, proyecto_id, sentido, origen, tipo, producto,
                     monto, moneda, cierre_esperado, calificacion, estado,
                     embudo_id, etapa_id, etapa_ingreso, asignado, atendido,
                     actualizado, aplazado, comentarios";

// Misma lista calificada con el alias `p`. El listado joinea con
// `datarocket_contactos` (buscador + orden por identidad) y ahi `id`, `tipo`,
// `correo` y varias mas son ambiguas sin prefijo.
const DR_PRO_COLS_P = "p.id, p.contacto_id, p.ingreso, p.proyecto_id, p.sentido, p.origen,
                       p.tipo, p.producto, p.monto, p.moneda, p.cierre_esperado,
                       p.calificacion, p.estado, p.embudo_id, p.etapa_id, p.etapa_ingreso,
                       p.asignado, p.atendido, p.actualizado, p.aplazado, p.comentarios";

const DR_PRO_COMBO_CAMPOS = ['sentido', 'origen', 'tipo', 'estado', 'producto'];
const DR_PRO_CAMPO_PREFIX = 'datasale_prospecto_';

// `moneda` es un campo propio de Datarocket (el ABM legacy de Datasale no
// maneja importes), asi que su catalogo NO reusa el prefijo heredado de arriba.
// Ver migracion 20260816_1300.
const DR_PRO_COMBO_CAMPOS_PROPIOS = ['moneda'];
const DR_PRO_CAMPO_PREFIX_PROPIO  = 'datarocket_prospecto_';

// Monedas aceptadas en el payload (ISO-4217). Se valida contra esta lista y no
// contra `estados` para que un catalogo mal cargado no abra la puerta a basura
// en la columna.
const DR_PRO_MONEDAS = ['ARS', 'USD'];

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

function drProEstadosMap(PDO $pdo): array {
    static $cache = null;
    if ($cache !== null) return $cache;
    $cache = [];
    $stmt = $pdo->prepare(
        "SELECT campo, valor, texto FROM estados
          WHERE campo LIKE :prefix OR campo LIKE :prefix_propio"
    );
    $stmt->execute([
        ':prefix'        => DR_PRO_CAMPO_PREFIX . '%',
        ':prefix_propio' => DR_PRO_CAMPO_PREFIX_PROPIO . '%',
    ]);
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
                web, domicilio, ciudad, localidad_id, provincia_id, pais_id, ubicacion
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

// Etapas con sus tres columnas relevantes. No reusa drProFetchLookupByIds
// (que solo trae `nombre`) porque el forecast necesita `tipo`
// (activa / ganada / perdida) y `probabilidad` para ponderar el monto.
function drProFetchEtapasByIds(PDO $pdo, array $ids): array {
    if (!$ids) return [];
    $placeholders = implode(',', array_fill(0, count($ids), '?'));
    $stmt = $pdo->prepare(
        "SELECT id, nombre, tipo, probabilidad
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
    $etapas    = drProFetchEtapasByIds($pdo,                        array_keys($etaIds));
    $contactos = drProFetchContactosByIds($pdo,                     array_keys($ctIds));
    $estados   = drProEstadosMap($pdo);

    // La ubicacion del contacto ahora son FKs a los catalogos (migracion
    // 20260815_1000). Se resuelven a nombre con un SELECT por catalogo, acotado
    // a los ids que realmente aparecen en estas filas.
    $ctPaisIds = $ctProvIds = $ctLocIds = [];
    foreach ($contactos as $c) {
        if (!empty($c['pais_id']))      $ctPaisIds[(int)$c['pais_id']]      = true;
        if (!empty($c['provincia_id'])) $ctProvIds[(int)$c['provincia_id']] = true;
        if (!empty($c['localidad_id'])) $ctLocIds[(int)$c['localidad_id']]  = true;
    }
    $ctPaises      = drProFetchLookupByIds($pdo, 'paises',      array_keys($ctPaisIds));
    $ctProvincias  = drProFetchLookupByIds($pdo, 'provincias',  array_keys($ctProvIds));
    $ctLocalidades = drProFetchLookupByIds($pdo, 'localidades', array_keys($ctLocIds));

    $out = [];
    foreach ($rows as $r) {
        $r['proyecto_nombre'] = !empty($r['proyecto_id']) ? ($proyectos[(int)$r['proyecto_id']] ?? null) : null;
        $r['asignado_nombre'] = !empty($r['asignado'])    ? ($usuarios[(int)$r['asignado']]     ?? null) : null;
        $r['atendido_nombre'] = !empty($r['atendido'])    ? ($usuarios[(int)$r['atendido']]     ?? null) : null;
        $r['embudo_nombre']   = !empty($r['embudo_id'])   ? ($embudos[(int)$r['embudo_id']]     ?? null) : null;

        // Etapa: ademas del nombre se exponen `tipo` y `probabilidad`, que son
        // los que convierten `monto` en pipeline ponderado. `monto_ponderado`
        // se calcula solo para etapas activas: una oportunidad ganada ya vale
        // su monto completo y una perdida vale cero, ponderarlas seria mentir
        // sobre el forecast.
        $e = !empty($r['etapa_id']) ? ($etapas[(int)$r['etapa_id']] ?? null) : null;
        $r['etapa_nombre']       = $e ? (string)$e['nombre'] : null;
        $r['etapa_tipo']         = $e ? (string)$e['tipo']   : null;
        $r['etapa_probabilidad'] = $e && $e['probabilidad'] !== null ? (int)$e['probabilidad'] : null;

        $r['monto'] = $r['monto'] !== null ? (float)$r['monto'] : null;
        $r['monto_ponderado'] = ($r['monto'] !== null && $r['etapa_tipo'] === 'activa'
                                 && $r['etapa_probabilidad'] !== null)
            ? round($r['monto'] * $r['etapa_probabilidad'] / 100, 2)
            : null;

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
        // Se exponen resueltos a nombre (antes viajaba el ID crudo, que el
        // frontend pintaba tal cual en la tarjeta de detalle).
        $r['contacto_localidad'] = $c && !empty($c['localidad_id']) ? ($ctLocalidades[(int)$c['localidad_id']] ?? null) : null;
        $r['contacto_provincia'] = $c && !empty($c['provincia_id']) ? ($ctProvincias[(int)$c['provincia_id']]  ?? null) : null;
        $r['contacto_pais']      = $c && !empty($c['pais_id'])      ? ($ctPaises[(int)$c['pais_id']]           ?? null) : null;
        $r['contacto_ubicacion'] = $c ? ($c['ubicacion'] !== '' ? (string)$c['ubicacion'] : null) : null;

        foreach (DR_PRO_COMBO_CAMPOS as $c2) {
            $v = $r[$c2] ?? null;
            $r["{$c2}_texto"] = ($v !== null && $v !== '')
                ? ($estados[DR_PRO_CAMPO_PREFIX . $c2 . '|' . (string)$v] ?? null)
                : null;
        }
        foreach (DR_PRO_COMBO_CAMPOS_PROPIOS as $c3) {
            $v = $r[$c3] ?? null;
            $r["{$c3}_texto"] = ($v !== null && $v !== '')
                ? ($estados[DR_PRO_CAMPO_PREFIX_PROPIO . $c3 . '|' . (string)$v] ?? null)
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
    // renderizar el select dependiente. Trae `tipo` y `probabilidad` para que
    // el modal pueda mostrarlas al lado del nombre.
    $etapas = $pdo->query(
        'SELECT id, embudo_id, nombre, orden, color, tipo, probabilidad
           FROM datarocket_etapas
       ORDER BY embudo_id ASC, orden ASC, id ASC'
    )->fetchAll();

    // Opciones de combos: los heredados salen del catalogo `datasale_prospecto_*`
    // y los propios de Datarocket (hoy solo `moneda`) de `datarocket_prospecto_*`.
    $stmt = $pdo->prepare("
        SELECT campo, valor, texto, orden
          FROM estados
         WHERE campo LIKE :prefix OR campo LIKE :prefix_propio
      ORDER BY campo, COALESCE(orden, 0), id
    ");
    $stmt->execute([
        ':prefix'        => DR_PRO_CAMPO_PREFIX . '%',
        ':prefix_propio' => DR_PRO_CAMPO_PREFIX_PROPIO . '%',
    ]);
    $opciones = array_fill_keys(
        array_merge(DR_PRO_COMBO_CAMPOS, DR_PRO_COMBO_CAMPOS_PROPIOS),
        []
    );
    foreach ($stmt->fetchAll() as $r) {
        $prefijo = str_starts_with($r['campo'], DR_PRO_CAMPO_PREFIX_PROPIO)
            ? DR_PRO_CAMPO_PREFIX_PROPIO
            : DR_PRO_CAMPO_PREFIX;
        $key = substr($r['campo'], strlen($prefijo));
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

    // `organizacion` y `nombre` salieron del listado de ordenables: las columnas
    // ya no existen (migracion 20260816_1500). Para ordenar por identidad ahora
    // hay que usar el nombre del contacto, que vive en el JOIN — se expone como
    // `contacto_nombre`.
    $allowedOrder = ['id', 'ingreso', 'proyecto_id', 'embudo_id', 'etapa_id', 'etapa_ingreso',
                     'sentido', 'origen', 'tipo', 'producto', 'monto', 'cierre_esperado',
                     'estado', 'calificacion', 'asignado', 'atendido', 'actualizado', 'aplazado',
                     'contacto_nombre'];
    if (!in_array($orderBy, $allowedOrder, true)) $orderBy = 'id';
    $dirSql = $dir === 'asc' ? 'ASC' : 'DESC';
    // Todas las columnas ordenables son de `p` salvo la del contacto joineado.
    $orderCol = $orderBy === 'contacto_nombre' ? 'c.nombre' : ('p.' . $orderBy);

    $where  = [];
    $params = [];

    if ($codigo     !== null) { $where[] = 'p.id = :codigo';               $params[':codigo']      = $codigo; }
    if ($contactoId !== null) { $where[] = 'p.contacto_id = :contacto_id'; $params[':contacto_id'] = $contactoId; }
    if ($proyecto   !== null) { $where[] = 'p.proyecto_id = :proyecto_id'; $params[':proyecto_id'] = $proyecto; }
    if ($embudo   !== null) { $where[] = 'p.embudo_id = :embudo_id';     $params[':embudo_id'] = $embudo; }
    if ($etapa    !== null) { $where[] = 'p.etapa_id = :etapa_id';       $params[':etapa_id']  = $etapa; }
    if ($asignado !== null) { $where[] = 'p.asignado = :asignado';       $params[':asignado']  = $asignado; }
    if ($atendido !== null) { $where[] = 'p.atendido = :atendido';       $params[':atendido']  = $atendido; }
    if ($estado   !== null) { $where[] = 'p.estado = :estado';           $params[':estado']    = $estado; }
    if ($sentido  !== '')   { $where[] = 'p.sentido = :sentido';         $params[':sentido']   = $sentido; }
    if ($tipo     !== '')   { $where[] = 'p.tipo = :tipo';               $params[':tipo']      = $tipo; }
    if ($origen   !== '')   { $where[] = 'p.origen = :origen';           $params[':origen']    = $origen; }
    if ($desde    !== '')   { $where[] = 'p.ingreso >= :desde';          $params[':desde']     = $desde . ' 00:00:00'; }
    if ($hasta    !== '')   { $where[] = 'p.ingreso <= :hasta';          $params[':hasta']     = $hasta . ' 23:59:59'; }

    if ($search !== '') {
        // El buscador cubria nombre / organizacion / contacto / correo / celular
        // sobre las columnas legacy del prospecto. Ahora esos cuatro campos se
        // buscan sobre `datarocket_contactos` via JOIN — misma cobertura para el
        // usuario, pero contra la fuente de verdad.
        //
        // PDO con emulate_prepares=false no permite reusar el mismo placeholder
        // en varias posiciones — duplicamos el bind, uno por columna.
        //
        // `p.asunto` salio del buscador (migracion 20260817_1700): su contenido
        // vive ahora en `p.comentarios`, que ya estaba cubierto.
        $where[] = '(c.nombre LIKE :s1 OR c.empresa LIKE :s2 OR c.correo LIKE :s3
                     OR c.celular LIKE :s4
                     OR p.producto LIKE :s5 OR p.comentarios LIKE :s6)';
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
          FROM datarocket_prospectos
    ")->fetch();

    jsonOk([
        'stats'    => [
            'total'       => (int)($stats['total']       ?? 0),
            'sin_atender' => (int)($stats['sin_atender'] ?? 0),
            'asignados'   => (int)($stats['asignados']   ?? 0),
        ],
        'forecast' => drProForecast($pdo),
        'items'    => drProEnrichRows($pdo, drProFetchList($pdo, $sqlWhere, $params, $orderCol, $dirSql, $limite)),
    ]);
}

// SELECT del listado. El JOIN a `datarocket_contactos` existe para que el
// buscador y el ORDER BY por identidad puedan mirar la fuente de verdad; las
// columnas del contacto NO se traen aca (las resuelve drProEnrichRows con un
// unico SELECT por lote, sin multiplicar filas).
function drProFetchList(PDO $pdo, string $sqlWhere, array $params,
                        string $orderCol, string $dirSql, int $limite): array {
    $sql = "
        SELECT " . DR_PRO_COLS_P . "
          FROM datarocket_prospectos p
          LEFT JOIN datarocket_contactos c ON c.id = p.contacto_id
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
function drProForecast(PDO $pdo): array {
    $rows = $pdo->query("
        SELECT
            COALESCE(p.moneda, 'ARS') AS moneda,
            SUM(CASE WHEN e.tipo = 'activa'  THEN p.monto ELSE 0 END) AS abierto,
            SUM(CASE WHEN e.tipo = 'activa'
                     THEN p.monto * COALESCE(e.probabilidad, 0) / 100 ELSE 0 END) AS ponderado,
            SUM(CASE WHEN e.tipo = 'ganada'  THEN p.monto ELSE 0 END) AS ganado,
            SUM(CASE WHEN e.tipo = 'perdida' THEN p.monto ELSE 0 END) AS perdido,
            SUM(CASE WHEN e.tipo = 'activa'  THEN 1 ELSE 0 END) AS abiertas,
            SUM(CASE WHEN e.tipo = 'ganada'  THEN 1 ELSE 0 END) AS ganadas,
            SUM(CASE WHEN e.tipo = 'perdida' THEN 1 ELSE 0 END) AS perdidas
          FROM datarocket_prospectos p
          JOIN datarocket_etapas e ON e.id = p.etapa_id
         WHERE p.monto IS NOT NULL
      GROUP BY COALESCE(p.moneda, 'ARS')
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

// Monto: acepta numero o string. Se toleran los separadores que escribe un
// humano en el form (miles con punto, decimales con coma) porque el input es
// `type="text"` justamente para poder tipear "1.250.000,50". Negativos y basura
// caen a NULL — un negocio no vale menos que cero.
function drProNullableMonto(mixed $v): ?float {
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
function drProMoneda(mixed $v): string {
    $s = strtoupper(trim((string)($v ?? '')));
    return in_array($s, DR_PRO_MONEDAS, true) ? $s : DR_PRO_MONEDAS[0];
}

// Fecha sola (sin hora) para `cierre_esperado`. Acepta 'YYYY-MM-DD' y tolera
// que venga un datetime completo, del que se queda con la parte de fecha.
function drProNullableFecha(mixed $v): ?string {
    $s = drProNullableStr($v);
    if ($s === null) return null;
    $s = substr($s, 0, 10);
    $d = DateTime::createFromFormat('Y-m-d', $s);
    return ($d && $d->format('Y-m-d') === $s) ? $s : null;
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
        'monto'           => drProNullableMonto($in['monto']          ?? null),
        'moneda'          => drProMoneda($in['moneda']                ?? null),
        'cierre_esperado' => drProNullableFecha($in['cierre_esperado'] ?? null),
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

    // Las 12 columnas de identidad legacy ya no existen (migracion 20260816_1500):
    // la identidad se lee del contacto vinculado. `asunto` y `acciones` tampoco
    // (migracion 20260817_1700).
    $sql = "
        INSERT INTO datarocket_prospectos
            (contacto_id, ingreso, proyecto_id, sentido, origen, tipo, producto,
             monto, moneda, cierre_esperado,
             calificacion, estado, embudo_id, etapa_id, etapa_ingreso,
             asignado, atendido, actualizado, aplazado, comentarios)
        VALUES
            (:contacto_id, :ingreso, :proyecto_id, :sentido, :origen, :tipo, :producto,
             :monto, :moneda, :cierre_esperado,
             :calificacion, :estado, :embudo_id, :etapa_id, :etapa_ingreso,
             :asignado, :atendido, :actualizado, :aplazado, :comentarios)
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
        ':monto'           => $p['monto'],
        ':moneda'          => $p['moneda'],
        ':cierre_esperado' => $p['cierre_esperado'],
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
    // Las 12 columnas de identidad legacy ya no existen (migracion 20260816_1500)
    // — la fuente de verdad son las columnas equivalentes de
    // `datarocket_contactos`. Para modificar esa data hay que editar el contacto
    // vinculado.
    $sql = "
        UPDATE datarocket_prospectos SET
            ingreso         = :ingreso,
            proyecto_id     = :proyecto_id,
            sentido         = :sentido,
            origen          = :origen,
            tipo            = :tipo,
            producto        = :producto,
            monto           = :monto,
            moneda          = :moneda,
            cierre_esperado = :cierre_esperado,
            calificacion  = :calificacion,
            estado        = :estado,
            embudo_id     = :embudo_id,
            etapa_id      = :etapa_id,
            etapa_ingreso = :etapa_ingreso,
            asignado      = :asignado,
            atendido      = :atendido,
            actualizado   = :actualizado,
            aplazado      = :aplazado,
            comentarios   = :comentarios
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
        ':monto'           => $p['monto'],
        ':moneda'          => $p['moneda'],
        ':cierre_esperado' => $p['cierre_esperado'],
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
