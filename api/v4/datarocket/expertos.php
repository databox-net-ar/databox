<?php
// api/v4/datarocket/expertos.php
// Microservicio del CRM Datarocket sobre la tabla `datarocket_expertos` — el
// catalogo de personalidades con las que una IA contesta las consultas de los
// interesados en un proyecto del grupo. Cada fila tiene un `contexto`: el
// prompt de sistema, guardado en MARKDOWN CRUDO.
//
//   GET /v4/datarocket/expertos?slug=reactor-asesor  -> el contexto en Markdown (text/markdown)
//   GET /v4/datarocket/expertos?slug=...&formato=json-> la fila entera en JSON
//   GET /v4/datarocket/expertos?id=N                 -> la fila entera en JSON
//   GET /v4/datarocket/expertos?id=N&formato=md      -> el contexto en Markdown
//   GET /v4/datarocket/expertos                      -> listado con filtros (sin el contexto entero)
//
// Auth: Bearer con apikey de la tabla `aplicaciones` (mismo esquema que el resto
// del stack — ver cloud/api/lib/apikey_auth.php). Cualquier apikey habilitada pasa.
//
// Tabla destino: `datarocket_expertos` (migracion
// 20260914_1000_datarocket_expertos_modulo.sql). El ABM interno equivalente,
// usado por el panel cloud, es cloud/api/datarocket_expertos.php — mismas
// columnas y mismas reglas; la diferencia es la capa de auth, que aca no hay
// escritura y que este endpoint entrega el contexto como documento.
//
// ---------------------------------------------------------------------------
// PARA QUE ESTA: BAJAR EL PROMPT DE SISTEMA POR SLUG
// ---------------------------------------------------------------------------
// Lo que un canal de entrada (un bot de WhatsApp, un widget del sitio, un
// agente) tiene a mano es un identificador estable escrito en su configuracion
// ("reactor-asesor"), no el `id` autoincremental que le toco al experto en esta
// base. El `nombre` tampoco sirve como referencia: es texto libre, editable
// desde el panel, y trae acentos, espacios y mayusculas.
//
// El `slug` es exactamente esa referencia estable (kebab-case, max 60 chars,
// UNIQUE GLOBAL). Este endpoint lo traduce a lo unico que el canal necesita
// para armar la conversacion: el contexto, tal cual se le antepone al modelo.
//
// ---------------------------------------------------------------------------
// EL CONTEXTO SALE COMO MARKDOWN CRUDO, NO ADENTRO DE UN JSON
// ---------------------------------------------------------------------------
// `?slug=` devuelve por default el cuerpo del contexto pelado, con
// `Content-Type: text/markdown`. No es un capricho de formato: ese texto es lo
// que se le pega al modelo como prompt de sistema, asi que el camino mas corto
// entre la base y el modelo tiene que ser una lectura, no un parseo.
//
// Mandarlo adentro de {"ok":true,"data":{"contexto":"..."}} obligaria a cada
// consumidor a decodificar un string con miles de `\n` escapados para recuperar
// exactamente los mismos bytes que ya estaban en la columna. Cada paso de esa
// cadena es una oportunidad de alterar el prompt sin que nadie se entere.
//
// Por eso el cuerpo se manda BYTE A BYTE como esta guardado: sin encabezado
// agregado, sin front-matter, sin "Contexto del experto X" adelante. Cualquier
// cosa que este archivo le sume al texto se convierte en parte del prompt.
//
// Lo unico que se normaliza es el `rtrim()` de la cola — mismo criterio con el
// que lo guarda el ABM (cloud/api/datarocket_expertos.php), asi que no cambia
// nada respecto de lo que el operador vio en el panel.
//
// El formato JSON sigue disponible con `&formato=json`, para cuando lo que hace
// falta es la metadata (nombre, proyecto, fechas) y no el prompt.
//
// ---------------------------------------------------------------------------
// LOS DEFAULTS DE `formato` SON DISTINTOS EN `?slug=` Y EN `?id=`
// ---------------------------------------------------------------------------
// `?slug=` default `md`; `?id=N` default `json`. No es una inconsistencia: son
// dos preguntas distintas.
//
// Quien entra por `?slug=` tiene el identificador que escribio en su config y
// esta pidiendo EL DOCUMENTO — es el caso de uso que justifica el endpoint.
// Quien entra por `?id=N` ya resolvio el experto y esta pidiendo EL REGISTRO,
// que es lo que `?id=N` devuelve en todo el arbol v4.
//
// Las dos puertas aceptan `formato` explicito, asi que ninguna queda atada a su
// default.
//
// ---------------------------------------------------------------------------
// EL SLUG ES UNICO GLOBAL (A DIFERENCIA DE EMBUDOS)
// ---------------------------------------------------------------------------
// El UNIQUE de la tabla es `slug` a secas (`uq_drex_slug`), no
// (proyecto_id, slug) como en `datarocket_embudos`. O sea que aca `?slug=`
// resuelve siempre una fila o ninguna, y no existe el 409 por ambiguedad que
// obliga a mandar `&proyecto_id=N` en embudos.
//
// Es a proposito: el slug del experto es lo que un canal de entrada usa para
// pedir "contesta con este", y ese pedido no viaja acompanado de un proyecto.
// `proyecto_id` ademas es NULLABLE (experto transversal al grupo), asi que
// desambiguar por proyecto ni siquiera seria posible en todos los casos.
//
// ---------------------------------------------------------------------------
// UN EXPERTO SIN CONTEXTO O DESACTIVADO NO SE SIRVE: CORTA CON 409
// ---------------------------------------------------------------------------
// Los dos casos podrian contestarse con un 200 de cuerpo vacio. Seria la peor
// respuesta posible: del otro lado hay algo que va a usar ese cuerpo como
// prompt de sistema, y un prompt vacio no falla — deja al modelo contestando
// sin personalidad ni datos del producto, que es exactamente el sintoma que
// nadie relaciona con "el endpoint devolvio 200".
//
//   * `activo = 0` -> 409. Desactivar es la forma que tiene el operador de decir
//     "no contesten mas con este experto"; servirlo igual haria que el toggle
//     del ABM no signifique nada. Se puede forzar con `?incluir_inactivos=1`
//     (para previsualizar desde el panel antes de reactivarlo).
//   * `contexto` NULL o vacio -> 409. Al 2026-09-29 tres de los cuatro expertos
//     de la base estan en esta situacion, asi que no es un caso teorico.
//
// Es 409 y no 404 porque el slug SI existe: un 404 mandaria a quien integra a
// revisar si lo escribio mal, cuando lo que hay que hacer es cargarle el
// contexto o reactivarlo en el panel.
//
// ---------------------------------------------------------------------------
// SE BUSCA POR SLUG O POR ID, NADA MAS
// ---------------------------------------------------------------------------
// Mismo criterio que `/v4/datarocket/embudos`: `?q=` y `?nombre=` cortan con
// 400 en vez de caer al listado. `nombre` y `contexto` son texto libre editable
// desde el panel; una integracion que resuelve su experto por aproximacion
// sobre esas columnas empieza a bajar OTRO prompt el dia que alguien retoca el
// catalogo, sin que nada falle de forma visible.
//
// Ignorarlos en silencio seria peor que rechazarlos: quien manda `?q=reactor` y
// recibe el catalogo entero con 200 cree que filtro. `?slug=` acepta el texto
// del nombre tal cual (`?slug=Reactor Asesor` cae en `reactor-asesor`, ver
// exSlugify), asi que no se pierde alcance.
//
// ---------------------------------------------------------------------------
// SOLO LECTURA
// ---------------------------------------------------------------------------
// No hay POST / PUT / PATCH / DELETE. Un experto no es un dato que llegue de una
// integracion: es curaduria — redactar el prompt con el que un producto del
// grupo se presenta ante sus interesados. Eso vive en el ABM del panel cloud,
// donde hay usuario identificado, permisos (`datarocket.expertos.*`) y suceso
// asociado. Desde afuera el experto se consulta; no se toca.

