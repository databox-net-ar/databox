<?php
// api/v4/databox/ubicaciones.php
// Microservicio de consulta del catalogo geografico: `paises`, `provincias` y
// `localidades` (schema en db/schema.sql).
//
//   GET /v4/databox/ubicaciones                             -> paises (default)
//   GET /v4/databox/ubicaciones?tipo=provincias&pais_id=1    -> provincias de un pais
//   GET /v4/databox/ubicaciones?tipo=localidades&provincia_id=6
//   GET /v4/databox/ubicaciones?q=quilmes                    -> busca en los tres niveles
//   GET /v4/databox/ubicaciones?tipo=localidades&id=6658     -> una sola, con su cadena
//
// SOLO LECTURA. No hay POST / PUT / DELETE: el padron geografico se mantiene
// por fuera de la API (es un catalogo, no un recurso de negocio). Cualquier otro
// metodo devuelve 405.
//
// Auth: Bearer con apikey de la tabla `aplicaciones`, igual que el resto de v4.
//
// ---------------------------------------------------------------------------
// POR QUE UN SOLO ENDPOINT Y NO TRES
// ---------------------------------------------------------------------------
// Los tres catalogos no son recursos independientes, son UN ARBOL: una
// localidad sin su provincia y su pais no le sirve a nadie, porque el consumidor
// tipico (el POST de /v4/datarocket/prospectos) necesita los tres ids juntos.
//
// Y el caso de uso que motiva el endpoint — resolver un texto a un id — cruza
// las tres tablas. Con endpoints separados, "¿que ids le mando al prospecto si
// lo unico que tengo es la palabra Quilmes?" son tres llamadas encadenadas que
// el cliente tiene que unir a mano; aca es una sola, y cada item ya viaja con
// toda su cadena hacia arriba.
//
// El costo asumido es que `?tipo=` es un discriminador y no una URL de recurso,
// o sea que no es REST purista. Se acepta: v4 ya no lo es (`?id=N`,
// `?verificar=1` en prospectos) y la cohesion del arbol pesa mas que la
// ortodoxia.
//
// Vive en `databox/` y no en `datarocket/` a proposito: la geografia no es del
// CRM. La van a querer otros microservicios v4.
//
// ---------------------------------------------------------------------------
// BUSQUEDA
// ---------------------------------------------------------------------------
// `q` hace `LIKE '%q%'` sobre `nombre`. Las tres columnas son
// utf8mb4_general_ci, collation que pliega acentos y eñe, asi que la busqueda
// sale insensible sin hacer nada: `peru` matchea `Perú` y `canuelas` matchea
// `Cañuelas`. NO hace falta normalizar el termino del lado del cliente — y
// conviene no hacerlo, porque mandar el texto tal cual es lo que permite que el
// match exacto gane el orden.

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

