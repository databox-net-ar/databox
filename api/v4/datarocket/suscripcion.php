<?php
// api/v4/datarocket/suscripcion.php
// Microservicio del CRM Datarocket: registra un prospecto Y lo suscribe a una
// lista en la misma llamada, resolviendo la lista por SLUG y las etiquetas por
// NOMBRE. Una sola operacion, atomica.
//
//   POST /v4/datarocket/suscripcion                     -> alta + suscripcion (+ etiquetas)
//   POST /v4/datarocket/suscripcion?crear_etiquetas=1   -> idem, creando las etiquetas nuevas
//
// Cualquier otro metodo devuelve 405.
//
// Auth: Bearer con apikey de la tabla `aplicaciones` (mismo esquema que el resto
// del stack — ver cloud/api/lib/apikey_auth.php). Cualquier apikey habilitada pasa.
//
// ---------------------------------------------------------------------------
// PARA QUE EXISTE SI YA ESTA /v4/datarocket/prospectos
// ---------------------------------------------------------------------------
// `POST /v4/datarocket/prospectos` ya acepta `lista_ids` y `etiqueta_ids`, asi
// que "dar de alta y suscribir" parece resuelto. No lo esta, y por cuatro
// motivos que juntos hacen que un formulario de suscripcion no pueda usarlo:
//
//   1. AHI LAS LISTAS Y LAS ETIQUETAS VAN POR ID, no por slug ni por nombre. Lo
//      que un integrador tiene escrito en su config es `vigicom-usuarios`, no
//      el `136` que le toco a esa lista en esta base. Hoy necesita dos llamadas
//      previas (/v4/datarocket/listas?slug=... y /v4/datarocket/etiquetas?slug=...)
//      solo para traducir. Aca el slug viaja en el mismo POST.
//
//   2. AHI UN SUSCRIPTOR QUE VUELVE SE COME UN 409. El alta simple de prospectos
//      rechaza el `correo` repetido, que es exactamente lo que hace alguien que
//      ya estaba en la base y se suscribe a otra lista (o a la misma dos veces
//      desde dos landings). Un formulario de suscripcion NO puede fallar por
//      eso: se reutiliza la ficha y se sigue. Ver "IDENTIFICACION" mas abajo.
//
//   3. AHI LA SUSCRIPCION NO PASA POR LA PUERTA UNICA. `drPrSyncListas()` de
//      prospectos.php escribe `datarocket_prospectos_listas` a mano: no deja
//      historial en `datarocket_listas_altas` y no recalcula
//      `datarocket_listas.suscriptos`, que queda mintiendo hasta el proximo
//      recalculo manual. Este endpoint delega en drListaSuscribir()
//      (cloud/api/lib/datarocket_listas_suscripciones.php), que hace las dos
//      cosas en la misma transaccion. Ver "QUE ESCRIBE".
//
//   4. AHI `lista_ids` ES UN FULL REPLACE. `drPrSyncListas()` borra TODAS las
//      suscripciones del prospecto y deja solo las del body. Un formulario que
//      suscribe a la lista A le estaria dando de baja de las B y C sin que nadie
//      se entere. Aca la suscripcion es ADITIVA por definicion, y las etiquetas
//      tambien.
//
// El reparto queda asi: la ficha completa (domicilio, ubicacion, redes, DNI) se
// carga y se corrige por /v4/datarocket/prospectos; suscribir se hace por aca.
// Ver "QUE CAMPOS ACEPTA".
//
// ---------------------------------------------------------------------------
// IDENTIFICACION: A QUE PROSPECTO SE LE CUELGA LA SUSCRIPCION
// ---------------------------------------------------------------------------
// Mismo criterio que el alta con consulta de /v4/datarocket/prospectos
// (drPrProspectoAReutilizar): el `correo` es el factor PRINCIPAL y el `celular`
// el de RESPALDO, y el respaldo solo entra cuando la llamada no trae correo.
//
//   con correo:  correo en la base   -> esa ficha (la mas reciente si hay varias)
//                correo que no esta  -> prospecto nuevo
//   sin correo:  celular en la base  -> esa ficha
//                celular que no esta -> prospecto nuevo
//
// Que el correo NO caiga al respaldo cuando no matchea es a proposito: si la
// llamada trae correo, ese correo es la identidad que declara, y un celular
// compartido (el conmutador de una empresa, un telefono familiar) no debe
// mandar la suscripcion al legajo de otra persona.
//
// NUNCA se contesta 409 por duplicado. Registrar la suscripcion es lo que no se
// puede perder: una suscripcion rechazada es alguien que dejo su correo, cree
// que se suscribio, y no le va a llegar nada.
//
// SOBRE UNA FICHA QUE YA EXISTIA NO SE PISA NADA. Los datos de un formulario
// suelen ser mas pobres que los que ya tiene cargados (un alta previa con cargo
// y domicilio, una suscripcion nueva con solo el correo). Lo unico que se
// escribe son los HUECOS de contacto — ver susCompletarFicha().
//
// ---------------------------------------------------------------------------
// AL MENOS `correo` O `celular`
// ---------------------------------------------------------------------------
// Sin una via de contacto no se da de alta nada. Una lista es lo que consume un
// envio masivo: un suscriptor sin destino no puede recibir nada, no se puede
// reencontrar por ningun campo (cada llamada nueva abriria otra ficha) y
// ademas entra al denormalizado `suscriptos` inflando un numero que despues
// nadie puede explicar. Es basura, no un lead.
//
// OJO que esta regla es de ESTE endpoint. El alta a secas de
// /v4/datarocket/prospectos sigue aceptando fichas sin contacto — un padron, un
// scraping — y esta bien que asi sea: 9.679 de las 43.244 filas no tienen
// correo y son legitimas. La diferencia es que ahi no se suscribe a nadie.
//
// ---------------------------------------------------------------------------
// EL NOMBRE HACE FALTA SOLO SI HAY QUE CREAR LA FICHA
// ---------------------------------------------------------------------------
// `datarocket_prospectos` tiene una INVARIANTE DE IDENTIDAD documentada en el
// schema: el campo de nombre del lado que marca `tipo` es obligatorio y
// `nombre` se DERIVA de el (tipo='persona' -> persona_nombre -> nombre). El
// schema es explicito en que la sostiene la capa PHP y en que "cualquier tercer
// escritor que se agregue tiene que replicarla o la tabla se vuelve a
// ensuciar". Este archivo es ese tercer escritor —los otros dos son
// cloud/api/datarocketprospectos.php y api/v4/datarocket/prospectos.php— y por
// eso susAssertIdentidad() / susDerivarNombre() estan replicadas aca abajo. No
// es duplicacion por comodidad: es la condicion que el schema le pone a un
// escritor nuevo. Fue justamente un importador sin esta validacion el que dejo
// 989 filas con `tipo='persona'` y `persona_nombre` NULL (migracion 20260817_2100).
//
// Consecuencia practica: el nombre es obligatorio para CREAR, y se ignora
// cuando la ficha ya existia (ahi no se pisa nada). O sea que el mismo payload
// sin nombre da 400 para alguien nuevo y 200 para alguien que ya estaba.
//
// ES DELIBERADO, pero conviene tenerlo presente: un formulario que solo pide el
// correo va a funcionar con los suscriptores que ya estan en la base y a fallar
// con los nuevos. LA RECOMENDACION PARA UN INTEGRADOR ES MANDAR SIEMPRE EL
// NOMBRE. La alternativa —aceptar la ficha sin nombre— era romper la invariante
// del schema, y la otra —rellenar `nombre` con el correo— era inventar un dato
// que despues aparece tal cual en el saludo de una plantilla ("Hola
// juan@gmail.com").
//
// `tipo` no es obligatorio: por default es `persona`, que es lo que se suscribe
// a una lista en el 99% de los casos. `nombre` es un alias que cae en el campo
// que corresponda al tipo, asi que un formulario puede mandar `nombre` a secas
// sin saber si va a `persona_nombre` o a `empresa_nombre`.
//
// ---------------------------------------------------------------------------
// QUE ESCRIBE
// ---------------------------------------------------------------------------
// La suscripcion NO se escribe aca: se delega en drListaSuscribir(), la unica
// puerta de entrada y salida de las listas
// (cloud/api/lib/datarocket_listas_suscripciones.php). Esa funcion registra el
// historial en `datarocket_listas_altas` ANTES de tocar la puente, denormaliza
// el `destino` del momento y recalcula `datarocket_listas.suscriptos`, todo en
// la misma transaccion. Lo unico que aporta este endpoint es el contexto:
// `motivo` (default `solicitada`), `origen` = DR_SU_ORIGEN y un `detalle` que
// siempre dice que aplicacion lo pidio.
//
// Todo el POST corre en UNA transaccion: prospecto + etiquetas + suscripcion
// entran juntos o no entra ninguno. Sin eso un fallo a mitad de camino dejaria
// una ficha creada que nadie pidio y que ninguna lista referencia.
//
// El alta de la suscripcion queda auditada en `datarocket_listas_altas`, que es
// mejor rastro que un suceso: dice cuando, con que destino, por que motivo y
// desde que origen. En `sucesos` solo se registran los errores (via _lib/log.php)
// y el alta de una ETIQUETA nueva, que es lo unico que toca un catalogo
// compartido por todo Datarocket.
//
// ---------------------------------------------------------------------------
// LAS ETIQUETAS QUE NO EXISTEN CORTAN CON 400, SALVO `?crear_etiquetas=1`
// ---------------------------------------------------------------------------
// Descartar en silencio una etiqueta desconocida seria lo peor de los dos
// mundos: el prospecto entra sin etiquetar y el cliente se va con un 200
// creyendo que quedo etiquetado. Un typo (`vipp`) no se descubriria nunca.
//
// Crearla sola tampoco puede ser el default: `datarocket_etiquetas` es un
// catalogo UNICO compartido por todo Datarocket (30 filas hoy), y una
// integracion con un typo lo ensucia para todos. Por eso crear es un opt-in
// explicito —`?crear_etiquetas=1`—, el mismo criterio con el que
// /v4/datarocket/etiquetas separa `POST` de `POST ?resolver=1`. Cuando se crea
// una queda un `info` en `sucesos` con el nombre de la app que la entro.
//
// La resolucion es por SLUG primero y por NOMBRE despues; las dos columnas son
// UNIQUE, asi que no hay ambiguedad posible. El termino se slugifica con la
// misma transformacion que usa el alta del catalogo, asi que `Expo`, `EXPO`,
// `expó` y `expo` caen todos en la misma etiqueta.
//
// ---------------------------------------------------------------------------
// UNA LISTA POR LLAMADA
// ---------------------------------------------------------------------------
// `lista` es un slug, en singular. Para suscribir a varias se llama varias
// veces, y es seguro: la operacion es IDEMPOTENTE (ver abajo), asi que la
// segunda llamada no duplica la ficha ni vuelve a contar el alta.
//
// El motivo de no aceptar un array es el contrato de error. El slug de lista es
// UNIQUE por PROYECTO, no global, asi que un slug puede matchear en dos
// proyectos y eso se contesta con 409 pidiendo `proyecto_id` (ver
// susResolverLista). Con varias listas en el mismo body habria que decidir que
// pasa cuando una resuelve y otra no: fallar entera es negarle al cliente las
// que si estaban, y aplicar las buenas devolviendo un error parcial es una
// respuesta que nadie lee bien. Con una lista por llamada la respuesta es si o
// no, y el cliente decide que hacer con cada una.
//
// ---------------------------------------------------------------------------
// IDEMPOTENTE
// ---------------------------------------------------------------------------
// Repetir el mismo POST no duplica nada: la ficha se reutiliza, la etiqueta ya
// aplicada no se vuelve a insertar y drListaSuscribir() devuelve 0 sin escribir
// historial (solo registra lo que CAMBIA de verdad). La respuesta lo dice campo
// por campo — `prospecto.creado`, `suscripcion.nueva`, `etiquetas[].aplicada` —
// asi que el cliente puede distinguir "lo hice" de "ya estaba" sin adivinar.
//
// El codigo HTTP sigue el mismo criterio que el alta con consulta de
// /v4/datarocket/prospectos: 201 cuando se creo la ficha, 200 cuando se
// reutilizo una que ya existia. El estado de la suscripcion no lo mueve — para
// eso esta `suscripcion.nueva`.

declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');

require_once dirname(__DIR__, 3) . '/env.php';
require_once dirname(__DIR__, 3) . '/cloud/api/db.php';
require_once dirname(__DIR__, 3) . '/cloud/api/lib/sucesos.php';
require_once dirname(__DIR__, 3) . '/cloud/api/lib/prospectos_normalizar.php';
require_once dirname(__DIR__, 3) . '/cloud/api/lib/datarocket_etiquetas_uso.php';
require_once dirname(__DIR__, 3) . '/cloud/api/lib/datarocket_listas_suscripciones.php';
require_once dirname(__DIR__) . '/_lib/log.php';

// Todo error de este endpoint queda registrado en `sucesos` (Visor de sucesos
// del panel). Va antes de la auth para que los 401 tambien caigan adentro.
v4InitLog('v4/datarocket.suscripcion');

// ---------------------------------------------------------------------------
// Auth
// ---------------------------------------------------------------------------
// Mismo shape que cloud/api/lib/apikey_auth.php, rodado inline como el resto de
// los microservicios v4 para no arrastrar dependencias. Apache no siempre
// propaga Authorization a $_SERVER (depende de mod_rewrite y CGIPassAuth), asi
// que se chequea $_SERVER, REDIRECT_HTTP_AUTHORIZATION y getallheaders().