declare(strict_types=1);

require_once dirname(__DIR__, 3) . '/env.php';
require_once dirname(__DIR__, 3) . '/cloud/api/db.php';
require_once dirname(__DIR__) . '/_lib/log.php';
require_once dirname(__DIR__, 3) . '/cloud/api/lib/apikey_auth.php';

// Todo error de este endpoint queda registrado en `sucesos` (Visor de sucesos
// del panel). Va antes de la auth para que los 401 tambien caigan adentro.
//
// El shutdown handler de _lib/log.php clasifica leyendo el sobre {"ok":...} del
// buffer. Las respuestas Markdown de este endpoint no lo tienen, pero salen con
// status 200 / 304 y por lo tanto se descartan sin registrar — que es lo
// correcto: no son errores. Los rechazos siguen saliendo por jsonError().
v4InitLog('v4/datarocket.expertos');

// A diferencia del resto del arbol v4, aca NO se fija `Content-Type:
// application/json` de entrada: la respuesta principal de este endpoint no es
// JSON. Cada salida declara el suyo — jsonOk()/jsonError() el JSON,
// exEnviarMarkdown() el text/markdown.

// ---------------------------------------------------------------------------
// Constantes del recurso
// ---------------------------------------------------------------------------

const DR_EX_TABLA = 'datarocket_expertos';

