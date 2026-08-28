<?php
// api/datarocketlistas.php
// ABM de listas Datarocket. Lee/escribe sobre la tabla `datarocket_listas`
// definida en db/schema.sql (creada por la migracion
// 20260811_1200_crear_datarocket_listas.sql; `slug` agregado por la
// 20260821_1000_datarocket_listas_agregar_slug.sql).
//   GET    api/datarocketlistas.php          -> listado con filtros (query string)
//   GET    api/datarocketlistas.php?id=N     -> registro individual
//   GET    api/datarocketlistas.php?id=N&suscriptos=1     -> prospectos suscriptos a la lista
//   GET    api/datarocketlistas.php?candidatos=1&q=&lista=N -> prospectos NO suscriptos (typeahead del editor)
//   POST   api/datarocketlistas.php          -> alta (JSON body)
//   PUT    api/datarocketlistas.php?id=N     -> modificacion (JSON body)
//   PUT    api/datarocketlistas.php?id=N&suscriptos=1     -> alta/baja de suscripciones {agregar:[], quitar:[]}
//   DELETE api/datarocketlistas.php?id=N     -> baja
// Respuesta siempre {ok: true, data: ...} u {ok: false, error: '...'} (STACK.md sec. 10).

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/lib/auth_check.php';

const DR_LI_COLS = "id, proyecto_id, nombre, slug, descripcion, suscriptos";

// Columnas del prospecto que devuelve `?suscriptos=1`. Es lo justo para
// identificar y contactar al suscripto en la pestaña del modal de consulta —
// el detalle completo vive en el ABM de prospectos.
const DR_LI_SUS_COLS = "p.id, p.tipo, p.nombre, p.correo, p.celular, p.whatsapp";

header('Content-Type: application/json; charset=utf-8');