function susReadBearer(): string {
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

function susRequireApp(): array {
    $token = susReadBearer();
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

// `datarocket_listas_altas.origen` es varchar(50) y es la columna por la que se
// filtra el historial. Fija y no parametrizable: identifica LA PUERTA por la que
// entro el alta, no al cliente. Dejarla elegir permitiria que una integracion se
// hiciera pasar por el ABM del panel. Quien llamo va en `detalle` (ver susCtxAlta).
const DR_SU_ORIGEN = 'v4/datarocket.suscripcion';

// Valor por defecto de `datarocket_listas_altas.motivo` (catalogo
// `datarocket_lista_alta_motivo` en `estados`: manual / solicitada /
// preexistente). `solicitada` porque el caso que motiva el endpoint es alguien
// dejando su correo en un formulario — la misma semantica con la que la pagina
// publica www/datarocket/suscripcion registra una re-suscripcion. Un importador
// que sepa que no fue solicitada manda `motivo` en el body.
const DR_SU_MOTIVO_DEFAULT = 'solicitada';

// `datarocket_prospectos.tipo`. Default `persona`: es lo que se suscribe a una
// lista salvo excepcion, y obligar a declararlo seria friccion pura en un
// formulario. Ver "EL NOMBRE HACE FALTA SOLO SI HAY QUE CREAR LA FICHA".
const DR_SU_TIPO_DEFAULT  = 'persona';
const DR_SU_TIPOS_VALIDOS = ['persona', 'empresa'];

// = varchar(40) de `datarocket_listas.slug` y de `datarocket_etiquetas.slug`.
const DR_SU_SLUG_MAX = 40;

// = varchar(80) de `datarocket_etiquetas.nombre`.
const DR_SU_ETIQUETA_NOMBRE_MAX = 80;

// Separador con el que /v4/datarocket/prospectos empaqueta `etiqueta_nombres` en
// su GROUP_CONCAT. Un nombre que lo contenga rompe el split del lado del
// consumidor, asi que no entra al catalogo (mismo chequeo que etiquetas.php).
const DR_SU_SEPARADOR_GC = '||~||';

// Techo de etiquetas por llamada. No hay un limite tecnico: es que un POST con
// 200 etiquetas no es una suscripcion, es un error del cliente (tipico: mandar
// una frase entera y que el CSV la parta). Cortar temprano evita ensuciar el
// catalogo compartido de un saque cuando ademas vino `?crear_etiquetas=1`.
const DR_SU_ETIQUETAS_MAX = 20;

// `datarocket_listas_altas.detalle` es varchar(255) — la lib ya trunca, esto es
// para recortar la parte del cliente y que el prefijo `app:` nunca se pierda.
const DR_SU_DETALLE_MAX = 255;

// ---------------------------------------------------------------------------
// Ruteo
// ---------------------------------------------------------------------------

try {
    $app    = v4LogApp(susRequireApp());
    $pdo    = db();
    $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

    if ($method !== 'POST') {
        // El detalle importa: quien prueba un GET aca no se equivoco de URL, se
        // equivoco de recurso. El mensaje lo manda a los dos endpoints que
        // contestan lo que probablemente buscaba — consultar y desuscribir— en
        // vez de dejarlo probando verbos.
        jsonError('Metodo no soportado. `/v4/datarocket/suscripcion` solo acepta POST. '
                . 'Para consultar a que listas pertenece un prospecto usa '
                . '`GET /v4/datarocket/prospectos?id=N` (`lista_ids` / `lista_nombres`), '
                . 'y para consultar el catalogo de listas `GET /v4/datarocket/listas`. '
                . 'La BAJA no se hace por API: la pide el destinatario desde el enlace '
                . 'firmado de sus correos, o la aplica un operador desde el ABM del panel '
                . 'cloud (Sistemas > Datarocket > Listas).', 405);
    }

    handleSuscribir($pdo, readJsonBody(), $_GET, $app);
} catch (Throwable $e) {
    jsonError($e->getMessage(), 500);
}

// ---------------------------------------------------------------------------
// Flags de query string
// ---------------------------------------------------------------------------

// Se acepta cualquier valor no vacio salvo los negativos explicitos, para que
// sirvan tanto `?crear_etiquetas=1` como `?crear_etiquetas=true`. Mismo criterio
// que el `?resolver=1` de etiquetas y el `?verificar=1` de prospectos.
function susFlagBool(array $q, string $clave): bool {
    if (!array_key_exists($clave, $q)) return false;
    $v = strtolower(trim((string)$q[$clave]));
    return !in_array($v, ['', '0', 'false', 'no'], true);
}

function susFlagCrearEtiquetas(array $q): bool { return susFlagBool($q, 'crear_etiquetas'); }

// ---------------------------------------------------------------------------
// Normalizacion
// ---------------------------------------------------------------------------

// Lleva un texto a kebab-case estricto: [a-z0-9-]+, sin acentos, sin guiones al
// borde, colapsando corridas de separadores, cortado a 40 caracteres.
//
// Es la COMPOSICION de las dos slugificaciones del arbol —`lisSlugify()` de
// api/v4/datarocket/listas.php y `etqSlugBusqueda()` (etqNormalizarNombre +
// etqSlugify) de api/v4/datarocket/etiquetas.php—, que difieren solo en el orden
// de los pasos y dan el mismo resultado para cualquier entrada. Que la busqueda
// use la MISMA transformacion que el alta es lo que garantiza que
// `lista: "Vigicom Usuarios"` caiga en `vigicom-usuarios` y que
// `etiquetas: ["EXPO"]` caiga en `expo`. Si cambia una, cambian las tres.
//
// El plegado de marcas combinantes U+0300-U+036F cubre el texto en forma NFD,
// donde la `í` viaja como `i` + tilde suelta (lo que mandan varios teclados de
// macOS / iOS) y por lo tanto no matchea la tabla `$pares`. Sin esto la tilde
// suelta caeria en el `[^a-z0-9]+` y quedaria un guion en el medio de la palabra
// ("frios" -> "fri-os"). El contenedor no trae `intl`, asi que la normalizacion
// es esta y no Normalizer::FORM_C.
//
// El `?? $s` de los preg_replace cubre una entrada que no sea UTF-8 valida: con
// el modificador /u eso devuelve null, y perder el texto entero es peor que
// dejarlo pasar sin plegar (mas adelante no matchea y sale 400, que es correcto).
function susSlugify(mixed $raw): string {
    $s = trim((string)$raw);
    if ($s === '') return '';
    $s = preg_replace('/\s+/u', ' ', $s) ?? $s;
    $s = preg_replace('/[\x{0300}-\x{036F}]+/u', '', $s) ?? $s;
    $s = mb_strtolower($s, 'UTF-8');
    $s = strtr($s, [
        'á'=>'a','é'=>'e','í'=>'i','ó'=>'o','ú'=>'u',
        'à'=>'a','è'=>'e','ì'=>'i','ò'=>'o','ù'=>'u',
        'ä'=>'a','ë'=>'e','ï'=>'i','ö'=>'o','ü'=>'u',
        'ñ'=>'n','ç'=>'c',
    ]);
    $s = preg_replace('/[^a-z0-9]+/', '-', $s) ?? $s;
    return substr(trim($s, '-'), 0, DR_SU_SLUG_MAX);
}

// Nombre canonico de una etiqueta: trim + corridas de espacios colapsadas +
// diacriticos combinantes plegados + minusculas. Espejo de etqNormalizarNombre()
// de api/v4/datarocket/etiquetas.php — es la clave logica del catalogo, y tiene
// que plegarse igual aca que ahi o el mismo texto crea dos filas.
function susPlegarNombre(mixed $raw): string {
    $s = trim((string)$raw);
    if ($s === '') return '';
    $s = preg_replace('/\s+/u', ' ', $s) ?? $s;
    $s = preg_replace('/[\x{0300}-\x{036F}]+/u', '', $s) ?? $s;
    return mb_strtolower($s, 'UTF-8');
}

function susNullableStr(mixed $v, ?int $max = null): ?string {
    if ($v === null) return null;
    $s = trim((string)$v);
    if ($s === '') return null;
    if ($max !== null) $s = mb_substr($s, 0, $max);
    return $s;
}

// Normaliza un id que llega por body o query string: vacio / no numerico / <= 0
// -> null (equivale a "no vino", no a "el id 0").
function susId(mixed $v): ?int {
    if ($v === null) return null;
    $s = trim((string)$v);
    if ($s === '' || !ctype_digit($s)) return null;
    $n = (int)$s;
    return $n > 0 ? $n : null;
}

// Genera un UUID v4 RFC 4122 (36 chars con guiones), el mismo formato que ya
// persiste `datarocket_prospectos.uuid` desde la migracion 20260727_2000.
function susUuidV4(): string {
    $d = random_bytes(16);
    $d[6] = chr((ord($d[6]) & 0x0f) | 0x40);
    $d[8] = chr((ord($d[8]) & 0x3f) | 0x80);
    return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($d), 4));
}

function susAhora(): string {
    return (new DateTime('now', new DateTimeZone('America/Argentina/Buenos_Aires')))
           ->format('Y-m-d H:i:s');
}

// ---------------------------------------------------------------------------
// Resolucion de la lista
// ---------------------------------------------------------------------------