const DR_EX_COLS = 'x.id, x.proyecto_id, x.slug, x.nombre, x.activo,
                    x.fecha_creacion, x.fecha_modificacion';

// `proyectos` es compartida con las apps legacy y ninguna tabla Datarocket
// nueva lleva FK contra ella, asi que el JOIN es LEFT por partida doble: el
// `proyecto_id` puede ser NULL (experto transversal al grupo) o apuntar a una
// fila que ya no este. En los dos casos el experto se devuelve igual, con
// `proyecto_nombre` en null, en vez de desaparecer del listado.
const DR_EX_FROM = 'FROM datarocket_expertos x
               LEFT JOIN proyectos p ON p.id = x.proyecto_id';

// Criterios de orden aceptados en el listado. Cualquier otro valor cae al
// default (`slug`) en vez de dar 400: un `order_by` mal escrito no justifica
// romperle la pantalla al cliente. Van prefijados con el alias porque `nombre`
// existe en las dos tablas del JOIN y sin el alias MySQL da "ambiguous".
const DR_EX_ORDENES = [
    'id'                 => 'x.id',
    'proyecto_id'        => 'x.proyecto_id',
    'slug'               => 'x.slug',
    'nombre'             => 'x.nombre',
    'activo'             => 'x.activo',
    'fecha_creacion'     => 'x.fecha_creacion',
    'fecha_modificacion' => 'x.fecha_modificacion',
];

const DR_EX_SLUG_MAX = 60;   // = varchar(60) de la columna

// Cuanto contexto viaja en el listado, para la vista rapida.
const DR_EX_EXTRACTO_LEN = 160;

// Cuanto contexto se trae de la base en el listado para armar ese extracto. El
// `contexto` es MEDIUMTEXT y puede pesar cientos de KB por fila: traerlo entero
// para recortarlo a 160 caracteres convertiria un listado de catalogo en una
// respuesta de megabytes. 400 alcanza de sobra para los 160 finales aun despues
// de colapsar los saltos del Markdown.
const DR_EX_EXTRACTO_FETCH = 400;

// ---------------------------------------------------------------------------
// Ruteo
// ---------------------------------------------------------------------------

try {
    v4LogApp(requireAppApikey());
    $pdo    = db();
    $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
    $id     = isset($_GET['id']) ? (int)$_GET['id'] : 0;

    if ($method !== 'GET') {
        // El detalle importa: un integrador que intenta un POST no se equivoco
        // de URL, se equivoco de capa. El mensaje le dice donde esta la
        // operacion que buscaba en vez de dejarlo probando verbos.
        jsonError('Metodo no soportado. `/v4/datarocket/expertos` es de solo lectura: '
                . 'los expertos se crean, editan y borran desde el ABM del panel cloud '
                . '(Sistemas > Datarocket > Expertos).', 405);
    }

    // Corta antes de resolver nada: `?q=` / `?nombre=` no se ignoran en silencio.
    exAssertBusqueda($_GET);

    if ($id > 0) {
        exHandleGetOne($pdo, $id, $_GET);
    } elseif (array_key_exists('slug', $_GET)) {
        exHandleGetPorSlug($pdo, (string)$_GET['slug'], $_GET);
    } else {
        exHandleList($pdo, $_GET);
    }
} catch (Throwable $e) {
    jsonError($e->getMessage(), 500);
}

// ---------------------------------------------------------------------------
// Flags de query string
// ---------------------------------------------------------------------------

