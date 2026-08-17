<?php
// api/datarocketcontactos.php
// ABM de contactos Datarocket. Lee/escribe sobre la tabla `datarocket_contactos`
// definida en db/schema.sql.
//   GET    api/datarocketcontactos.php          -> listado con filtros (query string)
//   GET    api/datarocketcontactos.php?id=N     -> registro individual
//   GET    api/datarocketcontactos.php?lookups=1                 -> paises (selects)
//   GET    api/datarocketcontactos.php?provincias=1&pais=N       -> provincias del pais
//   GET    api/datarocketcontactos.php?localidades=1&provincia=N -> localidades de la provincia
//   POST   api/datarocketcontactos.php          -> alta (JSON body)
//   PUT    api/datarocketcontactos.php?id=N     -> modificacion (JSON body)
//   DELETE api/datarocketcontactos.php?id=N     -> baja (en cascada: interacciones + prospectos)
// Respuesta siempre {ok: true, data: ...} u {ok: false, error: '...'} (STACK.md sec. 10).
//
// Normalizacion: `telefono` / `celular` / `whatsapp` se guardan como 10
// digitos argentinos, `correo` en minuscula validada y `web` como host + path
// sin esquema — reglas en lib/contactos_normalizar.php, compartidas con el
// endpoint v4. Un telefono que no se pueda llevar a 10 digitos se guarda
// igual, en digitos crudos; un correo del que no se pueda extraer una
// direccion valida se rechaza con 400; una `web` que no sea una URL va a NULL,
// salvo que sea un correo y `correo` este vacio (ahi se rescata).
//
// Buscador rapido (`?q=`): matchea nombre / empresa_nombre / correo / telefono /
// celular / whatsapp / persona_dni / uuid del contacto, y ademas el nombre de las
// etiquetas asignadas y de las listas suscriptas (via las puentes
// `datarocket_contactos_etiquetas` / `datarocket_contactos_listas`). Los
// filtros `etiqueta_id` / `lista_id` son otra cosa: ID exacto y en AND.
//
// Filtros del listado por campo: `codigo`, `tipo`, `nombre`, `etiqueta_id`,
// `lista_id`, `pais_id`, `provincia_id`, `correo`, `celular`, `web`, `desde`,
// `hasta`. Los de texto son LIKE %valor%; los de catalogo, ID exacto.
//
// `etiqueta_id` y `lista_id` son MULTI-VALOR (el modal de filtros los pinta
// con un picker de chips + typeahead, no con un select). Aceptan '5', '5,14'
// o el parametro repetido — ver drCtFiltroIds(). Con varios valores la
// semantica es OR dentro del campo ("cualquiera de estas etiquetas") y AND
// entre campos ("alguna de estas etiquetas Y alguna de estas listas").
//
// Ubicacion: `pais_id` / `provincia_id` / `localidad_id` son INT con FK a
// `paises` / `provincias` / `localidades` (migracion 20260815_1000). Antes eran
// VARCHAR con el mismo contenido (el ID como texto) y sin integridad. El
// endpoint expone ademas `pais_nombre` / `provincia_nombre` / `localidad_nombre`
// resueltos por lookup batch, para que el frontend no tenga que pedir el
// catalogo entero (`localidades` tiene ~94k filas).

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/lib/auth_check.php';
require_once __DIR__ . '/lib/contactos_normalizar.php';

const DR_CT_COLS = "id, uuid, tipo, nombre,
                    empresa_nombre, empresa_rubro, empresa_actividad, empresa_cargo,
                    persona_nombre, persona_genero, persona_nacimiento, persona_dni,
                    domicilio, ciudad, ubicacion,
                    localidad_id, provincia_id, pais_id, telefono, celular, whatsapp, correo,
                    web, facebook, instagram, tiktok, comentarios, registrado";

// Valores validos para `datarocket_contactos.tipo`. Filas historicas quedan
// en NULL hasta ser editadas (el ABM las obliga a elegir tipo al guardar).
const DR_CT_TIPOS_VALIDOS = ['persona', 'empresa'];

