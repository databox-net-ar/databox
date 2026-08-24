<?php
// api/v4/datarocket/prospectos.php
// Microservicio del CRM Datarocket sobre la tabla `datarocket_prospectos`.
//
//   GET    /v4/datarocket/prospectos              -> listado con filtros (query string)
//   GET    /v4/datarocket/prospectos?id=N         -> registro individual
//   GET    /v4/datarocket/prospectos?verificar=1  -> chequeo de existencia previa
//   POST   /v4/datarocket/prospectos              (JSON body) -> alta, devuelve {id, uuid, registrado}
//                                                 con `embudo` + `asunto` + `mensaje` en el body
//                                                 crea ademas la oportunidad y la interaccion
//                                                 (ver "ALTA COMPUESTA" mas abajo)
//   PUT    /v4/datarocket/prospectos?id=N         (JSON body) -> reemplazo total, devuelve {id}
//   PATCH  /v4/datarocket/prospectos?id=N         (JSON body) -> modificacion parcial, devuelve {id, campos}
//   DELETE /v4/datarocket/prospectos?id=N         -> baja definitiva, devuelve {id}
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
// ---------------------------------------------------------------------------
// UNICIDAD DE CORREO Y CELULAR
// ---------------------------------------------------------------------------
// Este endpoint es la puerta de entrada de los formularios y los importadores
// EXTERNOS, y ahi es donde se generan los prospectos duplicados. Por eso el alta
// rechaza con 409 cualquier payload cuyo `correo` o `celular` ya este cargado en
// otra fila (ver drPrAssertUnico). La modificacion aplica la misma regla
// excluyendose a si misma.
//
// La comparacion se hace SIEMPRE sobre el valor NORMALIZADO, nunca sobre el
// crudo: `celular` se lleva a 10 digitos argentinos y `correo` a minuscula antes
// de buscar (reglas en cloud/api/lib/prospectos_normalizar.php, las mismas que
// aplico la migracion 20260816_1700 sobre lo que ya estaba cargado). Sin eso
// "11 5678-1234" y "1156781234" pasarian como dos prospectos distintos.
//
// Los campos vacios NO colisionan: la tabla tiene 9.679 filas sin correo y
// 20.575 sin celular (dev al 2026-08-18), y todas son legitimas. Solo se busca
// duplicado cuando el valor normalizado tiene contenido.
//
// La regla vive en la capa PHP, no en un UNIQUE de la tabla: los datos
// historicos todavia tienen 2.876 correos y 2.031 celulares repetidos, asi que
// el indice unico no entra hasta depurarlos. Consecuencia a tener presente: dos
// POST identicos y SIMULTANEOS pueden colarse los dos, porque entre el SELECT y
// el INSERT no hay nada a nivel motor que los frene. Para el uso real (altas de
// formulario, importadores secuenciales) alcanza; si algun dia hace falta
// garantia dura, primero se depuran los duplicados y despues se agrega el
// UNIQUE. Los indices de busqueda los agrega la migracion 20260818_1300.
//
// `GET ?verificar=1` expone exactamente el mismo chequeo como consulta previa,
// para que un cliente pueda preguntar antes de armar el alta y no descubra la
// colision recien en el 409. Usa la misma funcion, con lo cual las dos
// respuestas no pueden divergir.
//
// ---------------------------------------------------------------------------
// PUT vs. PATCH
// ---------------------------------------------------------------------------
// `PUT` es reemplazo TOTAL: lo que no venga en el body se guarda como NULL. Es
// lo que quiere el panel, que siempre postea el formulario completo, y lo que
// NO quiere un integrador que solo necesita corregir un celular — para eso
// tendria que releer el prospecto entero y reenviarlo, con la carrera obvia si
// alguien lo edito en el medio.
//
// `PATCH` escribe unicamente las columnas presentes en el body (ver
// handlePatch). Dos reglas que lo distinguen de "un PUT con menos campos":
//
//   1. Las validaciones corren sobre el ESTADO RESULTANTE (fila + parche), no
//      sobre el body suelto: un PATCH que cambia `tipo` a empresa chequea el
//      `empresa_nombre` que ya estaba cargado.
//   2. Pero solo se exigen las invariantes que el parche puede romper. Las
//      heredadas no se auditan: hay filas historicas con `tipo` NULL y con
//      correo/celular duplicado, y hacer fallar por eso un PATCH de `domicilio`
//      seria pedirle al cliente que arregle datos que no toco.
//
// ---------------------------------------------------------------------------
// Normalizacion: `telefono` / `celular` / `whatsapp` se guardan como 10
// digitos argentinos (se descarta TODO lo que no sea digito: espacios, guiones,
// parentesis, puntos, `+`), `correo` en minuscula validada y `web` en minuscula
// y como host + path sin esquema (`bna.com.ar/sucursales`) — reglas en
// cloud/api/lib/prospectos_normalizar.php.
//
// El criterio general es CORREGIR lo corregible en vez de rechazarlo. Un
// telefono NUNCA hace fallar el alta: lo que no se pueda llevar a 10 digitos se
// guarda igual, en digitos crudos (hay prospectos del exterior en la tabla). Un
// `correo` con acentos, con el `@` escrito como "(a)", con espacios tipeados o
// con puntuacion espuria se corrige y entra; solo se rechaza con 400 cuando
// arreglarlo obligaria a ADIVINAR la direccion (falta el TLD, falta el `@`,
// "hotmailcom") o cuando directamente no es un correo ("no informado"). Una
// `web` que no sea una URL va a NULL, salvo que sea un
// correo y `correo` este vacio (ahi se rescata). Ojo los consumidores: `web`
// ya no incluye `http://` — hay que anteponerlo al armar el link — y desde el
// 2026-08-18 tampoco conserva las mayusculas del path, asi que un
// `facebook.com/MENDOSUR` que entre por aca se guarda en minuscula y como link
// puede no resolver.
//
// ---------------------------------------------------------------------------
// PROCEDENCIA: `extraccion_url` + `extraccion_autor`
// ---------------------------------------------------------------------------
// Son la EXCEPCION a todo lo anterior: no se normalizan. Registran de donde se
// extrajo el prospecto y quien lo extrajo (una persona o un bot).
//
// `extraccion_url` se guarda tal cual vino — con esquema y respetando las
// mayusculas del path y del query, que es justo donde viven los ids que
// identifican la fuente (`/p/MLA-123`, `?ref=Xk9Q`). Pasarla por
// prospectoNormalizarWeb() la romperia. Solo se recorta y se trunca a 500. No
// confundir con `web`, que es el sitio DEL prospecto.
//
// PARA QUE SIRVE: un bot pregunta `GET ?verificar=1&extraccion_url=...` ANTES de
// gastar el scraping, y si esa pagina ya se cargo se la saltea. Va por
// `idx_dr_prospectos_extraccion_url`.
//
// OJO — `extraccion_url` NO participa del chequeo de unicidad que devuelve 409
// en el alta, y no es un olvido: una sola URL de listado da de alta
// legitimamente muchos prospectos (una pagina de resultados con 20 empresas
// sale de una unica URL). Bloquear por eso romperia el caso de uso normal. El
// 409 sigue siendo solo de `correo` / `celular`; la URL se consulta aparte, en
// handleVerificar(). Migracion 20260823_1000 (renombro `origen_url`).

declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');

require_once dirname(__DIR__, 3) . '/env.php';
require_once dirname(__DIR__, 3) . '/cloud/api/db.php';
require_once dirname(__DIR__, 3) . '/cloud/api/lib/prospectos_normalizar.php';
require_once dirname(__DIR__, 3) . '/cloud/api/lib/datarocket_etiquetas_uso.php';
require_once dirname(__DIR__) . '/_lib/log.php';

// Todo error de este endpoint queda registrado en `sucesos` (Visor de sucesos
// del panel). Va antes de la auth para que los 401 tambien caigan adentro.
// Ojo al leerlo: el 409 de `?verificar=1` es una respuesta de diseño (dice
// que hay duplicados), no una falla — por eso entra como `alerta` y no como
// `error`.
v4InitLog('v4/datarocket.prospectos');

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
                    web, facebook, instagram, tiktok, comentarios,
                    extraccion_url, extraccion_autor, registrado";

// Valores validos para `datarocket_prospectos.tipo`. Se rechazan alta y
// modificacion que no traigan uno de estos valores; las filas historicas
// quedan en NULL hasta ser editadas.
const DR_CT_TIPOS_VALIDOS = ['persona', 'empresa'];

// Columnas que un PATCH puede escribir: las de drPrSanitize() menos `nombre`,
// que es derivado. `id` y `uuid` tampoco se tocan — igual que en el PUT.
const DR_CT_PATCH_COLS = [
    'tipo',
    'empresa_nombre', 'empresa_rubro', 'empresa_actividad', 'empresa_cargo',
    'persona_nombre', 'persona_genero', 'persona_nacimiento', 'persona_dni',
    'domicilio', 'ciudad', 'ubicacion',
    'localidad_id', 'provincia_id', 'pais_id',
    'telefono', 'celular', 'whatsapp', 'correo',
    'web', 'facebook', 'instagram', 'tiktok', 'comentarios',
    'extraccion_url', 'extraccion_autor', 'registrado',
];

// Alias legacy del body -> columna real. En POST / PUT alcanza con el `??` de
// drPrSanitize(), pero el PATCH necesita saber QUE columna toco el cliente y
// ademas mergea contra la fila actual: ahi la clave canonica ya viene ocupada
// por el valor guardado y el `??` nunca llegaria a mirar el alias.
const DR_CT_PATCH_ALIAS = [
    'localidad' => 'localidad_id',
    'provincia' => 'provincia_id',
    'pais'      => 'pais_id',
];