// Se acepta cualquier valor no vacio salvo los negativos explicitos, para que
// sirvan tanto `?incluir_inactivos=1` como `=true`. Mismo criterio que el
// `?con_etapas=1` de embudos.
function exFlagBool(array $q, string $clave): bool {
    if (!array_key_exists($clave, $q)) return false;
    $v = strtolower(trim((string)$q[$clave]));
    return !in_array($v, ['', '0', 'false', 'no'], true);
}

// `md` -> el contexto crudo con Content-Type text/markdown.
// `json` -> la fila entera en el sobre {"ok":true,"data":{...}}.
//
// Un valor desconocido corta con 400 en vez de caer al default, al reves que
// `order_by`. La diferencia es que de esto depende QUE TIPO DE DOCUMENTO se
// lleva el cliente: quien escribe `formato=markdown` y recibe un JSON porque el
// endpoint ignoro la palabra se lleva una respuesta silenciosamente incorrecta.
// Un `order_by` mal escrito, en cambio, devuelve los mismos datos en otro orden.
function exFormato(array $q, string $default): string {
    if (!array_key_exists('formato', $q)) return $default;

    $f = strtolower(trim((string)$q['formato']));
    if ($f === '')     return $default;
    if ($f === 'md')   return 'md';
    if ($f === 'json') return 'json';

    jsonError('El parametro `formato` solo acepta `md` o `json` (llego `' . $f . '`).', 400);
}

// ---------------------------------------------------------------------------
// Parametros de busqueda retirados
// ---------------------------------------------------------------------------

// Solo se busca por `slug` o por `id` (ver el encabezado). `q` existe en el ABM
// cloud — donde busca tambien adentro del `contexto` — pero no aca: una
// integracion que resuelve su experto por aproximacion sobre texto libre
// empieza a bajar otro prompt el dia que alguien retoca el catalogo.
//
// `nombre` nunca existio, pero es el tipeo natural de quien tiene el texto del
// experto a mano, asi que se contesta igual en vez de caer al listado.
//
// La lista va inline y no en una `const` de la seccion de constantes porque el
// bloque de ruteo corre ANTES de esta parte del archivo: las funciones se
// hoistean, las constantes de nivel de archivo no.
function exAssertBusqueda(array $q): void {
    foreach (['q', 'nombre'] as $clave) {
        if (!array_key_exists($clave, $q)) continue;
        jsonError('El parametro `' . $clave . '` no esta soportado: `/v4/datarocket/expertos` '
                . 'se consulta por `?slug=...` o por `?id=N`, nada mas. `?slug=` acepta el '
                . 'texto del nombre sin formatear (`?slug=Reactor Asesor` resuelve '
                . '`reactor-asesor`).', 400);
    }
}

// ---------------------------------------------------------------------------
// Normalizacion
// ---------------------------------------------------------------------------

// Pliega los acentos y la enie a ASCII. Lo usan exSlugify() —para que
// `?slug=Vigía Asesor` caiga en `vigia-asesor`— y exHeaderSafe() —para que el
// nombre pueda viajar en un header HTTP—. Una sola tabla para los dos, asi no
// se pueden ir separando.
//
// Cubre las dos formas en que llega un acento: precompuesto (`é`, un solo
// codepoint, lo que manda casi todo) y NFD (`e` + U+0301 suelto, lo que mandan
// varios teclados de macOS / iOS). Sin el segundo paso la tilde suelta
// sobreviviria a la tabla y terminaria convertida en un guion en el medio de la
// palabra.
//
// El `?? $s` de los preg_replace cubre un texto que no sea UTF-8 valido: con el
// modificador /u eso devuelve null, y perder el texto entero es peor que
// dejarlo pasar sin plegar (mas adelante no matchea y sale 404, que es correcto).
// El contenedor no trae `intl`, asi que la normalizacion es esta y no
// Normalizer::FORM_C.
function exPlegarAcentos(string $s): string {
    $pares = [
        'á'=>'a','é'=>'e','í'=>'i','ó'=>'o','ú'=>'u',
        'à'=>'a','è'=>'e','ì'=>'i','ò'=>'o','ù'=>'u',
        'ä'=>'a','ë'=>'e','ï'=>'i','ö'=>'o','ü'=>'u',
        'Á'=>'A','É'=>'E','Í'=>'I','Ó'=>'O','Ú'=>'U',
        'À'=>'A','È'=>'E','Ì'=>'I','Ò'=>'O','Ù'=>'U',
        'Ä'=>'A','Ë'=>'E','Ï'=>'I','Ö'=>'O','Ü'=>'U',
        'ñ'=>'n','Ñ'=>'N','ç'=>'c','Ç'=>'C',
    ];
    $s = strtr($s, $pares);
    return preg_replace('/[\x{0300}-\x{036F}]+/u', '', $s) ?? $s;
}