function ubiReadBearer(): string {
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

function ubiRequireApp(): array {
    $token = ubiReadBearer();
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
// Niveles del arbol
// ---------------------------------------------------------------------------

// Se aceptan plural y singular: `tipo=provincias` y `tipo=provincia` son lo
// mismo. Cuesta una linea y evita el 400 mas tonto posible.
const UBI_TIPOS = [
    'paises'      => 'paises',
    'pais'        => 'paises',
    'provincias'  => 'provincias',
    'provincia'   => 'provincias',
    'localidades' => 'localidades',
    'localidad'   => 'localidades',
];

// Cada item viaja SIEMPRE con su cadena completa hacia arriba — es el motivo de
// existir del endpoint. Los JOIN son LEFT porque `pais_id` / `provincia_id` son
// nullable en el schema; hoy no hay huerfanos en dev, pero un INNER convertiria
// una fila mal cargada en una fila invisible, que es la peor forma de fallar.
const UBI_SELECT = [
    'paises' => "SELECT 'pais' AS tipo, pa.id, pa.nombre
                   FROM paises pa",
    'provincias' => "SELECT 'provincia' AS tipo, pr.id, pr.nombre, pr.categoria,
                            pr.pais_id, pa.nombre AS pais_nombre
                       FROM provincias pr
                       LEFT JOIN paises pa ON pa.id = pr.pais_id",
    'localidades' => "SELECT 'localidad' AS tipo, lo.id, lo.nombre, lo.categoria,
                             lo.provincia_id, pr.nombre AS provincia_nombre,
                             lo.pais_id, pa.nombre AS pais_nombre
                        FROM localidades lo
                        LEFT JOIN provincias pr ON pr.id = lo.provincia_id
                        LEFT JOIN paises   pa ON pa.id = lo.pais_id",
];

// Alias de la tabla propia de cada nivel, para prefijar columnas en el WHERE
// sin ambiguedad contra las joineadas (las tres se llaman `nombre`).
const UBI_ALIAS = ['paises' => 'pa', 'provincias' => 'pr', 'localidades' => 'lo'];

// ---------------------------------------------------------------------------
// Ruteo
// ---------------------------------------------------------------------------

try {
    ubiRequireApp();
    $pdo    = db();
    $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
    if ($method !== 'GET') jsonError('Metodo no soportado', 405);

    handleConsulta($pdo, $_GET);
} catch (Throwable $e) {
    jsonError($e->getMessage(), 500);
}

function handleConsulta(PDO $pdo, array $q): void {
    $tipoRaw = strtolower(trim((string)($q['tipo'] ?? '')));
    $busca   = trim((string)($q['q'] ?? ''));
    $id      = ubiFiltroId($q['id'] ?? null);

    // Sin `tipo`: con `q` se busca en los tres niveles, sin `q` se devuelven los
    // paises. El default es paises y no "los tres" porque una llamada pelada al
    // endpoint es casi siempre el primer combo de una cascada.
    if ($tipoRaw === '') {
        $tipos = $busca !== '' ? ['paises', 'provincias', 'localidades'] : ['paises'];
    } elseif (isset(UBI_TIPOS[$tipoRaw])) {
        $tipos = [UBI_TIPOS[$tipoRaw]];
    } else {
        jsonError('El tipo debe ser paises, provincias o localidades.', 400);
    }

    // `id` identifica una fila DENTRO de un nivel; sin saber cual, el id 6 es
    // tres filas distintas. Se exige `tipo` en vez de adivinar.
    if ($id !== null && $tipoRaw === '') {
        jsonError('Para consultar por `id` hay que indicar el `tipo`.', 400);
    }

    $limite = isset($q['limite']) ? (int)$q['limite'] : 50;
    if ($limite < 1)   $limite = 1;
    if ($limite > 500) $limite = 500;

    $paisId      = ubiFiltroId($q['pais_id']      ?? null);
    $provinciaId = ubiFiltroId($q['provincia_id'] ?? null);

    $items = [];
    foreach ($tipos as $tipo) {
        // Cada nivel se pide con el `limite` entero y despues se recorta el
        // total: asi una busqueda que matchea 40 localidades y 1 provincia
        // devuelve las dos cosas, en vez de gastar el cupo en el primer nivel.
        foreach (ubiQuery($pdo, $tipo, $busca, $id, $paisId, $provinciaId, $limite) as $row) {
            $items[] = ubiFormat($row);
        }
    }

    // Consulta por id de un nivel puntual: que no exista es un 404, no una
    // lista vacia — el cliente pregunto por una fila concreta.
    if ($id !== null && !$items) {
        jsonError('Ubicación no encontrada', 404);
    }

    // Orden final: primero por nivel (pais, provincia, localidad) y dentro de
    // cada nivel por relevancia, que es como ya vienen de la query. Se ordena
    // por jerarquia y no por relevancia global a proposito: es predecible, y en
    // una busqueda ambigua ("Buenos Aires" es provincia Y localidad) el
    // resultado mas general primero es el que desambigua.
    $items = array_slice($items, 0, $limite);

    jsonOk([
        'total' => count($items),
        'items' => $items,
    ]);
}

// ---------------------------------------------------------------------------
// Query por nivel
// ---------------------------------------------------------------------------

function ubiQuery(PDO $pdo, string $tipo, string $busca, ?int $id,
                  ?int $paisId, ?int $provinciaId, int $limite): array {
    $a      = UBI_ALIAS[$tipo];
    $where  = [];
    $params = [];

    if ($id !== null) {
        $where[] = "{$a}.id = :id";
        $params[':id'] = $id;
    }
    // Los filtros de ancestro solo aplican donde la columna existe: pedir
    // `?tipo=paises&pais_id=1` no es un error, es una forma valida de traer un
    // pais puntual, y `provincia_id` sobre paises simplemente no aplica.
    if ($paisId !== null && $tipo !== 'paises') {
        $where[] = "{$a}.pais_id = :pais_id";
        $params[':pais_id'] = $paisId;
    }
    if ($paisId !== null && $tipo === 'paises') {
        $where[] = "{$a}.id = :pais_id";
        $params[':pais_id'] = $paisId;
    }
    if ($provinciaId !== null && $tipo === 'localidades') {
        $where[] = "{$a}.provincia_id = :provincia_id";
        $params[':provincia_id'] = $provinciaId;
    }
    // `provincia_id` sobre provincias se lee como "esta provincia".
    if ($provinciaId !== null && $tipo === 'provincias') {
        $where[] = "{$a}.id = :provincia_id";
        $params[':provincia_id'] = $provinciaId;
    }
    // Sobre paises no aplica: filtrar provincias de un pais que no existe daria
    // vacio, que es la respuesta correcta, pero pedir paises de una provincia no
    // significa nada. Se ignora en vez de inventar una semantica.

    if ($busca !== '') {
        $where[] = "{$a}.nombre LIKE :q";
        $params[':q'] = '%' . $busca . '%';
    }

    $sqlWhere = $where ? ('WHERE ' . implode(' AND ', $where)) : '';

    // Relevancia: exacto primero, despues los que empiezan con el termino,
    // despues el resto. Sin esto buscar "quilmes" puede devolver "Berazategui -
    // Quilmes Oeste" antes que "Quilmes". Los parametros se repiten con nombres
    // distintos porque la conexion corre con EMULATE_PREPARES = false y MySQL no
    // acepta el mismo placeholder dos veces.
    $orderBy = "{$a}.nombre";
    if ($busca !== '') {
        $orderBy = "CASE WHEN {$a}.nombre = :q_exacto      THEN 0
                         WHEN {$a}.nombre LIKE :q_prefijo  THEN 1
                         ELSE 2 END, {$a}.nombre";
        $params[':q_exacto']  = $busca;
        $params[':q_prefijo'] = $busca . '%';
    }

    // `limite` ya viene clampeado a [1, 500] e interpolado como int — no puede
    // ir como placeholder porque PDO lo citaria como string y MySQL rechaza
    // `LIMIT '50'`.
    $sql = UBI_SELECT[$tipo] . " {$sqlWhere} ORDER BY {$orderBy} LIMIT {$limite}";

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchAll();
}

// ---------------------------------------------------------------------------
// Helpers
// ---------------------------------------------------------------------------

// Normaliza un id que llega por query string: vacio / no numerico / <= 0 ->
// null (equivale a "sin filtro", no a filtrar por 0).
function ubiFiltroId(mixed $v): ?int {
    if ($v === null) return null;
    $s = trim((string)$v);
    if ($s === '' || !ctype_digit($s)) return null;
    $n = (int)$s;
    return $n > 0 ? $n : null;
}

// Castea los ids a int y saca las claves que no aplican al nivel. PDO devuelve
// todo como string; un `pais_id` que llegue como "1" obliga al consumidor a
// castear antes de reenviarlo, y el JSON de prospectos ya publica ints.
function ubiFormat(array $row): array {
    $out = [
        'tipo'   => (string)$row['tipo'],
        'id'     => (int)$row['id'],
        'nombre' => (string)$row['nombre'],
    ];
    if (array_key_exists('categoria', $row)) {
        $out['categoria'] = $row['categoria'];
    }
    if (array_key_exists('provincia_id', $row)) {
        $out['provincia_id']     = $row['provincia_id'] === null ? null : (int)$row['provincia_id'];
        $out['provincia_nombre'] = $row['provincia_nombre'];
    }
    if (array_key_exists('pais_id', $row)) {
        $out['pais_id']     = $row['pais_id'] === null ? null : (int)$row['pais_id'];
        $out['pais_nombre'] = $row['pais_nombre'];
    }
    return $out;
}
