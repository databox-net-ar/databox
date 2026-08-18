<?php
// api/v4/datarocket/embudos.php
// Microservicio del CRM Datarocket sobre la tabla `datarocket_embudos` — el
// catalogo de pipelines de venta / captacion. Cada oportunidad vive en un
// embudo y avanza por las etapas de ese embudo (`datarocket_etapas`).
//
//   GET /v4/datarocket/embudos                      -> listado con filtros (query string)
//   GET /v4/datarocket/embudos?id=N                 -> registro individual
//   GET /v4/datarocket/embudos?slug=causam-clientes -> resolucion slug -> registro completo
//
// Auth: Bearer con apikey de la tabla `aplicaciones` (mismo esquema que el resto
// del stack — ver cloud/api/lib/apikey_auth.php). Cualquier apikey habilitada pasa.
//
// Tabla destino: `datarocket_embudos` (migraciones 20260812_0200 / _0600 /
// _0700 / 20260818_1400). El ABM interno equivalente, usado por el panel cloud,
// es cloud/api/datarocket_embudos.php — mismas columnas y mismas reglas; la
// diferencia es la capa de auth, que el listado v4 no publica el bloque `stats`
// y que aca no hay escritura.
//
// ---------------------------------------------------------------------------
// PARA QUE ESTA: RESOLVER EL SLUG A UN ID
// ---------------------------------------------------------------------------
// Lo que un integrador tiene a mano es un identificador estable escrito a mano
// en su configuracion ("causam-clientes"), no el `id` autoincremental que le
// toco al embudo en esta base. El `nombre` tampoco sirve como referencia: es
// texto libre, editable desde el panel, y trae acentos, espacios y mayusculas.
//
// El `slug` es exactamente esa referencia estable (kebab-case, max 40 chars,
// UNIQUE por proyecto). Este endpoint lo traduce al `id` que despues viaja en
// `embudo_id` hacia los demas endpoints del CRM, y de paso devuelve la fila
// entera — incluido `proyecto_id`, que es el otro dato que el consumidor suele
// necesitar y no tiene por que conocer de memoria.
//
// ---------------------------------------------------------------------------
// EL SLUG ES UNICO POR PROYECTO, NO GLOBAL
// ---------------------------------------------------------------------------
// El UNIQUE de la tabla es (`proyecto_id`, `slug`), asi que dos proyectos
// distintos pueden tener cada uno su `captacion-general`. Un `?slug=` suelto
// puede entonces matchear mas de una fila.
//
// Devolver "la primera" seria una respuesta silenciosamente incorrecta — el
// cliente se llevaria el embudo de otro proyecto sin enterarse. Por eso la
// ambiguedad se contesta con 409 y la lista de candidatos, y se resuelve
// agregando `&proyecto_id=N`. Hoy (2026-08-18) no hay ningun slug repetido en
// la base, o sea que en la practica el 409 no aparece; el endpoint no se apoya
// en eso porque nada lo garantiza.
//
// ---------------------------------------------------------------------------
// SOLO LECTURA
// ---------------------------------------------------------------------------
// No hay POST / PUT / PATCH / DELETE: un embudo no es un dato que llegue de una
// integracion, es configuracion del CRM. Crearlo implica ademas cargarle las
// etapas (sin etapas el embudo no sirve para nada) y elegir el proyecto; y
// borrarlo esta bloqueado a nivel DB por la FK RESTRICT de
// `datarocket_oportunidades.embudo_id` mientras tenga oportunidades colgando.
//
// Todo eso es curaduria, no integracion, y vive en el ABM del panel cloud,
// donde hay usuario identificado, permisos (`datarocket.embudos.*`) y suceso
// asociado. Desde afuera el embudo se consulta; no se toca. Cualquier metodo
// que no sea GET devuelve 405.
//
// ---------------------------------------------------------------------------
// LAS ETAPAS VIAJAN CON `?con_etapas=1`
// ---------------------------------------------------------------------------
// Quien resuelve el embudo por slug casi siempre necesita despues el `id` de
// una etapa (la primera del pipeline, para dar de alta la oportunidad ahi). El
// flag las trae embebidas y le ahorra la segunda llamada, ordenadas por `orden`
// — que es el recorrido real del pipeline, no un detalle de presentacion.
// Off por default: es una query extra que la mayoria de los usos no necesita.

declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');

require_once dirname(__DIR__, 3) . '/env.php';
require_once dirname(__DIR__, 3) . '/cloud/api/db.php';

// ---------------------------------------------------------------------------
// Auth
// ---------------------------------------------------------------------------
// Mismo shape que cloud/api/lib/apikey_auth.php, rodado inline como el resto de
// los microservicios v4 para no arrastrar dependencias. Apache no siempre
// propaga Authorization a $_SERVER (depende de mod_rewrite y CGIPassAuth), asi
// que se chequea $_SERVER, REDIRECT_HTTP_AUTHORIZATION y getallheaders().

function embReadBearer(): string {
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

function embRequireApp(): array {
    $token = embReadBearer();
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
// Constantes del recurso
// ---------------------------------------------------------------------------

const DR_EM_COLS = 'e.id, e.proyecto_id, e.slug, e.nombre, e.descripcion, e.activo,
                    e.fecha_creacion, e.fecha_modificacion';

// `proyectos` no tiene FK obligatoria de nombre ni NOT NULL, asi que el JOIN es
// LEFT: un embudo cuyo proyecto no resuelva sigue devolviendose (con
// `proyecto_nombre` en null) en vez de desaparecer del listado.
const DR_EM_FROM = 'FROM datarocket_embudos e
               LEFT JOIN proyectos p ON p.id = e.proyecto_id';

// Criterios de orden aceptados en el listado. Cualquier otro valor cae al
// default (`slug`) en vez de dar 400: un `order_by` mal escrito no justifica
// romperle la pantalla al cliente. Van prefijados con el alias porque `nombre`
// existe en las dos tablas del JOIN y sin el alias MySQL da "ambiguous".
const DR_EM_ORDENES = [
    'id'                 => 'e.id',
    'proyecto_id'        => 'e.proyecto_id',
    'slug'               => 'e.slug',
    'nombre'             => 'e.nombre',
    'activo'             => 'e.activo',
    'fecha_creacion'     => 'e.fecha_creacion',
    'fecha_modificacion' => 'e.fecha_modificacion',
];

const DR_EM_SLUG_MAX = 40;  // = varchar(40) de la columna

// ---------------------------------------------------------------------------
// Ruteo
// ---------------------------------------------------------------------------

try {
    embRequireApp();
    $pdo    = db();
    $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
    $id     = isset($_GET['id']) ? (int)$_GET['id'] : 0;

    if ($method !== 'GET') {
        // El detalle importa: un integrador que intenta un POST no se equivoco
        // de URL, se equivoco de capa. El mensaje le dice donde esta la
        // operacion que buscaba en vez de dejarlo probando verbos.
        jsonError('Metodo no soportado. `/v4/datarocket/embudos` es de solo lectura: '
                . 'los embudos se crean, editan y borran desde el ABM del panel cloud.', 405);
    } elseif ($id > 0) {
        handleGetOne($pdo, $id, $_GET);
    } elseif (array_key_exists('slug', $_GET)) {
        handleGetPorSlug($pdo, (string)$_GET['slug'], $_GET);
    } else {
        handleList($pdo, $_GET);
    }
} catch (Throwable $e) {
    jsonError($e->getMessage(), 500);
}

// ---------------------------------------------------------------------------
// Flags de query string
// ---------------------------------------------------------------------------

// Se acepta cualquier valor no vacio salvo los negativos explicitos, para que
// sirvan tanto `?con_etapas=1` como `?con_etapas=true`. Mismo criterio que el
// `?con_conteo=1` de etiquetas.
function embFlagBool(array $q, string $clave): bool {
    if (!array_key_exists($clave, $q)) return false;
    $v = strtolower(trim((string)$q[$clave]));
    return !in_array($v, ['', '0', 'false', 'no'], true);
}

function embFlagEtapas(array $q): bool { return embFlagBool($q, 'con_etapas'); }
function embFlagConteo(array $q): bool { return embFlagBool($q, 'con_conteo'); }

// ---------------------------------------------------------------------------
// Normalizacion
// ---------------------------------------------------------------------------

// Lleva un texto a kebab-case estricto: [a-z0-9-]+, sin acentos, sin guiones al
// borde, colapsando corridas de separadores, cortado a 40 caracteres.
//
// Es el espejo exacto de `dremSlugify()` de cloud/api/datarocket_embudos.php —
// la funcion con la que se DERIVA el slug al dar de alta — y del backfill de la
// migracion 20260818_1400. Que la busqueda use la misma transformacion que el
// alta es lo que garantiza que `?slug=Causam Clientes` encuentre a
// `causam-clientes`: el cliente puede mandar el slug ya armado o el texto del
// nombre, y los dos caen en la misma clave.
//
// El `?? $s` del preg_replace cubre un `slug` que no sea UTF-8 valido: con el
// modificador /u eso devuelve null, y perder el texto entero es peor que
// dejarlo pasar sin plegar (mas adelante no matchea y sale 404, que es correcto).
function embSlugify(mixed $raw): string {
    $s = trim((string)$raw);
    if ($s === '') return '';
    $pares = [
        'á'=>'a','é'=>'e','í'=>'i','ó'=>'o','ú'=>'u',
        'à'=>'a','è'=>'e','ì'=>'i','ò'=>'o','ù'=>'u',
        'ä'=>'a','ë'=>'e','ï'=>'i','ö'=>'o','ü'=>'u',
        'Á'=>'a','É'=>'e','Í'=>'i','Ó'=>'o','Ú'=>'u',
        'ñ'=>'n','Ñ'=>'n','ç'=>'c','Ç'=>'c',
    ];
    $s = strtr($s, $pares);
    $s = mb_strtolower($s, 'UTF-8');
    // Las marcas combinantes U+0300-U+036F cubren el texto en forma NFD, donde
    // la `é` viaja como `e` + tilde suelta (lo que mandan varios teclados de
    // macOS / iOS) y por lo tanto no matchea la tabla `$pares` de arriba. Sin
    // esto la tilde suelta caeria en el `[^a-z0-9]+` y se convertiria en un
    // guion en el medio de la palabra. El contenedor no trae `intl`, asi que la
    // normalizacion es esta y no Normalizer::FORM_C.
    $s = preg_replace('/[\x{0300}-\x{036F}]+/u', '', $s) ?? $s;
    $s = preg_replace('/[^a-z0-9]+/', '-', $s) ?? $s;
    $s = trim($s, '-');
    return substr($s, 0, DR_EM_SLUG_MAX);
}

// Normaliza un id que llega por query string: vacio / no numerico / <= 0 ->
// null (equivale a "sin filtro", no a filtrar por 0).
function embFiltroId(mixed $v): ?int {
    if ($v === null) return null;
    $s = trim((string)$v);
    if ($s === '' || !ctype_digit($s)) return null;
    $n = (int)$s;
    return $n > 0 ? $n : null;
}

// PDO devuelve todo como string; el JSON publica ints donde la columna es int
// para que el consumidor pueda reenviar `id` como `embudo_id` sin castear.
//
// `oportunidades_count` / `etapas_count` / `etapas` NO aparecen aca: los agregan
// embAttachConteo() y embAttachEtapas() solo cuando el flag correspondiente
// vino en la query. Una clave ausente y una clave en null significan cosas
// distintas — "no lo pediste" vs. "lo pediste y es null" — y mezclarlas obliga
// al consumidor a adivinar.
function embFormatFila(array $r): array {
    return [
        'id'                 => (int)$r['id'],
        'proyecto_id'        => (int)$r['proyecto_id'],
        'proyecto_nombre'    => isset($r['proyecto_nombre']) && $r['proyecto_nombre'] !== null
                                ? (string)$r['proyecto_nombre'] : null,
        'slug'               => (string)$r['slug'],
        'nombre'             => (string)$r['nombre'],
        'descripcion'        => $r['descripcion'] !== null ? (string)$r['descripcion'] : null,
        'activo'             => (int)$r['activo'],
        'fecha_creacion'     => $r['fecha_creacion']     ?? null,
        'fecha_modificacion' => $r['fecha_modificacion'] ?? null,
    ];
}

function embFormatEtapa(array $r): array {
    return [
        'id'           => (int)$r['id'],
        'nombre'       => (string)$r['nombre'],
        'orden'        => (int)$r['orden'],
        'color'        => $r['color'] !== null ? (string)$r['color'] : null,
        'tipo'         => (string)$r['tipo'],
        'probabilidad' => $r['probabilidad'] !== null ? (int)$r['probabilidad'] : null,
    ];
}

// ---------------------------------------------------------------------------
// Acceso a datos
// ---------------------------------------------------------------------------

function embBuscarPorId(PDO $pdo, int $id): ?array {
    $st = $pdo->prepare('SELECT ' . DR_EM_COLS . ', p.nombre AS proyecto_nombre '
                      . DR_EM_FROM . ' WHERE e.id = :id LIMIT 1');
    $st->execute([':id' => $id]);
    $row = $st->fetch();
    return $row ? embFormatFila($row) : null;
}

// `$slug` tiene que venir YA normalizado por embSlugify(). Devuelve TODAS las
// coincidencias, no la primera: el UNIQUE de la tabla es (proyecto_id, slug),
// asi que sin acotar el proyecto puede haber mas de una y la decision de que
// hacer con eso es de handleGetPorSlug(), no de esta funcion.
//
// La igualdad la resuelve la collation utf8mb4_general_ci de la columna. Para un
// slug ya normalizado a [a-z0-9-] eso es una comparacion binaria en la practica
// (no quedan mayusculas ni acentos que plegar), pero se apoya en el mismo indice
// UNIQUE que respalda la unicidad, o sea que busqueda y constraint no pueden
// divergir.
function embBuscarPorSlug(PDO $pdo, string $slug, ?int $proyectoId): array {
    $sql    = 'SELECT ' . DR_EM_COLS . ', p.nombre AS proyecto_nombre ' . DR_EM_FROM . ' WHERE e.slug = :s';
    $params = [':s' => $slug];
    if ($proyectoId !== null) {
        $sql .= ' AND e.proyecto_id = :p';
        $params[':p'] = $proyectoId;
    }
    // El orden desempata la lista de candidatos del 409 de forma estable.
    $sql .= ' ORDER BY e.proyecto_id ASC, e.id ASC';

    $st = $pdo->prepare($sql);
    $st->execute($params);
    return array_map('embFormatFila', $st->fetchAll());
}

// Anexa `etapas_count` y `oportunidades_count` a cada item, con una query
// agrupada por tabla en vez de dos subqueries por fila. Solo corre con
// `?con_conteo=1`: la mayoria de los consumidores solo quiere el id del embudo,
// y `datarocket_oportunidades` crece sin techo.
function embAttachConteo(PDO $pdo, array &$items): void {
    if (!$items) return;
    // Los ids salen de embFormatFila(), o sea que ya son int — interpolarlos es
    // seguro y evita armar N placeholders.
    $in = implode(',', array_map(fn($r) => (int)$r['id'], $items));

    $conteos = [];
    foreach (['etapas_count' => 'datarocket_etapas', 'oportunidades_count' => 'datarocket_oportunidades'] as $clave => $tabla) {
        foreach ($pdo->query("SELECT embudo_id, COUNT(*) AS c
                                FROM {$tabla}
                               WHERE embudo_id IN ({$in})
                            GROUP BY embudo_id") as $r) {
            $conteos[$clave][(int)$r['embudo_id']] = (int)$r['c'];
        }
    }

    foreach ($items as &$row) {
        // Sin filas el GROUP BY no devuelve nada: es 0, no ausencia.
        $row['etapas_count']        = $conteos['etapas_count'][(int)$row['id']]        ?? 0;
        $row['oportunidades_count'] = $conteos['oportunidades_count'][(int)$row['id']] ?? 0;
    }
}

// Anexa `etapas` (array) a cada item con una sola query para todos los embudos.
// El orden es (embudo, orden) — `orden` es el recorrido real del pipeline y
// tiene UNIQUE por embudo, asi que el resultado es determinista.
function embAttachEtapas(PDO $pdo, array &$items): void {
    if (!$items) return;
    $in = implode(',', array_map(fn($r) => (int)$r['id'], $items));

    $porEmbudo = [];
    foreach ($pdo->query("SELECT id, embudo_id, nombre, orden, color, tipo, probabilidad
                            FROM datarocket_etapas
                           WHERE embudo_id IN ({$in})
                        ORDER BY embudo_id ASC, orden ASC") as $r) {
        $porEmbudo[(int)$r['embudo_id']][] = embFormatEtapa($r);
    }

    foreach ($items as &$row) {
        // Un embudo sin etapas devuelve `[]`, no null: es una lista vacia, no un
        // dato faltante. (Pasa: el ABM permite crear el embudo y cargarle las
        // etapas despues.)
        $row['etapas'] = $porEmbudo[(int)$row['id']] ?? [];
    }
}

// Aplica los flags que enriquecen la fila. Centralizado para que el listado,
// `?id=N` y `?slug=` devuelvan exactamente la misma forma.
function embAplicarFlags(PDO $pdo, array $items, array $q): array {
    if (embFlagConteo($q)) embAttachConteo($pdo, $items);
    if (embFlagEtapas($q)) embAttachEtapas($pdo, $items);
    return $items;
}

// ---------------------------------------------------------------------------
// Listado / consulta
// ---------------------------------------------------------------------------

function handleList(PDO $pdo, array $q): void {
    $codigo   = embFiltroId($q['codigo']      ?? null);
    $proyecto = embFiltroId($q['proyecto_id'] ?? null);
    $search   = trim((string)($q['q'] ?? ''));
    $activo   = isset($q['activo']) && trim((string)$q['activo']) !== ''
                ? (int)!!$q['activo'] : null;

    // `orden` es el nombre que usa el ABM cloud y `order_by` el que usa el resto
    // de v4: se aceptan los dos para no obligar a recordar cual va en cada capa.
    $orderBy = (string)($q['order_by'] ?? $q['orden'] ?? 'slug');
    if (!array_key_exists($orderBy, DR_EM_ORDENES)) $orderBy = 'slug';
    $orderSql = DR_EM_ORDENES[$orderBy];

    // Default alfabetico ascendente — al reves que prospectos (id desc) a
    // proposito: esto es un catalogo chico (7 embudos al 2026-08-18) que casi
    // siempre termina en un combo o en un vistazo, y ahi el orden util es el
    // alfabetico. Para los demas criterios el default es `desc`.
    $dirDefault = in_array($orderBy, ['slug', 'nombre'], true) ? 'asc' : 'desc';
    $dirSql     = strtolower((string)($q['dir'] ?? $dirDefault)) === 'asc' ? 'ASC' : 'DESC';

    $limite = isset($q['limite']) ? (int)$q['limite'] : 100;
    if ($limite < 1)    $limite = 1;
    if ($limite > 1000) $limite = 1000;

    $where  = [];
    $params = [];

    if ($codigo !== null) {
        $where[] = 'e.id = :codigo';
        $params[':codigo'] = $codigo;
    }
    if ($proyecto !== null) {
        $where[] = 'e.proyecto_id = :proyecto_id';
        $params[':proyecto_id'] = $proyecto;
    }
    if ($activo !== null) {
        $where[] = 'e.activo = :activo';
        $params[':activo'] = $activo;
    }
    // Busqueda parcial sobre slug, nombre y descripcion (igual que el ABM). No
    // lleva COLLATE explicito: las tres columnas ya son utf8mb4_general_ci, o
    // sea que el LIKE sale insensible a mayusculas y a acentos por definicion de
    // la tabla ("vigia" matchea "Vigía"). El termino NO se slugifica — aca se
    // busca tambien contra `nombre` y `descripcion`, que son texto libre con
    // espacios: convertirlos a guiones no encontraria nada.
    if ($search !== '') {
        $where[] = '(e.slug LIKE :s_slug OR e.nombre LIKE :s_nom OR e.descripcion LIKE :s_desc)';
        $params[':s_slug'] = '%' . $search . '%';
        $params[':s_nom']  = '%' . $search . '%';
        $params[':s_desc'] = '%' . $search . '%';
    }

    $sqlWhere = $where ? ('WHERE ' . implode(' AND ', $where)) : '';

    // `limite` ya viene clampeado a [1, 1000] e interpolado como int — no puede
    // ir como placeholder porque PDO lo citaria como string y MySQL rechaza
    // `LIMIT '100'`.
    $sql = 'SELECT ' . DR_EM_COLS . ', p.nombre AS proyecto_nombre
            ' . DR_EM_FROM . "
            {$sqlWhere}
            ORDER BY {$orderSql} {$dirSql}
            LIMIT {$limite}";
    $st = $pdo->prepare($sql);
    $st->execute($params);

    $items = embAplicarFlags($pdo, array_map('embFormatFila', $st->fetchAll()), $q);

    jsonOk([
        'total' => count($items),
        'items' => $items,
    ]);
}

function handleGetOne(PDO $pdo, int $id, array $q): void {
    $fila = embBuscarPorId($pdo, $id);
    if ($fila === null) jsonError('Embudo no encontrado', 404);

    jsonOk(embAplicarFlags($pdo, [$fila], $q)[0]);
}

// GET /v4/datarocket/embudos?slug=causam-clientes[&proyecto_id=N]
//
// Resolucion slug -> registro completo, que es el caso de uso que motiva el
// endpoint: el cliente tiene el identificador estable escrito en su config y
// necesita el `id` para mandarlo como `embudo_id` a los demas endpoints.
//
// Devuelve la MISMA forma que `?id=N` (un objeto, no una lista) con la fila
// entera, y 404 cuando no existe. Para buscar por aproximacion esta `?q=`, que
// devuelve listado y nunca 404.
function handleGetPorSlug(PDO $pdo, string $raw, array $q): void {
    $slug = embSlugify($raw);
    // `?slug=` vacio no se trata como "sin filtro": el cliente pidio buscar un
    // slug y devolverle el catalogo entero seria contestarle otra pregunta.
    if ($slug === '') {
        jsonError('El `slug` a buscar no puede estar vacio.', 400);
    }

    $proyecto = embFiltroId($q['proyecto_id'] ?? null);
    $filas    = embBuscarPorSlug($pdo, $slug, $proyecto);

    if (!$filas) {
        // El slug normalizado viaja en el error para que se entienda contra que
        // se busco realmente ("Causam Clientes" se busco como "causam-clientes").
        $consulta = ['slug' => $slug];
        if ($proyecto !== null) $consulta['proyecto_id'] = $proyecto;
        jsonError('Embudo no encontrado', 404, ['consulta' => $consulta]);
    }

    if (count($filas) > 1) {
        // El UNIQUE es (proyecto_id, slug): sin proyecto el slug puede repetirse
        // entre proyectos. Devolver "el primero" seria darle al cliente el
        // embudo de otro proyecto sin que se entere, asi que se corta con 409 y
        // se le pasan los candidatos completos — con eso puede elegir sin
        // volver a preguntar.
        jsonError('El slug `' . $slug . '` existe en mas de un proyecto. '
                . 'Agrega `&proyecto_id=N` para desambiguar.', 409,
                ['consulta' => ['slug' => $slug], 'embudos' => embAplicarFlags($pdo, $filas, $q)]);
    }

    jsonOk(embAplicarFlags($pdo, $filas, $q)[0]);
}