// Lleva un texto a kebab-case estricto: [a-z0-9-]+, sin acentos, sin guiones al
// borde, colapsando corridas de separadores, cortado a 60 caracteres.
//
// Es el espejo exacto de `drexSlugify()` de cloud/api/datarocket_expertos.php —
// la funcion con la que se DERIVA el slug al dar de alta — y de su gemela
// `drexSlugify()` en app.js. Que la busqueda use la misma transformacion que el
// alta es lo que garantiza que `?slug=Reactor Asesor` encuentre a
// `reactor-asesor`: el cliente puede mandar el slug ya armado o el texto del
// nombre, y los dos caen en la misma clave.
function exSlugify(mixed $raw): string {
    $s = trim((string)$raw);
    if ($s === '') return '';
    $s = exPlegarAcentos($s);
    $s = mb_strtolower($s, 'UTF-8');
    $s = preg_replace('/[^a-z0-9]+/', '-', $s) ?? $s;
    $s = trim($s, '-');
    return substr($s, 0, DR_EX_SLUG_MAX);
}

// Deja un texto apto para viajar como valor de header HTTP.
//
// Los headers son ASCII: "Vigía Asesor" mandado crudo llega mojibake o hace que
// el cliente descarte el header entero, y un salto de linea metido en el valor
// es una inyeccion de headers de manual. Se pliegan los acentos y se descarta
// todo lo que no sea ASCII imprimible.
//
// El nombre es dato de diagnostico, no el payload: quien necesite el texto
// exacto lo pide con `&formato=json`, donde viaja intacto.
function exHeaderSafe(mixed $raw): string {
    $s = exPlegarAcentos((string)$raw);
    return trim(preg_replace('/[^\x20-\x7E]+/', '', $s) ?? '');
}

// Normaliza un id que llega por query string: vacio / no numerico / <= 0 ->
// null (equivale a "sin filtro", no a filtrar por 0).
function exFiltroId(mixed $v): ?int {
    if ($v === null) return null;
    $s = trim((string)$v);
    if ($s === '' || !ctype_digit($s)) return null;
    $n = (int)$s;
    return $n > 0 ? $n : null;
}

// PDO devuelve todo como string; el JSON publica ints donde la columna es int.
//
// `contexto` solo aparece con `$conContexto = true` (consulta individual). El
// listado manda unicamente `contexto_largo` y `contexto_extracto`: el texto
// entero puede pesar cientos de KB por fila y ahi solo hace falta saber si hay
// y cuanto. Mismo recorte que el ABM cloud.
//
// `proyecto_id` puede ser NULL de verdad (experto transversal al grupo), asi
// que NO se castea a 0 — al reves que en embudos, donde la columna es
// obligatoria.
function exFormatFila(array $r, bool $conContexto): array {
    $contexto = rtrim((string)($r['contexto'] ?? ''));

    // El extracto se arma sobre el texto con los saltos colapsados: el Markdown
    // crudo arranca casi siempre con un '# Titulo' seguido de linea en blanco, y
    // un extracto que los conserve se ve como una celda vacia.
    $plano    = trim(preg_replace('/\s+/u', ' ', $contexto) ?? $contexto);
    $extracto = mb_substr($plano, 0, DR_EX_EXTRACTO_LEN);
    if (mb_strlen($plano) > DR_EX_EXTRACTO_LEN) $extracto .= '…';

    $out = [
        'id'                 => (int)$r['id'],
        'proyecto_id'        => $r['proyecto_id'] !== null ? (int)$r['proyecto_id'] : null,
        'proyecto_nombre'    => isset($r['proyecto_nombre']) && $r['proyecto_nombre'] !== null
                                ? (string)$r['proyecto_nombre'] : null,
        'slug'               => (string)$r['slug'],
        'nombre'             => (string)$r['nombre'],
        // En el listado el largo lo calcula MySQL sobre la columna entera
        // (`CHAR_LENGTH`), porque `$contexto` ahi es solo el prefijo que se
        // trajo para el extracto. En la consulta individual no viene la clave y
        // se mide el texto completo, que si esta.
        'contexto_largo'     => isset($r['contexto_largo'])
                                ? (int)$r['contexto_largo'] : mb_strlen($contexto),
        'contexto_extracto'  => $extracto,
        'activo'             => (int)$r['activo'],
        'fecha_creacion'     => $r['fecha_creacion']     ?? null,
        'fecha_modificacion' => $r['fecha_modificacion'] ?? null,
    ];

    if ($conContexto) $out['contexto'] = $contexto === '' ? null : $contexto;

    return $out;
}