// Devuelve la fila de `datarocket_listas` a la que hay que suscribir. Corta con
// 4xx si no resuelve.
//
// Se acepta `lista` (slug o el texto del nombre sin formatear) o `lista_id` (el
// id, para el cliente que ya lo resolvio contra /v4/datarocket/listas). El id
// gana si vienen los dos: es la referencia mas precisa de las dos.
//
// AMBIGUEDAD: el UNIQUE de la tabla es (`proyecto_id`, `slug`), no `slug` a
// secas, asi que dos proyectos pueden tener cada uno su `clientes`. Y como
// `proyecto_id` es NULLABLE y MySQL/MariaDB tratan cada NULL como distinto
// dentro de un indice unico, el UNIQUE ni siquiera restringe a las listas sin
// proyecto: dos huerfanas pueden compartir slug.
//
// Devolver "la primera" seria suscribir a alguien a la lista de otro proyecto
// sin que nadie se entere, y con listas eso es la audiencia equivocada de un
// envio masivo. Se contesta 409 con los candidatos y se desambigua con
// `proyecto_id` en el body — salvo que las coincidencias sean todas huerfanas,
// donde no hay valor de `proyecto_id` que las separe y la unica salida es
// `lista_id`. El error lo dice explicito segun el caso, en vez de recomendar
// algo que no puede funcionar.
//
// Corre ANTES de abrir la transaccion: una lista que no existe tiene que salir
// por 400 sin haber creado un prospecto que despues quedaria suelto.
function susResolverLista(PDO $pdo, array $in): array {
    $listaId = susId($in['lista_id'] ?? null);
    if ($listaId !== null) {
        $st = $pdo->prepare('SELECT id, proyecto_id, slug, nombre
                               FROM datarocket_listas WHERE id = :i LIMIT 1');
        $st->execute([':i' => $listaId]);
        $row = $st->fetch();
        if (!$row) jsonError('La lista con id ' . $listaId . ' no existe.', 400);
        return $row;
    }

    $raw  = (string)($in['lista'] ?? '');
    $slug = susSlugify($raw);
    if ($slug === '') {
        jsonError('Falta `lista`: el slug de la lista a la que suscribir. Se consulta en '
                . '`GET /v4/datarocket/listas` y acepta tambien el texto del nombre sin '
                . 'formatear (`"Vigicom Usuarios"` resuelve `vigicom-usuarios`).', 400);
    }

    $proyectoId = susId($in['proyecto_id'] ?? null);
    $sql    = 'SELECT id, proyecto_id, slug, nombre FROM datarocket_listas WHERE slug = :s';
    $params = [':s' => $slug];
    if ($proyectoId !== null) {
        $sql .= ' AND proyecto_id = :p';
        $params[':p'] = $proyectoId;
    }
    // El orden desempata la lista de candidatos del 409 de forma estable. Con
    // ASC los NULL de `proyecto_id` van primero en MySQL y en MariaDB.
    $sql .= ' ORDER BY proyecto_id ASC, id ASC';

    $st = $pdo->prepare($sql);
    $st->execute($params);
    $filas = $st->fetchAll();

    if (!$filas) {
        // El slug normalizado viaja en el error para que se entienda contra que
        // se busco realmente ("Vigicom Usuarios" se busco como "vigicom-usuarios").
        //
        // No hay un `?crear_listas=1` equivalente al de etiquetas, y no es una
        // omision: una etiqueta nueva es inofensiva, una lista nueva define una
        // audiencia de envio y arrastra la decision de a que proyecto pertenece.
        // Eso es curaduria del CRM y vive en el ABM del panel.
        $consulta = ['lista' => $slug];
        if ($proyectoId !== null) $consulta['proyecto_id'] = $proyectoId;
        jsonError('La lista `' . $slug . '` no existe'
                . ($proyectoId !== null ? " en el proyecto {$proyectoId}" : '')
                . '. El catalogo se consulta en `GET /v4/datarocket/listas`; las listas se '
                . 'crean desde el ABM del panel cloud.', 400, ['consulta' => $consulta]);
    }

    if (count($filas) > 1) {
        $sinProyecto = 0;
        foreach ($filas as $f) if ($f['proyecto_id'] === null) $sinProyecto++;

        $sugerencia = $sinProyecto > 1
            ? 'Hay ' . $sinProyecto . ' coincidencias sin proyecto y `proyecto_id` no las '
            . 'separa: elegi una de la lista y mandala por `lista_id`.'
            : 'Agrega `proyecto_id` al body para desambiguar.';

        jsonError('El slug de lista `' . $slug . '` existe en mas de un proyecto. ' . $sugerencia,
            409,
            ['consulta' => ['lista' => $slug], 'listas' => array_map(fn($f) => [
                'id'          => (int)$f['id'],
                'proyecto_id' => $f['proyecto_id'] !== null ? (int)$f['proyecto_id'] : null,
                'slug'        => (string)$f['slug'],
                'nombre'      => $f['nombre'] !== null ? (string)$f['nombre'] : null,
            ], $filas)]);
    }

    return $filas[0];
}

// ---------------------------------------------------------------------------
// Motivo del alta (catalogo `estados`)
// ---------------------------------------------------------------------------

// Valida `motivo` contra el catalogo `datarocket_lista_alta_motivo` y devuelve
// la variante CANONICA (la cargada en la tabla). Resuelve case-insensitive: los
// valores del catalogo estan en minuscula hoy, pero un cliente que mande
// "Solicitada" no deberia comerse un 400 por una mayuscula.
//
// Si el catalogo esta vacio se acepta lo que vino: un `estados` sin seed no
// tiene por que frenar una suscripcion. Es el mismo criterio de
// drPrValorDeCatalogo() en /v4/datarocket/prospectos.
function susResolverMotivo(PDO $pdo, array $in): string {
    $raw = trim((string)($in['motivo'] ?? '')) ?: DR_SU_MOTIVO_DEFAULT;

    $st = $pdo->prepare('SELECT valor FROM estados WHERE campo = :c ORDER BY orden, id');
    $st->execute([':c' => 'datarocket_lista_alta_motivo']);
    $validos = array_map('strval', array_column($st->fetchAll(), 'valor'));
    if (!$validos) return $raw;

    foreach ($validos as $v) {
        if (strcasecmp($v, $raw) === 0) return $v;
    }
    jsonError('El motivo `' . $raw . '` no existe. Valores validos: '
            . implode(', ', $validos) . '.', 400);
}

// Contexto que se le pasa a drListaSuscribir(). `origen` es fijo (identifica la
// puerta) y `detalle` SIEMPRE dice que aplicacion pidio el alta: es lo primero
// que se quiere saber mirando `datarocket_listas_altas` seis meses despues, y la
// tabla no tiene columna para el apikey. El texto del cliente, si vino, se
// concatena detras.
//
// `destino` se pisa a mano en vez de dejar que la lib lo saque de `p.correo`:
// un suscriptor identificado por celular no tiene correo cargado y el historial
// quedaria con `destino` NULL, sin poder responder despues "a que dato entro".
function susCtxAlta(array $in, string $motivo, array $app, int $prospectoId, ?string $destino): array {
    $detalle = 'app: ' . (string)($app['nombre'] ?? '?');
    $delCliente = susNullableStr($in['detalle'] ?? null);
    if ($delCliente !== null) $detalle .= ' — ' . $delCliente;

    return [
        'motivo'        => $motivo,
        'origen'        => DR_SU_ORIGEN,
        'detalle'       => mb_substr($detalle, 0, DR_SU_DETALLE_MAX),
        // Sin sesion: la suscripcion la pide una integracion, no un operador.
        'usuario_id'    => null,
        'por_prospecto' => [$prospectoId => ['destino' => $destino]],
    ];
}

// ---------------------------------------------------------------------------
// Payload del prospecto
// ---------------------------------------------------------------------------

// QUE CAMPOS ACEPTA
// -----------------
// El subconjunto que un formulario de suscripcion recolecta: identidad,
// contacto, procedencia y un comentario libre. La ficha COMPLETA —domicilio,
// ubicacion (`localidad_id` / `provincia_id` / `pais_id`), rubro, actividad,
// DNI, redes— se carga y se corrige por /v4/datarocket/prospectos, que valida
// las FK de los catalogos y expone el PATCH parcial.
//
// El recorte es deliberado y no una version a medias: aceptar aca el payload
// entero convertia este endpoint en una segunda implementacion del alta de
// prospectos, con dos juegos de validaciones que se separan a la primera
// modificacion. Lo que si comparte con esa alta son las REGLAS DE CAMPO, que
// viven en un lib (cloud/api/lib/prospectos_normalizar.php) y no aca: telefonos
// a 10 digitos argentinos, correo a minuscula validada, `web` como host + path
// sin esquema. Las claves desconocidas se ignoran, igual que en prospectos.
function susSanitize(array $in): array {
    $p = [
        'tipo'               => susNullableStr($in['tipo']               ?? null, 20),
        'persona_nombre'     => susNullableStr($in['persona_nombre']     ?? null, 255),
        'empresa_nombre'     => susNullableStr($in['empresa_nombre']     ?? null, 255),
        'empresa_cargo'      => susNullableStr($in['empresa_cargo']      ?? null, 255),
        'persona_genero'     => susNullableStr($in['persona_genero']     ?? null, 1),
        'persona_nacimiento' => susNullableStr($in['persona_nacimiento'] ?? null, 255),
        'ciudad'             => susNullableStr($in['ciudad']             ?? null, 255),
        'comentarios'        => susNullableStr($in['comentarios']        ?? null, 500),
        // Telefonos a 10 digitos argentinos y correo a minuscula validada. Ademas
        // de definir lo que se guarda, es lo que hace COMPARABLE la identificacion
        // de susProspectoAReutilizar(): sin esto "11 5678-1234" y "1156781234"
        // serian dos suscriptores distintos.
        'telefono' => prospectoNormalizarTelefono($in['telefono'] ?? null),
        'celular'  => prospectoNormalizarTelefono($in['celular']  ?? null),
        'whatsapp' => prospectoNormalizarTelefono($in['whatsapp'] ?? null),
        'correo'   => prospectoNormalizarCorreo($in['correo']     ?? null),
        // Host + path sin esquema; lo que no es una URL va a NULL.
        'web'      => prospectoNormalizarWeb($in['web'] ?? null),
        // Procedencia: de donde salio el suscriptor y quien lo trajo. Van SIN
        // normalizar — la URL se guarda tal cual vino, con esquema y con las
        // mayusculas del path y del query, porque es un link para volver a la
        // fuente. Mismo criterio que /v4/datarocket/prospectos.
        'extraccion_url'   => susNullableStr($in['extraccion_url']   ?? null, 500),
        'extraccion_autor' => susNullableStr($in['extraccion_autor'] ?? null, 255),
    ];

    // `nombre` es un ALIAS de conveniencia: cae en el campo de identidad que
    // corresponda al `tipo`, para que un formulario pueda mandar un solo campo
    // sin saber si va a `persona_nombre` o a `empresa_nombre`. Nunca pisa al
    // especifico si el cliente mando los dos.
    $alias = susNullableStr($in['nombre'] ?? null, 255);
    if ($alias !== null) {
        $esEmpresa = strcasecmp((string)($p['tipo'] ?? DR_SU_TIPO_DEFAULT), 'empresa') === 0;
        $campo = $esEmpresa ? 'empresa_nombre' : 'persona_nombre';
        if ($p[$campo] === null) $p[$campo] = $alias;
    }

    // Un correo cargado por error en `web` se rescata a `correo` cuando ese
    // campo viene vacio. Mismo criterio que el ABM cloud y que prospectos v4 —
    // y aca pesa mas que alla: sin correo no hay suscripcion posible.
    if ($p['correo'] === null) {
        $p['correo'] = prospectoWebComoCorreo($in['web'] ?? null);
    }

    return $p;
}

// Corta con 400 un `correo` que venga con algo escrito pero del que no se pueda
// extraer ninguna direccion valida. Se chequea sobre el payload CRUDO porque
// prospectoNormalizarCorreo() devuelve null tanto para "campo vacio" como para
// "campo con basura", y solo el segundo es un error del cliente.
//
// prospectoNormalizarCorreo() ya corrige sola todo lo corregible sin adivinar
// (acentos, el `@` escrito como "(a)", espacios tipeados, puntuacion espuria).
// Lo que llega hasta aca y da null es lo que no tiene arreglo deterministico:
// falta el TLD, falta el `@`, "hotmailcom", o directamente "no informado". Si
// aparece un patron nuevo y recurrente, la solucion es enseñarselo al
// normalizador, no relajar este chequeo.
function susAssertCorreo(array $in): void {
    if (!array_key_exists('correo', $in)) return;
    $raw = trim((string)($in['correo'] ?? ''));
    if ($raw === '') return;
    if (prospectoNormalizarCorreo($raw) === null) {
        jsonError('El correo no es válido.', 400);
    }
}

// Sin `correo` ni `celular` no se da de alta nada: ni la ficha ni la
// suscripcion. Ver el bloque "AL MENOS `correo` O `celular`" del encabezado.
//
// Se mira el valor NORMALIZADO, no el crudo: un `correo` de "no informado" o un
// `celular` sin ningun digito ya vinieron a null desde susSanitize() y tienen
// que contar como ausentes.
function susAssertContacto(array $p): void {
    if (($p['correo'] ?? null) !== null || ($p['celular'] ?? null) !== null) return;

    jsonError('Para suscribir hace falta al menos `correo` o `celular`: una lista es lo que '
            . 'consume un envio masivo, y un suscriptor sin via de contacto no puede recibir '
            . 'nada ni volver a identificarse en una llamada posterior.', 400);
}

// Invariante de identidad del schema, replicada (ver el encabezado): el campo de
// nombre del lado que marca `tipo` es obligatorio, porque es el que alimenta a
// `nombre` — la columna con la que el prospecto se lista, se busca y se saluda
// en una plantilla.
//
// Solo se exige el campo del tipo. El del OTRO lado sigue siendo opcional y es
// legitimo tenerlo cargado: en un prospecto persona `empresa_nombre` es donde
// trabaja, y en uno empresa `persona_nombre` es quien atiende.
//
// Corre UNICAMENTE en el camino de creacion. Sobre una ficha que ya existe no se
// valida nada: hay filas historicas con `tipo` NULL y sin nombre, y hacer
// fallar por eso una suscripcion seria pedirle al cliente que arregle datos que
// no toco y que ni siquiera puede ver.
function susAssertIdentidad(array $p): void {
    if (!in_array($p['tipo'], DR_SU_TIPOS_VALIDOS, true)) {
        jsonError('El `tipo` tiene que ser `persona` o `empresa` (por default `'
                . DR_SU_TIPO_DEFAULT . '`).', 400);
    }

    $campo = $p['tipo'] === 'persona' ? 'persona_nombre' : 'empresa_nombre';
    if (($p[$campo] ?? null) !== null) return;

    jsonError('Falta el nombre: no hay ninguna ficha cargada con ese contacto, asi que hay '
            . 'que crearla, y un prospecto de tipo `' . $p['tipo'] . '` necesita `' . $campo
            . '` (o `nombre`, que cae ahi solo). Solo hace falta cuando el prospecto es '
            . 'nuevo — si ya estaba en la base se reutiliza su ficha y no se pisa nada —, '
            . 'asi que conviene mandarlo siempre.', 400);
}

// `nombre` es DERIVADO, no un campo que el cliente elija: sale del campo de
// identidad que corresponde al `tipo`. Asi la columna no puede divergir de
// `persona_nombre` / `empresa_nombre`, que es como se ensucio la tabla antes de
// la backfill 20260817_2100. Asume susAssertIdentidad() ya corrido.
function susDerivarNombre(array $p): string {
    return $p['tipo'] === 'persona'
        ? (string)$p['persona_nombre']
        : (string)$p['empresa_nombre'];
}

// ---------------------------------------------------------------------------
// Identificacion del prospecto
// ---------------------------------------------------------------------------

// Ultima ficha (la mas reciente) con ese valor exacto en esa columna, o null.
// `$campo` sale siempre de un literal del call site, nunca del body, asi que
// interpolarlo es seguro; el valor va por bind.
//
// El desempate por `id DESC` importa porque la base historica arrastra
// duplicados en los dos campos: entre varias, la mas reciente es la que mas
// probablemente este en uso.
function susUltimoPorContacto(PDO $pdo, string $campo, ?string $valor): ?int {
    if ($valor === null || $valor === '') return null;
    $st = $pdo->prepare("SELECT id FROM datarocket_prospectos
                          WHERE {$campo} = :v ORDER BY id DESC LIMIT 1");
    $st->execute([':v' => $valor]);
    $row = $st->fetch();
    return $row ? (int)$row['id'] : null;
}

// Decide si la suscripcion puede colgarse de una ficha ya cargada. Devuelve su
// id, o null si hay que crear una nueva. Ver "IDENTIFICACION" en el encabezado.
//
// El caso "sin correo y sin celular" no llega hasta aca: lo corta antes
// susAssertContacto() con 400, sin crear nada.
function susProspectoAReutilizar(PDO $pdo, array $p): ?int {
    if ($p['correo'] !== null && $p['correo'] !== '') {
        return susUltimoPorContacto($pdo, 'correo', $p['correo']);
    }
    return susUltimoPorContacto($pdo, 'celular', $p['celular']);
}

// Crea la ficha con los campos aprovechables del payload. Devuelve la fila
// minima que necesita la respuesta.
function susCrearProspecto(PDO $pdo, array $p): array {
    $uuid       = susUuidV4();
    $registrado = susAhora();
    $nombre     = susDerivarNombre($p);

    $st = $pdo->prepare("
        INSERT INTO datarocket_prospectos
            (uuid, tipo, nombre, persona_nombre, empresa_nombre, empresa_cargo,
             persona_genero, persona_nacimiento, ciudad, telefono, celular, whatsapp,
             correo, web, comentarios, extraccion_url, extraccion_autor, registrado)
        VALUES
            (:uuid, :tipo, :nombre, :persona_nombre, :empresa_nombre, :empresa_cargo,
             :persona_genero, :persona_nacimiento, :ciudad, :telefono, :celular, :whatsapp,
             :correo, :web, :comentarios, :extraccion_url, :extraccion_autor, :registrado)
    ");
    $st->execute([
        ':uuid'               => $uuid,
        ':tipo'               => $p['tipo'],
        ':nombre'             => $nombre,
        ':persona_nombre'     => $p['persona_nombre'],
        ':empresa_nombre'     => $p['empresa_nombre'],
        ':empresa_cargo'      => $p['empresa_cargo'],
        ':persona_genero'     => $p['persona_genero'],
        ':persona_nacimiento' => $p['persona_nacimiento'],
        ':ciudad'             => $p['ciudad'],
        ':telefono'           => $p['telefono'],
        ':celular'            => $p['celular'],
        ':whatsapp'           => $p['whatsapp'],
        ':correo'             => $p['correo'],
        ':web'                => $p['web'],
        ':comentarios'        => $p['comentarios'],
        ':extraccion_url'     => $p['extraccion_url'],
        ':extraccion_autor'   => $p['extraccion_autor'],
        ':registrado'         => $registrado,
    ]);

    return [
        'id'         => (int)$pdo->lastInsertId(),
        'uuid'       => $uuid,
        'nombre'     => $nombre,
        'correo'     => $p['correo'],
        'celular'    => $p['celular'],
        'registrado' => $registrado,
    ];
}

// Completa los HUECOS de contacto de una ficha reutilizada con lo que trajo esta
// suscripcion. Devuelve la lista de campos escritos (vacia si no habia nada que
// completar).
//
// Es la unica excepcion al "no se pisa nada de lo cargado", y no lo contradice:
// no se reemplaza un dato por otro, se llena un vacio. El caso tipico es el
// prospecto identificado por su correo que nunca dejo un telefono y ahora lo
// deja. Sin esto la ficha se queda a medias para siempre.
//
// Un valor YA cargado no se toca ni se compara: si difiere del que vino, es una
// CORRECCION, y eso es un `PATCH /v4/datarocket/prospectos` deliberado, no un
// efecto colateral de suscribirse a una lista.
//
// Se limita a los cuatro campos de contacto y no a todo el payload a proposito.
// Son los unicos donde "vacio" es inequivocamente un hueco a llenar y donde el
// dato de un formulario de suscripcion es tan bueno como cualquier otro. Con
// `ciudad` o `empresa_cargo` no es asi: un dato viejo puede estar vacio porque
// nadie lo cargo o porque no aplica, y el de un formulario suele ser mas pobre.
//
// No se chequea unicidad antes del UPDATE, y no es un olvido: el celular que se
// escribe puede estar ya en otra ficha y esta bien que asi sea (un numero
// compartido, dos fichas historicas del mismo contacto). La columna no tiene
// UNIQUE y este endpoint no rechaza duplicados en ningun caso.
function susCompletarFicha(PDO $pdo, int $prospectoId, array $row, array $p): array {
    $campos = [];
    foreach (['correo', 'celular', 'whatsapp', 'telefono'] as $campo) {
        // La columna arrastra NULL y '' como "vacio" (el '' viene del default
        // historico de varias de ellas), asi que los dos cuentan como hueco.
        $guardado = trim((string)($row[$campo] ?? ''));
        $nuevo    = $p[$campo] ?? null;
        if ($guardado === '' && $nuevo !== null && $nuevo !== '') $campos[] = $campo;
    }
    if (!$campos) return [];

    $sets   = [];
    $params = [':i' => $prospectoId];
    foreach ($campos as $campo) {
        $sets[]              = "{$campo} = :{$campo}";
        $params[":{$campo}"] = $p[$campo];
    }
    // Los nombres de columna salen del array literal de arriba, nunca del body.
    $pdo->prepare('UPDATE datarocket_prospectos SET ' . implode(', ', $sets) . ' WHERE id = :i')
        ->execute($params);

    return $campos;
}

// ---------------------------------------------------------------------------
// Etiquetas
// ---------------------------------------------------------------------------

// Normaliza el parametro `etiquetas`. Acepta las dos formas con que un cliente
// puede mandarlo: `["expo","vip"]` (array) o `"expo,vip"` (CSV, que es lo que
// sale natural de un campo oculto de formulario). Devuelve los terminos crudos
// recortados, sin vacios y sin repetidos — la resolucion contra el catalogo la
// hace susResolverEtiquetas().
function susEtiquetasDelBody(mixed $raw): array {
    if ($raw === null) return [];
    $out = [];
    foreach (is_array($raw) ? $raw : [$raw] as $v) {
        // Un array de arrays / objetos no es una lista de etiquetas: cortar es
        // mejor que castear un array a "Array" y buscar esa etiqueta.
        if (is_array($v)) jsonError('`etiquetas` tiene que ser una lista de textos.', 400);
        foreach (explode(',', (string)$v) as $t) {
            $t = trim($t);
            if ($t !== '') $out[$t] = true;
        }
    }
    return array_keys($out);
}

// Resuelve los terminos contra `datarocket_etiquetas`. Devuelve
// `[ [termino, id|null, nombre, slug], ... ]` en el orden en que vinieron: las
// que no existen van con `id => null` y las resuelve (o rechaza) el llamador.
//
// SLUG PRIMERO, NOMBRE DESPUES. Las dos columnas son UNIQUE globales (el
// catalogo no tiene `proyecto_id`), asi que ninguna de las dos busquedas puede
// ser ambigua. El slug va primero porque es la referencia estable: `nombre` es
// texto libre y se edita desde el ABM ("expo" -> "Expo 2027"), y ahi el slug no
// se mueve. El fallback por nombre existe para el caso inverso — una etiqueta
// vieja cuyo slug quedo con sufijo (`santa-fe-2`) sigue encontrandose por su
// nombre exacto.
//
// Es solo LECTURA: no crea nada. Asi el 400 de una etiqueta desconocida sale
// antes de tocar la base. La creacion, si el cliente la habilito, la hace
// susCrearEtiqueta() ya adentro de la transaccion.
function susResolverEtiquetas(PDO $pdo, array $terminos): array {
    if (!$terminos) return [];

    if (count($terminos) > DR_SU_ETIQUETAS_MAX) {
        jsonError('Demasiadas etiquetas: llegaron ' . count($terminos) . ' y el maximo por '
                . 'llamada es ' . DR_SU_ETIQUETAS_MAX . '. Si mandaste una frase en `etiquetas`, '
                . 'revisa que no se este partiendo por las comas.', 400);
    }

    $porSlug   = $pdo->prepare('SELECT id, nombre, slug FROM datarocket_etiquetas WHERE slug = :s LIMIT 1');
    $porNombre = $pdo->prepare('SELECT id, nombre, slug FROM datarocket_etiquetas WHERE nombre = :n LIMIT 1');

    $out = [];
    foreach ($terminos as $termino) {
        $slug   = susSlugify($termino);
        $nombre = susPlegarNombre($termino);
        if ($slug === '' && $nombre === '') {
            jsonError('La etiqueta `' . $termino . '` no tiene ningun caracter aprovechable.', 400);
        }

        $fila = false;
        if ($slug !== '') {
            $porSlug->execute([':s' => $slug]);
            $fila = $porSlug->fetch();
        }
        if (!$fila && $nombre !== '') {
            // La igualdad la resuelve la collation utf8mb4_general_ci de la
            // columna — insensible a mayusculas y a acentos precompuestos —, que
            // es exactamente la que respalda el UNIQUE de `nombre`.
            $porNombre->execute([':n' => $nombre]);
            $fila = $porNombre->fetch();
        }

        $out[] = [
            'termino' => $termino,
            'id'      => $fila ? (int)$fila['id'] : null,
            'nombre'  => $fila ? (string)$fila['nombre'] : $nombre,
            'slug'    => $fila ? (string)$fila['slug']   : $slug,
        ];
    }
    return $out;
}

// Corta con 400 si quedo alguna etiqueta sin resolver y el cliente no habilito
// `?crear_etiquetas=1`. Ver el bloque del encabezado: ni descartarlas en
// silencio ni crearlas sin permiso son opciones.
function susAssertEtiquetasResueltas(array $etiquetas, bool $crear): void {
    if ($crear) return;
    $faltan = array_values(array_map(
        fn($e) => $e['termino'],
        array_filter($etiquetas, fn($e) => $e['id'] === null)
    ));
    if (!$faltan) return;

    jsonError('No existe' . (count($faltan) > 1 ? 'n las etiquetas' : ' la etiqueta') . ' '
            . '`' . implode('`, `', $faltan) . '`. Nunca se descartan en silencio: o las '
            . 'creas antes (`POST /v4/datarocket/etiquetas?resolver=1`), o repetis esta '
            . 'llamada con `?crear_etiquetas=1` para que se creen solas. El catalogo se '
            . 'consulta en `GET /v4/datarocket/etiquetas`.',
        400, ['faltantes' => $faltan]);
}

// Corta con 400 un nombre de etiqueta que no sirva como clave del catalogo.
// Mismos limites que el alta de /v4/datarocket/etiquetas — el catalogo es uno
// solo y no puede aceptar por esta puerta lo que rechaza por la otra.
function susAssertNombreEtiqueta(string $nombre): void {
    if (mb_strlen($nombre) > DR_SU_ETIQUETA_NOMBRE_MAX) {
        jsonError('El nombre de etiqueta `' . mb_substr($nombre, 0, 40) . '…` supera los '
                . DR_SU_ETIQUETA_NOMBRE_MAX . ' caracteres.', 400);
    }
    // Los parentesis eran los delimitadores del formato historico
    // `(expo)(visitante)` con el que las tablas legacy sin guion bajo
    // (`datarocketcontactos.tags`) todavia guardan sus etiquetas. Un nombre que
    // los contenga rompe cualquier parser que siga leyendo de ahi.
    if (strpbrk($nombre, '()') !== false) {
        jsonError('El nombre de etiqueta `' . $nombre . '` no puede contener parentesis.', 400);
    }
    if (str_contains($nombre, DR_SU_SEPARADOR_GC)) {
        jsonError('El nombre de etiqueta `' . $nombre . '` no puede contener la secuencia `'
                . DR_SU_SEPARADOR_GC . '`.', 400);
    }
}

// `nombre` UNIQUE no implica `slug` UNIQUE: dos nombres distintos pueden
// colapsar al mismo slug ("santa fe" y "santa-fe"). Como el slug es metadata
// derivada y no lo que el cliente pidio, un choque no justifica un error: se
// busca el primer sufijo libre (`-2`, `-3`, ...). El fallback `etiqueta` cubre un
// nombre sin caracteres alfanumericos (p.ej. "★"), valido como nombre pero que
// no deja slug. Espejo de etqSlugLibre() de /v4/datarocket/etiquetas.
function susSlugLibre(PDO $pdo, string $nombre): string {
    $base = susSlugify($nombre);
    if ($base === '') $base = 'etiqueta';

    $st   = $pdo->prepare('SELECT id FROM datarocket_etiquetas WHERE slug = :s LIMIT 1');
    $slug = $base;
    for ($i = 2; $i <= 99; $i++) {
        $st->execute([':s' => $slug]);
        if (!$st->fetch()) return $slug;
        $sufijo = '-' . $i;
        $slug   = substr($base, 0, DR_SU_SLUG_MAX - strlen($sufijo)) . $sufijo;
    }
    // 98 variantes ocupadas no pasa con un catalogo de decenas de filas; si
    // pasara, el UNIQUE de la DB corta el INSERT y sube como 500.
    return $slug;
}

// Da de alta una etiqueta del catalogo compartido y devuelve su fila. Solo se
// llama con `?crear_etiquetas=1`.
//
// El `nombre` que se guarda es el PLEGADO (minusculas, espacios colapsados,
// diacriticos combinantes fuera), igual que en el alta de
// /v4/datarocket/etiquetas: si una puerta guardara el crudo y la otra el
// plegado, el mismo texto terminaria creando dos filas que la collation
// considera la misma.
//
// Queda un `info` en `sucesos` con la app que la entro: el catalogo es
// compartido por todo Datarocket y una etiqueta basura hay que poder rastrearla
// hasta quien la creo.
function susCrearEtiqueta(PDO $pdo, string $nombre, array $app): array {
    susAssertNombreEtiqueta($nombre);
    $slug = susSlugLibre($pdo, $nombre);

    try {
        $st = $pdo->prepare('INSERT INTO datarocket_etiquetas (nombre, slug, descripcion)
                             VALUES (:nombre, :slug, NULL)');
        $st->execute([':nombre' => $nombre, ':slug' => $slug]);
        $id = (int)$pdo->lastInsertId();
    } catch (PDOException $e) {
        // Carrera con otro POST simultaneo del mismo nombre: entre la resolucion
        // y este INSERT no hay lock, pero el UNIQUE de `nombre` si desempata.
        // 23000 = integrity constraint violation.
        if ($e->getCode() !== '23000') throw $e;
        $st = $pdo->prepare('SELECT id, nombre, slug FROM datarocket_etiquetas WHERE nombre = :n LIMIT 1');
        $st->execute([':n' => $nombre]);
        $fila = $st->fetch();
        if (!$fila) throw $e;   // no era el UNIQUE del nombre
        return ['id' => (int)$fila['id'], 'nombre' => (string)$fila['nombre'],
                'slug' => (string)$fila['slug'], 'creada' => false];
    }

    registrarSuceso($pdo, DR_SU_ORIGEN, 'info',
        "Alta etiqueta #{$id} — \"{$nombre}\" (app: " . (string)($app['nombre'] ?? '?') . ')');

    return ['id' => $id, 'nombre' => $nombre, 'slug' => $slug, 'creada' => true];
}

// Aplica las etiquetas al prospecto de forma ADITIVA: nunca borra las que ya
// tenia. La variante destructiva (`etiqueta_ids` como full replace) vive en
// /v4/datarocket/prospectos y no tiene sentido aca — suscribirse a una lista no
// es motivo para perder las etiquetas que le puso un operador.
//
// Devuelve los ids que quedaron efectivamente APLICADOS EN ESTA LLAMADA, para
// que la respuesta pueda distinguirlos de los que ya estaban puestos.
function susAplicarEtiquetas(PDO $pdo, int $prospectoId, array $etiquetaIds): array {
    if (!$etiquetaIds) return [];

    // Los ids ya son int (salen de la resolucion contra el catalogo), asi que
    // interpolarlos es seguro y evita armar N placeholders.
    $in = implode(',', $etiquetaIds);
    $st = $pdo->prepare("SELECT etiqueta_id FROM datarocket_prospectos_etiquetas
                          WHERE prospecto_id = :p AND etiqueta_id IN ({$in})");
    $st->execute([':p' => $prospectoId]);
    $yaTenia = array_map('intval', array_column($st->fetchAll(), 'etiqueta_id'));
    $nuevas  = array_values(array_diff($etiquetaIds, $yaTenia));

    if ($nuevas) {
        // INSERT IGNORE y no INSERT a secas: entre el SELECT de arriba y esta
        // linea puede haber entrado otra request con el mismo prospecto. La PK
        // compuesta lo resuelve sin abortar la operacion entera.
        $ins = $pdo->prepare('INSERT IGNORE INTO datarocket_prospectos_etiquetas
                              (prospecto_id, etiqueta_id) VALUES (:p, :e)');
        foreach ($nuevas as $eid) $ins->execute([':p' => $prospectoId, ':e' => $eid]);
    }

    // `fecha_uso` se estampa sobre TODAS las pedidas, no solo sobre las nuevas:
    // aplicar una etiqueta que el prospecto ya tenia sigue siendo usarla, y la
    // columna es "ultima vez que se uso", no "ultima vez que se agrego". El
    // helper es el unico escritor legitimo de esa columna
    // (cloud/api/lib/datarocket_etiquetas_uso.php) y es best effort.
    marcarUsoEtiquetas($pdo, $etiquetaIds);

    return $nuevas;
}

// ---------------------------------------------------------------------------
// Handler
// ---------------------------------------------------------------------------

// POST /v4/datarocket/suscripcion
//
// Orden de operaciones, y no es casual: TODO lo que puede fallar por culpa del
// cliente se valida ANTES de abrir la transaccion. Una lista inexistente, un
// motivo fuera del catalogo, un correo impresentable o una etiqueta desconocida
// salen por 4xx sin haber escrito nada. Lo unico que queda adentro de la
// transaccion son escrituras.
function handleSuscribir(PDO $pdo, array $in, array $q, array $app): void {
    $lista  = susResolverLista($pdo, $in);
    $motivo = susResolverMotivo($pdo, $in);
    $crear  = susFlagCrearEtiquetas($q);

    susAssertCorreo($in);
    $p = susSanitize($in);
    $p['tipo'] = strtolower((string)($p['tipo'] ?? '')) ?: DR_SU_TIPO_DEFAULT;
    susAssertContacto($p);

    $etiquetas = susResolverEtiquetas($pdo, susEtiquetasDelBody($in['etiquetas'] ?? null));
    susAssertEtiquetasResueltas($etiquetas, $crear);

    $listaId = (int)$lista['id'];

    $pdo->beginTransaction();
    try {
        // ---- 1. Prospecto: reutilizar o crear ----------------------------
        $reusaId = susProspectoAReutilizar($pdo, $p);
        if ($reusaId !== null) {
            $st = $pdo->prepare('SELECT id, uuid, nombre, correo, celular, whatsapp,
                                        telefono, registrado
                                   FROM datarocket_prospectos WHERE id = :i');
            $st->execute([':i' => $reusaId]);
            $row = $st->fetch() ?: [];

            $completado = susCompletarFicha($pdo, $reusaId, $row, $p);

            $prospecto = [
                'id'         => $reusaId,
                'uuid'       => $row['uuid']   ?? null,
                'nombre'     => $row['nombre'] ?? null,
                // El contacto de la RESPUESTA es el estado resultante: si esta
                // llamada lleno un hueco, lo que se devuelve es el valor nuevo.
                'correo'     => in_array('correo',  $completado, true) ? $p['correo']  : ($row['correo']  ?? null),
                'celular'    => in_array('celular', $completado, true) ? $p['celular'] : ($row['celular'] ?? null),
                'registrado' => $row['registrado'] ?? null,
            ];
            $creado = false;
        } else {
            // Recien aca se exige el nombre: es el unico camino que crea ficha.
            susAssertIdentidad($p);
            $prospecto  = susCrearProspecto($pdo, $p);
            $completado = [];
            $creado     = true;
        }

        $prospectoId = (int)$prospecto['id'];

        // ---- 2. Etiquetas ------------------------------------------------
        // Se crean las que falten (solo con `?crear_etiquetas=1`; sin el flag no
        // quedo ninguna sin resolver) y despues se aplican todas de una.
        foreach ($etiquetas as $i => $e) {
            if ($e['id'] !== null) continue;
            $nueva = susCrearEtiqueta($pdo, $e['nombre'], $app);
            $etiquetas[$i]['id']     = $nueva['id'];
            $etiquetas[$i]['nombre'] = $nueva['nombre'];
            $etiquetas[$i]['slug']   = $nueva['slug'];
            $etiquetas[$i]['creada'] = $nueva['creada'];
        }
        $etiquetaIds = array_values(array_unique(array_map(fn($e) => (int)$e['id'], $etiquetas)));
        $aplicadas   = susAplicarEtiquetas($pdo, $prospectoId, $etiquetaIds);

        // ---- 3. Suscripcion ----------------------------------------------
        // Por la puerta unica: historial en `datarocket_listas_altas` ANTES de
        // tocar la puente, `destino` denormalizado y recalculo de
        // `datarocket_listas.suscriptos`, todo en ESTA transaccion (la lib se
        // engancha a la del caller y no abre una propia).
        //
        // Devuelve cuantos cambiaron DE VERDAD: 0 si ya estaba suscripto, y en
        // ese caso no escribe historial. Eso es lo que hace la llamada
        // idempotente.
        $destino = $p['correo'] ?? $prospecto['correo'] ?? $p['celular'] ?? $prospecto['celular'] ?? null;
        $n = drListaSuscribir($pdo, $listaId, [$prospectoId],
                              susCtxAlta($in, $motivo, $app, $prospectoId, $destino));

        $pdo->commit();

        jsonOk([
            'prospecto' => [
                'id'         => $prospectoId,
                'uuid'       => $prospecto['uuid'],
                'nombre'     => $prospecto['nombre'],
                'correo'     => $prospecto['correo'],
                'celular'    => $prospecto['celular'],
                'registrado' => $prospecto['registrado'],
                // false = la ficha ya existia y se reutilizo. Ver "IDENTIFICACION".
                'creado'     => $creado,
                // Campos de contacto que estaban vacios en la ficha y se llenaron
                // con lo que trajo esta llamada. Siempre vacio en un alta nueva
                // (ahi entro todo). Se publica para que el cliente vea que su
                // POST enriquecio la ficha sin tener que releerla y compararla.
                'completado' => $completado,
            ],
            'suscripcion' => [
                'lista_id'     => $listaId,
                'lista_slug'   => (string)$lista['slug'],
                'lista_nombre' => $lista['nombre'] !== null ? (string)$lista['nombre'] : null,
                'proyecto_id'  => $lista['proyecto_id'] !== null ? (int)$lista['proyecto_id'] : null,
                // false = ya estaba suscripto. No es un error: la llamada es
                // idempotente y no se registro un alta duplicada en el historial.
                'nueva'        => $n > 0,
                'motivo'       => $motivo,
                'destino'      => $destino,
            ],
            // Una entrada por etiqueta pedida, en el orden en que vinieron.
            //   `creada`   -> la etiqueta no existia en el catalogo y se creo aca.
            //   `aplicada` -> se le puso al prospecto en ESTA llamada (false = ya la tenia).
            'etiquetas' => array_map(fn($e) => [
                'id'       => (int)$e['id'],
                'nombre'   => (string)$e['nombre'],
                'slug'     => (string)$e['slug'],
                'creada'   => (bool)($e['creada'] ?? false),
                'aplicada' => in_array((int)$e['id'], $aplicadas, true),
            ], $etiquetas),
        ], $creado ? 201 : 200);
    } catch (Throwable $e) {
        // Los jsonError() de adentro de la transaccion cortan con exit(), asi que
        // ese rollback lo hace PDO al cerrarse la conexion. Este catch es para
        // los errores que si suben como excepcion.
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    }
}
