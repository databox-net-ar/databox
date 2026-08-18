<?php
// api/v4/datarocket/etiquetas.php
// Microservicio del CRM Datarocket sobre la tabla `datarocket_etiquetas` — el
// catalogo de etiquetas reutilizables que se aplican a los prospectos via la
// tabla puente `datarocket_prospectos_etiquetas`.
//
//   GET  /v4/datarocket/etiquetas              -> listado con filtros (query string)
//   GET  /v4/datarocket/etiquetas?id=N         -> registro individual
//   GET  /v4/datarocket/etiquetas?nombre=expo  -> resolucion exacta nombre -> id
//   POST /v4/datarocket/etiquetas              (JSON body) -> alta, 409 si ya existe
//   POST /v4/datarocket/etiquetas?resolver=1   (JSON body) -> alta idempotente
//                                                 (devuelve la existente si la hay)
//
// Auth: Bearer con apikey de la tabla `aplicaciones` (mismo esquema que el resto
// del stack — ver cloud/api/lib/apikey_auth.php). Cualquier apikey habilitada pasa.
//
// Tabla destino: `datarocket_etiquetas` (migraciones 20260811_1200 / _1400 /
// _1800 / 20260812_0000). El ABM interno equivalente, usado por el panel cloud,
// es cloud/api/datarocket_etiquetas.php — mismas columnas y mismas reglas; la
// diferencia es la capa de auth y que el listado v4 no publica el bloque `stats`.
//
// ---------------------------------------------------------------------------
// POR QUE SOLO LECTURA Y ALTA (no hay PUT / PATCH / DELETE)
// ---------------------------------------------------------------------------
// Una etiqueta no es un dato de un cliente: es una clave compartida por TODOS
// los prospectos que la tienen puesta. Renombrarla les cambia el significado a
// todos a la vez, y borrarla se lleva puestas las asignaciones (la puente cae
// por el ON DELETE CASCADE de `fk_dpe_etiqueta`, sin aviso y sin vuelta atras).
//
// Esas dos operaciones son de curaduria del catalogo, no de integracion, asi
// que quedan unicamente en el ABM del panel cloud, donde hay usuario, permisos
// (`datarocket.etiquetas.editar` / `.eliminar`) y suceso asociado. Desde afuera
// un integrador puede consultar el catalogo y sumarle etiquetas nuevas; lo que
// ya existe no lo puede tocar. Cualquier otro metodo devuelve 405.
//
// ---------------------------------------------------------------------------
// EL NOMBRE ES LA CLAVE LOGICA
// ---------------------------------------------------------------------------
// `datarocket_etiquetas.nombre` es UNIQUE — es lo que el usuario ve y elige, y
// lo que un importador externo tiene a mano ("expo", "inmobiliaria"). Por eso el
// endpoint ofrece dos formas de llegar al id:
//
//   * `GET ?nombre=expo`     busca y NO escribe. 404 si no esta.
//   * `POST ?resolver=1`     busca y, si no esta, la crea. Nunca 409.
//
// ---------------------------------------------------------------------------
// LA BUSQUEDA NO DISTINGUE MAYUSCULAS NI ACENTOS
// ---------------------------------------------------------------------------
// Buscar "EXPO", "Expo" o "expo" da lo mismo, y buscar "peru" encuentra "Perú".
// Vale para las dos formas de buscar — `?nombre=` (igualdad) y `?q=` (LIKE) — y
// tambien para el UNIQUE que impide dar de alta la misma etiqueta dos veces.
//
// Lo resuelven dos capas:
//
//   1. La collation `utf8mb4_general_ci` de la columna, que pliega mayusculas y
//      diacriticos precompuestos: para MySQL `cafe` = `café`, `nandu` = `ñandú`
//      y `pinguino` = `pingüino`. No hace falta que el cliente normalice nada.
//   2. etqPlegarTexto() del lado PHP, que ademas de trim + colapsar espacios
//      + minusculas saca las marcas combinantes (U+0300-U+036F). Eso cubre el
//      agujero que la collation NO tapa: el texto en forma NFD, donde la `é`
//      viaja como `e` + tilde suelta (lo que mandan varios teclados de macOS /
//      iOS). Ahi la fila tiene 5 caracteres contra 4 y el `=` falla aunque la
//      collation sea accent-insensitive. El contenedor no trae `intl`, asi que
//      la normalizacion es esta y no Normalizer::FORM_C.
//
// La normalizacion se aplica IGUAL al alta y a la busqueda — es lo que garantiza
// que "  Expo " encuentre a "expo" en vez de crear una segunda fila. Sin eso el
// catalogo se degrada solo.
//
// ---------------------------------------------------------------------------
// `etiquetados` ES UN SNAPSHOT, NO UN CONTADOR VIVO
// ---------------------------------------------------------------------------
// La columna `etiquetados` es un denormalizado que se recalcula a mano desde el
// ABM cloud (boton "Recalcular etiquetados"), no un trigger. O sea que puede
// estar atrasada respecto de la puente. Se publica igual porque para pintar un
// listado alcanza y sale gratis; cuando el numero tiene que ser exacto va
// `?con_conteo=1`, que agrega `etiquetados_reales` contado en vivo contra
// `datarocket_prospectos_etiquetas`.

declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');

require_once dirname(__DIR__, 3) . '/env.php';
require_once dirname(__DIR__, 3) . '/cloud/api/db.php';
require_once dirname(__DIR__, 3) . '/cloud/api/lib/sucesos.php';

// ---------------------------------------------------------------------------
// Auth
// ---------------------------------------------------------------------------
// Mismo shape que cloud/api/lib/apikey_auth.php, rodado inline como el resto de
// los microservicios v4 para no arrastrar dependencias. Apache no siempre
// propaga Authorization a $_SERVER (depende de mod_rewrite y CGIPassAuth), asi
// que se chequea $_SERVER, REDIRECT_HTTP_AUTHORIZATION y getallheaders().

function etqReadBearer(): string {
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

function etqRequireApp(): array {
    $token = etqReadBearer();
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

const DR_ET_COLS = 'id, nombre, descripcion, etiquetados, fecha_creacion, fecha_modificacion';

// Criterios de orden aceptados en el listado. Cualquier otro valor cae al
// default (`nombre`) en vez de dar 400: un `order_by` mal escrito no justifica
// romperle la pantalla al cliente.
const DR_ET_ORDENES = ['id', 'nombre', 'etiquetados', 'fecha_creacion', 'fecha_modificacion'];

const DR_ET_NOMBRE_MAX      = 80;   // = varchar(80) de la columna
const DR_ET_DESCRIPCION_MAX = 500;  // = varchar(500)

// Separador con el que /v4/datarocket/prospectos empaqueta `etiqueta_nombres`
// en su GROUP_CONCAT. Un nombre que lo contenga rompe el split del lado del
// consumidor, asi que no entra al catalogo (ver etqAssertNombre).
const DR_ET_SEPARADOR_GC = '||~||';

// ---------------------------------------------------------------------------
// Ruteo
// ---------------------------------------------------------------------------

try {
    $app    = etqRequireApp();
    $pdo    = db();
    $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
    $id     = isset($_GET['id']) ? (int)$_GET['id'] : 0;

    if ($method === 'GET' && etqFlagResolver($_GET)) {
        // `?resolver=1` crea si no existe: no puede viajar en un GET. Se contesta
        // explicito porque el error de tipeo es facil y el 200 con el listado
        // entero seria una respuesta silenciosamente incorrecta.
        jsonError('`?resolver=1` requiere POST (crea la etiqueta si no existe). '
                . 'Para buscar sin crear usa `GET ?nombre=...`.', 405);
    } elseif ($method === 'GET' && $id > 0) {
        handleGetOne($pdo, $id, $_GET);
    } elseif ($method === 'GET' && array_key_exists('nombre', $_GET)) {
        handleGetPorNombre($pdo, (string)$_GET['nombre'], $_GET);
    } elseif ($method === 'GET') {
        handleList($pdo, $_GET);
    } elseif ($method === 'POST') {
        handleCreate($pdo, readJsonBody(), $app, etqFlagResolver($_GET));
    } else {
        jsonError('Metodo no soportado', 405);
    }
} catch (Throwable $e) {
    jsonError($e->getMessage(), 500);
}

// ---------------------------------------------------------------------------
// Flags de query string
// ---------------------------------------------------------------------------

// Se acepta cualquier valor no vacio salvo los negativos explicitos, para que
// sirvan tanto `?resolver=1` como `?resolver=true`. Mismo criterio que el
// `?verificar=1` de prospectos.
function etqFlagBool(array $q, string $clave): bool {
    if (!array_key_exists($clave, $q)) return false;
    $v = strtolower(trim((string)$q[$clave]));
    return !in_array($v, ['', '0', 'false', 'no'], true);
}

function etqFlagResolver(array $q): bool { return etqFlagBool($q, 'resolver'); }
function etqFlagConteo(array $q): bool   { return etqFlagBool($q, 'con_conteo'); }

// ---------------------------------------------------------------------------
// Normalizacion y validacion del payload
// ---------------------------------------------------------------------------

// trim + corridas de espacios colapsadas + diacriticos combinantes plegados +
// minusculas. Se aplica IGUAL al alta y a la busqueda (ver el encabezado).
// Devuelve '' cuando no queda nada util.
//
// El rango U+0300-U+036F es exactamente el bloque de diacriticos latinos
// (tildes, dieresis, virgulillas). Se saca solo ese y no `\p{Mn}` entero para no
// mutilar escrituras donde la marca combinante no es un adorno sino parte de la
// palabra (vocales del hebreo, harakat del arabe).
//
// Efecto de borde asumido: un cliente que manda "café" en NFD guarda `cafe` sin
// tilde, mientras que uno que la manda en NFC guarda `café`. Las dos filas no
// pueden coexistir (la collation las considera la misma) y cualquiera de las dos
// grafias encuentra a la otra, que es lo que importa. Guardar el NFD tal cual
// seria peor: quedaria una fila que ninguna busqueda normal vuelve a encontrar.
//
// El `?? $s` de los preg_replace cubre el caso de un `nombre` que no sea UTF-8
// valido: con el modificador /u eso devuelve null, y perder el texto entero es
// peor que dejarlo pasar sin plegar (mas adelante lo frena el largo o el UNIQUE).
function etqPlegarTexto(mixed $raw): string {
    $s = trim((string)$raw);
    if ($s === '') return '';
    $s = preg_replace('/\s+/u', ' ', $s) ?? $s;
    $s = preg_replace('/[\x{0300}-\x{036F}]+/u', '', $s) ?? $s;
    return mb_strtolower($s, 'UTF-8');
}

// Alias por legibilidad en los puntos donde lo que se pliega es la clave logica
// del catalogo y no un termino de busqueda suelto.
function etqNormalizarNombre(mixed $raw): string {
    return etqPlegarTexto($raw);
}

// Corta con 400 si el nombre ya normalizado no sirve como clave del catalogo.
function etqAssertNombre(string $nombre): void {
    if ($nombre === '') {
        jsonError('El `nombre` es obligatorio.', 400);
    }
    if (mb_strlen($nombre) > DR_ET_NOMBRE_MAX) {
        jsonError('El `nombre` no puede superar los ' . DR_ET_NOMBRE_MAX . ' caracteres.', 400);
    }
    // Los parentesis eran los delimitadores del formato historico
    // `(expo)(visitante)` con el que las tablas legacy sin guion bajo
    // (`datarocketcontactos.tags`) todavia guardan sus etiquetas. Un nombre que
    // los contenga rompe cualquier parser que siga leyendo de ahi.
    if (strpbrk($nombre, '()') !== false) {
        jsonError('El `nombre` no puede contener parentesis.', 400);
    }
    if (str_contains($nombre, DR_ET_SEPARADOR_GC)) {
        jsonError('El `nombre` no puede contener la secuencia `' . DR_ET_SEPARADOR_GC . '`.', 400);
    }
}

// Descripcion: nota interna opcional. Vacia se guarda como NULL (no como '')
// para que el catalogo tenga una sola representacion de "sin descripcion".
function etqSanitizeDescripcion(mixed $raw): ?string {
    $s = trim((string)($raw ?? ''));
    if ($s === '') return null;
    if (mb_strlen($s) > DR_ET_DESCRIPCION_MAX) {
        jsonError('La `descripcion` no puede superar los ' . DR_ET_DESCRIPCION_MAX . ' caracteres.', 400);
    }
    return $s;
}

// Normaliza un id que llega por query string: vacio / no numerico / <= 0 ->
// null (equivale a "sin filtro", no a filtrar por 0).
function etqFiltroId(mixed $v): ?int {
    if ($v === null) return null;
    $s = trim((string)$v);
    if ($s === '' || !ctype_digit($s)) return null;
    $n = (int)$s;
    return $n > 0 ? $n : null;
}

// PDO devuelve todo como string; el JSON publica ints donde la columna es int
// para que el consumidor pueda reenviar `id` a `etiqueta_ids` sin castear.
function etqFormatFila(array $r): array {
    return [
        'id'                 => (int)$r['id'],
        'nombre'             => (string)$r['nombre'],
        'descripcion'        => $r['descripcion'] !== null ? (string)$r['descripcion'] : null,
        'etiquetados'        => (int)($r['etiquetados'] ?? 0),
        'fecha_creacion'     => $r['fecha_creacion']     ?? null,
        'fecha_modificacion' => $r['fecha_modificacion'] ?? null,
    ];
}

// ---------------------------------------------------------------------------
// Acceso a datos
// ---------------------------------------------------------------------------

function etqBuscarPorId(PDO $pdo, int $id): ?array {
    $st = $pdo->prepare('SELECT ' . DR_ET_COLS . ' FROM datarocket_etiquetas WHERE id = :id LIMIT 1');
    $st->execute([':id' => $id]);
    $row = $st->fetch();
    return $row ? etqFormatFila($row) : null;
}

// `$nombre` tiene que venir YA plegado por etqNormalizarNombre(). La igualdad la
// resuelve la collation utf8mb4_general_ci de la columna — insensible a
// mayusculas y a acentos — que es exactamente la que respalda el UNIQUE: si esta
// consulta no encuentra nada, el INSERT no puede chocar por nombre (salvo
// carrera, contemplada en handleCreate). Las dos capas no pueden divergir
// porque son la misma comparacion.
function etqBuscarPorNombre(PDO $pdo, string $nombre): ?array {
    $st = $pdo->prepare('SELECT ' . DR_ET_COLS . ' FROM datarocket_etiquetas WHERE nombre = :n LIMIT 1');
    $st->execute([':n' => $nombre]);
    $row = $st->fetch();
    return $row ? etqFormatFila($row) : null;
}

// Anexa `etiquetados_reales` (conteo vivo contra la puente) a cada item, con una
// sola query agrupada. Solo corre con `?con_conteo=1`: `etiquetados` alcanza
// para la mayoria de los usos y esto es una lectura extra sobre una tabla que
// crece con los prospectos, no con el catalogo.
function etqAttachConteo(PDO $pdo, array &$items): void {
    if (!$items) return;
    // Los ids salen de etqFormatFila(), o sea que ya son int — interpolarlos es
    // seguro y evita armar N placeholders.
    $in  = implode(',', array_map(fn($r) => (int)$r['id'], $items));
    $map = [];
    foreach ($pdo->query("SELECT etiqueta_id, COUNT(*) AS c
                            FROM datarocket_prospectos_etiquetas
                           WHERE etiqueta_id IN ({$in})
                        GROUP BY etiqueta_id") as $r) {
        $map[(int)$r['etiqueta_id']] = (int)$r['c'];
    }
    foreach ($items as &$row) {
        // Sin fila en la puente el COUNT no devuelve nada: es 0, no ausencia.
        $row['etiquetados_reales'] = $map[(int)$row['id']] ?? 0;
    }
}

// ---------------------------------------------------------------------------
// Listado / consulta
// ---------------------------------------------------------------------------

function handleList(PDO $pdo, array $q): void {
    $codigo = etqFiltroId($q['codigo'] ?? null);
    // El termino se pliega igual que un nombre: la collation ya resuelve
    // mayusculas y acentos precompuestos, pero el NFD hay que plegarlo aca.
    $search = etqPlegarTexto($q['q'] ?? '');

    // `orden` es el nombre que usa el ABM cloud y `order_by` el que usa el resto
    // de v4: se aceptan los dos para no obligar a recordar cual va en cada capa.
    $orderBy = (string)($q['order_by'] ?? $q['orden'] ?? 'nombre');
    if (!in_array($orderBy, DR_ET_ORDENES, true)) $orderBy = 'nombre';

    // Default alfabetico ascendente — al reves que prospectos (id desc) a
    // proposito: esto es un catalogo chico que casi siempre termina en un combo,
    // y ahi el orden util es el alfabetico.
    $dirDefault = $orderBy === 'nombre' ? 'asc' : 'desc';
    $dirSql     = strtolower((string)($q['dir'] ?? $dirDefault)) === 'asc' ? 'ASC' : 'DESC';

    $limite = isset($q['limite']) ? (int)$q['limite'] : 100;
    if ($limite < 1)    $limite = 1;
    if ($limite > 1000) $limite = 1000;

    $where  = [];
    $params = [];

    if ($codigo !== null) {
        $where[] = 'id = :codigo';
        $params[':codigo'] = $codigo;
    }
    // Busqueda parcial, sobre nombre Y descripcion (igual que el ABM). No lleva
    // COLLATE explicito: las dos columnas ya son utf8mb4_general_ci, o sea que
    // el LIKE sale insensible a mayusculas y a acentos por definicion de la
    // tabla ("peru" matchea "Perú").
    if ($search !== '') {
        $where[] = '(nombre LIKE :s_nom OR descripcion LIKE :s_desc)';
        $params[':s_nom']  = '%' . $search . '%';
        $params[':s_desc'] = '%' . $search . '%';
    }

    $sqlWhere = $where ? ('WHERE ' . implode(' AND ', $where)) : '';

    // `limite` ya viene clampeado a [1, 1000] e interpolado como int — no puede
    // ir como placeholder porque PDO lo citaria como string y MySQL rechaza
    // `LIMIT '100'`.
    $sql = 'SELECT ' . DR_ET_COLS . "
              FROM datarocket_etiquetas
              {$sqlWhere}
              ORDER BY {$orderBy} {$dirSql}
              LIMIT {$limite}";
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);

    $items = array_map('etqFormatFila', $stmt->fetchAll());
    if (etqFlagConteo($q)) etqAttachConteo($pdo, $items);

    jsonOk([
        'total' => count($items),
        'items' => $items,
    ]);
}

function handleGetOne(PDO $pdo, int $id, array $q): void {
    $fila = etqBuscarPorId($pdo, $id);
    if ($fila === null) jsonError('Etiqueta no encontrada', 404);

    $items = [$fila];
    if (etqFlagConteo($q)) etqAttachConteo($pdo, $items);
    jsonOk($items[0]);
}

// GET /v4/datarocket/etiquetas?nombre=expo
//
// Resolucion exacta nombre -> id, que es el caso de uso que motiva el endpoint:
// el cliente tiene el texto de la etiqueta y necesita el id para mandarlo en
// `etiqueta_ids` de /v4/datarocket/prospectos.
//
// Devuelve la MISMA forma que `?id=N` (un objeto, no una lista) y 404 cuando no
// existe. Para buscar por aproximacion esta `?q=`, que devuelve listado y nunca
// 404; para "traemela o creala" esta `POST ?resolver=1`.
function handleGetPorNombre(PDO $pdo, string $raw, array $q): void {
    $nombre = etqNormalizarNombre($raw);
    // `?nombre=` vacio no se trata como "sin filtro": el cliente pidio buscar un
    // nombre y devolverle el catalogo entero seria contestarle otra pregunta.
    if ($nombre === '') jsonError('El `nombre` a buscar no puede estar vacio.', 400);

    $fila = etqBuscarPorNombre($pdo, $nombre);
    if ($fila === null) {
        // El nombre normalizado viaja en el error para que se entienda contra
        // que se busco realmente (" Expo " se busco como "expo").
        jsonError('Etiqueta no encontrada', 404, ['consulta' => ['nombre' => $nombre]]);
    }

    $items = [$fila];
    if (etqFlagConteo($q)) etqAttachConteo($pdo, $items);
    jsonOk($items[0]);
}

// ---------------------------------------------------------------------------
// Alta
// ---------------------------------------------------------------------------

// POST /v4/datarocket/etiquetas            -> 201, o 409 si el nombre ya existe
// POST /v4/datarocket/etiquetas?resolver=1 -> 201 si la creo, 200 si ya estaba
//
// Los dos caminos devuelven la fila completa mas `creada` (bool), asi que el
// cliente lee `data.id` igual en ambos casos. `?resolver=1` es lo que quiere un
// importador que etiqueta sobre la marcha; el POST pelado es lo que quiere un
// alta explicita, que prefiere enterarse del choque.
//
// `?resolver=1` NO es un upsert: si la etiqueta ya existe, la `descripcion` del
// body se ignora en vez de pisar la que hay. Modificar el catalogo se hace desde
// el ABM cloud (ver el encabezado del archivo).
function handleCreate(PDO $pdo, array $in, array $app, bool $resolver): void {
    $nombre = etqNormalizarNombre($in['nombre'] ?? '');
    etqAssertNombre($nombre);
    $descripcion = etqSanitizeDescripcion($in['descripcion'] ?? null);

    $existente = etqBuscarPorNombre($pdo, $nombre);
    if ($existente !== null) {
        etqResponderExistente($existente, $resolver);
    }

    try {
        $st = $pdo->prepare('INSERT INTO datarocket_etiquetas (nombre, descripcion)
                             VALUES (:nombre, :descripcion)');
        $st->execute([':nombre' => $nombre, ':descripcion' => $descripcion]);
        $newId = (int)$pdo->lastInsertId();
    } catch (PDOException $e) {
        // Carrera con otro POST simultaneo del mismo nombre: entre el SELECT de
        // arriba y este INSERT no hay lock, pero el UNIQUE de `nombre` si
        // desempata. 23000 = integrity constraint violation.
        if ($e->getCode() !== '23000') throw $e;
        $existente = etqBuscarPorNombre($pdo, $nombre);
        if ($existente === null) throw $e;  // no era el UNIQUE del nombre
        etqResponderExistente($existente, $resolver);
        return;                             // inalcanzable: etqResponder* corta
    }

    // Quien creo la etiqueta importa: el catalogo es compartido y una etiqueta
    // basura entrada por una integracion hay que poder rastrearla.
    registrarSuceso($pdo, 'v4/datarocket.etiquetas', 'info',
        "Alta etiqueta #{$newId} — \"{$nombre}\" (app: " . (string)($app['nombre'] ?? '?') . ')');

    $fila = etqBuscarPorId($pdo, $newId) ?? ['id' => $newId, 'nombre' => $nombre];
    jsonOk($fila + ['creada' => true], 201);
}

// La etiqueta ya existia: con `?resolver=1` es el resultado esperado (200), sin
// el flag es un choque (409). El 409 lleva la fila en `etiqueta` para que el
// cliente pueda quedarse con el id sin tener que volver a preguntar.
function etqResponderExistente(array $fila, bool $resolver): void {
    if ($resolver) {
        jsonOk($fila + ['creada' => false]);
    }
    jsonError('Ya existe una etiqueta con ese nombre.', 409, ['etiqueta' => $fila]);
}