// ---------------------------------------------------------------------------
// Acceso a datos
// ---------------------------------------------------------------------------

// Las dos consultas individuales traen el `contexto` entero: es el payload del
// endpoint, no un extra.
function exBuscarPorId(PDO $pdo, int $id): ?array {
    $st = $pdo->prepare('SELECT ' . DR_EX_COLS . ', x.contexto, p.nombre AS proyecto_nombre '
                      . DR_EX_FROM . ' WHERE x.id = :id LIMIT 1');
    $st->execute([':id' => $id]);
    return $st->fetch() ?: null;
}

// `$slug` tiene que venir YA normalizado por exSlugify().
//
// Devuelve una fila o null — sin lista de candidatos ni 409 como en embudos:
// el UNIQUE de esta tabla es `slug` a secas (`uq_drex_slug`), global, asi que la
// ambiguedad no puede existir. La igualdad la resuelve la collation
// utf8mb4_general_ci de la columna, apoyada en ese mismo indice, o sea que
// busqueda y constraint no pueden divergir.
function exBuscarPorSlug(PDO $pdo, string $slug): ?array {
    $st = $pdo->prepare('SELECT ' . DR_EX_COLS . ', x.contexto, p.nombre AS proyecto_nombre '
                      . DR_EX_FROM . ' WHERE x.slug = :s LIMIT 1');
    $st->execute([':s' => $slug]);
    return $st->fetch() ?: null;
}

// ---------------------------------------------------------------------------
// Entrega
// ---------------------------------------------------------------------------

// Puerta unica de las dos consultas individuales: `?id=N` y `?slug=` devuelven
// exactamente la misma cosa para el mismo `formato`, y lo unico que cambia
// entre ellas es cual es el default.
function exEntregar(array $fila, array $q, string $formatoDefault): void {
    if (exFormato($q, $formatoDefault) === 'json') {
        jsonOk(exFormatFila($fila, true));
    }
    exEnviarMarkdown($fila, exContextoServible($fila, $q));
}

// Devuelve el contexto listo para mandar, o corta con 409 si el experto existe
// pero no esta en condiciones de contestar (ver el encabezado). Los dos cortes
// son 409 y no 404 porque el slug SI existe: el problema no es como lo escribio
// quien integra, es el estado de la fila en el panel.
function exContextoServible(array $r, array $q): string {
    $slug = (string)$r['slug'];

    if ((int)$r['activo'] !== 1 && !exFlagBool($q, 'incluir_inactivos')) {
        jsonError('El experto `' . $slug . '` existe pero esta desactivado, asi que no se '
                . 'sirve su contexto. Reactivalo desde el ABM del panel cloud, o agrega '
                . '`&incluir_inactivos=1` si lo que queres es previsualizarlo.', 409,
                ['experto' => exFormatFila($r, false)]);
    }

    // rtrim() y no trim(): la cola sobrante es ruido del textarea del ABM, pero
    // lo que este al principio es parte del prompt y no se toca.
    $contexto = rtrim((string)($r['contexto'] ?? ''));
    if ($contexto === '') {
        jsonError('El experto `' . $slug . '` no tiene contexto cargado. Cargaselo desde el '
                . 'ABM del panel cloud (Sistemas > Datarocket > Expertos): sin contexto no '
                . 'hay prompt de sistema que mandar.', 409,
                ['experto' => exFormatFila($r, false)]);
    }

    return $contexto;
}

