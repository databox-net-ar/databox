<?php
// api/v4/datarocket/listas.php
// Microservicio del CRM Datarocket sobre la tabla `datarocket_listas` — el
// catalogo de listas de suscripcion. Cada lista agrupa prospectos a traves de
// la tabla puente `datarocket_prospectos_listas`, y es lo que despues consume
// un envio masivo (correo / WhatsApp).
//
//   GET /v4/datarocket/listas                        -> listado con filtros (query string)
//   GET /v4/datarocket/listas?id=N                   -> registro individual
//   GET /v4/datarocket/listas?slug=vigicom-usuarios  -> resolucion slug -> registro completo
//
// Auth: Bearer con apikey de la tabla `aplicaciones` (mismo esquema que el resto
// del stack — ver cloud/api/lib/apikey_auth.php). Cualquier apikey habilitada pasa.
//
// Tabla destino: `datarocket_listas` (migraciones 20260811_1200 / _1300 /
// 20260815_1200 / 20260821_1000). El ABM interno equivalente, usado por el panel
// cloud, es cloud/api/datarocketlistas.php — mismas columnas y mismas reglas; la
// diferencia es la capa de auth, que el listado v4 no publica el bloque `stats`
// y que aca no hay escritura.
//
// Es el gemelo de /v4/datarocket/embudos: mismo patron de resolucion, mismos
// flags, mismo contrato. Las dos diferencias que importan estan mas abajo —
// `proyecto_id` aca es NULLABLE, y `suscriptos` es un snapshot, no un contador
// vivo.
//
// ---------------------------------------------------------------------------
// PARA QUE ESTA: RESOLVER EL SLUG A UN ID
// ---------------------------------------------------------------------------
// Lo que un integrador tiene a mano es un identificador estable escrito a mano
// en su configuracion ("vigicom-usuarios"), no el `id` autoincremental que le
// toco a la lista en esta base. El `nombre` tampoco sirve como referencia: es
// texto libre, editable desde el panel, y trae acentos, espacios y mayusculas
// ("Vigicom Prospectos Frios").
//
// El `slug` es exactamente esa referencia estable (kebab-case, max 40 chars,
// UNIQUE por proyecto, agregado por la migracion 20260821_1000). Este endpoint
// lo traduce al `id` que despues viaja dentro de `lista_ids` hacia
// /v4/datarocket/prospectos, y de paso devuelve la fila entera — incluido
// `proyecto_id`, que es el otro dato que el consumidor suele necesitar y no
// tiene por que conocer de memoria.
//
// `POST /v4/datarocket/prospectos` acepta `embudo` por slug pero `lista_ids`
// solo por id: no hay forma de suscribir un prospecto a una lista sin conocer
// antes ese numero. Este endpoint es la pieza que faltaba para conseguirlo.
//
// ---------------------------------------------------------------------------
// SE BUSCA POR SLUG O POR ID, NADA MAS
// ---------------------------------------------------------------------------
// Las dos unicas claves de busqueda son `?slug=` y `?id=N` (`?codigo=N` en el
// listado, que es el mismo id con el nombre que usa el ABM). No hay busqueda por
// texto libre: el viejo `?q=` —coincidencia parcial sobre slug, nombre y
// descripcion— se saco, y `?nombre=` nunca existio.
//
// El motivo es el mismo que justifica el endpoint: `nombre` y `descripcion` son
// texto libre, editables desde el panel, y no identifican nada de forma estable.
// Una integracion que resuelve su lista por aproximacion sobre esas columnas
// queda atada a como este redactado el catalogo hoy, y el dia que alguien lo
// retoca desde el ABM la resolucion empieza a devolver otra fila —o ninguna—
// sin que nada falle de forma visible. Con listas eso no es un detalle: la fila
// equivocada es la audiencia equivocada de un envio masivo.
//
// Perder el `?q=` no le saca alcance al cliente: `?slug=` acepta el texto del
// nombre tal cual (`?slug=Vigicom Usuarios` cae en `vigicom-usuarios`, ver
// lisSlugify), y el catalogo es lo bastante chico como para traerlo entero y
// filtrarlo del lado del consumidor si de verdad necesita una aproximacion.
//
// Los dos parametros retirados NO se ignoran en silencio: mandar `?q=` y recibir
// el catalogo entero con 200 seria una respuesta silenciosamente incorrecta —el
// cliente creeria que filtro— asi que se contestan con 400 (ver
// lisAssertBusqueda). `proyecto_id`, `order_by`, `dir` y `limite` siguen
// estando: son filtros y presentacion del listado, no formas de buscar.
//
// ---------------------------------------------------------------------------
// EL SLUG ES UNICO POR PROYECTO — Y NI SIQUIERA ESO CUANDO NO HAY PROYECTO
// ---------------------------------------------------------------------------
// El UNIQUE de la tabla es (`proyecto_id`, `slug`), asi que dos proyectos
// distintos pueden tener cada uno su `clientes`. Hasta aca, igual que embudos.
//
// La diferencia: en `datarocket_listas` la columna `proyecto_id` es NULLABLE
// (el ABM ofrece "— Sin proyecto —"), y MySQL/MariaDB tratan cada NULL como
// distinto dentro de un indice unico. O sea que el UNIQUE NO restringe a las
// filas sin proyecto: dos listas huerfanas pueden compartir slug y la base las
// acepta. Es una degradacion asumida a nivel esquema (ver el encabezado de la
// migracion 20260821_1000), no algo que esta capa pueda arreglar.
//
// Consecuencia practica: un `?slug=` suelto puede matchear mas de una fila.
// Devolver "la primera" seria una respuesta silenciosamente incorrecta — el
// cliente se llevaria la lista de otro proyecto sin enterarse y le mandaria un
// envio masivo a la audiencia equivocada. Por eso la ambiguedad se contesta con
// 409 y la lista de candidatos. Se resuelve con `&proyecto_id=N`, salvo que las
// coincidencias sean todas sin proyecto: ahi no hay valor de `proyecto_id` que
// las separe y la unica salida es `?id=N`. El 409 lo dice explicito segun el
// caso, en vez de recomendar algo que no va a funcionar.
//
// Hoy (2026-08-21) las 29 listas tienen proyecto y no hay ningun slug repetido,
// o sea que en la practica el 409 no aparece; el endpoint no se apoya en eso
// porque nada lo garantiza.
//
// ---------------------------------------------------------------------------
// `suscriptos` ES UN SNAPSHOT, NO UN CONTADOR VIVO
// ---------------------------------------------------------------------------
// La columna `suscriptos` es un denormalizado que se recalcula a mano desde el
// ABM cloud (boton "Recalcular suscriptos" -> cloud/api/datarocketlistas_recalcular.php),
// no un trigger. O sea que puede estar atrasada respecto de la puente — al
// 2026-08-21 `reactor-usuarios` dice 2393 y la puente tiene 2392.
//
// Se publica igual porque para pintar un listado alcanza y sale gratis; cuando
// el numero tiene que ser exacto va `?con_conteo=1`, que agrega
// `suscriptos_reales` contado en vivo contra `datarocket_prospectos_listas`.
//
// `suscriptos` en null y en 0 NO son lo mismo: null es "nunca se recalculo"
// (el DEFAULT de la columna, que es lo que queda al crear una lista desde el
// ABM) y 0 es "se recalculo y no tiene a nadie". El JSON respeta esa
// diferencia en vez de aplanar el null a 0.
//
// ---------------------------------------------------------------------------
// SOLO LECTURA
// ---------------------------------------------------------------------------
// No hay POST / PUT / PATCH / DELETE: una lista no es un dato que llegue de una
// integracion, es configuracion del CRM. Crearla implica elegir el proyecto al
// que pertenece y define a quien le va a llegar un envio masivo; y borrarla se
// lleva puestas TODAS las suscripciones por el ON DELETE CASCADE de
// `fk_dpl_lista`, sin aviso y sin vuelta atras (`vigicom-usuarios` tiene 7.038
// al 2026-08-21).
//
// Todo eso es curaduria, no integracion, y vive en el ABM del panel cloud,
// donde hay usuario identificado, permisos (`datarocket.listas.*`) y suceso
// asociado. Desde afuera la lista se consulta; no se toca. Suscribir un
// prospecto A una lista si es una operacion de integracion, y esa ya existe:
// `lista_ids` en POST/PUT/PATCH de /v4/datarocket/prospectos. Cualquier metodo
// que no sea GET devuelve 405.

declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');

require_once dirname(__DIR__, 3) . '/env.php';
require_once dirname(__DIR__, 3) . '/cloud/api/db.php';
require_once dirname(__DIR__) . '/_lib/log.php';

// Todo error de este endpoint queda registrado en `sucesos` (Visor de sucesos
// del panel). Va antes de la auth para que los 401 tambien caigan adentro.
v4InitLog('v4/datarocket.listas');

// ---------------------------------------------------------------------------
// Auth
// ---------------------------------------------------------------------------
// Mismo shape que cloud/api/lib/apikey_auth.php, rodado inline como el resto de
// los microservicios v4 para no arrastrar dependencias. Apache no siempre
// propaga Authorization a $_SERVER (depende de mod_rewrite y CGIPassAuth), asi
// que se chequea $_SERVER, REDIRECT_HTTP_AUTHORIZATION y getallheaders().

function lisReadBearer(): string {
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

function lisRequireApp(): array {
    $token = lisReadBearer();
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

// La tabla no tiene `activo` ni timestamps (a diferencia de
// `datarocket_embudos`): estas seis columnas son todo el registro.
const DR_LI_COLS = 'l.id, l.proyecto_id, l.slug, l.nombre, l.descripcion, l.suscriptos';

// `proyectos` no tiene FK obligatoria de nombre y `proyecto_id` es nullable, asi
// que el JOIN es LEFT: una lista sin proyecto —o cuyo proyecto no resuelva—
// sigue devolviendose (con `proyecto_nombre` en null) en vez de desaparecer del
// listado.
const DR_LI_FROM = 'FROM datarocket_listas l
               LEFT JOIN proyectos p ON p.id = l.proyecto_id';

// Criterios de orden aceptados en el listado. Cualquier otro valor cae al
// default (`slug`) en vez de dar 400: un `order_by` mal escrito no justifica
// romperle la pantalla al cliente. Van prefijados con el alias porque `nombre`
// existe en las dos tablas del JOIN y sin el alias MySQL da "ambiguous".
const DR_LI_ORDENES = [
    'id'          => 'l.id',
    'proyecto_id' => 'l.proyecto_id',
    'slug'        => 'l.slug',
    'nombre'      => 'l.nombre',
    'suscriptos'  => 'l.suscriptos',
];

const DR_LI_SLUG_MAX = 40;  // = varchar(40) de la columna

// ---------------------------------------------------------------------------
// Ruteo
// ---------------------------------------------------------------------------

try {
    v4LogApp(lisRequireApp());
    $pdo    = db();
    $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
    $id     = isset($_GET['id']) ? (int)$_GET['id'] : 0;

    if ($method !== 'GET') {
        // El detalle importa: un integrador que intenta un POST no se equivoco
        // de URL, se equivoco de capa. El mensaje le dice donde esta la
        // operacion que buscaba en vez de dejarlo probando verbos — y para el
        // caso mas probable (suscribir a alguien) la respuesta no es el ABM,
        // es otro endpoint v4.
        jsonError('Metodo no soportado. `/v4/datarocket/listas` es de solo lectura: '
                . 'las listas se crean, editan y borran desde el ABM del panel cloud. '
                . 'Para suscribir un prospecto a una lista usa `lista_ids` en '
                . '/v4/datarocket/prospectos.', 405);
    }

    // Corta antes de resolver nada: `?q=` / `?nombre=` no se ignoran en silencio.
    lisAssertBusqueda($_GET);

    if ($id > 0) {
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
// sirvan tanto `?con_conteo=1` como `?con_conteo=true`. Mismo criterio que el
// `?con_etapas=1` de embudos.
function lisFlagBool(array $q, string $clave): bool {
    if (!array_key_exists($clave, $q)) return false;
    $v = strtolower(trim((string)$q[$clave]));
    return !in_array($v, ['', '0', 'false', 'no'], true);
}

function lisFlagConteo(array $q): bool { return lisFlagBool($q, 'con_conteo'); }

// ---------------------------------------------------------------------------
// Parametros de busqueda retirados
// ---------------------------------------------------------------------------

// Solo se busca por `slug` o por `id` (ver el encabezado). `q` existio y ya no;
// `nombre` no existio nunca pero es el tipeo natural de quien tiene el texto de
// la lista a mano. Los dos cortan con 400 en vez de caer al listado: quien manda
// `?q=frios` y recibe el catalogo entero con 200 se lleva una respuesta
// silenciosamente incorrecta, porque cree que filtro.
//
// El 400 no es un callejon sin salida — dice como se hace ahora lo que el
// cliente estaba intentando, y para los dos casos la respuesta es la misma:
// `?slug=` acepta el texto sin formatear.
//
// La lista va inline y no en una `const` de la seccion de constantes porque el
// bloque de ruteo corre ANTES de esta parte del archivo: las funciones se
// hoistean, las constantes de nivel de archivo no.
function lisAssertBusqueda(array $q): void {
    foreach (['q', 'nombre'] as $clave) {
        if (!array_key_exists($clave, $q)) continue;
        jsonError('El parametro `' . $clave . '` no esta soportado: `/v4/datarocket/listas` '
                . 'se consulta por `?slug=...` o por `?id=N`, nada mas. `?slug=` acepta el '
                . 'texto del nombre sin formatear (`?slug=Vigicom Usuarios` resuelve '
                . '`vigicom-usuarios`).', 400);
    }
}

// ---------------------------------------------------------------------------
// Normalizacion
// ---------------------------------------------------------------------------

// Lleva un texto a kebab-case estricto: [a-z0-9-]+, sin acentos, sin guiones al
// borde, colapsando corridas de separadores, cortado a 40 caracteres.
//
// Es el espejo exacto de `drliSlugify()` de cloud/api/datarocketlistas.php —
// la funcion con la que se DERIVA el slug al dar de alta —, de su gemela JS en
// cloud/assets/js/app.js y del backfill de la migracion 20260821_1000. Que la
// busqueda use la misma transformacion que el alta es lo que garantiza que
// `?slug=Vigicom Usuarios` encuentre a `vigicom-usuarios`: el cliente puede
// mandar el slug ya armado o el texto del nombre, y los dos caen en la misma
// clave.
//
// El `?? $s` del preg_replace cubre un `slug` que no sea UTF-8 valido: con el
// modificador /u eso devuelve null, y perder el texto entero es peor que
// dejarlo pasar sin plegar (mas adelante no matchea y sale 404, que es correcto).
function lisSlugify(mixed $raw): string {
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
    // la `í` viaja como `i` + tilde suelta (lo que mandan varios teclados de
    // macOS / iOS) y por lo tanto no matchea la tabla `$pares` de arriba. Sin
    // esto la tilde suelta caeria en el `[^a-z0-9]+` y se convertiria en un
    // guion en el medio de la palabra ("frios" -> "fri-os"). El contenedor no
    // trae `intl`, asi que la normalizacion es esta y no Normalizer::FORM_C.
    $s = preg_replace('/[\x{0300}-\x{036F}]+/u', '', $s) ?? $s;
    $s = preg_replace('/[^a-z0-9]+/', '-', $s) ?? $s;
    $s = trim($s, '-');
    return substr($s, 0, DR_LI_SLUG_MAX);
}

// Normaliza un id que llega por query string: vacio / no numerico / <= 0 ->
// null (equivale a "sin filtro", no a filtrar por 0).
function lisFiltroId(mixed $v): ?int {
    if ($v === null) return null;
    $s = trim((string)$v);
    if ($s === '' || !ctype_digit($s)) return null;
    $n = (int)$s;
    return $n > 0 ? $n : null;
}

// Filtro por proyecto — TRI-ESTADO, no un id a secas, porque
// `datarocket_listas.proyecto_id` es NULLABLE (a diferencia de
// `datarocket_embudos.proyecto_id`, que es obligatorio y donde alcanza con
// lisFiltroId()):
//
//   false    -> el parametro no vino o no es un entero: sin filtro.
//   null     -> vino `?proyecto_id=0`: filtra por `proyecto_id IS NULL`, o sea
//               el "— Sin proyecto —" del ABM.
//   int > 0  -> ese proyecto.
//
// El `0` como sinonimo de "sin proyecto" no es un invento de esta capa: es lo
// que la columna guardaba antes de la migracion 20260815_1200, que hizo
// `UPDATE datarocket_listas SET proyecto_id = NULL WHERE proyecto_id = 0` para
// poder colgarle la FK. Se conserva como sentinel de query string porque
// `?proyecto_id=` vacio ya significa "sin filtro" y hacen falta las dos cosas:
// sin el sentinel, las listas huerfanas no se pueden aislar ni desambiguar.
function lisFiltroProyecto(array $q): false|null|int {
    if (!array_key_exists('proyecto_id', $q)) return false;
    $s = trim((string)$q['proyecto_id']);
    if ($s === '' || !ctype_digit($s)) return false;
    return $s === '0' ? null : (int)$s;
}

// Arma la condicion SQL del filtro por proyecto y le carga los params. Devuelve
// '' cuando no hay filtro, asi que el llamador decide como engancharla (el
// listado la mete en su implode, la resolucion por slug la concatena con AND).
//
// Centralizado para que el listado y la resolucion por slug filtren igual: si
// divergen, `?slug=X&proyecto_id=0` y `?proyecto_id=0` dejan de hablar del mismo
// conjunto de filas. Y `IS NULL` no puede salir de un placeholder — con
// `= :p` y :p en null MySQL devuelve NULL, no true, y el filtro no matchearia
// nunca.
function lisCondProyecto(false|null|int $proyecto, array &$params): string {
    if ($proyecto === false) return '';
    if ($proyecto === null)  return 'l.proyecto_id IS NULL';
    $params[':proyecto_id'] = $proyecto;
    return 'l.proyecto_id = :proyecto_id';
}

// PDO devuelve todo como string; el JSON publica ints donde la columna es int
// para que el consumidor pueda reenviar `id` dentro de `lista_ids` sin castear.
//
// Los tres nullables se publican como null y no aplanados:
//   * `proyecto_id`  null = lista sin proyecto (opcion real del ABM).
//   * `nombre`       null = la columna lo permite; hoy no pasa, pero nada lo impide.
//   * `suscriptos`   null = nunca se recalculo, distinto de 0 (ver el encabezado).
//
// `suscriptos_reales` NO aparece aca: lo agrega lisAttachConteo() solo cuando
// vino `?con_conteo=1`. Una clave ausente y una clave en null significan cosas
// distintas — "no lo pediste" vs. "lo pediste y es null" — y mezclarlas obliga
// al consumidor a adivinar.
function lisFormatFila(array $r): array {
    return [
        'id'              => (int)$r['id'],
        'proyecto_id'     => $r['proyecto_id'] !== null ? (int)$r['proyecto_id'] : null,
        'proyecto_nombre' => isset($r['proyecto_nombre']) && $r['proyecto_nombre'] !== null
                             ? (string)$r['proyecto_nombre'] : null,
        'slug'            => (string)$r['slug'],
        'nombre'          => $r['nombre']      !== null ? (string)$r['nombre'] : null,
        'descripcion'     => $r['descripcion'] !== null ? (string)$r['descripcion'] : null,
        'suscriptos'      => $r['suscriptos']  !== null ? (int)$r['suscriptos'] : null,
    ];
}

// ---------------------------------------------------------------------------
// Acceso a datos
// ---------------------------------------------------------------------------

function lisBuscarPorId(PDO $pdo, int $id): ?array {
    $st = $pdo->prepare('SELECT ' . DR_LI_COLS . ', p.nombre AS proyecto_nombre '
                      . DR_LI_FROM . ' WHERE l.id = :id LIMIT 1');
    $st->execute([':id' => $id]);
    $row = $st->fetch();
    return $row ? lisFormatFila($row) : null;
}

// `$slug` tiene que venir YA normalizado por lisSlugify(). Devuelve TODAS las
// coincidencias, no la primera: el UNIQUE de la tabla es (proyecto_id, slug) y
// ni siquiera restringe a las filas sin proyecto, asi que puede haber mas de
// una y la decision de que hacer con eso es de handleGetPorSlug(), no de esta
// funcion.
//
// La igualdad la resuelve la collation utf8mb4_general_ci de la columna. Para un
// slug ya normalizado a [a-z0-9-] eso es una comparacion binaria en la practica
// (no quedan mayusculas ni acentos que plegar), pero se apoya en el mismo indice
// UNIQUE que respalda la unicidad, o sea que busqueda y constraint no pueden
// divergir.
function lisBuscarPorSlug(PDO $pdo, string $slug, false|null|int $proyecto): array {
    $params = [':s' => $slug];
    $cond   = lisCondProyecto($proyecto, $params);
    $sql    = 'SELECT ' . DR_LI_COLS . ', p.nombre AS proyecto_nombre ' . DR_LI_FROM
            . ' WHERE l.slug = :s' . ($cond !== '' ? ' AND ' . $cond : '');

    // El orden desempata la lista de candidatos del 409 de forma estable. Con
    // ASC los NULL de `proyecto_id` van primero en MySQL y en MariaDB.
    $sql .= ' ORDER BY l.proyecto_id ASC, l.id ASC';

    $st = $pdo->prepare($sql);
    $st->execute($params);
    return array_map('lisFormatFila', $st->fetchAll());
}

// Anexa `suscriptos_reales` (conteo vivo contra la puente) a cada item, con una
// sola query agrupada en vez de una subquery por fila. Solo corre con
// `?con_conteo=1`: `suscriptos` alcanza para la mayoria de los usos y esto es
// una lectura extra sobre una tabla que crece con los prospectos (decenas de
// miles de filas), no con el catalogo.
function lisAttachConteo(PDO $pdo, array &$items): void {
    if (!$items) return;
    // Los ids salen de lisFormatFila(), o sea que ya son int — interpolarlos es
    // seguro y evita armar N placeholders.
    $in  = implode(',', array_map(fn($r) => (int)$r['id'], $items));
    $map = [];
    foreach ($pdo->query("SELECT lista_id, COUNT(*) AS c
                            FROM datarocket_prospectos_listas
                           WHERE lista_id IN ({$in})
                        GROUP BY lista_id") as $r) {
        $map[(int)$r['lista_id']] = (int)$r['c'];
    }
    foreach ($items as &$row) {
        // Sin fila en la puente el COUNT no devuelve nada: es 0, no ausencia.
        // Ojo que aca 0 SI es 0 — a diferencia de `suscriptos`, que puede ser
        // null porque nunca se recalculo. El conteo vivo siempre tiene valor.
        $row['suscriptos_reales'] = $map[(int)$row['id']] ?? 0;
    }
}

// Aplica los flags que enriquecen la fila. Centralizado para que el listado,
// `?id=N` y `?slug=` devuelvan exactamente la misma forma.
function lisAplicarFlags(PDO $pdo, array $items, array $q): array {
    if (lisFlagConteo($q)) lisAttachConteo($pdo, $items);
    return $items;
}

// ---------------------------------------------------------------------------
// Listado / consulta
// ---------------------------------------------------------------------------

// El listado no busca: filtra. `codigo` es el `id` con el nombre que usa el ABM,
// `proyecto_id` acota el conjunto por una columna cerrada, y el resto es
// presentacion. La busqueda por texto vive en `?slug=`, que resuelve una fila y
// no un subconjunto (ver lisAssertBusqueda).
function handleList(PDO $pdo, array $q): void {
    $codigo   = lisFiltroId($q['codigo'] ?? null);
    $proyecto = lisFiltroProyecto($q);

    // `orden` es el nombre que usa el ABM cloud y `order_by` el que usa el resto
    // de v4: se aceptan los dos para no obligar a recordar cual va en cada capa.
    $orderBy = (string)($q['order_by'] ?? $q['orden'] ?? 'slug');
    if (!array_key_exists($orderBy, DR_LI_ORDENES)) $orderBy = 'slug';
    $orderSql = DR_LI_ORDENES[$orderBy];

    // Default alfabetico ascendente — al reves que prospectos (id desc) a
    // proposito: esto es un catalogo chico (29 listas al 2026-08-21) que casi
    // siempre termina en un combo o en un vistazo, y ahi el orden util es el
    // alfabetico. Para los demas criterios el default es `desc` (con
    // `suscriptos` lo que se quiere ver primero son las listas grandes).
    $dirDefault = in_array($orderBy, ['slug', 'nombre'], true) ? 'asc' : 'desc';
    $dirSql     = strtolower((string)($q['dir'] ?? $dirDefault)) === 'asc' ? 'ASC' : 'DESC';

    $limite = isset($q['limite']) ? (int)$q['limite'] : 100;
    if ($limite < 1)    $limite = 1;
    if ($limite > 1000) $limite = 1000;

    $where  = [];
    $params = [];

    if ($codigo !== null) {
        $where[] = 'l.id = :codigo';
        $params[':codigo'] = $codigo;
    }
    $condProyecto = lisCondProyecto($proyecto, $params);
    if ($condProyecto !== '') $where[] = $condProyecto;

    $sqlWhere = $where ? ('WHERE ' . implode(' AND ', $where)) : '';

    // `limite` ya viene clampeado a [1, 1000] e interpolado como int — no puede
    // ir como placeholder porque PDO lo citaria como string y MySQL rechaza
    // `LIMIT '100'`.
    $sql = 'SELECT ' . DR_LI_COLS . ', p.nombre AS proyecto_nombre
            ' . DR_LI_FROM . "
            {$sqlWhere}
            ORDER BY {$orderSql} {$dirSql}
            LIMIT {$limite}";
    $st = $pdo->prepare($sql);
    $st->execute($params);

    $items = lisAplicarFlags($pdo, array_map('lisFormatFila', $st->fetchAll()), $q);

    jsonOk([
        'total' => count($items),
        'items' => $items,
    ]);
}

function handleGetOne(PDO $pdo, int $id, array $q): void {
    $fila = lisBuscarPorId($pdo, $id);
    if ($fila === null) jsonError('Lista no encontrada', 404);

    jsonOk(lisAplicarFlags($pdo, [$fila], $q)[0]);
}

// GET /v4/datarocket/listas?slug=vigicom-usuarios[&proyecto_id=N]
//
// Resolucion slug -> registro completo, que es el caso de uso que motiva el
// endpoint: el cliente tiene el identificador estable escrito en su config y
// necesita el `id` para mandarlo dentro de `lista_ids` a
// /v4/datarocket/prospectos.
//
// Devuelve la MISMA forma que `?id=N` (un objeto, no una lista) con la fila
// entera, y 404 cuando no existe. No hay una variante "por aproximacion": el
// `?q=` que la ofrecia se retiro (ver el encabezado), asi que para explorar el
// catalogo va el listado pelado, que igual entra entero en una sola llamada.
function handleGetPorSlug(PDO $pdo, string $raw, array $q): void {
    $slug = lisSlugify($raw);
    // `?slug=` vacio no se trata como "sin filtro": el cliente pidio buscar un
    // slug y devolverle el catalogo entero seria contestarle otra pregunta.
    if ($slug === '') {
        jsonError('El `slug` a buscar no puede estar vacio.', 400);
    }

    $proyecto = lisFiltroProyecto($q);
    $filas    = lisBuscarPorSlug($pdo, $slug, $proyecto);

    if (!$filas) {
        // El slug normalizado viaja en el error para que se entienda contra que
        // se busco realmente ("Vigicom Usuarios" se busco como
        // "vigicom-usuarios"). `proyecto_id` se reporta con el mismo sentinel
        // con el que entro: 0 significa "sin proyecto", no "proyecto cero".
        $consulta = ['slug' => $slug];
        if ($proyecto !== false) $consulta['proyecto_id'] = $proyecto ?? 0;
        jsonError('Lista no encontrada', 404, ['consulta' => $consulta]);
    }

    if (count($filas) > 1) {
        // Sin acotar el proyecto el slug puede repetirse entre proyectos.
        // Devolver "la primera" seria darle al cliente la lista de otro proyecto
        // sin que se entere — y con listas eso termina en un envio masivo a la
        // audiencia equivocada. Se corta con 409 y se le pasan los candidatos
        // completos: con eso puede elegir sin volver a preguntar.
        $sinProyecto = 0;
        foreach ($filas as $f) if ($f['proyecto_id'] === null) $sinProyecto++;

        // Si hay dos o mas huerfanas compartiendo el slug, `proyecto_id` no las
        // separa (el UNIQUE de la tabla no restringe los NULL, ver el
        // encabezado): recomendarlo seria mandarlo a intentar algo que no puede
        // funcionar. En ese caso la unica salida es el id.
        $sugerencia = $sinProyecto > 1
            ? 'Hay ' . $sinProyecto . ' coincidencias sin proyecto y `proyecto_id` no las separa: '
            . 'elegi una de la lista y consultala por `?id=N`.'
            : 'Agrega `&proyecto_id=N` para desambiguar (`&proyecto_id=0` = las que no tienen proyecto).';

        jsonError('El slug `' . $slug . '` existe en mas de una lista. ' . $sugerencia, 409,
                ['consulta' => ['slug' => $slug], 'listas' => lisAplicarFlags($pdo, $filas, $q)]);
    }

    jsonOk(lisAplicarFlags($pdo, $filas, $q)[0]);
}