try {
    requirePermCrud('datarocket.listas');
    $pdo    = db();
    $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
    $id     = isset($_GET['id']) ? (int)$_GET['id'] : 0;

    if ($method === 'GET' && isset($_GET['candidatos'])) {
        handleCandidatos($pdo, $_GET);
    } elseif ($method === 'GET' && $id > 0 && isset($_GET['suscriptos'])) {
        handleSuscriptos($pdo, $id, $_GET);
    } elseif ($method === 'GET' && $id > 0) {
        handleGetOne($pdo, $id);
    } elseif ($method === 'GET') {
        handleList($pdo, $_GET);
    } elseif ($method === 'POST') {
        handleCreate($pdo, readJsonBody());
    } elseif ($method === 'PUT' && isset($_GET['suscriptos'])) {
        if ($id <= 0) jsonError('Falta id', 400);
        handleSuscriptosGuardar($pdo, $id, readJsonBody());
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
// Listado y stats
// ----------------------------------------------------------------------------

function handleList(PDO $pdo, array $q): void {
    $codigo     = isset($q['codigo'])      && $q['codigo']      !== '' ? (int)$q['codigo']      : null;
    $proyectoId = isset($q['proyecto_id']) && $q['proyecto_id'] !== '' ? (int)$q['proyecto_id'] : null;
    $search     = trim((string)($q['q'] ?? ''));

    $orderBy = $q['order_by'] ?? 'id';
    $dir     = strtolower((string)($q['dir'] ?? 'desc'));
    $limite  = isset($q['limite']) ? (int)$q['limite'] : 100;
    if ($limite < 1)    $limite = 1;
    if ($limite > 1000) $limite = 1000;

    $allowedOrder = ['id', 'nombre', 'slug', 'proyecto_id', 'suscriptos'];
    if (!in_array($orderBy, $allowedOrder, true)) $orderBy = 'id';
    $dirSql = $dir === 'asc' ? 'ASC' : 'DESC';

    $where  = [];
    $params = [];

    if ($codigo     !== null) { $where[] = 'id = :codigo';               $params[':codigo']      = $codigo; }
    if ($proyectoId !== null) { $where[] = 'proyecto_id = :proyecto_id'; $params[':proyecto_id'] = $proyectoId; }

    if ($search !== '') {
        $where[] = '(nombre LIKE :s1 OR slug LIKE :s2 OR descripcion LIKE :s3)';
        $like = "%{$search}%";
        $params[':s1'] = $like;
        $params[':s2'] = $like;
        $params[':s3'] = $like;
    }

    $sqlWhere = $where ? ('WHERE ' . implode(' AND ', $where)) : '';

    // Stats globales (ignoran filtros — son indicadores del recurso).
    $stats = $pdo->query("
        SELECT
            COUNT(*)                                                         AS total,
            SUM(CASE WHEN proyecto_id IS NOT NULL THEN 1 ELSE 0 END)         AS con_proyecto,
            COALESCE(SUM(CASE WHEN suscriptos IS NULL THEN 0 ELSE suscriptos END), 0) AS suscriptos_total
        FROM datarocket_listas
    ")->fetch();

    $sql = "
        SELECT " . DR_LI_COLS . "
        FROM datarocket_listas
        {$sqlWhere}
        ORDER BY {$orderBy} {$dirSql}
        LIMIT {$limite}
    ";
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $rows = $stmt->fetchAll();

    jsonOk([
        'stats' => [
            'total'           => (int)($stats['total']           ?? 0),
            'con_proyecto'    => (int)($stats['con_proyecto']    ?? 0),
            'suscriptos_total' => (int)($stats['suscriptos_total'] ?? 0),
        ],
        'items' => $rows,
    ]);
}

function handleGetOne(PDO $pdo, int $id): void {
    $stmt = $pdo->prepare("SELECT " . DR_LI_COLS . " FROM datarocket_listas WHERE id = :id");
    $stmt->execute([':id' => $id]);
    $row = $stmt->fetch();
    if (!$row) jsonError('Lista no encontrada', 404);
    jsonOk($row);
}

// ----------------------------------------------------------------------------
// Suscriptos de una lista (pestaña "Suscriptos" del modal de consulta)
// ----------------------------------------------------------------------------

// Prospectos suscriptos a la lista `$id`, via la puente
// `datarocket_prospectos_listas` (PK compuesta prospecto_id + lista_id, FKs
// con CASCADE — no hay huerfanos que descartar).
//
//   GET api/datarocketlistas.php?id=N&suscriptos=1&q=texto&limite=100
//   -> {ok:true, data:{total_lista:N, total:M, items:[...]}}
//
// `total_lista` es la cuenta cruda de suscriptos y `total` la cuenta con el
// buscador aplicado (iguales si `q` viene vacio). La UI las usa para aclarar
// cuanto recorta el limite. Se cuenta contra la puente en vez de leer el
// denormalizado `datarocket_listas.suscriptos`, que puede estar desfasado
// hasta que corra el recalculo.
//
// Vive en este endpoint y no en `datarocketprospectos.php?lista_id=N` a
// proposito: aquel exige permiso `datarocket.prospectos.consultar`, y quien
// consulta una lista tiene que poder ver quien esta adentro con su propio
// permiso `datarocket.listas.consultar` (el que ya valido requirePermCrud).
function handleSuscriptos(PDO $pdo, int $id, array $q): void {
    $existe = $pdo->prepare('SELECT id FROM datarocket_listas WHERE id = :id');
    $existe->execute([':id' => $id]);
    if (!$existe->fetch()) jsonError('Lista no encontrada', 404);

    $search = trim((string)($q['q'] ?? ''));
    $limite = isset($q['limite']) ? (int)$q['limite'] : 100;
    if ($limite < 1)    $limite = 1;
    if ($limite > 1000) $limite = 1000;

    $where  = ['dpl.lista_id = :lista_id'];
    $params = [':lista_id' => $id];

    if ($search !== '') {
        // LIKE sobre utf8mb4_general_ci: la collation ya pliega caja y
        // acentos, asi que "jose" matchea "José" sin normalizar en PHP.
        // Mismos campos propios del prospecto que el buscador rapido del ABM
        // de prospectos (sin los EXISTS de listas/etiquetas, que aca no
        // aportan: la lista ya esta fijada por `lista_id`).
        $where[] = drLiWhereBusquedaProspecto($search, $params, 's');
    }

    $sqlWhere = 'WHERE ' . implode(' AND ', $where);

    // Cuenta cruda: entra directo por `idx_dcl_lista` sin tocar prospectos.
    $st = $pdo->prepare('SELECT COUNT(*) FROM datarocket_prospectos_listas WHERE lista_id = :id');
    $st->execute([':id' => $id]);
    $totalLista = (int)$st->fetchColumn();

    if ($search === '') {
        $total = $totalLista;
    } else {
        $st = $pdo->prepare("
            SELECT COUNT(*)
              FROM datarocket_prospectos_listas dpl
              JOIN datarocket_prospectos p ON p.id = dpl.prospecto_id
            {$sqlWhere}
        ");
        $st->execute($params);
        $total = (int)$st->fetchColumn();
    }

    // Orden alfabetico: la pestaña se lee como un directorio, y con el limite
    // recortando siempre devuelve el mismo tramo (el desempate por id evita
    // que dos homonimos se intercambien entre corridas).
    $stmt = $pdo->prepare("
        SELECT " . DR_LI_SUS_COLS . ", dpl.fecha_creacion
          FROM datarocket_prospectos_listas dpl
          JOIN datarocket_prospectos p ON p.id = dpl.prospecto_id
        {$sqlWhere}
        ORDER BY p.nombre ASC, p.id ASC
        LIMIT {$limite}
    ");
    $stmt->execute($params);

    jsonOk([
        'total_lista' => $totalLista,
        'total'       => $total,
        'items'       => $stmt->fetchAll(),
    ]);
}

// Predicado de busqueda de prospectos compartido por `?suscriptos=1` y
// `?candidatos=1`, para que el operador encuentre lo mismo escribiendo en la
// tabla de suscriptos que en el buscador de "agregar". Devuelve el SQL y
// carga los binds en `$params` por referencia.
function drLiWhereBusquedaProspecto(string $search, array &$params, string $pre): string {
    $like = "%{$search}%";
    $campos = ['nombre', 'empresa_nombre', 'persona_nombre', 'correo',
               'telefono', 'celular', 'whatsapp', 'persona_dni', 'uuid'];
    $ors = [];
    foreach ($campos as $i => $campo) {
        $k = ":{$pre}{$i}";
        $ors[] = "p.{$campo} LIKE {$k}";
        $params[$k] = $like;
    }
    return '(' . implode(' OR ', $ors) . ')';
}

// Prospectos que NO estan suscriptos a la lista `?lista=N`, para el typeahead
// de "Agregar prospectos" del modal de alta/edicion. Con `lista=0` (alta, la
// lista todavia no existe) no excluye nada.
//
//   GET api/datarocketlistas.php?candidatos=1&q=texto&lista=N
//   -> {ok:true, data:{items:[...]}}
//
// Pide `datarocket.listas.editar` y no el `.consultar` que ya valido
// requirePermCrud para los GET: esto busca sobre TODO el padron de
// prospectos, no sobre los de una lista, y solo lo usa el editor.
function handleCandidatos(PDO $pdo, array $q): void {
    requirePermission('datarocket.listas.editar');

    $search = trim((string)($q['q'] ?? ''));
    // Sin texto no hay sugerencias: devolver "los primeros N prospectos" no le
    // sirve a nadie y son 150k filas para ordenar.
    if ($search === '') { jsonOk(['items' => []]); return; }

    $listaId = isset($q['lista']) ? (int)$q['lista'] : 0;
    $limite  = isset($q['limite']) ? (int)$q['limite'] : 10;
    if ($limite < 1)  $limite = 1;
    if ($limite > 25) $limite = 25;

    $params = [];
    $where  = [drLiWhereBusquedaProspecto($search, $params, 'c')];

    if ($listaId > 0) {
        $where[] = 'NOT EXISTS (SELECT 1 FROM datarocket_prospectos_listas dpl
                                 WHERE dpl.lista_id = :lista_id AND dpl.prospecto_id = p.id)';
        $params[':lista_id'] = $listaId;
    }

    $stmt = $pdo->prepare("
        SELECT " . DR_LI_SUS_COLS . "
          FROM datarocket_prospectos p
         WHERE " . implode(' AND ', $where) . "
         ORDER BY p.nombre ASC, p.id ASC
         LIMIT {$limite}
    ");
    $stmt->execute($params);
    jsonOk(['items' => $stmt->fetchAll()]);
}

// Normaliza una lista de ids que llega del cliente: enteros positivos, sin
// repetidos, sin basura.
function drLiIdsUnicos(mixed $v): array {
    if (!is_array($v)) return [];
    $out = [];
    foreach ($v as $x) {
        $n = (int)$x;
        if ($n > 0) $out[$n] = true;
    }
    return array_keys($out);
}

// Aplica las altas y bajas de suscripcion que junto el editor.
//
//   PUT api/datarocketlistas.php?id=N&suscriptos=1
//   Body: {agregar:[ids], quitar:[ids]}
//   -> {ok:true, data:{agregados:N, quitados:M, suscriptos:T}}
//
// Idempotente: el alta usa INSERT IGNORE contra la PK compuesta y la baja un
// DELETE por id, asi que repetir la misma llamada deja el mismo estado. Los
// ids inexistentes se descartan solos — el alta los filtra con un JOIN contra
// `datarocket_prospectos` (no confiamos en que reviente la FK) y la baja
// simplemente no matchea ninguna fila.
//
// Las dos operaciones dejan historial (`datarocket_listas_altas` y
// `datarocket_listas_bajas`), y los dos INSERT van ANTES de tocar la puente
// para poder apoyarse en ella: la puente es la que sabe quien estaba y quien no,
// o sea quien cambio de verdad. Eso mantiene la idempotencia tambien del log —
// la segunda corrida de la misma llamada no registra nada.
function handleSuscriptosGuardar(PDO $pdo, int $id, array $in): void {
    $existe = $pdo->prepare('SELECT id FROM datarocket_listas WHERE id = :id');
    $existe->execute([':id' => $id]);
    if (!$existe->fetch()) jsonError('Lista no encontrada', 404);

    $agregar = drLiIdsUnicos($in['agregar'] ?? null);
    // Si un id viene en las dos, gana el alta: es lo que el operador ve en
    // pantalla (la fila queda listada, no tachada).
    $quitar  = array_values(array_diff(drLiIdsUnicos($in['quitar'] ?? null), $agregar));

    // Tope por llamada. El editor manda de a uno o dos; un lote gigante viene
    // de un cliente propio y merece partirse para no clavar la transaccion.
    if (count($agregar) > 5000 || count($quitar) > 5000) {
        jsonError('Demasiadas suscripciones en una sola llamada (maximo 5000 por operacion).', 400);
    }

    $agregados = 0;
    $quitados  = 0;

    // `sub` es el id de usuario en el JWT (ver computePermisosUsuario). 0 -> NULL
    // para no dejar un id inexistente en el historial. Se resuelve una sola vez:
    // lo usan los dos bloques (altas y bajas) y decodificar el token dos veces
    // por request no aporta nada.
    $uid = (int) (currentAuth()['sub'] ?? 0);
    $uid = $uid > 0 ? $uid : null;

    $pdo->beginTransaction();
    try {
        if ($quitar) {
            $ph = implode(',', array_fill(0, count($quitar), '?'));

            // Historial ANTES de borrar: una vez ejecutado el DELETE no queda
            // de donde sacar el `destino` ni a quien se quito. Sin este registro
            // la estadistica de bajas contaria solo las automaticas por rebote y
            // diria "perdimos 3 suscriptos" cuando alguien saco 200 a mano.
            //
            // El INSERT ... SELECT se apoya en la propia tabla puente, asi que
            // solo registra a los que REALMENTE estaban suscriptos: pedir la
            // baja de alguien que no estaba en la lista no inventa un renglon
            // de historial.
            $st = $pdo->prepare("
                INSERT INTO datarocket_listas_bajas
                    (lista_id, prospecto_id, destino, motivo, origen, usuario_id, fecha)
                SELECT dpl.lista_id, dpl.prospecto_id, NULLIF(TRIM(p.correo), ''),
                       'manual', 'abm/datarocketlistas', ?, NOW()
                  FROM datarocket_prospectos_listas dpl
                  JOIN datarocket_prospectos p ON p.id = dpl.prospecto_id
                 WHERE dpl.lista_id = ? AND dpl.prospecto_id IN ({$ph})
            ");
            $st->execute(array_merge([$uid, $id], $quitar));

            $st = $pdo->prepare("
                DELETE FROM datarocket_prospectos_listas
                 WHERE lista_id = ? AND prospecto_id IN ({$ph})
            ");
            $st->execute(array_merge([$id], $quitar));
            $quitados = $st->rowCount();
        }

        if ($agregar) {
            $ph = implode(',', array_fill(0, count($agregar), '?'));

            // Historial ANTES de insertar, espejo del bloque de bajas. El
            // `NOT EXISTS` contra la puente es lo que hace que solo se registre
            // a quien REALMENTE entra: sin el, volver a guardar el editor con
            // la misma seleccion inventaria un alta por cada suscripto que ya
            // estaba. El INSERT IGNORE de abajo no duplica la suscripcion, pero
            // el log si duplicaria el evento — y es un log, no se puede limpiar
            // despues.
            //
            // El JOIN contra `datarocket_prospectos` cumple la misma funcion que
            // en el INSERT de abajo: descarta ids inexistentes antes de tocar la
            // FK, y de paso trae el correo del momento para `destino`.
            $st = $pdo->prepare("
                INSERT INTO datarocket_listas_altas
                    (lista_id, prospecto_id, destino, motivo, origen, usuario_id, fecha)
                SELECT ?, p.id, NULLIF(TRIM(p.correo), ''),
                       'manual', 'abm/datarocketlistas', ?, NOW()
                  FROM datarocket_prospectos p
                 WHERE p.id IN ({$ph})
                   AND NOT EXISTS (SELECT 1
                                     FROM datarocket_prospectos_listas dpl
                                    WHERE dpl.lista_id = ? AND dpl.prospecto_id = p.id)
            ");
            $st->execute(array_merge([$id, $uid], $agregar, [$id]));

            $st = $pdo->prepare("
                INSERT IGNORE INTO datarocket_prospectos_listas (prospecto_id, lista_id)
                SELECT p.id, ? FROM datarocket_prospectos p WHERE p.id IN ({$ph})
            ");
            $st->execute(array_merge([$id], $agregar));
            $agregados = $st->rowCount();
        }

        // El denormalizado se recalcula para esta lista sola (el recalculo
        // global es otra herramienta). Sin esto el listado del ABM sigue
        // mostrando el contador viejo hasta la proxima corrida.
        $st = $pdo->prepare("
            UPDATE datarocket_listas dl
               SET dl.suscriptos = (SELECT COUNT(*)
                                      FROM datarocket_prospectos_listas dpl
                                     WHERE dpl.lista_id = dl.id)
             WHERE dl.id = :id
        ");
        $st->execute([':id' => $id]);

        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }

    $st = $pdo->prepare('SELECT suscriptos FROM datarocket_listas WHERE id = :id');
    $st->execute([':id' => $id]);

    jsonOk([
        'agregados'  => $agregados,
        'quitados'   => $quitados,
        'suscriptos' => (int)$st->fetchColumn(),
    ]);
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

// Normaliza un string a un slug kebab-case: [a-z0-9-]+, sin acentos, sin
// caracteres raros, sin guiones al borde, colapsando corridas de separadores.
// Se usa como fallback cuando el operador no llena el campo `slug` a mano.
// Espejo JS en app.js (`drliSlugify`) y en la migracion 20260821_1000.
function drliSlugify(string $s): string {
    $s = trim($s);
    if ($s === '') return '';
    $pares = [
        'á'=>'a','é'=>'e','í'=>'i','ó'=>'o','ú'=>'u',
        'à'=>'a','è'=>'e','ì'=>'i','ò'=>'o','ù'=>'u',
        'ä'=>'a','ë'=>'e','ï'=>'i','ö'=>'o','ü'=>'u',
        'Á'=>'a','É'=>'e','Í'=>'i','Ó'=>'o','Ú'=>'u',
        'ñ'=>'n','Ñ'=>'n','ç'=>'c','Ç'=>'c',
    ];
    $s = strtr($s, $pares);
    $s = mb_strtolower($s);
    $s = preg_replace('/[^a-z0-9]+/', '-', $s);
    $s = trim($s, '-');
    return substr($s, 0, 40);
}

function sanitizePayload(array $in): array {
    $nombre = nullableStr($in['nombre'] ?? null, 255);

    // `slug` es NOT NULL en la DB. Si el operador no lo carga, se deriva del
    // nombre (mismo criterio que datarocket_embudos y datacount_empresas).
    $slug = strtolower(trim((string)($in['slug'] ?? '')));
    if ($slug === '' && $nombre !== null) $slug = drliSlugify($nombre);
    if ($slug !== '' && !preg_match('/^[a-z0-9]+(?:-[a-z0-9]+)*$/', $slug)) {
        jsonError('El slug solo admite minusculas, digitos y guiones (kebab-case).', 400);
    }
    if (strlen($slug) > 40) {
        jsonError('El slug no puede superar los 40 caracteres.', 400);
    }

    return [
        'proyecto_id' => nullableInt($in['proyecto_id'] ?? null),
        'nombre'      => $nombre,
        'slug'        => $slug,
        'descripcion' => nullableStr($in['descripcion'] ?? null, 500),
    ];
}

// Chequeo amigable del UNIQUE (proyecto_id, slug) antes de que MySQL tire el
// error crudo. `$excluirId` es la propia lista en un update (0 en el alta).
//
// OJO: con `proyecto_id IS NULL` el UNIQUE de la DB no restringe nada (cada
// NULL es distinto en un indice unico), asi que aca tampoco chequeamos —
// no hay un alcance definido contra el cual comparar.
function drLiValidarSlugUnico(PDO $pdo, ?int $proyectoId, string $slug, int $excluirId = 0): void {
    if ($proyectoId === null) return;
    $st = $pdo->prepare(
        'SELECT id FROM datarocket_listas
          WHERE proyecto_id = :p AND slug = :s AND id <> :id LIMIT 1'
    );
    $st->execute([':p' => $proyectoId, ':s' => $slug, ':id' => $excluirId]);
    if ($st->fetch()) {
        jsonError('Ya existe otra lista con ese slug en el proyecto seleccionado.', 409);
    }
}

function handleCreate(PDO $pdo, array $in): void {
    $p = sanitizePayload($in);
    if ($p['nombre'] === null) jsonError('El nombre es obligatorio', 400);
    if ($p['slug'] === '') {
        jsonError('No se pudo derivar un slug a partir del nombre. Cargalo manualmente.', 400);
    }
    drLiValidarSlugUnico($pdo, $p['proyecto_id'], $p['slug']);

    // `suscriptos` no se toca desde el ABM (lo recalcula el motor); queda
    // con el DEFAULT NULL de la columna al crear una lista nueva.
    $sql = "
        INSERT INTO datarocket_listas
            (proyecto_id, nombre, slug, descripcion)
        VALUES
            (:proyecto_id, :nombre, :slug, :descripcion)
    ";
    $stmt = $pdo->prepare($sql);
    $stmt->execute([
        ':proyecto_id' => $p['proyecto_id'],
        ':nombre'      => $p['nombre'],
        ':slug'        => $p['slug'],
        ':descripcion' => $p['descripcion'],
    ]);
    jsonOk(['id' => (int)$pdo->lastInsertId()], 201);
}

function handleUpdate(PDO $pdo, int $id, array $in): void {
    $exists = $pdo->prepare('SELECT id, slug FROM datarocket_listas WHERE id = :id');
    $exists->execute([':id' => $id]);
    $prev = $exists->fetch();
    if (!$prev) jsonError('Lista no encontrada', 404);

    // El `slug` es un identificador estable: si el cliente no lo manda, se
    // conserva el actual en vez de re-derivarlo del nombre (re-derivarlo
    // romperia las referencias externas, que es justo lo que el slug evita).
    // El ABM del panel siempre lo envia; esto cubre a otros consumidores.
    if (!array_key_exists('slug', $in)) $in['slug'] = (string)$prev['slug'];

    $p = sanitizePayload($in);
    if ($p['nombre'] === null) jsonError('El nombre es obligatorio', 400);
    if ($p['slug'] === '') jsonError('El slug no puede quedar vacio.', 400);
    drLiValidarSlugUnico($pdo, $p['proyecto_id'], $p['slug'], $id);

    // `suscriptos` es un contador denormalizado que recalcula el motor
    // desde afuera; el ABM no lo edita, asi que no se toca en el UPDATE
    // (si lo incluyeramos con :suscriptos = NULL, pisariamos el valor real
    // que dejo el motor).
    $sql = "
        UPDATE datarocket_listas SET
            proyecto_id = :proyecto_id,
            nombre      = :nombre,
            slug        = :slug,
            descripcion = :descripcion
        WHERE id = :id
    ";
    $stmt = $pdo->prepare($sql);
    $stmt->execute([
        ':proyecto_id' => $p['proyecto_id'],
        ':nombre'      => $p['nombre'],
        ':slug'        => $p['slug'],
        ':descripcion' => $p['descripcion'],
        ':id'          => $id,
    ]);
    jsonOk(['id' => $id]);
}

function handleDelete(PDO $pdo, int $id): void {
    $stmt = $pdo->prepare('DELETE FROM datarocket_listas WHERE id = :id');
    $stmt->execute([':id' => $id]);
    if ($stmt->rowCount() === 0) jsonError('Lista no encontrada', 404);
    jsonOk(['id' => $id]);
}