// Manda el contexto como documento Markdown.
//
// El cuerpo es el texto TAL CUAL, sin nada agregado: cualquier encabezado o
// separador que este archivo le sumara se convertiria en parte del prompt de
// sistema del modelo.
//
// La metadata que el consumidor igual suele querer (que experto contesto, de
// que proyecto, cuando se edito) viaja en headers `X-Experto-*`, que no tocan
// el cuerpo. Para la version estructurada y completa esta `&formato=json`.
function exEnviarMarkdown(array $r, string $contexto): void {
    // ETag sobre el contexto y no sobre `fecha_modificacion`: la fecha cambia
    // con cualquier edicion de la fila (renombrar el experto, desactivarlo) y
    // eso invalidaria el cache de un prompt que no cambio. El hash cambia si y
    // solo si cambio el texto, que es lo unico que este cuerpo transporta.
    $etag = '"' . md5($contexto) . '"';

    header('Content-Type: text/markdown; charset=utf-8');
    header('ETag: ' . $etag);
    // El prompt de sistema se relee seguido (tipico: una vez por conversacion
    // nueva) y cambia poco. `no-cache` no prohibe cachear: obliga a revalidar,
    // que es justo lo que habilita el 304 de abajo. Una edicion en el panel se
    // ve en la llamada siguiente, no cuando vence un TTL.
    header('Cache-Control: no-cache');
    header('X-Experto-Id: '                  . (int)$r['id']);
    header('X-Experto-Slug: '                . (string)$r['slug']);
    header('X-Experto-Nombre: '              . exHeaderSafe($r['nombre'] ?? ''));
    header('X-Experto-Caracteres: '          . mb_strlen($contexto));
    header('X-Experto-Fecha-Modificacion: '  . (string)($r['fecha_modificacion'] ?? ''));
    // Se omite en vez de mandarse vacio: `proyecto_id` NULL significa "experto
    // transversal al grupo", que es un dato, no un campo sin cargar. Un header
    // en blanco los mezcla.
    if ($r['proyecto_id'] !== null) {
        header('X-Experto-Proyecto-Id: ' . (int)$r['proyecto_id']);
    }

    if (exIfNoneMatch($etag)) {
        http_response_code(304);
        exit;
    }

    echo $contexto;
    exit;
}

// Resuelve el `If-None-Match` del request contra el ETag actual. Acepta la
// lista separada por comas y el prefijo `W/` de los validadores debiles, que es
// lo que mandan varios clientes HTTP al repetir un ETag que recibieron fuerte.
function exIfNoneMatch(string $etag): bool {
    $raw = trim((string)($_SERVER['HTTP_IF_NONE_MATCH'] ?? ''));
    if ($raw === '') return false;

    foreach (explode(',', $raw) as $candidato) {
        $candidato = trim($candidato);
        if (str_starts_with($candidato, 'W/')) $candidato = substr($candidato, 2);
        if ($candidato === '*' || $candidato === $etag) return true;
    }
    return false;
}

// ---------------------------------------------------------------------------
// Handlers
// ---------------------------------------------------------------------------