header('Content-Type: application/json; charset=utf-8');

try {
    requirePermCrud('datarocket.contactos');
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
// Catalogo geografico (paises / provincias / localidades)
// ----------------------------------------------------------------------------

// Mismo contrato que los lookups de `datasaleprospectos.php`: el select de
// paises viaja en `?lookups=1` y los dependientes se piden por demanda. No se
// mandan las ~94k localidades de una.
function handleLookups(PDO $pdo): void {
    $paises = $pdo->query('SELECT id, nombre FROM paises ORDER BY nombre')->fetchAll();
    jsonOk([
        'paises' => array_map(
            fn($r) => ['id' => (int)$r['id'], 'nombre' => (string)$r['nombre']],
            $paises
        ),
    ]);
}

function handleProvincias(PDO $pdo, int $pais): void {
    $stmt = $pdo->prepare('SELECT id, nombre FROM provincias WHERE pais_id = :p ORDER BY nombre');
    $stmt->execute([':p' => $pais]);
    jsonOk(array_map(
        fn($r) => ['id' => (int)$r['id'], 'nombre' => (string)$r['nombre']],
        $stmt->fetchAll()
    ));
}

function handleLocalidades(PDO $pdo, int $provincia): void {
    $stmt = $pdo->prepare('SELECT id, nombre FROM localidades WHERE provincia_id = :p ORDER BY nombre');
    $stmt->execute([':p' => $provincia]);
    jsonOk(array_map(
        fn($r) => ['id' => (int)$r['id'], 'nombre' => (string)$r['nombre']],
        $stmt->fetchAll()
    ));
}

// Diccionario [id => nombre] de una tabla de catalogo, limitado a los ids que
// aparecen en las filas que se van a devolver. Mismo helper (y misma whitelist
// defensiva) que `fetchLookupByIds` en datasaleprospectos.php.
function drCtFetchLookupByIds(PDO $pdo, string $table, array $ids): array {
    if (!$ids) return [];
    $whitelist = ['paises', 'provincias', 'localidades'];
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

// Anexa `pais_nombre` / `provincia_nombre` / `localidad_nombre` a las filas,
// con un SELECT por catalogo (no N+1). Las columnas `*_id` quedan como estan:
// son la fuente de verdad y lo que el formulario manda de vuelta.
function attachUbicacionNombres(PDO $pdo, array &$rows): void {
    if (!$rows) return;
    $paisIds = $provIds = $locIds = [];
    foreach ($rows as $r) {
        if (!empty($r['pais_id']))      $paisIds[(int)$r['pais_id']]      = true;
        if (!empty($r['provincia_id'])) $provIds[(int)$r['provincia_id']] = true;
        if (!empty($r['localidad_id'])) $locIds[(int)$r['localidad_id']]  = true;
    }
    $paises      = drCtFetchLookupByIds($pdo, 'paises',      array_keys($paisIds));
    $provincias  = drCtFetchLookupByIds($pdo, 'provincias',  array_keys($provIds));
    $localidades = drCtFetchLookupByIds($pdo, 'localidades', array_keys($locIds));

    foreach ($rows as &$r) {
        $r['pais_id']          = $r['pais_id']      !== null ? (int)$r['pais_id']      : null;
        $r['provincia_id']     = $r['provincia_id'] !== null ? (int)$r['provincia_id'] : null;
        $r['localidad_id']     = $r['localidad_id'] !== null ? (int)$r['localidad_id'] : null;
        $r['pais_nombre']      = $r['pais_id']      !== null ? ($paises[$r['pais_id']]           ?? null) : null;
        $r['provincia_nombre'] = $r['provincia_id'] !== null ? ($provincias[$r['provincia_id']]  ?? null) : null;
        $r['localidad_nombre'] = $r['localidad_id'] !== null ? ($localidades[$r['localidad_id']] ?? null) : null;
    }
}

// ----------------------------------------------------------------------------
// Listado y stats
// ----------------------------------------------------------------------------

function handleList(PDO $pdo, array $q): void {
    $codigo       = isset($q['codigo'])      && $q['codigo']      !== '' ? (int)$q['codigo']      : null;
    // `lista_id` / `etiqueta_id` son MULTI-VALOR: aceptan un id solo ('5'), un
    // CSV ('5,14' — lo que manda el picker de chips del modal de filtros) o
    // repeticiones del parametro. Ver drCtFiltroIds().
    $listaIds     = drCtFiltroIds($q['lista_id']    ?? null);
    $etiquetaIds  = drCtFiltroIds($q['etiqueta_id'] ?? null);
    $tipo         = trim((string)($q['tipo']         ?? ''));
    $nombre       = trim((string)($q['nombre']        ?? ''));
    $genero       = trim((string)($q['persona_genero']       ?? ''));
    // Ubicacion: los filtros viajan como ID de catalogo (el ABM los pinta con
    // selects). Se aceptan las claves legacy `pais` / `provincia` porque el
    // contenido era el mismo ID cuando las columnas eran VARCHAR.
    $paisId       = drCtFiltroId($q['pais_id']      ?? $q['pais']      ?? null);
    $provinciaId  = drCtFiltroId($q['provincia_id'] ?? $q['provincia'] ?? null);
    $correo       = trim((string)($q['correo']       ?? ''));
    $celular      = trim((string)($q['celular']      ?? ''));
    $web          = trim((string)($q['web']          ?? ''));
    $desde        = trim((string)($q['desde']        ?? ''));
    $hasta        = trim((string)($q['hasta']        ?? ''));
    $search       = trim((string)($q['q']            ?? ''));

    $orderBy = $q['order_by'] ?? 'id';
    $dir     = strtolower((string)($q['dir'] ?? 'desc'));
    $limite  = isset($q['limite']) ? (int)$q['limite'] : 100;
    if ($limite < 1)    $limite = 1;
    if ($limite > 1000) $limite = 1000;

    $allowedOrder = ['id', 'nombre', 'empresa_nombre', 'correo', 'registrado',
                     'pais_id', 'provincia_id'];
    // Alias legacy: el ABM viejo ordenaba por 'pais' / 'provincia'.
    if ($orderBy === 'pais')      $orderBy = 'pais_id';
    if ($orderBy === 'provincia') $orderBy = 'provincia_id';
    if (!in_array($orderBy, $allowedOrder, true)) $orderBy = 'id';
    $dirSql = $dir === 'asc' ? 'ASC' : 'DESC';

    $where  = [];
    $params = [];

    if ($codigo       !== null) { $where[] = 'id = :codigo';                     $params[':codigo']       = $codigo; }
    // Suscripcion a listas de distribucion — la relacion vive en
    // datarocket_contactos_listas (PK compuesta contacto_id + lista_id, FKs
    // con CASCADE). EXISTS evita duplicados y no obliga a aliasar la tabla
    // principal.
    //
    // SEMANTICA CON VARIAS SELECCIONADAS: OR dentro del campo (el IN), o sea
    // "que este en CUALQUIERA de estas listas" = union de audiencias. Es lo
    // que espera un filtro facetado y lo unico util en la practica: con AND,
    // elegir dos listas devolveria casi siempre cero (un contacto rara vez
    // esta en varias a la vez) y se leeria como que el filtro esta roto.
    // Entre Listas y Etiquetas, en cambio, se combina con AND — son dos
    // condiciones distintas del mismo query.
    if ($listaIds) {
        $ph = [];
        foreach ($listaIds as $i => $lid) {
            $k = ":lista_id{$i}";
            $ph[] = $k;
            $params[$k] = $lid;
        }
        $where[] = 'EXISTS (SELECT 1 FROM datarocket_contactos_listas dcl
                            WHERE dcl.contacto_id = datarocket_contactos.id
                              AND dcl.lista_id IN (' . implode(',', $ph) . '))';
    }
    // Etiquetas asignadas al contacto, via `datarocket_contactos_etiquetas`.
    // Mismo patron y misma semantica OR-dentro/AND-entre que las listas.
    if ($etiquetaIds) {
        $ph = [];
        foreach ($etiquetaIds as $i => $eid) {
            $k = ":etiqueta_id{$i}";
            $ph[] = $k;
            $params[$k] = $eid;
        }
        $where[] = 'EXISTS (SELECT 1 FROM datarocket_contactos_etiquetas dce
                            WHERE dce.contacto_id = datarocket_contactos.id
                              AND dce.etiqueta_id IN (' . implode(',', $ph) . '))';
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
    if ($genero       !== '')   { $where[] = 'persona_genero = :persona_genero'; $params[':persona_genero'] = $genero; }
    if ($paisId      !== null)  { $where[] = 'pais_id = :pais_id';               $params[':pais_id']      = $paisId; }
    if ($provinciaId !== null)  { $where[] = 'provincia_id = :provincia_id';     $params[':provincia_id'] = $provinciaId; }
    if ($nombre       !== '')   { $where[] = 'nombre LIKE :nombre';              $params[':nombre']       = '%' . $nombre . '%'; }
    if ($correo       !== '')   { $where[] = 'correo LIKE :correo';              $params[':correo']       = '%' . $correo . '%'; }
    if ($celular      !== '')   { $where[] = 'celular LIKE :celular';            $params[':celular']      = '%' . $celular . '%'; }
    if ($web          !== '')   { $where[] = 'web LIKE :web';                    $params[':web']          = '%' . $web . '%'; }
    if ($desde        !== '')   { $where[] = 'registrado >= :desde';             $params[':desde']        = $desde . ' 00:00:00'; }
    if ($hasta        !== '')   { $where[] = 'registrado <= :hasta';             $params[':hasta']        = $hasta . ' 23:59:59'; }

    if ($search !== '') {
        // Ademas de los campos propios del contacto, el buscador rapido matchea
        // el NOMBRE de las etiquetas asignadas y de las listas suscriptas: asi
        // escribir "newsletter" o "expo" encuentra a los contactos de esa lista
        // o con esa etiqueta sin tener que abrir el modal de filtros y elegir
        // del combo. Los dos EXISTS van por `idx_dcl_lista`/`idx_dce_etiqueta`
        // + PK compuesta de las puentes (migraciones 20260811_1400 y _1600).
        // Nota: esto es un OR contra el resto del buscador, distinto de los
        // filtros `lista_id`/`etiqueta_id`, que siguen siendo un AND por ID
        // exacto.
        $where[] = '(nombre LIKE :s1 OR empresa_nombre LIKE :s2 OR correo LIKE :s3
                     OR telefono LIKE :s4 OR celular LIKE :s5 OR whatsapp LIKE :s6
                     OR persona_dni LIKE :s7 OR uuid LIKE :s8
                     OR EXISTS (SELECT 1 FROM datarocket_contactos_etiquetas dceq
                                  JOIN datarocket_etiquetas deq ON deq.id = dceq.etiqueta_id
                                 WHERE dceq.contacto_id = datarocket_contactos.id
                                   AND deq.nombre LIKE :s9)
                     OR EXISTS (SELECT 1 FROM datarocket_contactos_listas dclq
                                  JOIN datarocket_listas dlq ON dlq.id = dclq.lista_id
                                 WHERE dclq.contacto_id = datarocket_contactos.id
                                   AND dlq.nombre LIKE :s10))';
        $like = "%{$search}%";
        $params[':s1']  = $like;
        $params[':s2']  = $like;
        $params[':s3']  = $like;
        $params[':s4']  = $like;
        $params[':s5']  = $like;
        $params[':s6']  = $like;
        $params[':s7']  = $like;
        $params[':s8']  = $like;
        $params[':s9']  = $like;  // etiquetas.nombre
        $params[':s10'] = $like;  // listas.nombre
    }

    $sqlWhere = $where ? ('WHERE ' . implode(' AND ', $where)) : '';

    // Stats globales (ignoran filtros — son indicadores del recurso).
    $stats = $pdo->query("SELECT COUNT(*) AS total FROM datarocket_contactos")->fetch();

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
    attachUbicacionNombres($pdo, $rows);

    jsonOk([
        'stats'    => [
            'total'    => (int)($stats['total'] ?? 0),
            'filtrado' => $filtrado,
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

    // `attachUbicacionNombres` trabaja sobre un array de filas — se le pasa
    // esta sola envuelta para no duplicar la logica de lookup.
    $solo = [$row];
    attachUbicacionNombres($pdo, $solo);
    $row = $solo[0];

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

// Normaliza un ID de catalogo que llega por query string. Devuelve null para
// vacio / no numerico / <= 0 — asi un `?pais_id=` sin valor equivale a "sin
// filtro" en vez de filtrar por 0.
function drCtFiltroId(mixed $v): ?int {
    if ($v === null) return null;
    $s = trim((string)$v);
    if ($s === '' || !ctype_digit($s)) return null;
    $n = (int)$s;
    return $n > 0 ? $n : null;
}

// Version multi-valor de `drCtFiltroId` para los filtros que aceptan varias
// selecciones (`lista_id`, `etiqueta_id`). Acepta las tres formas en que puede
// llegar el parametro y devuelve int[] deduplicado, sin ceros y sin basura:
//   ?lista_id=5          -> [5]
//   ?lista_id=5,14       -> [5, 14]   (lo que manda el picker de chips)
//   ?lista_id[]=5&...=14 -> [5, 14]   (PHP lo entrega como array)
// Un valor no numerico se descarta en silencio, igual que en drCtFiltroId:
// equivale a "sin filtro" en vez de reventar el query.
function drCtFiltroIds(mixed $v): array {
    if ($v === null) return [];
    $partes = is_array($v) ? $v : explode(',', (string)$v);
    $out = [];
    foreach ($partes as $p) {
        $s = trim((string)$p);
        if ($s === '' || !ctype_digit($s)) continue;
        $n = (int)$s;
        if ($n > 0) $out[$n] = true;
    }
    return array_keys($out);
}

// Valida que los ids de ubicacion existan en su catalogo antes de tocar la
// tabla. Sin esto la violacion de FK sale como excepcion PDO y el ABM muestra
// un 500 con el mensaje crudo de InnoDB.
function assertUbicacionValida(PDO $pdo, array $p): void {
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
// porque contactoNormalizarCorreo() devuelve null tanto para "campo vacio"
// como para "campo con basura", y esos dos casos no son lo mismo: el primero
// es legitimo, el segundo es un error que el usuario tiene que ver.
// Mismo criterio en el endpoint v4 (api/v4/datarocket/contactos.php).
function assertCorreoValido(array $in): void {
    if (!array_key_exists('correo', $in)) return;
    $raw = trim((string)($in['correo'] ?? ''));
    if ($raw === '') return;
    if (contactoNormalizarCorreo($raw) === null) {
        jsonError('El correo no es válido.', 400);
    }
}

// Invariante de identidad: el campo de nombre que corresponde al `tipo` tiene
// que venir cargado, porque es el que alimenta a `nombre` (ver drCtDerivarNombre).
// Un contacto persona sin `persona_nombre` no tiene de donde sacar el nombre
// con el que se lista, se busca y se saluda en una plantilla.
//
// Solo se exige el campo del tipo. El del OTRO lado sigue siendo opcional y es
// legitimo tenerlo cargado: en un contacto persona `empresa_nombre` es donde
// trabaja, y en un contacto empresa `persona_nombre` es quien atiende.
//
// La backfill 20260817_2100 dejo la tabla cumpliendo esta invariante salvo 5
// filas que no tienen ningun nombre en ninguna columna (solo celular). Esas
// caen aca la primera vez que alguien las edite, que es cuando hay un humano
// para resolverlas — es el comportamiento buscado, no un efecto colateral.
// Mismo criterio en el endpoint v4 (api/v4/datarocket/contactos.php).
function assertIdentidadValida(array $p): void {
    if ($p['tipo'] === 'persona' && (string)($p['persona_nombre'] ?? '') === '') {
        jsonError('El nombre de la persona es obligatorio para un contacto de tipo persona.', 400);
    }
    if ($p['tipo'] === 'empresa' && (string)($p['empresa_nombre'] ?? '') === '') {
        jsonError('El nombre de la empresa es obligatorio para un contacto de tipo empresa.', 400);
    }
}

// `nombre` es DERIVADO, no un campo que el cliente elija: sale del campo de
// identidad que corresponde al `tipo`. Se pisa lo que haya mandado el cliente
// a proposito — asi la columna no puede volver a divergir de `persona_nombre`
// / `empresa_nombre`, que es justamente como se ensucio la tabla antes de la
// backfill 20260817_2100. El form del ABM muestra `#drcNombre` readonly y lo
// calcula en vivo con la misma regla, para que lo que se ve sea lo que se
// guarda.
//
// Se asume assertIdentidadValida() ya corrido: el campo de origen no es vacio.
function drCtDerivarNombre(array $p): string {
    return $p['tipo'] === 'persona'
        ? (string)$p['persona_nombre']
        : (string)$p['empresa_nombre'];
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
    $p = [
        'tipo'               => nullableStr($in['tipo']                    ?? null, 20),
        'nombre'             => nullableStr($in['nombre']                  ?? null, 255),
        'empresa_nombre'     => nullableStr($in['empresa_nombre']          ?? null, 255),
        'empresa_rubro'      => nullableStr($in['empresa_rubro']           ?? null, 255),
        'empresa_actividad'  => nullableStr($in['empresa_actividad']       ?? null, 255),
        'empresa_cargo'      => nullableStr($in['empresa_cargo']           ?? null, 255),
        'persona_nombre'     => nullableStr($in['persona_nombre']          ?? null, 255),
        'persona_genero'     => nullableStr($in['persona_genero']          ?? null, 1),
        'persona_nacimiento' => nullableStr($in['persona_nacimiento']      ?? null, 255),
        'persona_dni'        => nullableStr($in['persona_dni']             ?? null, 255),
        'domicilio'          => nullableStr($in['domicilio']               ?? null, 255),
        'ciudad'             => nullableStr($in['ciudad']                  ?? null, 255),
        'ubicacion'          => nullableStr($in['ubicacion']               ?? null, 255),
        // FK a `localidades` / `provincias` / `paises`. Se aceptan las claves
        // legacy sin sufijo: cuando las columnas eran VARCHAR ya guardaban el ID.
        'localidad_id' => nullableInt($in['localidad_id']            ?? $in['localidad'] ?? null),
        'provincia_id' => nullableInt($in['provincia_id']            ?? $in['provincia'] ?? null),
        'pais_id'      => nullableInt($in['pais_id']                 ?? $in['pais']      ?? null),
        // Telefonos a 10 digitos argentinos y correo a minuscula validada —
        // reglas en lib/contactos_normalizar.php, compartidas con el endpoint
        // v4 y con la migracion 20260816_1700 que puso al dia lo ya cargado.
        'telefono' => contactoNormalizarTelefono($in['telefono'] ?? null),
        'celular'  => contactoNormalizarTelefono($in['celular']  ?? null),
        'whatsapp' => contactoNormalizarTelefono($in['whatsapp'] ?? null),
        'correo'   => contactoNormalizarCorreo($in['correo']     ?? null),
        // `web` se guarda como host + path sin esquema; lo que no es una URL
        // va a NULL. Mismas reglas, mismo lib, migracion 20260816_1800.
        'web'         => contactoNormalizarWeb($in['web']           ?? null),
        'facebook'    => nullableStr($in['facebook']                ?? null, 255),
        'instagram'   => nullableStr($in['instagram']               ?? null, 255),
        'tiktok'      => nullableStr($in['tiktok']                  ?? null, 255),
        'comentarios' => nullableStr($in['comentarios']             ?? null, 500),
        'registrado'  => nullableDateTime($in['registrado']         ?? null),
    ];
    // Un correo cargado por error en `web` se rescata a `correo` cuando ese
    // campo viene vacio; si el contacto ya trae correo, se descarta con el
    // resto de los no-URL. Mismo criterio en el endpoint v4.
    if ($p['correo'] === null) {
        $p['correo'] = contactoWebComoCorreo($in['web'] ?? null);
    }
    return $p;
}

function handleCreate(PDO $pdo, array $in): void {
    assertCorreoValido($in);
    $p = sanitizePayload($in);
    // `tipo` obligatorio en alta — el ABM (y cualquier cliente del endpoint)
    // debe elegir persona o empresa. Ver DR_CT_TIPOS_VALIDOS.
    if (!in_array($p['tipo'], DR_CT_TIPOS_VALIDOS, true)) {
        jsonError('El tipo es obligatorio (persona o empresa).', 400);
    }
    assertIdentidadValida($p);
    $p['nombre'] = drCtDerivarNombre($p);
    assertUbicacionValida($pdo, $p);
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
                 :instagram, :tiktok, :comentarios, :registrado)
        ";
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

    assertCorreoValido($in);
    $p = sanitizePayload($in);
    // `tipo` obligatorio en edicion — filas historicas con `tipo` NULL deben
    // recibir un valor la primera vez que las editen (parte del proceso
    // manual de asignar tipo a los contactos existentes).
    if (!in_array($p['tipo'], DR_CT_TIPOS_VALIDOS, true)) {
        jsonError('El tipo es obligatorio (persona o empresa).', 400);
    }
    assertIdentidadValida($p);
    $p['nombre'] = drCtDerivarNombre($p);
    assertUbicacionValida($pdo, $p);
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
            WHERE id = :id
        ";
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
        if ($listaIds    !== null) syncListas($pdo, $id, $listaIds);
        if ($etiquetaIds !== null) syncEtiquetas($pdo, $id, $etiquetaIds);
        $pdo->commit();
        jsonOk(['id' => $id]);
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }
}

// Baja en cascada explicita. El orden importa:
//   1) `datarocket_interacciones` — las propias del contacto y las colgadas de
//      sus prospectos. No hay FK contra el contacto (solo contra el prospecto,
//      y con ON DELETE SET NULL), asi que si no se borran a mano quedan filas
//      huerfanas apuntando a un contacto inexistente.
//   2) `datarocket_prospectos` — su FK `fk_dr_prospectos_contacto` es ON DELETE
//      RESTRICT, o sea que mientras exista un prospecto el borrado del contacto
//      falla con error de integridad.
//   3) `datarocket_contactos` — recien aca. Las puente `..._listas` y
//      `..._etiquetas` se borran solas por el ON DELETE CASCADE de sus FKs.
// Todo dentro de una transaccion: o se va el contacto entero o no se va nada.
function handleDelete(PDO $pdo, int $id): void {
    $exists = $pdo->prepare('SELECT id FROM datarocket_contactos WHERE id = :id');
    $exists->execute([':id' => $id]);
    if (!$exists->fetchColumn()) jsonError('Contacto no encontrado', 404);

    $pdo->beginTransaction();
    try {
        $delInteracciones = $pdo->prepare(
            'DELETE FROM datarocket_interacciones
              WHERE contacto_id = :id
                 OR prospecto_id IN (SELECT id FROM datarocket_prospectos
                                      WHERE contacto_id = :id2)'
        );
        $delInteracciones->execute([':id' => $id, ':id2' => $id]);
        $interacciones = $delInteracciones->rowCount();

        $delProspectos = $pdo->prepare('DELETE FROM datarocket_prospectos WHERE contacto_id = :id');
        $delProspectos->execute([':id' => $id]);
        $prospectos = $delProspectos->rowCount();

        $delContacto = $pdo->prepare('DELETE FROM datarocket_contactos WHERE id = :id');
        $delContacto->execute([':id' => $id]);

        $pdo->commit();
        jsonOk([
            'id'            => $id,
            'interacciones' => $interacciones,
            'prospectos'    => $prospectos,
        ]);
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }
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