// Defaults del bloque de consulta (ver "Alta compuesta" mas abajo). Pensados
// para el caso que lo motiva — el formulario web — pero parametrizables desde el
// body: el mismo alta sirve para una consulta que entra por WhatsApp.
const DR_CT_CANAL_DEFAULT  = 'web';   // datarocket_interacciones.canal
const DR_CT_ORIGEN_DEFAULT = 'Web';   // datarocket_oportunidades.origen

// `datarocket_oportunidades.sentido` es varchar(1) con catalogo E/S en `estados`
// (datarocket_oportunidad_sentido); `datarocket_interacciones.sentido` es
// varchar(10) con catalogo entrante/saliente/interna. Son dos vocabularios
// distintos para la misma idea — venian asi de antes de este endpoint y no se
// unifican aca.
const DR_CT_OPO_SENTIDO_ENTRANTE = 'E';
const DR_CT_INT_SENTIDO_ENTRANTE = 'entrante';

try {
    v4LogApp(requireApp());
    $pdo    = db();
    $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
    $id     = isset($_GET['id']) ? (int)$_GET['id'] : 0;

    if ($method === 'GET' && drPrFlagVerificar($_GET)) {
        handleVerificar($pdo, $_GET);
    } elseif ($method === 'GET' && $id > 0) {
        handleGetOne($pdo, $id);
    } elseif ($method === 'GET') {
        handleList($pdo, $_GET);
    } elseif ($method === 'POST') {
        handleCreate($pdo, readJsonBody());
    } elseif ($method === 'PUT') {
        if ($id <= 0) jsonError('Falta id (int > 0)', 400);
        handleUpdate($pdo, $id, readJsonBody());
    } elseif ($method === 'PATCH') {
        if ($id <= 0) jsonError('Falta id (int > 0)', 400);
        handlePatch($pdo, $id, readJsonBody());
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
// Verificacion de existencia previa
// ---------------------------------------------------------------------------

// `?verificar=1`. Se acepta cualquier valor no vacio salvo los negativos
// explicitos, para que sirva tanto `?verificar=1` como `?verificar=true`. Sin
// esto un `?verificar=0` colgado de un cliente distraido caeria en el listado.
function drPrFlagVerificar(array $q): bool {
    if (!array_key_exists('verificar', $q)) return false;
    $v = strtolower(trim((string)$q['verificar']));
    return !in_array($v, ['', '0', 'false', 'no'], true);
}

// GET /v4/datarocket/prospectos?verificar=1&correo=...&celular=...&extraccion_url=...
//
// Responde si ya hay algo cargado con esos datos, SIN escribir nada. Tiene dos
// usos que conviene no mezclar, y por eso la respuesta trae DOS banderas:
//
//   `existe`       -> hay al menos una coincidencia por CUALQUIERA de los tres
//                     campos. Es la pregunta del bot: "esto ya esta en la base,
//                     me lo salteo?".
//   `bloquea_alta` -> las coincidencias incluyen `correo` o `celular`, que son
//                     los unicos dos campos que hacen fallar el POST con 409.
//                     Es la pregunta del formulario: "si posteo, me rebota?".
//
// Para `correo` / `celular` corre exactamente la misma normalizacion y la misma
// busqueda que el POST, asi que `bloquea_alta:false` implica que el alta pasa el
// chequeo de unicidad (los otros obligatorios — `tipo` e identidad — se validan
// aparte, en el alta).
//
// `extraccion_url` es distinto: NO bloquea el alta (una misma URL de listado da
// de alta muchos prospectos), asi que puede poner `existe:true` con
// `bloquea_alta:false`. Es justamente el caso del bot que chequea antes de
// scrapear. Hasta el 2026-08-23 `existe` significaba lo que hoy significa
// `bloquea_alta`; los dos coinciden en cualquier llamada que no mande
// `extraccion_url`, que son todas las que existian antes del campo.
//
// Al menos uno de los tres parametros tiene que venir con contenido; preguntar
// "existe?" sin datos es un error del cliente, no un "no existe".
function handleVerificar(PDO $pdo, array $q): void {
    $correoRaw  = trim((string)($q['correo']  ?? ''));
    $celularRaw = trim((string)($q['celular'] ?? ''));
    // Sin normalizar, igual que al guardarla: la URL se compara tal cual vino.
    $extraccionUrl = trim((string)($q['extraccion_url'] ?? ''));

    if ($correoRaw === '' && $celularRaw === '' && $extraccionUrl === '') {
        jsonError('Hay que indicar al menos `correo`, `celular` o `extraccion_url` para verificar.', 400);
    }

    // Un correo con basura se rechaza igual que en el alta: si no se puede
    // normalizar, el alta lo iba a frenar con 400 y devolver "no existe" aca
    // seria mentirle al cliente.
    if ($correoRaw !== '' && prospectoNormalizarCorreo($correoRaw) === null) {
        jsonError('El correo no es válido.', 400);
    }

    $correo  = prospectoNormalizarCorreo($correoRaw);
    $celular = prospectoNormalizarTelefono($celularRaw);

    // Dos busquedas separadas a proposito: la de arriba es la que gobierna el
    // 409 del alta, la de abajo no. Mezclarlas en un solo OR haria que
    // drPrAssertUnico() —que comparte drPrBuscarDuplicados()— empezara a
    // rechazar altas por repetir la URL de origen.
    $coincidencias = drPrBuscarDuplicados($pdo, $correo, $celular);
    $bloqueaAlta   = $coincidencias !== [];
    if ($extraccionUrl !== '') {
        $coincidencias = array_merge($coincidencias, drPrBuscarPorExtraccionUrl($pdo, $extraccionUrl));
    }

    jsonOk([
        'existe'       => $coincidencias !== [],
        'bloquea_alta' => $bloqueaAlta,
        // Los valores normalizados con los que se busco. El cliente los ve para
        // entender por que "11 5678-1234" matcheo contra "1156781234".
        'consulta' => [
            'correo'         => $correo,
            'celular'        => $celular,
            'extraccion_url' => $extraccionUrl !== '' ? $extraccionUrl : null,
        ],
        'coincidencias' => $coincidencias,
    ]);
}

// Prospectos ya cargados que salieron de esta misma URL. Mismo shape que
// drPrBuscarDuplicados() para que las dos listas se puedan concatenar, con
// `campo` = 'extraccion_url'.
//
// Comparacion por `=` sobre la columna, que es utf8mb4_general_ci: pliega
// mayusculas y acentos, asi que el bot no pierde el match por como venga
// escrito el host. La columna GUARDA la capitalizacion original (es un link que
// hay que poder volver a abrir); es solo la comparacion la que es insensible.
//
// Entra por `idx_dr_prospectos_extraccion_url` (migracion 20260823_1000) — sin
// ese indice esto es un full scan en cada iteracion del scraping.
//
// El LIMIT es el mismo criterio que el de los duplicados: una URL de listado
// puede tener 20 prospectos colgados y la respuesta es informativa.
function drPrBuscarPorExtraccionUrl(PDO $pdo, string $url): array {
    $stmt = $pdo->prepare(
        "SELECT id, uuid, nombre, correo, celular, registrado
           FROM datarocket_prospectos
          WHERE extraccion_url = :u
          ORDER BY id
          LIMIT 20"
    );
    $stmt->execute([':u' => $url]);

    $out = [];
    foreach ($stmt->fetchAll() as $row) {
        $out[] = [
            'campo' => 'extraccion_url',
            'valor' => $url,
            'prospecto' => [
                'id'         => (int)$row['id'],
                'uuid'       => (string)$row['uuid'],
                'nombre'     => $row['nombre'],
                'correo'     => $row['correo'],
                'celular'    => $row['celular'],
                'registrado' => $row['registrado'],
            ],
        ];
    }
    return $out;
}

// ---------------------------------------------------------------------------
// Unicidad de correo / celular
// ---------------------------------------------------------------------------

// Busca prospectos ya cargados que choquen con `$correo` y/o `$celular` (ambos
// YA normalizados por el llamador). Devuelve una lista de coincidencias, una
// por campo en conflicto — una misma fila puede aparecer dos veces si repite
// los dos campos.
//
// `$excluirId` saca de la busqueda al prospecto que se esta editando: sin eso
// todo PUT chocaria contra su propia fila.
//
// El LIMIT existe porque el resultado es informativo (para que el cliente pueda
// mostrar contra quien choco); un correo repetido 300 veces en los datos
// historicos no tiene por que inflar la respuesta del 409.
function drPrBuscarDuplicados(PDO $pdo, ?string $correo, ?string $celular, int $excluirId = 0): array {
    $ors    = [];
    $params = [];
    // Los campos vacios no participan: media tabla tiene `correo = ''` heredado
    // del default de la columna y matchearlos entre si seria absurdo.
    if ($correo  !== null && $correo  !== '') { $ors[] = 'correo  = :correo';  $params[':correo']  = $correo; }
    if ($celular !== null && $celular !== '') { $ors[] = 'celular = :celular'; $params[':celular'] = $celular; }
    if (!$ors) return [];

    $sql = "SELECT id, uuid, nombre, correo, celular, registrado
              FROM datarocket_prospectos
             WHERE (" . implode(' OR ', $ors) . ")";
    if ($excluirId > 0) {
        $sql .= " AND id <> :excluir";
        $params[':excluir'] = $excluirId;
    }
    $sql .= " ORDER BY id LIMIT 20";

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);

    $out = [];
    foreach ($stmt->fetchAll() as $row) {
        $snap = [
            'id'         => (int)$row['id'],
            'uuid'       => (string)$row['uuid'],
            'nombre'     => $row['nombre'],
            'correo'     => $row['correo'],
            'celular'    => $row['celular'],
            'registrado' => $row['registrado'],
        ];
        // Se reclasifica en PHP cual de los dos campos matcheo: el OR de la
        // query no lo dice, y el cliente necesita saber cual corregir.
        // La comparacion del correo es case-insensitive para espejar la
        // collation utf8mb4_general_ci de la columna — si en la tabla quedo un
        // correo historico con mayusculas, la fila vuelve del SELECT y aca
        // tiene que reconocerse igual.
        if ($correo !== null && $correo !== '' && strcasecmp((string)$row['correo'], $correo) === 0) {
            $out[] = ['campo' => 'correo', 'valor' => $correo, 'prospecto' => $snap];
        }
        if ($celular !== null && $celular !== '' && (string)$row['celular'] === $celular) {
            $out[] = ['campo' => 'celular', 'valor' => $celular, 'prospecto' => $snap];
        }
    }
    return $out;
}

// Corta con 409 si `correo` o `celular` ya estan cargados en otra fila. El
// cuerpo del error lleva `coincidencias` con los prospectos que chocaron, para
// que el cliente pueda ofrecer "ya lo tenes cargado, ¿queres verlo?" en vez de
// un mensaje ciego.
function drPrAssertUnico(PDO $pdo, array $p, int $excluirId = 0): void {
    $coincidencias = drPrBuscarDuplicados($pdo, $p['correo'], $p['celular'], $excluirId);
    if (!$coincidencias) return;

    $campos = array_unique(array_column($coincidencias, 'campo'));
    $msg = match (true) {
        count($campos) > 1        => 'Ya existe un prospecto con ese correo y ese celular.',
        $campos[0] === 'correo'   => 'Ya existe un prospecto con ese correo.',
        default                   => 'Ya existe un prospecto con ese celular.',
    };
    jsonError($msg, 409, ['coincidencias' => $coincidencias]);
}

// ---------------------------------------------------------------------------
// Filtro por etiquetas / listas (multi-valor, semantica AND)
// ---------------------------------------------------------------------------

// Normaliza un parametro multi-valor. Acepta las tres formas con que un cliente
// puede mandarlo: 'expo', 'expo,vip' (CSV) o el parametro repetido
// (?etiqueta=expo&etiqueta=vip, que PHP entrega como array solo si el cliente
// usa `etiqueta[]`; el CSV cubre el resto). Devuelve valores recortados, sin
// vacios y sin duplicados.
function drPrFiltroMulti(mixed $raw): array {
    if ($raw === null) return [];
    $partes = [];
    foreach (is_array($raw) ? $raw : [$raw] as $v) {
        foreach (explode(',', (string)$v) as $p) {
            $p = trim($p);
            if ($p !== '') $partes[$p] = true;
        }
    }
    return array_keys($partes);
}

// Igual pero para ids enteros — la variante `etiqueta_id` / `lista_id`, que se
// mantiene por paridad con el ABM cloud y para el cliente que ya resolvio los
// ids contra /v4/datarocket/etiquetas.
function drPrFiltroMultiIds(mixed $raw): array {
    $out = [];
    foreach (drPrFiltroMulti($raw) as $v) {
        $n = (int)$v;
        if ($n > 0) $out[$n] = true;
    }
    return array_keys($out);
}

// Slugs de etiqueta -> ids. `datarocket_etiquetas.slug` es UNIQUE GLOBAL
// (migracion 20260821_1100), asi que cada slug resuelve a lo sumo una fila.
//
// Se slugifica la entrada con drPrSlugify(), la misma transformacion que usa el
// alta del catalogo, para que 'Mar del Plata', 'mar-del-plata' y 'MAR DEL PLATA'
// caigan todos en la misma etiqueta.
//
// UN SLUG QUE NO EXISTE CORTA CON 400, y es una divergencia DELIBERADA respecto
// de `etiqueta_ids` en el POST, donde un id desconocido se descarta en silencio.
// El motivo es que aca el valor es un FILTRO: descartarlo en silencio AGRANDA el
// resultado en vez de achicarlo. Un `?etiqueta=expo,vipp` con un typo devolveria
// a todos los de 'expo' y, en el caso de uso que motiva esto —barrer el
// resultado para un envio masivo—, eso es mandarle a gente que no correspondia.
// Fallar ruidoso es lo unico seguro.
function drPrResolverEtiquetaSlugs(PDO $pdo, array $slugs): array {
    if (!$slugs) return [];
    $st  = $pdo->prepare('SELECT id FROM datarocket_etiquetas WHERE slug = :s LIMIT 1');
    $ids = [];
    foreach ($slugs as $raw) {
        $slug = drPrSlugify($raw);
        if ($slug === '') jsonError("Etiqueta invalida: `{$raw}`.", 400);
        $st->execute([':s' => $slug]);
        $id = $st->fetchColumn();
        if ($id === false) {
            jsonError("La etiqueta `{$slug}` no existe.", 400);
        }
        $ids[(int)$id] = true;
    }
    return array_keys($ids);
}

// Slugs de lista -> ids. A diferencia de las etiquetas, el UNIQUE de
// `datarocket_listas` es (`proyecto_id`, `slug`): el slug es unico POR PROYECTO,
// no global, asi que el mismo texto puede resolver a dos listas de proyectos
// distintos. Ahi no se elige una — se corta con 409 y el cliente desambigua con
// `&proyecto_id=N`, el mismo contrato que ya tienen /v4/datarocket/listas y el
// `embudo` del alta. Elegir la primera seria mandarle a la audiencia equivocada
// sin que nadie se entere.
function drPrResolverListaSlugs(PDO $pdo, array $slugs, ?int $proyectoId): array {
    if (!$slugs) return [];
    $sql = 'SELECT id, proyecto_id FROM datarocket_listas WHERE slug = :s';
    if ($proyectoId !== null) $sql .= ' AND proyecto_id = :p';
    $st  = $pdo->prepare($sql);
    $ids = [];
    foreach ($slugs as $raw) {
        $slug = drPrSlugify($raw);
        if ($slug === '') jsonError("Lista invalida: `{$raw}`.", 400);
        $bind = [':s' => $slug];
        if ($proyectoId !== null) $bind[':p'] = $proyectoId;
        $st->execute($bind);
        $filas = $st->fetchAll();
        if (!$filas) {
            jsonError("La lista `{$slug}` no existe" .
                      ($proyectoId !== null ? " en el proyecto {$proyectoId}." : '.'), 400);
        }
        if (count($filas) > 1) {
            jsonError("El slug de lista `{$slug}` existe en mas de un proyecto; " .
                      'desambigua con `proyecto_id`.', 409,
                      ['candidatos' => array_map(
                          fn($f) => ['id' => (int)$f['id'], 'proyecto_id' => (int)$f['proyecto_id']],
                          $filas
                      )]);
        }
        $ids[(int)$filas[0]['id']] = true;
    }
    return array_keys($ids);
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
    // Procedencia. Son LIKE parcial, igual que `correo` / `celular` aca: sirven
    // para barrer ("todo lo que salio de mercadolibre", "todo lo que trajo el
    // bot X"). El chequeo exacto de "esta URL ya se extrajo?" NO es este — es
    // `?verificar=1&extraccion_url=...`, que compara entera y usa el indice.
    $extraccionUrl   = trim((string)($q['extraccion_url']   ?? ''));
    $extraccionAutor = trim((string)($q['extraccion_autor'] ?? ''));
    $desde        = trim((string)($q['desde']        ?? ''));
    $hasta        = trim((string)($q['hasta']        ?? ''));
    $search       = trim((string)($q['q']            ?? ''));

    // Etiquetas y listas: MULTI-VALOR y con semantica AND (ver mas abajo). Se
    // aceptan por slug (`etiqueta=expo,vip`) o por id (`etiqueta_id=5,14`); las
    // dos formas se combinan si vienen juntas. El slug es lo recomendado para un
    // integrador — no obliga a resolver ids contra el catalogo antes de listar.
    $proyectoId  = drPrFiltroId($q['proyecto_id'] ?? null);
    // array_values: los EXISTS de abajo usan el indice del array para nombrar el
    // placeholder y el alias, asi que las claves tienen que ser 0..N seguidas.
    $etiquetaIds = array_values(array_unique(array_merge(
        drPrFiltroMultiIds($q['etiqueta_id'] ?? null),
        drPrResolverEtiquetaSlugs($pdo, drPrFiltroMulti($q['etiqueta'] ?? null))
    )));
    $listaIds = array_values(array_unique(array_merge(
        drPrFiltroMultiIds($q['lista_id'] ?? null),
        drPrResolverListaSlugs($pdo, drPrFiltroMulti($q['lista'] ?? null), $proyectoId)
    )));

    $orderBy = $q['order_by'] ?? 'id';
    $dir     = strtolower((string)($q['dir'] ?? 'desc'));
    $limite  = isset($q['limite']) ? (int)$q['limite'] : 100;
    if ($limite < 1)    $limite = 1;
    if ($limite > 1000) $limite = 1000;

    // ---- Paginacion -------------------------------------------------------
    // Dos modos, y el correcto depende de para que se pagina:
    //
    //   `offset`   navegacion clasica. Anda con cualquier `order_by`, pero es
    //              INESTABLE: si entran prospectos nuevos mientras se recorre,
    //              las paginas siguientes se corren y aparecen repetidos o se
    //              saltean filas. Sirve para mirar, no para barrer.
    //
    //   `desde_id` barrido estable (keyset / seek). Pide los que tengan
    //              `id > desde_id` ordenados por id, asi que un alta concurrente
    //              no mueve nada de lo ya leido: no hay repetidos ni omitidos.
    //              Ademas no degrada en profundidad — entra por la PK en vez de
    //              contar y descartar N filas como hace OFFSET.
    //
    // `desde_id` GANA sobre `offset` y sobre `order_by`/`dir` si vienen los dos:
    // el barrido solo es estable si el orden es por id ascendente, y respetar un
    // `order_by=nombre` con un cursor de id daria un recorrido incoherente en
    // silencio. Es el modo recomendado para armar una audiencia de envio.
    $offset  = isset($q['offset']) ? max(0, (int)$q['offset']) : 0;
    $desdeId = isset($q['desde_id']) && $q['desde_id'] !== '' ? max(0, (int)$q['desde_id']) : null;

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
    if ($extraccionUrl   !== '') { $where[] = 'extraccion_url LIKE :extraccion_url';     $params[':extraccion_url']   = '%' . $extraccionUrl   . '%'; }
    if ($extraccionAutor !== '') { $where[] = 'extraccion_autor LIKE :extraccion_autor'; $params[':extraccion_autor'] = '%' . $extraccionAutor . '%'; }
    if ($desde        !== '')   { $where[] = 'registrado >= :desde';         $params[':desde']        = $desde . ' 00:00:00'; }
    if ($hasta        !== '')   { $where[] = 'registrado <= :hasta';         $params[':hasta']        = $hasta . ' 23:59:59'; }

    // Etiquetas asignadas y listas suscriptas, via las tablas puente.
    //
    // SEMANTICA CON VARIAS: AND (restrictivo). Un EXISTS por cada id elegido, o
    // sea "que tenga TODAS estas etiquetas" = interseccion, no union. Entre
    // etiquetas y listas tambien es AND, asi que el filtro entero se lee como
    // una sola conjuncion: sumar un valor siempre achica el resultado.
    //
    // Un EXISTS por id en vez de un solo IN con `HAVING COUNT(DISTINCT ...) = N`
    // porque cada uno entra directo por la PK compuesta de la puente y no
    // obliga a agrupar. Mismo patron que el ABM cloud.
    foreach ($etiquetaIds as $i => $eid) {
        $k = ":f_etiqueta{$i}";
        $params[$k] = $eid;
        $where[] = 'EXISTS (SELECT 1 FROM datarocket_prospectos_etiquetas dpe' . $i . '
                             WHERE dpe' . $i . '.prospecto_id = datarocket_prospectos.id
                               AND dpe' . $i . '.etiqueta_id = ' . $k . ')';
    }
    foreach ($listaIds as $i => $lid) {
        $k = ":f_lista{$i}";
        $params[$k] = $lid;
        $where[] = 'EXISTS (SELECT 1 FROM datarocket_prospectos_listas dpl' . $i . '
                             WHERE dpl' . $i . '.prospecto_id = datarocket_prospectos.id
                               AND dpl' . $i . '.lista_id = ' . $k . ')';
    }

    if ($search !== '') {
        $where[] = '(nombre LIKE :s1 OR empresa_nombre LIKE :s2 OR correo LIKE :s3
                     OR telefono LIKE :s4 OR celular LIKE :s5 OR whatsapp LIKE :s6
                     OR persona_dni LIKE :s7 OR uuid LIKE :s8)';
        $like = "%{$search}%";
        $params[':s1'] = $like; $params[':s2'] = $like; $params[':s3'] = $like;
        $params[':s4'] = $like; $params[':s5'] = $like; $params[':s6'] = $like;
        $params[':s7'] = $like; $params[':s8'] = $like;
    }

    // El cursor del barrido es una condicion mas del WHERE, pero se agrega
    // DESPUES de calcular el total: `total_filtrado` tiene que contar el
    // resultado entero, no lo que queda por delante del cursor.
    $sqlWhere = $where ? ('WHERE ' . implode(' AND ', $where)) : '';

    // Cuantos cumplen los filtros, sin LIMIT. Es lo que le dice al cliente
    // cuantas paginas le quedan (y a un envio masivo, a cuanta gente le va a
    // llegar antes de empezar a mandar). Mismo calculo que la tarjeta "Total"
    // del ABM cloud.
    $stmtTotal = $pdo->prepare("SELECT COUNT(*) FROM datarocket_prospectos {$sqlWhere}");
    $stmtTotal->execute($params);
    $totalFiltrado = (int)$stmtTotal->fetchColumn();

    if ($desdeId !== null) {
        // Modo barrido: el cursor manda y el orden se fuerza por id ascendente.
        $where[]              = 'id > :desde_id';
        $params[':desde_id']  = $desdeId;
        $sqlWhere             = 'WHERE ' . implode(' AND ', $where);
        $orderBy              = 'id';
        $dirSql               = 'ASC';
        $sqlLimit             = "LIMIT {$limite}";
    } else {
        // `$limite` y `$offset` ya son int saneados, no llegan del cliente como
        // texto: interpolarlos es seguro. Van interpolados y no por bind porque
        // en LIMIT / OFFSET, con emulacion de prepares apagada, PDO los manda
        // como string y MySQL rechaza la sentencia.
        $sqlLimit = "LIMIT {$limite}" . ($offset > 0 ? " OFFSET {$offset}" : '');
    }

    $sql = "SELECT " . DR_CT_COLS . "
              FROM datarocket_prospectos
              {$sqlWhere}
              ORDER BY {$orderBy} {$dirSql}
              {$sqlLimit}";
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $rows = $stmt->fetchAll();

    // Anexa `lista_ids` y `etiqueta_ids` (int[]) a cada prospecto — batch
    // queries contra las puentes `datarocket_prospectos_listas` (20260811_1400)
    // y `datarocket_prospectos_etiquetas` (20260811_1600). Sin N+1.
    drPrAttachListaIds($pdo, $rows);
    drPrAttachEtiquetaIds($pdo, $rows);

    // `cursor` es el valor para el `desde_id` de la proxima vuelta, y solo tiene
    // sentido en modo barrido: con `offset` el orden puede ser por nombre y el
    // id del ultimo no sirve de cursor. Va null en ese caso para no invitar a
    // usarlo mal.
    $cursor = ($desdeId !== null && $rows) ? (int)$rows[count($rows) - 1]['id'] : null;

    jsonOk([
        // Sin cambios: la cantidad de filas de ESTA respuesta. Se mantiene con
        // el mismo significado que antes de la paginacion para no romper a
        // ningun cliente.
        'total'          => count($rows),
        'total_filtrado' => $totalFiltrado,
        'limite'         => $limite,
        'offset'         => $desdeId !== null ? null : $offset,
        'cursor'         => $cursor,
        'hay_mas'        => $desdeId !== null
            ? count($rows) === $limite
            : ($offset + count($rows)) < $totalFiltrado,
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
//
// OJO que esto NO es la primera linea de defensa: prospectoNormalizarCorreo()
// ya corrige sola todo lo que se puede corregir sin adivinar (acentos, `(a)`,
// espacios, puntuacion). Lo que llega hasta aca y da null es lo que no tiene
// arreglo deterministico. Si aparece un patron nuevo y recurrente que hoy cae
// en este 400, la solucion es enseñarselo al normalizador, no relajar el check.
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
        // ABM cloud y con la migracion 20260816_1700. Ademas de definir lo que
        // se guarda, es lo que hace comparable el chequeo de unicidad de
        // drPrAssertUnico().
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
        // Procedencia: de que pagina se extrajo el prospecto y quien lo
        // extrajo. Van SIN normalizar — la URL se guarda tal cual vino, con
        // esquema y con las mayusculas del path y del query, porque es un link
        // para volver a la fuente. Ver el encabezado.
        'extraccion_url'   => drPrNullableStr($in['extraccion_url']   ?? null, 500),
        'extraccion_autor' => drPrNullableStr($in['extraccion_autor'] ?? null, 255),
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

// ---------------------------------------------------------------------------
// Alta compuesta: prospecto + oportunidad + interaccion
// ---------------------------------------------------------------------------
// Un formulario web no genera "un prospecto": genera una CONSULTA. Quien la
// manda es el prospecto, lo que pregunta es la interaccion, y el trabajo que
// eso abre es la oportunidad. Los tres registros nacen del mismo evento, asi
// que nacen del mismo POST — partirlo en tres llamadas obligaria al cliente a
// orquestar ids y a manejar el estado intermedio de un alta a medias (prospecto
// cargado, oportunidad no).
//
// El bloque es ATOMICO EN EL BODY: `embudo` + `asunto` + `mensaje` van los tres
// o no va ninguno.
//
//   los tres  -> prospecto + oportunidad + interaccion
//   ninguno   -> solo el prospecto (comportamiento historico del endpoint)
//   algunos   -> 400
//
// Un `mensaje` sin `embudo` no tendria kanban donde colgarse; un `embudo` sin
// mensaje abriria una oportunidad vacia. Ninguna de las dos es lo que el cliente
// quiso, y descartar en silencio la clave sobrante seria peor: el cliente se
// quedaria esperando una oportunidad que nunca se creo.
//
// El embudo aporta los tres datos que el consumidor externo no tiene por que
// conocer: `embudo_id`, `proyecto_id` (el del embudo — no se acepta del cliente)
// y `etapa_id` (la PRIMERA etapa del embudo por `orden`, que es la entrada del
// pipeline). Ver api/v4/datarocket/embudos.php.
//
// A QUE PROSPECTO SE LE CUELGA: identifica el `correo`, y si la llamada no trae
// correo identifica el `celular`. Si el campo que corresponda ya esta en una
// ficha, la consulta va ahi (la mas reciente si hubiera varias); si no, se crea
// un prospecto nuevo con todos los datos aprovechables del body. El chequeo de
// unicidad del alta simple NO corre en este modo — registrar la consulta es lo
// que no se puede perder. Ver drPrProspectoAReutilizar().

// Espejo de embSlugify() de api/v4/datarocket/embudos.php y de dremSlugify() del
// ABM cloud. Que la busqueda use la MISMA transformacion que el alta es lo que
// permite mandar `causam-clientes` o `Causam Clientes` y caer en la misma fila.
// Si cambia una, cambian las tres.
function drPrSlugify(mixed $raw): string {
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
    // Marcas combinantes del texto en forma NFD (macOS / iOS): sin esto la
    // tilde suelta caeria en el [^a-z0-9] y quedaria un guion en el medio de la
    // palabra. El contenedor no trae `intl`, asi que la normalizacion es esta.
    $s = preg_replace('/[\x{0300}-\x{036F}]+/u', '', $s) ?? $s;
    $s = preg_replace('/[^a-z0-9]+/', '-', $s) ?? $s;
    return substr(trim($s, '-'), 0, 40);
}

// Valida un valor contra el catalogo `estados` y devuelve la variante CANONICA
// (la que esta cargada en la tabla). Resuelve case-insensitive a proposito:
// `datarocket_oportunidad_origen` tiene los valores capitalizados ('Web') y un
// cliente que mande "web" no deberia comerse un 400 por una mayuscula.
//
// Si el catalogo esta vacio se acepta lo que vino: un `estados` sin seed no
// tiene por que frenar un alta.
function drPrValorDeCatalogo(PDO $pdo, string $campo, string $valor): ?string {
    $st = $pdo->prepare('SELECT valor FROM estados WHERE campo = :c ORDER BY orden, id');
    $st->execute([':c' => $campo]);
    $validos = array_column($st->fetchAll(), 'valor');
    if (!$validos) return $valor;
    foreach ($validos as $v) {
        if (strcasecmp((string)$v, $valor) === 0) return (string)$v;
    }
    return null;
}

// Lista de valores del catalogo, para el mensaje del 400 — un "canal invalido"
// sin decir cuales son los validos obliga a ir a leer la doc.
function drPrCatalogo(PDO $pdo, string $campo): array {
    $st = $pdo->prepare('SELECT valor FROM estados WHERE campo = :c ORDER BY orden, id');
    $st->execute([':c' => $campo]);
    return array_map('strval', array_column($st->fetchAll(), 'valor'));
}

// Resuelve el bloque de consulta del body. Devuelve null si no vino ninguna de
// las tres claves (alta simple), y corta con 4xx si vino incompleto o si el
// embudo no resuelve.
//
// Corre ANTES de abrir la transaccion: un embudo inexistente tiene que salir por
// 400 sin haber escrito un prospecto que despues quedaria sin su oportunidad.
function drPrResolverConsulta(PDO $pdo, array $in): ?array {
    $embudoRaw = trim((string)($in['embudo'] ?? ''));
    $embudoId  = drPrNullableInt($in['embudo_id'] ?? null);
    $asunto    = drPrNullableStr($in['asunto']  ?? null, 500);
    $mensaje   = drPrNullableStr($in['mensaje'] ?? null, 65535);

    $tieneEmbudo = $embudoRaw !== '' || ($embudoId !== null && $embudoId > 0);
    if (!$tieneEmbudo && $asunto === null && $mensaje === null) return null;

    // Bloque incompleto. El mensaje dice exactamente que falta, porque el error
    // tipico es mandar `mensaje` sin `asunto` (o el embudo sin ninguno de los
    // dos) y desde afuera no se ve por que no se creo la oportunidad.
    $faltan = [];
    if (!$tieneEmbudo)        $faltan[] = '`embudo`';
    if ($asunto  === null)    $faltan[] = '`asunto`';
    if ($mensaje === null)    $faltan[] = '`mensaje`';
    if ($faltan) {
        jsonError(
            'Para registrar la consulta hacen falta `embudo`, `asunto` y `mensaje`: falta ' .
            implode(' y ', $faltan) . '. Sin las tres claves el alta crea solo el prospecto.',
            400
        );
    }

    $proyectoId = drPrNullableInt($in['proyecto_id'] ?? null);
    $embudo     = drPrBuscarEmbudo($pdo, $embudoRaw, $embudoId, $proyectoId);

    // La etapa de entrada es la de menor `orden` — el recorrido real del
    // pipeline, no un detalle de presentacion. `UNIQUE(embudo_id, orden)` hace
    // que el desempate sea deterministico.
    $st = $pdo->prepare('SELECT id, nombre FROM datarocket_etapas
                          WHERE embudo_id = :e ORDER BY orden ASC, id ASC LIMIT 1');
    $st->execute([':e' => (int)$embudo['id']]);
    $etapa = $st->fetch();
    if (!$etapa) {
        // Un embudo sin etapas es un pipeline a medio configurar: no hay columna
        // del kanban donde dejar la oportunidad. Se frena antes de escribir en
        // vez de crear una oportunidad con `etapa_id` NULL que nadie ve.
        jsonError(
            'El embudo `' . $embudo['slug'] . '` no tiene etapas cargadas, asi que no hay '
            . 'donde ubicar la oportunidad. Cargalas desde el panel (Sistemas > Datarocket > Etapas).',
            409
        );
    }

    // Canal de la interaccion y origen de la oportunidad. Defaults pensados para
    // el caso que motiva esto — el formulario web — pero parametrizables: el
    // mismo alta sirve para una consulta que entra por WhatsApp.
    $canalRaw  = trim((string)($in['canal']  ?? '')) ?: DR_CT_CANAL_DEFAULT;
    $origenRaw = trim((string)($in['origen'] ?? '')) ?: DR_CT_ORIGEN_DEFAULT;

    $canal = drPrValorDeCatalogo($pdo, 'datarocket_interaccion_canal', $canalRaw);
    if ($canal === null) {
        jsonError('El canal `' . $canalRaw . '` no existe. Valores validos: '
                  . implode(', ', drPrCatalogo($pdo, 'datarocket_interaccion_canal')) . '.', 400);
    }
    $origen = drPrValorDeCatalogo($pdo, 'datarocket_oportunidad_origen', $origenRaw);
    if ($origen === null) {
        jsonError('El origen `' . $origenRaw . '` no existe. Valores validos: '
                  . implode(', ', drPrCatalogo($pdo, 'datarocket_oportunidad_origen')) . '.', 400);
    }

    return [
        'embudo_id'      => (int)$embudo['id'],
        'embudo_slug'    => (string)$embudo['slug'],
        'embudo_nombre'  => (string)$embudo['nombre'],
        'proyecto_id'    => $embudo['proyecto_id'] !== null ? (int)$embudo['proyecto_id'] : null,
        'etapa_id'       => (int)$etapa['id'],
        'etapa_nombre'   => (string)$etapa['nombre'],
        // `datarocket_oportunidades.asunto` es varchar(1000) y el de la
        // interaccion varchar(500); drPrNullableStr ya trunco al mas chico, que
        // es el que manda porque es el mismo texto en los dos lados.
        'asunto'         => $asunto,
        'mensaje'        => $mensaje,
        'canal'          => $canal,
        'origen'         => $origen,
    ];
}

// Busca el embudo por id o por slug. El slug se slugifica primero (ver
// drPrSlugify), asi que se acepta tanto `causam-clientes` como `Causam Clientes`.
//
// Mismo criterio de ambiguedad que /v4/datarocket/embudos: el UNIQUE de la tabla
// es (proyecto_id, slug), no slug a secas, asi que un slug puede matchear en dos
// proyectos. Devolver "el primero" le daria al cliente el embudo de otro
// proyecto sin que se entere; se contesta 409 con los candidatos y se desambigua
// con `proyecto_id` en el body.
function drPrBuscarEmbudo(PDO $pdo, string $raw, ?int $id, ?int $proyectoId): array {
    if ($id !== null && $id > 0) {
        $st = $pdo->prepare('SELECT id, proyecto_id, slug, nombre FROM datarocket_embudos WHERE id = :i');
        $st->execute([':i' => $id]);
        $row = $st->fetch();
        if (!$row) jsonError('El embudo con id ' . $id . ' no existe.', 400);
        return $row;
    }

    $slug = drPrSlugify($raw);
    if ($slug === '') {
        jsonError('El `embudo` indicado no tiene ningun caracter aprovechable para armar un slug.', 400);
    }

    $sql    = 'SELECT id, proyecto_id, slug, nombre FROM datarocket_embudos WHERE slug = :s';
    $params = [':s' => $slug];
    if ($proyectoId !== null && $proyectoId > 0) {
        $sql .= ' AND proyecto_id = :p';
        $params[':p'] = $proyectoId;
    }
    $sql .= ' ORDER BY proyecto_id ASC, id ASC';
    $st = $pdo->prepare($sql);
    $st->execute($params);
    $rows = $st->fetchAll();

    if (!$rows) {
        // No hay `?resolver=1` como en etiquetas: una etiqueta nueva es
        // inofensiva, un embudo vacio es un pipeline roto. El embudo se crea en
        // el panel, deliberadamente. Ver embudos.md.
        jsonError(
            'El embudo `' . $slug . '` no existe. Los embudos se consultan en '
            . '/v4/datarocket/embudos y se crean desde el panel cloud.',
            400,
            ['consulta' => ['embudo' => $slug]]
        );
    }
    if (count($rows) > 1) {
        jsonError(
            'El embudo `' . $slug . '` existe en mas de un proyecto. Agrega `proyecto_id` para desambiguar.',
            409,
            ['consulta' => ['embudo' => $slug], 'embudos' => array_map(fn($r) => [
                'id'          => (int)$r['id'],
                'proyecto_id' => $r['proyecto_id'] !== null ? (int)$r['proyecto_id'] : null,
                'slug'        => (string)$r['slug'],
                'nombre'      => (string)$r['nombre'],
            ], $rows)]
        );
    }
    return $rows[0];
}

// Busca una oportunidad ABIERTA del prospecto en ese embudo. "Abierta" es estar
// en una etapa `tipo='activa'` — `ganada` y `perdida` son terminales — o no
// tener etapa asignada, que es un dato incompleto, no un cierre.
//
// Existe para que N consultas del mismo cliente no inflen el kanban con N
// tarjetas: la segunda consulta se cuelga de la oportunidad que ya esta en curso
// (la mas reciente si hubiera varias, que es en la que alguien esta trabajando).
function drPrOportunidadAbierta(PDO $pdo, int $prospectoId, int $embudoId): ?int {
    $st = $pdo->prepare("
        SELECT o.id
          FROM datarocket_oportunidades o
          LEFT JOIN datarocket_etapas e ON e.id = o.etapa_id
         WHERE o.prospecto_id = :p
           AND o.embudo_id    = :e
           AND (o.etapa_id IS NULL OR e.tipo = 'activa')
      ORDER BY o.id DESC
         LIMIT 1
    ");
    $st->execute([':p' => $prospectoId, ':e' => $embudoId]);
    $row = $st->fetch();
    return $row ? (int)$row['id'] : null;
}

// Con bloque de consulta hace falta al menos UN dato de contacto. Sin `correo`
// ni `celular` no se da de alta nada: ni el prospecto, ni la oportunidad, ni la
// interaccion.
//
// La razon es que una consulta sin ninguna via de contacto no se puede
// responder. Se abriria una tarjeta en el kanban que nadie puede contestar y una
// ficha que ninguna consulta posterior va a poder reencontrar — no tiene por
// donde identificarse (ver drPrProspectoAReutilizar), asi que cada mensaje
// nuevo del mismo contacto abriria otra ficha mas. Es basura que ensucia el
// pipeline, no un lead.
//
// Se mira el valor NORMALIZADO, no el crudo: un `correo` de "no informado" o un
// `celular` sin ningun digito ya vinieron a null desde drPrSanitize(), y tienen
// que contar como ausentes.
//
// OJO que esto vale SOLO para el alta con consulta. El alta a secas (un
// importador, un padron) sigue aceptando prospectos sin datos de contacto:
// la tabla tiene 9.679 filas sin correo y 20.575 sin celular, todas legitimas.
function drPrAssertContacto(array $p): void {
    $correo  = (string)($p['correo']  ?? '');
    $celular = (string)($p['celular'] ?? '');
    if ($correo !== '' || $celular !== '') return;

    jsonError(
        'Para registrar la consulta hace falta al menos `correo` o `celular`: sin una via de '
        . 'contacto la consulta no se puede responder ni se puede reencontrar la ficha, asi que '
        . 'no se da de alta el prospecto ni la oportunidad ni la interaccion.',
        400
    );
}

// Ultima ficha (la mas reciente) que tenga ese valor exacto en esa columna, o
// null si no hay ninguna. `$campo` sale siempre de un literal del call site,
// nunca del body, asi que interpolarlo es seguro; el valor va por bind.
//
// El desempate por `id DESC` importa porque la base historica arrastra
// duplicados en los dos campos (1.984 correos y 1.586 celulares en mas de una
// ficha, dev al 2026-08-19): entre varias, la mas reciente es la que mas
// probablemente este en uso.
function drPrUltimoPorContacto(PDO $pdo, string $campo, ?string $valor): ?int {
    if ($valor === null || $valor === '') return null;
    $st = $pdo->prepare("SELECT id FROM datarocket_prospectos
                          WHERE {$campo} = :v ORDER BY id DESC LIMIT 1");
    $st->execute([':v' => $valor]);
    $row = $st->fetch();
    return $row ? (int)$row['id'] : null;
}

// Decide si el alta de una consulta puede reutilizar un prospecto ya cargado.
// Devuelve su id, o null si hay que crear uno nuevo.
//
// El `correo` es el factor de identificacion PRINCIPAL y el `celular` el de
// RESPALDO. El respaldo solo entra cuando la llamada no trae correo:
//
//   con correo:  correo en la base   -> esa ficha
//                correo que no esta  -> prospecto nuevo
//   sin correo:  celular en la base  -> esa ficha
//                celular que no esta -> prospecto nuevo
//
// Ojo con el segundo renglon: que el celular no participe de la BUSQUEDA no
// significa que se descarte. El prospecto nuevo se crea con todos los datos
// aprovechables del body, celular incluido. Aca se decide a QUE ficha se le
// cuelga la consulta, no que se guarda en ella.
//
// El caso "sin correo y sin celular" no llega hasta aca: lo corta antes
// drPrAssertContacto() con 400, sin crear nada.
//
// El respaldo por celular existe para que las consultas que entran por un canal
// donde nadie deja el correo — WhatsApp es el caso — no abran una ficha nueva
// cada vez. Sin el, el mismo contacto escribiendo tres veces generaba tres
// prospectos, tres oportunidades y tres hilos de interacciones sueltos; con el,
// las tres caen en la misma linea de mensajeria.
//
// Que el correo NO caiga al respaldo cuando no matchea es a proposito: si la
// llamada trae correo, ese correo es la identidad que declara, y un celular
// compartido (el conmutador de una empresa, el telefono de una familia) no debe
// mandar la consulta al legajo de otra persona. Sin correo no hay nada mejor
// que el celular, y ahi el riesgo se acepta.
//
// Esta funcion no corta con 409 en ningun caso, y es deliberado: registrar la
// consulta es lo que no se puede perder. Una consulta rechazada es un lead que
// se cae, y del lado del cliente el unico recorte posible es reintentar mandando
// menos datos — que es exactamente como el formulario de cotizacion
// (vigicom-www/cotizar) venia guardando prospectos sin telefono: se comia un 409
// por un celular repetido en la base historica y reintentaba sin el celular.
function drPrProspectoAReutilizar(PDO $pdo, array $p): ?int {
    $correo = $p['correo'];
    if ($correo !== null && $correo !== '') {
        return drPrUltimoPorContacto($pdo, 'correo', $correo);
    }
    return drPrUltimoPorContacto($pdo, 'celular', $p['celular']);
}

// Crea la oportunidad y la interaccion del bloque de consulta y responde. No
// vuelve: jsonOk() hace exit.
//
// `$creado` distingue los dos caminos que llegan aca — prospecto recien
// insertado vs. prospecto reutilizado — y define tres cosas: el status (201 vs.
// 200), de donde salen `uuid` / `registrado`, y si las listas y etiquetas del
// body ya se aplicaron o hay que sumarlas ahora.
function drPrAltaConsulta(
    PDO $pdo, int $prospectoId, array $p, array $c,
    array $listaIds, array $etiquetaIds, bool $creado
): void {
    $ahora = (new DateTime('now', new DateTimeZone('America/Argentina/Buenos_Aires')))
             ->format('Y-m-d H:i:s');

    $uuid       = $p['uuid']       ?? null;
    $registrado = $p['registrado'] ?? null;
    $completado = [];

    if (!$creado) {
        // Sobre un prospecto que ya existia NO se pisa nada de lo cargado: los
        // datos del formulario pueden ser mas pobres que los que ya tiene (un
        // alta previa con domicilio y cargo, una consulta nueva con solo el
        // nombre y el correo). Se lee su identidad para responderla, y de paso
        // el contacto para completar los huecos (ver drPrCompletarContacto).
        $st = $pdo->prepare('SELECT uuid, registrado, correo, celular
                               FROM datarocket_prospectos WHERE id = :i');
        $st->execute([':i' => $prospectoId]);
        $row        = $st->fetch() ?: [];
        $uuid       = $row['uuid']       ?? null;
        $registrado = $row['registrado'] ?? null;

        $completado = drPrCompletarContacto($pdo, $prospectoId, $row, $p);

        // Las listas y etiquetas si se SUMAN — es informacion nueva sobre el
        // prospecto ("ademas vino por la expo"), y aca no se puede usar el
        // reemplazo total de drPrSyncEtiquetas() sin borrarle las que ya tenia.
        drPrAgregarListas($pdo, $prospectoId, $listaIds);
        drPrAgregarEtiquetas($pdo, $prospectoId, $etiquetaIds);
    }

    // Oportunidad: se reutiliza la que ya este abierta en ese embudo para no
    // llenar el kanban de tarjetas duplicadas del mismo cliente.
    $oportunidadId = drPrOportunidadAbierta($pdo, $prospectoId, $c['embudo_id']);
    $oportunidadCreada = $oportunidadId === null;

    if ($oportunidadCreada) {
        $st = $pdo->prepare("
            INSERT INTO datarocket_oportunidades
                (prospecto_id, ingreso, proyecto_id, sentido, origen,
                 embudo_id, etapa_id, etapa_ingreso, actualizado, asunto)
            VALUES
                (:prospecto_id, :ingreso, :proyecto_id, :sentido, :origen,
                 :embudo_id, :etapa_id, :etapa_ingreso, :actualizado, :asunto)
        ");
        $st->execute([
            ':prospecto_id'  => $prospectoId,
            ':ingreso'       => $ahora,
            // El proyecto sale del EMBUDO, no del cliente: son el mismo dato y
            // aceptarlo por separado permitiria una oportunidad cuyo proyecto no
            // es el del embudo en el que vive.
            ':proyecto_id'   => $c['proyecto_id'],
            ':sentido'       => DR_CT_OPO_SENTIDO_ENTRANTE,
            ':origen'        => $c['origen'],
            ':embudo_id'     => $c['embudo_id'],
            ':etapa_id'      => $c['etapa_id'],
            ':etapa_ingreso' => $ahora,
            ':actualizado'   => $ahora,
            ':asunto'        => $c['asunto'],
        ]);
        $oportunidadId = (int)$pdo->lastInsertId();
    } else {
        // No se toca la etapa: si alguien ya la movio a "Propuesta", una consulta
        // nueva no tiene por que devolverla al principio del pipeline. Solo se
        // marca que hubo movimiento, que es lo que ordena el kanban.
        $pdo->prepare('UPDATE datarocket_oportunidades SET actualizado = :a WHERE id = :i')
            ->execute([':a' => $ahora, ':i' => $oportunidadId]);
    }

    // Interaccion: siempre se crea una nueva. Es el mensaje concreto que mando
    // el prospecto y el historial de la oportunidad se lee de aca.
    //
    // `respondida` NO va en el INSERT a proposito: una consulta que acaba de
    // entrar esta PENDIENTE por definicion — nadie la contesto todavia. La
    // columna se sella unicamente a mano desde el ABM del panel
    // (cloud/api/datarocketinteracciones.php, PUT ?action=responder), que es lo
    // que alimenta la metrica de demora de respuesta. Si algun dia se agrega
    // aca, el tablero de pendientes deja de servir: todo entraria ya contestado
    // con demora 0. Mismo criterio en el alta del ABM.
    $st = $pdo->prepare("
        INSERT INTO datarocket_interacciones
            (fecha, prospecto_id, oportunidad_id, sentido, canal, asunto, mensaje)
        VALUES
            (:fecha, :prospecto_id, :oportunidad_id, :sentido, :canal, :asunto, :mensaje)
    ");
    $st->execute([
        ':fecha'          => $ahora,
        ':prospecto_id'   => $prospectoId,
        ':oportunidad_id' => $oportunidadId,
        ':sentido'        => DR_CT_INT_SENTIDO_ENTRANTE,
        ':canal'          => $c['canal'],
        ':asunto'         => $c['asunto'],
        ':mensaje'        => $c['mensaje'],
    ]);
    $interaccionId = (int)$pdo->lastInsertId();

    $pdo->commit();

    // `id` / `uuid` / `registrado` se mantienen en la raiz: son el contrato que
    // ya consumen los clientes del alta simple, y un formulario que empieza a
    // mandar el bloque de consulta no deberia tener que mover donde los lee.
    jsonOk([
        'id'         => $prospectoId,
        'uuid'       => $uuid,
        'registrado' => $registrado,
        'prospecto'  => [
            'id'         => $prospectoId,
            'uuid'       => $uuid,
            'registrado' => $registrado,
            'creado'     => $creado,
            // Campos que estaban vacios en la ficha y se llenaron con lo que
            // trajo esta consulta. Vacio en un alta nueva (ahi entro todo) y en
            // una reutilizacion que no aporto nada. Se publica para que el
            // cliente pueda ver que su POST enriquecio la ficha: sin esto, la
            // unica forma de enterarse es releer el prospecto y compararlo.
            'completado' => $completado,
        ],
        'oportunidad' => [
            'id'          => $oportunidadId,
            'creada'      => $oportunidadCreada,
            'embudo_id'   => $c['embudo_id'],
            'embudo_slug' => $c['embudo_slug'],
            'proyecto_id' => $c['proyecto_id'],
            'etapa_id'    => $c['etapa_id'],
            'etapa_nombre'=> $c['etapa_nombre'],
        ],
        'interaccion' => [
            'id'      => $interaccionId,
            'creada'  => true,
            'sentido' => DR_CT_INT_SENTIDO_ENTRANTE,
            'canal'   => $c['canal'],
        ],
    ], $creado ? 201 : 200);
}

// Completa `correo` / `celular` del prospecto reutilizado con los que trajo la
// consulta, UNICAMENTE cuando la ficha los tiene vacios. Devuelve la lista de
// campos que se escribieron (vacia si no habia nada que completar).
//
// Es la unica excepcion al "no se pisa nada de lo cargado" del alta con
// consulta, y no la contradice: no se reemplaza un dato por otro, se llena un
// hueco. El caso tipico es el prospecto identificado por su correo que nunca
// dejo un telefono y ahora lo deja. Sin esto la ficha se queda a medias para
// siempre y el dato solo vive en la interaccion.
//
// Un valor YA cargado no se toca ni se compara: si difiere del que vino, es una
// correccion y eso es un PATCH deliberado, no un efecto colateral de registrar
// un mensaje.
//
// En la practica el unico campo que llega a completarse es `celular`, y sale de
// como identifica drPrProspectoAReutilizar(): el campo por el que matcheo nunca
// esta vacio, y el camino del celular solo se toma cuando la llamada NO trae
// correo, asi que por ahi tampoco hay correo para completar. Se deja el loop
// sobre los dos porque la regla es la misma y no depende de cual sea hoy el
// campo identificador.
//
// No se chequea unicidad antes del UPDATE, y no es un olvido: el celular que se
// escribe puede estar ya en otra ficha, y esta bien que asi sea. Un celular
// repetido dejo de ser un conflicto — es un numero compartido, o dos fichas
// historicas del mismo contacto. La columna no tiene UNIQUE y el alta con
// consulta no rechaza duplicados, asi que frenar el relleno aca seria perder el
// telefono de alguien cuya ficha ya identificamos por su correo.
function drPrCompletarContacto(PDO $pdo, int $prospectoId, array $row, array $p): array {
    $campos = [];
    foreach (['correo', 'celular'] as $campo) {
        // La columna arrastra NULL y '' como "vacio" (el '' viene del default
        // historico), asi que los dos cuentan como hueco.
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

// Suma listas / etiquetas sin borrar las que el prospecto ya tenia — la variante
// no destructiva de drPrSyncListas() / drPrSyncEtiquetas(), para el camino en
// que la consulta cae sobre un prospecto preexistente.
function drPrAgregarListas(PDO $pdo, int $prospectoId, array $listaIds): void {
    if (!$listaIds) return;
    $ph  = implode(',', array_fill(0, count($listaIds), '?'));
    $val = $pdo->prepare("SELECT id FROM datarocket_listas WHERE id IN ({$ph})");
    $val->execute($listaIds);
    $ins = $pdo->prepare('INSERT IGNORE INTO datarocket_prospectos_listas
                          (prospecto_id, lista_id) VALUES (:cid, :lid)');
    foreach (array_column($val->fetchAll(), 'id') as $lid) {
        $ins->execute([':cid' => $prospectoId, ':lid' => (int)$lid]);
    }
}

function drPrAgregarEtiquetas(PDO $pdo, int $prospectoId, array $etiquetaIds): void {
    if (!$etiquetaIds) return;
    $ph  = implode(',', array_fill(0, count($etiquetaIds), '?'));
    $val = $pdo->prepare("SELECT id FROM datarocket_etiquetas WHERE id IN ({$ph})");
    $val->execute($etiquetaIds);
    $validIds = array_map('intval', array_column($val->fetchAll(), 'id'));
    $ins = $pdo->prepare('INSERT IGNORE INTO datarocket_prospectos_etiquetas
                          (prospecto_id, etiqueta_id) VALUES (:cid, :eid)');
    foreach ($validIds as $eid) {
        $ins->execute([':cid' => $prospectoId, ':eid' => $eid]);
    }

    // Ver drPrSyncEtiquetas(): aplicar una etiqueta es usarla.
    marcarUsoEtiquetas($pdo, $validIds);
}

function handleCreate(PDO $pdo, array $in): void {
    // El bloque de consulta se resuelve ANTES de tocar la base: si el embudo no
    // existe, si vino incompleto o si el embudo no tiene etapas, el error sale
    // sin haber escrito un prospecto que quedaria sin su oportunidad.
    $consulta = drPrResolverConsulta($pdo, $in);

    drPrAssertCorreo($in);
    $p = drPrSanitize($in);
    // `tipo` obligatorio en alta — cualquier cliente del microservicio v4
    // debe indicar persona o empresa. Ver DR_CT_TIPOS_VALIDOS.
    if (!in_array($p['tipo'], DR_CT_TIPOS_VALIDOS, true)) {
        jsonError('El tipo es obligatorio (persona o empresa).', 400);
    }
    drPrAssertIdentidad($p);
    if ($consulta !== null) drPrAssertContacto($p);
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
        // Unicidad de correo / celular. Va DENTRO de la transaccion y lo mas
        // pegado posible al INSERT para achicar la ventana de carrera (ver el
        // encabezado: sin UNIQUE en la tabla, la ventana no se cierra del todo).
        //
        // Con bloque de consulta la colision NO es un error: el POST ya no
        // significa "dar de alta un prospecto" sino "registrar una consulta", y
        // que quien la manda ya este en la base es lo normal — es un cliente que
        // vuelve. Se reutiliza su fila y la oportunidad se le cuelga ahi. Sin
        // bloque de consulta el POST sigue siendo un alta a secas y el duplicado
        // sigue siendo el error que el 409 previene.
        if ($consulta !== null) {
            $reusaId = drPrProspectoAReutilizar($pdo, $p);
            if ($reusaId !== null) {
                drPrAltaConsulta($pdo, $reusaId, $p, $consulta, $listaIds, $etiquetaIds, false);
            }
            // Sin drPrAssertUnico(): con bloque de consulta la identificacion ya
            // la resolvio drPrProspectoAReutilizar(), y si decidio "prospecto
            // nuevo" teniendo un celular repetido en la base historica, ese es
            // justamente el desenlace que se quiere — no un 409. Volver a
            // chequear unicidad aca lo revertiria.
        } else {
            drPrAssertUnico($pdo, $p);
        }

        $sql = "INSERT INTO datarocket_prospectos
                    (uuid, tipo, nombre,
                     empresa_nombre, empresa_rubro, empresa_actividad, empresa_cargo,
                     persona_nombre, persona_genero, persona_nacimiento, persona_dni,
                     domicilio, ciudad, ubicacion, localidad_id,
                     provincia_id, pais_id, telefono, celular, whatsapp, correo, web, facebook,
                     instagram, tiktok, comentarios, extraccion_url, extraccion_autor, registrado)
                VALUES
                    (:uuid, :tipo, :nombre,
                     :empresa_nombre, :empresa_rubro, :empresa_actividad, :empresa_cargo,
                     :persona_nombre, :persona_genero, :persona_nacimiento, :persona_dni,
                     :domicilio, :ciudad, :ubicacion, :localidad_id,
                     :provincia_id, :pais_id, :telefono, :celular, :whatsapp, :correo, :web, :facebook,
                     :instagram, :tiktok, :comentarios, :extraccion_url, :extraccion_autor, :registrado)";
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
            ':extraccion_url'     => $p['extraccion_url'],
            ':extraccion_autor'   => $p['extraccion_autor'],
            ':registrado'         => $p['registrado'],
        ]);
        $newId = (int)$pdo->lastInsertId();
        drPrSyncListas($pdo, $newId, $listaIds);
        drPrSyncEtiquetas($pdo, $newId, $etiquetaIds);

        // Con bloque de consulta el alta sigue con la oportunidad y la
        // interaccion, y responde adentro (jsonOk hace exit) — todo bajo la
        // misma transaccion, asi que o entran los tres registros o no entra
        // ninguno.
        if ($consulta !== null) {
            drPrAltaConsulta($pdo, $newId, $p, $consulta, $listaIds, $etiquetaIds, true);
        }

        $pdo->commit();
        jsonOk([
            'id'         => $newId,
            'uuid'       => $p['uuid'],
            'registrado' => $p['registrado'],
        ], 201);
    } catch (Throwable $e) {
        // drPrAssertUnico() corta con exit() adentro de jsonError, asi que el
        // rollback del 409 lo hace PDO al cerrarse la conexion. Este catch es
        // para los errores que si suben como excepcion.
        if ($pdo->inTransaction()) $pdo->rollBack();
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
        // Misma regla de unicidad que el alta, excluyendo la propia fila. Sin
        // esto el 409 del POST seria trivial de esquivar: alta con correo
        // libre + PUT pisandolo con el que ya existia.
        drPrAssertUnico($pdo, $p, $id);

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
                    extraccion_url     = :extraccion_url,
                    extraccion_autor   = :extraccion_autor,
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
            ':extraccion_url'     => $p['extraccion_url'],
            ':extraccion_autor'   => $p['extraccion_autor'],
            ':registrado'         => $p['registrado'],
            ':id'                 => $id,
        ]);
        if ($listaIds    !== null) drPrSyncListas($pdo, $id, $listaIds);
        if ($etiquetaIds !== null) drPrSyncEtiquetas($pdo, $id, $etiquetaIds);
        $pdo->commit();
        jsonOk(['id' => $id]);
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    }
}

// PATCH /v4/datarocket/prospectos?id=N
//
// Modificacion PARCIAL: se escriben unicamente las columnas presentes en el
// body (ver el bloque "PUT vs. PATCH" del encabezado). Las claves desconocidas
// se ignoran, como en POST / PUT; un body sin ninguna clave conocida corta con
// 400 en vez de hacer un UPDATE vacio y devolver 200.
//
// El armado tiene tres pasos: (1) que columnas toco el cliente, (2) el estado
// resultante contra el que se valida — fila actual pisada por el parche —, y
// (3) el UPDATE, que solo lleva las columnas del paso 1.
function handlePatch(PDO $pdo, int $id, array $in): void {
    $stmt = $pdo->prepare("SELECT " . DR_CT_COLS . " FROM datarocket_prospectos WHERE id = :id");
    $stmt->execute([':id' => $id]);
    $row = $stmt->fetch();
    if (!$row) jsonError('Prospecto no encontrado', 404);

    // Alias legacy -> clave canonica, antes de mirar que vino en el body.
    foreach (DR_CT_PATCH_ALIAS as $alias => $col) {
        if (array_key_exists($alias, $in) && !array_key_exists($col, $in)) {
            $in[$col] = $in[$alias];
        }
    }

    // (1) Columnas tocadas. `nombre` no esta en la whitelist: mandarlo no hace
    // nada, igual que en el alta, porque se deriva mas abajo.
    $tocadas = array_values(array_filter(
        DR_CT_PATCH_COLS,
        static fn(string $c): bool => array_key_exists($c, $in)
    ));
    $patchListas    = array_key_exists('lista_ids', $in);
    $patchEtiquetas = array_key_exists('etiqueta_ids', $in);
    if (!$tocadas && !$patchListas && !$patchEtiquetas) {
        jsonError('El cuerpo del PATCH no trae ningun campo modificable.', 400);
    }

    // (2) Estado resultante. Se sanitiza el merge entero — no solo el parche —
    // porque las invariantes de identidad y de `nombre` se definen sobre la
    // fila completa. Las columnas heredadas se re-normalizan aca pero NO se
    // escriben: solo alimentan las validaciones.
    drPrAssertCorreo($in);                 // solo valida si el cliente mando `correo`
    $p = drPrSanitize(array_merge($row, $in));

    // `tipo` se valida solo si el parche lo toca. Las filas historicas lo tienen
    // en NULL y exigirlo siempre dejaria fuera del PATCH justo a las que mas
    // falta les hace: corregirle el celular a una fila vieja no deberia obligar
    // al cliente a decidirle el tipo.
    if (array_key_exists('tipo', $in) && !in_array($p['tipo'], DR_CT_TIPOS_VALIDOS, true)) {
        jsonError('El tipo es obligatorio (persona o empresa).', 400);
    }

    // Con un `tipo` valido — venga del body o de la fila — rigen las mismas
    // invariantes que en el PUT. `nombre` se re-deriva sobre el estado
    // resultante y se escribe solo si cambio: asi un PATCH de `persona_nombre`
    // no deja el `nombre` viejo colgado, que es exactamente como se ensucio la
    // tabla antes de la backfill 20260817_2100.
    if (in_array($p['tipo'], DR_CT_TIPOS_VALIDOS, true)) {
        drPrAssertIdentidad($p);
        $p['nombre'] = drPrDerivarNombre($p);
        if ((string)$row['nombre'] !== $p['nombre']) $tocadas[] = 'nombre';
    }

    // Los catalogos se validan solo donde el parche escribe: los ids ya
    // guardados pasaron por la FK cuando se escribieron.
    drPrAssertUbicacion($pdo, [
        'pais_id'      => in_array('pais_id',      $tocadas, true) ? $p['pais_id']      : null,
        'provincia_id' => in_array('provincia_id', $tocadas, true) ? $p['provincia_id'] : null,
        'localidad_id' => in_array('localidad_id', $tocadas, true) ? $p['localidad_id'] : null,
    ]);

    // Rescate de un correo cargado por error en `web`: drPrSanitize() ya lo
    // resolvio sobre el merge, aca solo se decide si esa columna entra al
    // UPDATE. Se rescata cuando el parche trae `web`, no trae `correo` y el
    // prospecto no tenia uno cargado — mismo criterio que el alta.
    if (in_array('web', $tocadas, true) && !in_array('correo', $tocadas, true)
        && $p['correo'] !== null && (string)($row['correo'] ?? '') === '') {
        $tocadas[] = 'correo';
    }

    $listaIds    = $patchListas    ? drPrSanitizeListaIds($in['lista_ids'])       : null;
    $etiquetaIds = $patchEtiquetas ? drPrSanitizeEtiquetaIds($in['etiqueta_ids']) : null;

    $pdo->beginTransaction();
    try {
        // Unicidad SOLO sobre lo que el parche escribe. Chequear tambien los
        // valores heredados haria que un PATCH de `domicilio` sobre una fila con
        // un correo duplicado historico (hay 2.876) se caiga con un 409 que el
        // cliente no provoco y no puede arreglar.
        drPrAssertUnico($pdo, [
            'correo'  => in_array('correo',  $tocadas, true) ? $p['correo']  : null,
            'celular' => in_array('celular', $tocadas, true) ? $p['celular'] : null,
        ], $id);

        // (3) UPDATE armado con las columnas tocadas. Los nombres salen de
        // DR_CT_PATCH_COLS (mas `nombre`), nunca del body, asi que interpolarlos
        // en el SQL es seguro; los valores siguen yendo por bind.
        if ($tocadas) {
            $sets   = [];
            $params = [':id' => $id];
            foreach ($tocadas as $col) {
                $sets[]            = "{$col} = :{$col}";
                $params[":{$col}"] = $p[$col];
            }
            $sql = "UPDATE datarocket_prospectos SET " . implode(', ', $sets) . " WHERE id = :id";
            $pdo->prepare($sql)->execute($params);
        }
        if ($listaIds    !== null) drPrSyncListas($pdo, $id, $listaIds);
        if ($etiquetaIds !== null) drPrSyncEtiquetas($pdo, $id, $etiquetaIds);
        $pdo->commit();

        // `campos` devuelve lo que realmente se escribio, incluidos los
        // agregados por el endpoint (`nombre` derivado, `correo` rescatado de
        // `web`) y sin las claves del body que se ignoraron.
        $campos = $tocadas;
        if ($listaIds    !== null) $campos[] = 'lista_ids';
        if ($etiquetaIds !== null) $campos[] = 'etiqueta_ids';
        jsonOk(['id' => $id, 'campos' => $campos]);
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
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

    // Estampa `datarocket_etiquetas.fecha_uso` — este es el punto en que las
    // etiquetas efectivamente se usan. Se marcan TODAS las que quedan aplicadas
    // y no solo las que entraron nuevas: el sync es un full replace y despues
    // del DELETE no queda con que distinguirlas.
    marcarUsoEtiquetas($pdo, $validIds);
}