// El listado no busca: filtra. `codigo` es el `id` con el nombre que usa el ABM,
// `proyecto_id` y `activo` acotan el conjunto por columnas cerradas, y el resto
// es presentacion. La busqueda por texto vive en `?slug=`, que resuelve una fila
// y no un subconjunto (ver exAssertBusqueda).
//
// Siempre devuelve JSON: es un catalogo de varias filas, y no existe "el
// Markdown" de un conjunto. Un `&formato=md` aca se ignora a proposito, porque
// no hay ninguna lectura razonable de ese pedido.
function exHandleList(PDO $pdo, array $q): void {
    $codigo   = exFiltroId($q['codigo']      ?? null);
    $proyecto = exFiltroId($q['proyecto_id'] ?? null);
    $activo   = isset($q['activo']) && trim((string)$q['activo']) !== ''
                ? (int)!!$q['activo'] : null;

    // `orden` es el nombre que usa el ABM cloud y `order_by` el que usa el resto
    // de v4: se aceptan los dos para no obligar a recordar cual va en cada capa.
    $orderBy = (string)($q['order_by'] ?? $q['orden'] ?? 'slug');
    if (!array_key_exists($orderBy, DR_EX_ORDENES)) $orderBy = 'slug';
    $orderSql = DR_EX_ORDENES[$orderBy];

    // Default alfabetico ascendente: esto es un catalogo chico (4 expertos al
    // 2026-09-29) que casi siempre termina en un combo o en un vistazo, y ahi el
    // orden util es el alfabetico. Para los demas criterios el default es `desc`.
    $dirDefault = in_array($orderBy, ['slug', 'nombre'], true) ? 'asc' : 'desc';
    $dirSql     = strtolower((string)($q['dir'] ?? $dirDefault)) === 'asc' ? 'ASC' : 'DESC';

    $limite = isset($q['limite']) ? (int)$q['limite'] : 100;
    if ($limite < 1)    $limite = 1;
    if ($limite > 1000) $limite = 1000;

    $where  = [];
    $params = [];

    if ($codigo !== null) {
        $where[] = 'x.id = :codigo';
        $params[':codigo'] = $codigo;
    }
    if ($proyecto !== null) {
        $where[] = 'x.proyecto_id = :proyecto_id';
        $params[':proyecto_id'] = $proyecto;
    }
    if ($activo !== null) {
        $where[] = 'x.activo = :activo';
        $params[':activo'] = $activo;
    }
    $sqlWhere = $where ? ('WHERE ' . implode(' AND ', $where)) : '';

    // Desempate por `nombre` para que el orden sea determinista cuando la
    // columna elegida empata (pasa siempre con `activo`).
    $desempate = $orderBy === 'nombre' ? '' : ', x.nombre ASC';

    // CHAR_LENGTH sobre la columna entera + LEFT para el extracto: el
    // `contexto` no se trae completo (ver DR_EX_EXTRACTO_FETCH). Las dos
    // funciones cuentan CARACTERES, no bytes, asi que el corte no puede partir
    // un multibyte al medio.
    //
    // `limite` ya viene clampeado a [1, 1000] e interpolado como int — no puede
    // ir como placeholder porque PDO lo citaria como string y MySQL rechaza
    // `LIMIT '100'`.
    $sql = 'SELECT ' . DR_EX_COLS . ', p.nombre AS proyecto_nombre,
                   CHAR_LENGTH(x.contexto) AS contexto_largo,
                   LEFT(x.contexto, ' . DR_EX_EXTRACTO_FETCH . ') AS contexto
            ' . DR_EX_FROM . "
            {$sqlWhere}
            ORDER BY {$orderSql} {$dirSql}{$desempate}
            LIMIT {$limite}";
    $st = $pdo->prepare($sql);
    $st->execute($params);

    $items = array_map(fn($r) => exFormatFila($r, false), $st->fetchAll());

    jsonOk([
        'total' => count($items),
        'items' => $items,
    ]);
}

// GET /v4/datarocket/expertos?id=N[&formato=md]
//
// Consulta individual por el id de la base. Default `json`: quien entra por aca
// ya resolvio el experto y esta pidiendo el registro. El contexto entero viene
// igual en `data.contexto`; `&formato=md` lo entrega como documento.
function exHandleGetOne(PDO $pdo, int $id, array $q): void {
    $fila = exBuscarPorId($pdo, $id);
    if ($fila === null) jsonError('Experto no encontrado', 404);

    exEntregar($fila, $q, 'json');
}

// GET /v4/datarocket/expertos?slug=reactor-asesor[&formato=json]
//
// La operacion que motiva el endpoint: el canal de entrada tiene el
// identificador estable escrito en su config y necesita el prompt de sistema.
// Default `md` — devuelve el contexto crudo, listo para anteponerle al modelo.
function exHandleGetPorSlug(PDO $pdo, string $raw, array $q): void {
    $slug = exSlugify($raw);
    // `?slug=` vacio no se trata como "sin filtro": el cliente pidio buscar un
    // slug y devolverle el catalogo entero seria contestarle otra pregunta.
    if ($slug === '') {
        jsonError('El `slug` a buscar no puede estar vacio.', 400);
    }

    $fila = exBuscarPorSlug($pdo, $slug);
    if ($fila === null) {
        // El slug normalizado viaja en el error para que se entienda contra que
        // se busco realmente ("Reactor Asesor" se busco como "reactor-asesor").
        jsonError('Experto no encontrado', 404, ['consulta' => ['slug' => $slug]]);
    }

    exEntregar($fila, $q, 'md');
}
