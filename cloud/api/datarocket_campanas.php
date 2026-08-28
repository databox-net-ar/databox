<?php
// api/datarocket_campanas.php
// Datarocket > Campanas (CRUD). Lee/escribe sobre `datarocket_campanas` — la
// definicion de un envio masivo: "esta lista x esta plantilla por este canal".
// El padron de destinatarios vive en `datarocket_campanas_mensajes` y este
// endpoint lo expone SOLO en lectura (?id=N&mensajes=1). La justificacion del
// modelo de dos tablas esta en la migracion
// 20260828_1000_datarocket_campanas_modulo.sql.
//
//   GET    api/datarocket_campanas.php[?q=..&proyecto=..&medio=..&estado=..&lista=..&limite=100&orden=id&dir=desc]
//                                                 -> listado + stats
//   GET    api/datarocket_campanas.php?id=N        -> registro individual
//   GET    api/datarocket_campanas.php?id=N&mensajes=1[&estado=..&q=..&limite=200]
//                                                 -> padron de la campana + conteo por estado
//   GET    api/datarocket_campanas.php?lookups=1[&medio=..&proyecto=..]
//                                                 -> catalogos para los <select>
//   POST   api/datarocket_campanas.php             -> alta (JSON body)
//   POST   api/datarocket_campanas.php?id=N&action=iniciar
//                                                 -> larga la campana: valida que
//                                                    este completa y la deja lista
//                                                    para el expansor. Requiere
//                                                    `datarocket.campanas.editar`.
//   POST   api/datarocket_campanas.php?id=N&action=recalcular
//                                                 -> recomputa los contadores
//                                                    desde el padron. Requiere
//                                                    `datarocket.campanas.editar`.
//   PUT    api/datarocket_campanas.php?id=N        -> modificacion (JSON body)
//   DELETE api/datarocket_campanas.php?id=N        -> baja
//
// ALCANCE: este endpoint NO expande ni encola. Eso vive en la lib
// `lib/datarocket_campanas_expandir.php`, que disparan el endpoint SSE
// `datarocket_campanas_ejecutar.php` (boton "Ejecutar ahora") y el cron
// `jobs/datarocket_campanas_expandir.php` (camino programado). Aca solo se
// consume drcaCampanaReconciliar(), para ?action=recalcular.
//
// Respuesta siempre {ok: true, data: ...} u {ok: false, error: '...'} (STACK.md sec. 10).

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/lib/auth_check.php';
require_once __DIR__ . '/lib/sucesos.php';
// El motor de las campanas. Aca se usa solo drcaCampanaReconciliar() (para
// ?action=recalcular); la expansion y el encolado los dispara el endpoint SSE
// datarocket_campanas_ejecutar.php y el job del cron.
require_once __DIR__ . '/lib/datarocket_campanas_expandir.php';

requireAuth();
header('Content-Type: application/json; charset=utf-8');

const DRCA_TABLA        = 'datarocket_campanas';
const DRCA_TABLA_PADRON = 'datarocket_campanas_mensajes';

const DRCA_ORDENES = ['id', 'nombre', 'medio', 'estado', 'prioridad', 'programada',
                      'total', 'enviados', 'fecha_creacion', 'fecha_modificacion'];

const DRCA_COLS = 'id, proyecto_id, nombre, slug, descripcion, asunto, medio, canal_id, lista_id,
                   plantilla_id, prioridad, estado, programada, iniciada, completada,
                   total, encolados, enviados, fallidos, omitidos, rebotados, bajas,
                   observaciones, fecha_creacion, fecha_modificacion';

// Campos de `estados` que alimentan los combos del ABM.
const DRCA_CAMPO_MEDIO         = 'datarocket_campana_medio';
const DRCA_CAMPO_ESTADO        = 'datarocket_campana_estado';
const DRCA_CAMPO_ESTADO_PADRON = 'datarocket_campana_mensaje_estado';
// Feedback de entrega de SES (entregado/abierto/cliqueado/spam/rebotado/
// rechazado). Es un eje distinto de `estado`: un rebote llega con
// estado='enviado' porque el mensaje SI salio — reboto despues.
const DRCA_CAMPO_RESULTADO_PADRON = 'datarocket_campana_mensaje_resultado';

// Estados en los que la campana ya arranco y por lo tanto su configuracion
// (lista, plantilla, canal, medio) deja de ser editable: cambiarla a mitad de
// camino dejaria el padron ya expandido apuntando a otra cosa.
const DRCA_ESTADOS_ARRANCADOS = ['expandiendo', 'enviando', 'completada'];

// Traduccion medio legible -> letra legacy de `datarocket_plantillas.medio`
// (varchar(1) heredado: 'C' correo, 'W' whatsapp). Telegram todavia no tiene
// plantillas propias, de ahi que no figure: el lookup devuelve lista vacia y
// el ABM lo avisa en vez de ofrecer plantillas de otro medio.
const DRCA_MEDIO_A_LETRA = ['correo' => 'C', 'whatsapp' => 'W'];

// Tabla de canales por medio. `canal_id` no puede llevar FK porque el destino
// depende de `medio` — la resolucion se hace aca, en un solo lugar.
const DRCA_CANALES_POR_MEDIO = [
    'correo'   => ['tabla' => 'aws_canales',       'proyecto' => null],
    'whatsapp' => ['tabla' => 'evolution_canales', 'proyecto' => 'proyecto'],
    'telegram' => ['tabla' => 'telegram_canales',  'proyecto' => 'proyecto'],
];

// De donde sale el destino de cada prospecto segun el medio, para la pestaña
// Lista del modal de Consultar. ESPEJA DRCA_DESTINO_SQL de
// lib/datarocket_campanas_expandir.php — se duplica en vez de requerir la lib
// porque esa arrastra los tres libs de mensajes (~1400 lineas) y aca solo se
// necesita la expresion. Si una cambia, cambiar la otra: el punto de esa
// pestaña es mostrar exactamente el destino que va a resolver el expansor,
// incluido el hueco de quien no tiene el dato cargado.
//
// Va arriba y no al lado de su handler porque las `const` de nivel de archivo
// NO se hoistean como las funciones: declarada mas abajo, el dispatcher se la
// come sin definir.
const DRCA_DESTINO_PREVIEW_SQL = [
    'correo'   => "NULLIF(TRIM(p.correo), '')",
    'whatsapp' => "COALESCE(NULLIF(TRIM(p.whatsapp), ''), NULLIF(TRIM(p.celular), ''))",
    'telegram' => "COALESCE(NULLIF(TRIM(p.telefono), ''), NULLIF(TRIM(p.celular), ''))",
];

try {
    $pdo    = db();
    $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
    $id     = isset($_GET['id']) ? (int) $_GET['id'] : 0;
    $action = (string) ($_GET['action'] ?? '');

    // "recalcular" e "iniciar" son modificaciones sobre una campana existente,
    // asi que se rigen por .editar y no por el .agregar que requirePermCrud le
    // daria al POST.
    if ($method === 'POST' && $action === 'recalcular') {
        requirePermission('datarocket.campanas.editar');
        if ($id <= 0) jsonError('Falta id', 400);
        handleRecalcularCampana($pdo, $id);
        return;
    }
    if ($method === 'POST' && $action === 'iniciar') {
        requirePermission('datarocket.campanas.editar');
        if ($id <= 0) jsonError('Falta id', 400);
        handleIniciarCampana($pdo, $id);
        return;
    }

    requirePermCrud('datarocket.campanas');

    if ($method === 'GET' && ($_GET['lookups'] ?? '') !== '') {
        handleLookupsCampana($pdo, $_GET);
    } elseif ($method === 'GET' && $id > 0 && ($_GET['mensajes'] ?? '') !== '') {
        handlePadronCampana($pdo, $id, $_GET);
    } elseif ($method === 'GET' && $id > 0 && ($_GET['plantilla'] ?? '') !== '') {
        handlePlantillaCampana($pdo, $id);
    } elseif ($method === 'GET' && $id > 0 && ($_GET['lista'] ?? '') !== '') {
        // OJO con el orden: `lista` tambien es un filtro de handleListCampanas.
        // No chocan porque aquel solo se alcanza sin `id`, pero mover esta rama
        // debajo de la del listado romperia el filtro o esta pestaña.
        handleListaCampana($pdo, $id, $_GET);
    } elseif ($method === 'GET' && $id > 0) {
        handleGetOneCampana($pdo, $id);
    } elseif ($method === 'GET') {
        handleListCampanas($pdo, $_GET);
    } elseif ($method === 'POST') {
        handleCreateCampana($pdo, readJsonBody());
    } elseif ($method === 'PUT') {
        if ($id <= 0) jsonError('Falta id', 400);
        handleUpdateCampana($pdo, $id, readJsonBody());
    } elseif ($method === 'DELETE') {
        if ($id <= 0) jsonError('Falta id', 400);
        handleDeleteCampana($pdo, $id);
    } else {
        jsonError('Método no soportado', 405);
    }
} catch (Throwable $e) {
    jsonError($e->getMessage(), 500);
}

// ----------------------------------------------------------------------------
// Helpers
// ----------------------------------------------------------------------------

function normalizarFilaCampana(array $r): array {
    return [
        'id'                 => (int) ($r['id'] ?? 0),
        'proyecto_id'        => $r['proyecto_id'] !== null ? (int) $r['proyecto_id'] : null,
        'proyecto_nombre'    => isset($r['proyecto_nombre'])  && $r['proyecto_nombre']  !== null ? (string) $r['proyecto_nombre']  : null,
        'nombre'             => (string) ($r['nombre'] ?? ''),
        'slug'               => (string) ($r['slug']   ?? ''),
        'descripcion'        => $r['descripcion'] !== null ? (string) $r['descripcion'] : null,
        'asunto'             => $r['asunto']      !== null ? (string) $r['asunto']      : null,
        'medio'              => (string) ($r['medio'] ?? ''),
        'medio_texto'        => isset($r['medio_texto'])      && $r['medio_texto']      !== null ? (string) $r['medio_texto']      : null,
        'canal_id'           => $r['canal_id']     !== null ? (int) $r['canal_id']     : null,
        'canal_nombre'       => isset($r['canal_nombre'])     && $r['canal_nombre']     !== null ? (string) $r['canal_nombre']     : null,
        'lista_id'           => $r['lista_id']     !== null ? (int) $r['lista_id']     : null,
        'lista_nombre'       => isset($r['lista_nombre'])     && $r['lista_nombre']     !== null ? (string) $r['lista_nombre']     : null,
        'lista_suscriptos'   => isset($r['lista_suscriptos']) && $r['lista_suscriptos'] !== null ? (int) $r['lista_suscriptos']    : null,
        'plantilla_id'       => $r['plantilla_id'] !== null ? (int) $r['plantilla_id'] : null,
        'plantilla_nombre'   => isset($r['plantilla_nombre']) && $r['plantilla_nombre'] !== null ? (string) $r['plantilla_nombre'] : null,
        'prioridad'          => (int) ($r['prioridad'] ?? 5),
        'estado'             => (string) ($r['estado'] ?? 'borrador'),
        'estado_texto'       => isset($r['estado_texto'])     && $r['estado_texto']     !== null ? (string) $r['estado_texto']     : null,
        'programada'         => $r['programada']         ?? null,
        'iniciada'           => $r['iniciada']           ?? null,
        'completada'         => $r['completada']         ?? null,
        'total'              => (int) ($r['total']     ?? 0),
        'encolados'          => (int) ($r['encolados'] ?? 0),
        'enviados'           => (int) ($r['enviados']  ?? 0),
        'fallidos'           => (int) ($r['fallidos']  ?? 0),
        'omitidos'           => (int) ($r['omitidos']  ?? 0),
        // Rebotados / rechazados / spam segun SES, y cuantos de esos causaron la
        // baja del prospecto de la lista. Se miden sobre `resultado`, no sobre
        // `estado`: un rebote llega con estado='enviado'.
        'rebotados'          => (int) ($r['rebotados'] ?? 0),
        'bajas'              => (int) ($r['bajas']     ?? 0),
        // Renglones del padron todavia sin encolar. No es una columna de la
        // tabla: lo cuenta drcaSelectCols() del padron, porque no se puede
        // derivar de los otros cinco contadores.
        'pendientes'         => (int) ($r['pendientes'] ?? 0),
        'observaciones'      => $r['observaciones'] !== null ? (string) $r['observaciones'] : null,
        'fecha_creacion'     => $r['fecha_creacion']     ?? null,
        'fecha_modificacion' => $r['fecha_modificacion'] ?? null,
    ];
}

// Normaliza un string a kebab-case: [a-z0-9-]+, sin acentos, sin guiones al
// borde, colapsando corridas de separadores. Espejo JS en app.js (`drcaSlugify`).
// Mismo criterio que datarocket_listas / _embudos / _etiquetas / _redes_sociales.
function drcaSlugify(string $s): string {
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
    return substr($s, 0, 60);
}

// Chequeo amigable del UNIQUE de `slug` antes de que MySQL tire el error crudo.
// Alcance global (la tabla no agrupa por proyecto), igual que las etiquetas.
function drcaValidarSlugUnico(PDO $pdo, string $slug, int $excluirId = 0): void {
    $st = $pdo->prepare('SELECT id FROM ' . DRCA_TABLA . ' WHERE slug = :s AND id <> :id LIMIT 1');
    $st->execute([':s' => $slug, ':id' => $excluirId]);
    if ($st->fetch()) jsonError('Ya existe otra campaña con ese slug.', 409);
}

// Valida que un valor exista en el catalogo `estados` del campo dado. Evita que
// un POST a mano meta un `medio` o un `estado` que despues ningun combo sabe
// pintar y que el expansor no sabria interpretar.
function drcaValidarCatalogo(PDO $pdo, string $campo, string $valor, string $etiqueta): void {
    $st = $pdo->prepare('SELECT 1 FROM estados WHERE campo = :c AND valor = :v LIMIT 1');
    $st->execute([':c' => $campo, ':v' => $valor]);
    if (!$st->fetch()) jsonError("El {$etiqueta} \"{$valor}\" no está en el catálogo de estados.", 400);
}

// 'YYYY-MM-DDTHH:MM' (input datetime-local) -> 'YYYY-MM-DD HH:MM:SS' (DB).
// Devuelve null para vacio; jsonError si vino algo que no parsea, en vez de
// guardar un '0000-00-00' silencioso.
function drcaFechaHora(?string $v, string $etiqueta): ?string {
    $v = trim((string) $v);
    if ($v === '') return null;
    $v = str_replace('T', ' ', $v);
    $ts = strtotime($v);
    if ($ts === false) jsonError("La fecha de {$etiqueta} no es válida.", 400);
    return date('Y-m-d H:i:s', $ts);
}

function sanitizePayloadCampana(PDO $pdo, array $in, bool $esAlta): array {
    $nombre = trim((string) ($in['nombre'] ?? ''));
    if ($esAlta && $nombre === '') jsonError('El nombre es obligatorio.', 400);
    if ($nombre !== '' && mb_strlen($nombre) > 150) {
        jsonError('El nombre no puede superar los 150 caracteres.', 400);
    }

    // `slug` es NOT NULL. Si el operador no lo carga, se deriva del nombre —
    // mismo criterio que listas / embudos / etiquetas / redes sociales.
    $slug = strtolower(trim((string) ($in['slug'] ?? '')));
    if ($slug === '' && $nombre !== '') $slug = drcaSlugify($nombre);
    if ($slug !== '' && !preg_match('/^[a-z0-9]+(?:-[a-z0-9]+)*$/', $slug)) {
        jsonError('El slug solo admite minúsculas, dígitos y guiones (kebab-case).', 400);
    }
    if (strlen($slug) > 60) jsonError('El slug no puede superar los 60 caracteres.', 400);

    $medio = trim((string) ($in['medio'] ?? ''));
    if ($esAlta && $medio === '') jsonError('El medio es obligatorio.', 400);
    if ($medio !== '') drcaValidarCatalogo($pdo, DRCA_CAMPO_MEDIO, $medio, 'medio');

    $estado = trim((string) ($in['estado'] ?? ''));
    if ($estado === '' && $esAlta) $estado = 'borrador';
    if ($estado !== '') drcaValidarCatalogo($pdo, DRCA_CAMPO_ESTADO, $estado, 'estado');

    // La prioridad espeja la de las colas de canal (`aws_mensajes.prioridad`,
    // tinyint 1..9, mas alto = antes). 5 es el default neutro.
    $prioridad = (int) ($in['prioridad'] ?? 5);
    if ($prioridad < 1 || $prioridad > 9) jsonError('La prioridad debe estar entre 1 y 9.', 400);

    $intOrNull = function ($v): ?int {
        if ($v === null || $v === '' || $v === 'null') return null;
        return (int) $v;
    };

    $descripcion   = trim((string) ($in['descripcion']   ?? ''));
    $asunto        = trim((string) ($in['asunto']        ?? ''));
    $observaciones = trim((string) ($in['observaciones'] ?? ''));
    if (mb_strlen($descripcion)   > 5000) jsonError('La descripción no puede superar los 5000 caracteres.', 400);
    if (mb_strlen($asunto)        >  255) jsonError('El asunto no puede superar los 255 caracteres.', 400);
    if (mb_strlen($observaciones) > 5000) jsonError('Las observaciones no pueden superar los 5000 caracteres.', 400);

    return [
        'nombre'        => $nombre,
        'slug'          => $slug,
        'descripcion'   => $descripcion   === '' ? null : $descripcion,
        'asunto'        => $asunto        === '' ? null : $asunto,
        'medio'         => $medio,
        'canal_id'      => $intOrNull($in['canal_id']     ?? null),
        'lista_id'      => $intOrNull($in['lista_id']     ?? null),
        'plantilla_id'  => $intOrNull($in['plantilla_id'] ?? null),
        'proyecto_id'   => $intOrNull($in['proyecto_id']  ?? null),
        'prioridad'     => $prioridad,
        'estado'        => $estado,
        'programada'    => drcaFechaHora($in['programada'] ?? null, 'programación'),
        'observaciones' => $observaciones === '' ? null : $observaciones,
    ];
}

// Coherencia entre lo que la campana dice y lo que existe. Se corre en alta y
// en modificacion sobre el estado RESULTANTE (no sobre el body suelto), porque
// cambiar solo `medio` en un PUT puede invalidar el `plantilla_id` que ya
// estaba guardado.
function drcaValidarCoherencia(PDO $pdo, array $f): void {
    if ($f['lista_id'] !== null) {
        $st = $pdo->prepare('SELECT id FROM datarocket_listas WHERE id = :id LIMIT 1');
        $st->execute([':id' => $f['lista_id']]);
        if (!$st->fetch()) jsonError('La lista indicada no existe.', 400);
    }

    if ($f['plantilla_id'] !== null) {
        $st = $pdo->prepare('SELECT id, medio FROM datarocket_plantillas WHERE id = :id LIMIT 1');
        $st->execute([':id' => $f['plantilla_id']]);
        $pl = $st->fetch();
        if (!$pl) jsonError('La plantilla indicada no existe.', 400);

        // La plantilla tiene que ser del mismo medio que la campana: mandar una
        // plantilla de correo por WhatsApp produce un mensaje con asunto y HTML
        // que el canal no sabe renderizar.
        $letra = DRCA_MEDIO_A_LETRA[$f['medio']] ?? null;
        if ($letra !== null && (string) $pl['medio'] !== '' && (string) $pl['medio'] !== $letra) {
            jsonError('La plantilla elegida no es del mismo medio que la campaña.', 400);
        }
    }

    if ($f['canal_id'] !== null) {
        $cfg = DRCA_CANALES_POR_MEDIO[$f['medio']] ?? null;
        if ($cfg === null) jsonError('El medio de la campaña no tiene canales asociados.', 400);
        // El nombre de tabla sale de una constante del propio archivo, nunca del
        // request: `medio` ya paso por drcaValidarCatalogo y por este lookup.
        $st = $pdo->prepare('SELECT id FROM ' . $cfg['tabla'] . ' WHERE id = :id LIMIT 1');
        $st->execute([':id' => $f['canal_id']]);
        if (!$st->fetch()) jsonError('El canal indicado no existe para ese medio.', 400);
    }
}

// ----------------------------------------------------------------------------
// Handlers — listado y ficha
// ----------------------------------------------------------------------------

// LEFT JOIN con proyectos / listas / plantillas para que el listado no tenga
// que resolver los nombres fila por fila desde el front. El canal NO se joinea:
// vive en una tabla distinta segun `medio`, asi que lo resuelve
// drcaCanalNombre() sobre las filas ya traidas.
function drcaFrom(): string {
    return DRCA_TABLA . ' c
            LEFT JOIN proyectos             pr ON pr.id = c.proyecto_id
            LEFT JOIN datarocket_listas     li ON li.id = c.lista_id
            LEFT JOIN datarocket_plantillas pl ON pl.id = c.plantilla_id';
}

// Los textos amigables de `medio` y `estado` salen de `estados` como subquery
// escalar y NO como JOIN: `estados` no tiene UNIQUE(campo, valor), asi que un
// duplicado cargado a mano desde el Editor de estados multiplicaria las filas.
function drcaSelectCols(): string {
    $cols = preg_replace('/\s+/', ' ', DRCA_COLS);
    $cols = implode(', ', array_map(fn($c) => 'c.' . trim($c), explode(',', $cols)));
    return $cols
         . ', pr.nombre AS proyecto_nombre'
         . ', li.nombre AS lista_nombre'
         . ', li.suscriptos AS lista_suscriptos'
         . ', pl.nombre AS plantilla_nombre'
         . ", (SELECT e1.texto FROM estados e1
                WHERE e1.campo = '" . DRCA_CAMPO_MEDIO . "' AND e1.valor = c.medio
                ORDER BY e1.orden ASC, e1.id ASC LIMIT 1) AS medio_texto"
         . ", (SELECT e2.texto FROM estados e2
                WHERE e2.campo = '" . DRCA_CAMPO_ESTADO . "' AND e2.valor = c.estado
                ORDER BY e2.orden ASC, e2.id ASC LIMIT 1) AS estado_texto"
         // Cuantos renglones del padron quedan sin encolar. NO sale de los 5
         // contadores denormalizados: `encolados` cuenta `mensaje_id IS NOT NULL`
         // (o sea, todo lo que alguna vez llego a la cola, incluido lo ya enviado
         // y lo que reboto despues), asi que total - encolados - ... no da
         // pendientes. Hay que contarlo del padron.
         //
         // Lo consume el menu de la fila para decidir si ofrecer "Continuar
         // encolado": una campana en 'enviando' con pendientes es reanudable.
         // Cuesta un seek sobre idx_drcam_campana_estado(campana_id, estado) por
         // fila listada, con un LIMIT de 1000 como techo.
         . ", (SELECT COUNT(*) FROM datarocket_campanas_mensajes m
                WHERE m.campana_id = c.id AND m.estado = 'pendiente') AS pendientes";
}

// Resuelve el nombre del canal de cada fila. Agrupa por medio y hace UNA query
// por tabla de canales involucrada (a lo sumo 3), en vez de una por fila.
function drcaCanalNombre(PDO $pdo, array $filas): array {
    $porMedio = [];
    foreach ($filas as $f) {
        if ($f['canal_id'] === null) continue;
        if (!isset(DRCA_CANALES_POR_MEDIO[$f['medio']])) continue;
        $porMedio[$f['medio']][$f['canal_id']] = true;
    }

    $nombres = [];   // "medio:canal_id" => nombre
    foreach ($porMedio as $medio => $ids) {
        $ids  = array_keys($ids);
        $ph   = implode(',', array_fill(0, count($ids), '?'));
        $tab  = DRCA_CANALES_POR_MEDIO[$medio]['tabla'];
        $st   = $pdo->prepare("SELECT id, nombre FROM {$tab} WHERE id IN ({$ph})");
        $st->execute($ids);
        foreach ($st->fetchAll() as $r) {
            $nombres[$medio . ':' . (int) $r['id']] = (string) ($r['nombre'] ?? '');
        }
    }

    foreach ($filas as &$f) {
        $f['canal_nombre'] = $f['canal_id'] !== null
            ? ($nombres[$f['medio'] . ':' . $f['canal_id']] ?? null)
            : null;
    }
    unset($f);

    return $filas;
}

function handleListCampanas(PDO $pdo, array $q): void {
    $search   = trim((string) ($q['q']        ?? ''));
    $proyecto = trim((string) ($q['proyecto'] ?? ''));
    $medio    = trim((string) ($q['medio']    ?? ''));
    $estado   = trim((string) ($q['estado']   ?? ''));
    $lista    = trim((string) ($q['lista']    ?? ''));
    $limite   = max(1, min(1000, (int) ($q['limite'] ?? 100)));
    $orden    = in_array(($q['orden'] ?? ''), DRCA_ORDENES, true) ? $q['orden'] : 'id';
    $dir      = strtolower((string) ($q['dir'] ?? 'desc')) === 'asc' ? 'ASC' : 'DESC';

    $where  = [];
    $params = [];

    // El LIKE va sobre columnas utf8mb4_general_ci, que pliega mayusculas y
    // acentos: "navidad" matchea "Navidad" y "promocion" matchea "Promoción".
    if ($search !== '') {
        $where[] = '(c.nombre LIKE :s1 OR c.slug LIKE :s2 OR c.descripcion LIKE :s3)';
        foreach (['s1', 's2', 's3'] as $k) $params[":{$k}"] = "%{$search}%";
    }
    if ($proyecto !== '' && ctype_digit($proyecto)) {
        $where[] = 'c.proyecto_id = :proyecto';
        $params[':proyecto'] = (int) $proyecto;
    }
    if ($medio !== '')  { $where[] = 'c.medio  = :medio';  $params[':medio']  = $medio; }
    if ($estado !== '') { $where[] = 'c.estado = :estado'; $params[':estado'] = $estado; }
    if ($lista !== '' && ctype_digit($lista)) {
        $where[] = 'c.lista_id = :lista';
        $params[':lista'] = (int) $lista;
    }

    $sqlWhere = $where ? ('WHERE ' . implode(' AND ', $where)) : '';

    // Desempate por `nombre` para que el orden sea determinista cuando la
    // columna elegida empata (pasa siempre con `medio`, `estado` y `prioridad`).
    $desempate = $orden === 'nombre' ? '' : ', c.nombre ASC';

    $sql = 'SELECT ' . drcaSelectCols() . ' FROM ' . drcaFrom()
         . " {$sqlWhere} ORDER BY c.{$orden} {$dir}{$desempate} LIMIT {$limite}";
    $st = $pdo->prepare($sql);
    $st->execute($params);
    $rows = drcaCanalNombre($pdo, array_map('normalizarFilaCampana', $st->fetchAll()));

    // Stats globales (ignoran filtros — son indicadores del recurso).
    $s = $pdo->query('
        SELECT COUNT(*)                                                          AS total,
               SUM(CASE WHEN estado = \'programada\' THEN 1 ELSE 0 END)          AS programadas,
               SUM(CASE WHEN estado IN (\'expandiendo\',\'enviando\') THEN 1 ELSE 0 END) AS en_curso,
               COALESCE(SUM(enviados), 0)                                        AS enviados
          FROM ' . DRCA_TABLA
    )->fetch();

    jsonOk([
        'items' => $rows,
        'stats' => [
            'total'       => (int) ($s['total']       ?? 0),
            'programadas' => (int) ($s['programadas'] ?? 0),
            'en_curso'    => (int) ($s['en_curso']    ?? 0),
            'enviados'    => (int) ($s['enviados']    ?? 0),
        ],
    ]);
}

function handleGetOneCampana(PDO $pdo, int $id): void {
    $st = $pdo->prepare('SELECT ' . drcaSelectCols() . ' FROM ' . drcaFrom() . ' WHERE c.id = :id LIMIT 1');
    $st->execute([':id' => $id]);
    $row = $st->fetch();
    if (!$row) jsonError('Campaña no encontrada', 404);
    $filas = drcaCanalNombre($pdo, [normalizarFilaCampana($row)]);
    jsonOk($filas[0]);
}

// Padron de la campana: quien esta en la lista de destinatarios, con que
// destino resuelto y en que estado quedo. Solo lectura — lo escribe el job
// expansor. Devuelve tambien el conteo por estado, que es lo que alimenta los
// chips de filtro del modal.
function handlePadronCampana(PDO $pdo, int $id, array $q): void {
    $st = $pdo->prepare('SELECT id FROM ' . DRCA_TABLA . ' WHERE id = :id LIMIT 1');
    $st->execute([':id' => $id]);
    if (!$st->fetch()) jsonError('Campaña no encontrada', 404);

    $estado    = trim((string) ($q['estado']    ?? ''));
    $resultado = trim((string) ($q['resultado'] ?? ''));
    $search    = trim((string) ($q['q']         ?? ''));
    $limite    = max(1, min(1000, (int) ($q['limite'] ?? 200)));

    $where  = ['m.campana_id = :id'];
    $params = [':id' => $id];

    if ($estado !== '') { $where[] = 'm.estado = :estado'; $params[':estado'] = $estado; }
    // 'sin_evento' es el pseudo-valor con el que el conteo agrupa los NULL, asi
    // que el chip correspondiente tiene que traducirse a IS NULL y no a una
    // comparacion literal (que no matchearia nunca).
    if ($resultado === 'sin_evento') {
        $where[] = 'm.resultado IS NULL';
    } elseif ($resultado !== '') {
        $where[] = 'm.resultado = :resultado';
        $params[':resultado'] = $resultado;
    }
    if ($search !== '') {
        $where[] = '(m.destino LIKE :s1 OR p.nombre LIKE :s2 OR m.motivo LIKE :s3)';
        foreach (['s1', 's2', 's3'] as $k) $params[":{$k}"] = "%{$search}%";
    }

    $sql = 'SELECT m.id, m.prospecto_id, m.destino, m.estado, m.motivo, m.mensaje_id,
                   m.encolado, m.enviado, m.fecha_creacion,
                   m.resultado, m.resultado_fecha, m.baja_lista,
                   p.nombre AS prospecto_nombre,
                   (SELECT e.texto FROM estados e
                     WHERE e.campo = \'' . DRCA_CAMPO_ESTADO_PADRON . '\' AND e.valor = m.estado
                     ORDER BY e.orden ASC, e.id ASC LIMIT 1) AS estado_texto,
                   (SELECT e.texto FROM estados e
                     WHERE e.campo = \'' . DRCA_CAMPO_RESULTADO_PADRON . '\' AND e.valor = m.resultado
                     ORDER BY e.orden ASC, e.id ASC LIMIT 1) AS resultado_texto
              FROM ' . DRCA_TABLA_PADRON . ' m
              LEFT JOIN datarocket_prospectos p ON p.id = m.prospecto_id
             WHERE ' . implode(' AND ', $where) . '
             ORDER BY m.id ASC
             LIMIT ' . $limite;
    $st = $pdo->prepare($sql);
    $st->execute($params);

    $items = array_map(fn($r) => [
        'id'               => (int) $r['id'],
        'prospecto_id'     => (int) $r['prospecto_id'],
        'prospecto_nombre' => $r['prospecto_nombre'] !== null ? (string) $r['prospecto_nombre'] : null,
        'destino'          => $r['destino'] !== null ? (string) $r['destino'] : null,
        'estado'           => (string) $r['estado'],
        'estado_texto'     => $r['estado_texto'] !== null ? (string) $r['estado_texto'] : null,
        'motivo'           => $r['motivo'] !== null ? (string) $r['motivo'] : null,
        'mensaje_id'       => $r['mensaje_id'] !== null ? (int) $r['mensaje_id'] : null,
        'encolado'         => $r['encolado'] ?? null,
        'enviado'          => $r['enviado']  ?? null,
        'resultado'        => $r['resultado']       !== null ? (string) $r['resultado']       : null,
        'resultado_texto'  => $r['resultado_texto'] !== null ? (string) $r['resultado_texto'] : null,
        'resultado_fecha'  => $r['resultado_fecha'] ?? null,
        // Estampa de la baja automatica. El renglon de la lista ya no existe:
        // esto es lo unico que prueba que la persona estuvo suscripta.
        'baja_lista'       => $r['baja_lista'] ?? null,
        'fecha_creacion'   => $r['fecha_creacion'] ?? null,
    ], $st->fetchAll());

    // Conteo por estado sobre el padron COMPLETO (sin el LIMIT ni el buscador):
    // los chips tienen que mostrar cuantos hay de cada tipo, no cuantos entraron
    // en la pagina que se esta mirando.
    $sc = $pdo->prepare('SELECT estado, COUNT(*) AS c FROM ' . DRCA_TABLA_PADRON
                      . ' WHERE campana_id = :id GROUP BY estado');
    $sc->execute([':id' => $id]);
    $conteo = [];
    foreach ($sc->fetchAll() as $r) $conteo[(string) $r['estado']] = (int) $r['c'];

    // Mismo criterio para `resultado`, que es el otro eje del padron. Los NULL
    // se agrupan bajo 'sin_evento' en vez de descartarse: "todavia no llego
    // ningun evento de SES" es informacion, y distinguirlo de 'entregado' es lo
    // que permite notar que el webhook SNS dejo de llegar.
    $rc = $pdo->prepare('SELECT COALESCE(resultado, \'sin_evento\') AS resultado, COUNT(*) AS c
                           FROM ' . DRCA_TABLA_PADRON . '
                          WHERE campana_id = :id GROUP BY COALESCE(resultado, \'sin_evento\')');
    $rc->execute([':id' => $id]);
    $conteoResultado = [];
    foreach ($rc->fetchAll() as $r) $conteoResultado[(string) $r['resultado']] = (int) $r['c'];

    jsonOk([
        'items'            => $items,
        'conteo'           => $conteo,
        'conteo_resultado' => $conteoResultado,
        'total'            => array_sum($conteo),
    ]);
}

// ----------------------------------------------------------------------------
// Handlers — que se manda (plantilla) y a quien (lista)
// ----------------------------------------------------------------------------
//
// Las dos pestañas homonimas del modal de Consultar. Viven ACA y no en
// `datarocketplantillas.php` / `datarocketlistas.php` por el mismo motivo que
// `?suscriptos=1` vive en el endpoint de listas: quien puede consultar una
// campaña tiene que poder ver que mensaje sale y a quien le llega con su propio
// `datarocket.campanas.consultar`, sin necesitar ademas
// `sistemas.datarocket.plantillas.consultar` ni `datarocket.listas.consultar`.
// Ambas son SOLO lectura.

/**
 * La plantilla que la campaña va a enviar, con el asunto ya resuelto.
 *
 *   GET api/datarocket_campanas.php?id=N&plantilla=1
 *   -> {ok:true, data:{plantilla:{...}|null, asunto_resuelto:'...', ...}}
 *
 * `plantilla` en null cubre dos casos distintos y el front los distingue por
 * `plantilla_id`: la campaña todavia no eligio plantilla (null) o la eligio y
 * la plantilla se borro despues (id con fila faltante).
 */
function handlePlantillaCampana(PDO $pdo, int $id): void {
    $st = $pdo->prepare('SELECT id, medio, asunto, plantilla_id FROM ' . DRCA_TABLA . ' WHERE id = :id LIMIT 1');
    $st->execute([':id' => $id]);
    $c = $st->fetch();
    if (!$c) jsonError('Campaña no encontrada', 404);

    $plantillaId   = $c['plantilla_id'] !== null ? (int) $c['plantilla_id'] : null;
    $campanaAsunto = (string) ($c['asunto'] ?? '');

    $tpl = null;
    if ($plantillaId !== null) {
        $st = $pdo->prepare('SELECT pl.id, pl.slug, pl.nombre, pl.proyecto_id, pl.medio,
                                    pl.remitente, pl.remite, pl.asunto, pl.cuerpo,
                                    pl.formato, pl.adjunto, pl.adjunto_origen,
                                    pr.nombre AS proyecto_nombre
                               FROM datarocket_plantillas pl
                               LEFT JOIN proyectos pr ON pr.id = pl.proyecto_id
                              WHERE pl.id = :id LIMIT 1');
        $st->execute([':id' => $plantillaId]);
        $row = $st->fetch();
        if ($row) {
            $tpl = [
                'id'              => (int) $row['id'],
                'slug'            => $row['slug']   !== null ? (string) $row['slug']   : null,
                'nombre'          => $row['nombre'] !== null ? (string) $row['nombre'] : null,
                'proyecto_id'     => $row['proyecto_id'] !== null ? (int) $row['proyecto_id'] : null,
                'proyecto_nombre' => $row['proyecto_nombre'] !== null ? (string) $row['proyecto_nombre'] : null,
                'medio'           => $row['medio']     !== null ? (string) $row['medio']     : null,
                'remitente'       => $row['remitente'] !== null ? (string) $row['remitente'] : null,
                'remite'          => $row['remite']    !== null ? (string) $row['remite']    : null,
                'asunto'          => $row['asunto']    !== null ? (string) $row['asunto']    : null,
                'cuerpo'          => $row['cuerpo']    !== null ? (string) $row['cuerpo']    : null,
                'formato'         => $row['formato']   !== null ? (string) $row['formato']   : null,
                'adjunto'         => $row['adjunto']   !== null ? (string) $row['adjunto']   : null,
                'adjunto_origen'  => $row['adjunto_origen'] !== null ? (string) $row['adjunto_origen'] : null,
            ];
        }
    }

    // Mismo calculo que hace drcaValidarLanzable() antes de largar: las
    // plantillas "transaccionales" guardan literalmente `{asunto}` esperando
    // recibirlo del caller, asi que el asunto real es el de la plantilla con el
    // de la campaña sustituido adentro. Se expone precalculado para que la
    // pestaña muestre lo que va a salir y no dos campos sueltos que el operador
    // tenga que combinar mentalmente.
    $tplAsunto      = (string) ($tpl['asunto'] ?? '');
    $usaPlaceholder = strpos($tplAsunto, '{asunto}') !== false;
    $asuntoResuelto = trim(str_replace('{asunto}', $campanaAsunto, $tplAsunto));

    jsonOk([
        'plantilla_id'    => $plantillaId,
        'plantilla'       => $tpl,
        'medio'           => (string) ($c['medio'] ?? ''),
        'campana_asunto'  => $campanaAsunto !== '' ? $campanaAsunto : null,
        'usa_placeholder' => $usaPlaceholder,
        'asunto_resuelto' => $asuntoResuelto !== '' ? $asuntoResuelto : null,
    ]);
}

/**
 * Suscriptos de la lista de la campaña, con el destino que les tocaria segun el
 * medio. Es la foto de la lista HOY — no el padron, que es la foto congelada al
 * momento de expandir y vive en `?mensajes=1`.
 *
 *   GET api/datarocket_campanas.php?id=N&lista=1&q=texto&limite=100&sin_dato=1
 *   -> {ok:true, data:{lista:{...}|null, total_lista:N, total:M, sin_dato:K, items:[...]}}
 *
 * `total_lista` es la cuenta cruda de suscriptos, `total` la cuenta con el
 * buscador aplicado y `sin_dato` cuantos de la lista entera quedarian omitidos
 * por no tener el dato de contacto del medio. Los tres se cuentan contra la
 * tabla puente y no contra el denormalizado `datarocket_listas.suscriptos`, que
 * puede estar atrasado hasta que corra el recalculo.
 */
function handleListaCampana(PDO $pdo, int $id, array $q): void {
    $st = $pdo->prepare('SELECT id, medio, lista_id FROM ' . DRCA_TABLA . ' WHERE id = :id LIMIT 1');
    $st->execute([':id' => $id]);
    $c = $st->fetch();
    if (!$c) jsonError('Campaña no encontrada', 404);

    $medio   = (string) ($c['medio'] ?? '');
    $listaId = $c['lista_id'] !== null ? (int) $c['lista_id'] : null;

    if ($listaId === null) {
        jsonOk(['lista' => null, 'lista_id' => null, 'medio' => $medio,
                'total_lista' => 0, 'total' => 0, 'sin_dato' => 0, 'items' => []]);
        return;
    }

    $st = $pdo->prepare('SELECT li.id, li.nombre, li.slug, li.descripcion, li.suscriptos,
                                li.proyecto_id, pr.nombre AS proyecto_nombre
                           FROM datarocket_listas li
                           LEFT JOIN proyectos pr ON pr.id = li.proyecto_id
                          WHERE li.id = :id LIMIT 1');
    $st->execute([':id' => $listaId]);
    $lista = $st->fetch() ?: null;

    if (!$lista) {
        // La lista se borro despues de asignarla. No es 404 de la campaña: la
        // campaña existe, lo que falta es su lista, y el front lo avisa.
        jsonOk(['lista' => null, 'lista_id' => $listaId, 'medio' => $medio,
                'total_lista' => 0, 'total' => 0, 'sin_dato' => 0, 'items' => []]);
        return;
    }

    // Sin medio conocido no hay expresion de destino: se muestra el directorio
    // sin la columna Destino en vez de romper.
    $destinoSql = DRCA_DESTINO_PREVIEW_SQL[$medio] ?? 'NULL';

    $search  = trim((string) ($q['q'] ?? ''));
    $sinDato = trim((string) ($q['sin_dato'] ?? '')) !== '';
    $limite  = max(1, min(1000, (int) ($q['limite'] ?? 100)));

    $where  = ['dpl.lista_id = :lista_id'];
    $params = [':lista_id' => $listaId];

    if ($search !== '') {
        // LIKE sobre utf8mb4_general_ci, que ya pliega caja y acentos. Mismos
        // campos que el buscador de la pestaña Suscriptos del ABM de listas.
        $campos = ['nombre', 'empresa_nombre', 'persona_nombre', 'correo',
                   'telefono', 'celular', 'whatsapp', 'persona_dni', 'uuid'];
        $ors = [];
        foreach ($campos as $i => $campo) {
            $k = ":s{$i}";
            $ors[]        = "p.{$campo} LIKE {$k}";
            $params[$k]   = "%{$search}%";
        }
        $where[] = '(' . implode(' OR ', $ors) . ')';
    }
    // Chip "Sin dato": los que hoy quedarian omitidos al expandir. Es la razon
    // practica de mirar esta pestaña antes de lanzar.
    if ($sinDato) $where[] = "{$destinoSql} IS NULL";

    $sqlWhere = 'WHERE ' . implode(' AND ', $where);

    $st = $pdo->prepare('SELECT COUNT(*) FROM datarocket_prospectos_listas WHERE lista_id = :id');
    $st->execute([':id' => $listaId]);
    $totalLista = (int) $st->fetchColumn();

    // Cuantos de la lista ENTERA no tienen el dato de contacto del medio. Se
    // cuenta siempre sobre la lista completa (sin buscador ni chip) porque
    // alimenta el chip, que tiene que decir cuantos hay y no cuantos se ven.
    $st = $pdo->prepare("SELECT COUNT(*)
                           FROM datarocket_prospectos_listas dpl
                           JOIN datarocket_prospectos p ON p.id = dpl.prospecto_id
                          WHERE dpl.lista_id = :id AND {$destinoSql} IS NULL");
    $st->execute([':id' => $listaId]);
    $conteoSinDato = (int) $st->fetchColumn();

    if ($search === '' && !$sinDato) {
        $total = $totalLista;
    } else {
        $st = $pdo->prepare("SELECT COUNT(*)
                               FROM datarocket_prospectos_listas dpl
                               JOIN datarocket_prospectos p ON p.id = dpl.prospecto_id
                             {$sqlWhere}");
        $st->execute($params);
        $total = (int) $st->fetchColumn();
    }

    // Orden alfabetico: la pestaña se lee como un directorio, y con el limite
    // recortando siempre devuelve el mismo tramo (el desempate por id evita que
    // dos homonimos se intercambien entre corridas).
    $st = $pdo->prepare("SELECT p.id, p.tipo, p.nombre, p.correo, p.celular, p.whatsapp,
                                {$destinoSql} AS destino, dpl.fecha_creacion
                           FROM datarocket_prospectos_listas dpl
                           JOIN datarocket_prospectos p ON p.id = dpl.prospecto_id
                         {$sqlWhere}
                         ORDER BY p.nombre ASC, p.id ASC
                         LIMIT {$limite}");
    $st->execute($params);

    jsonOk([
        'lista' => [
            'id'              => (int) $lista['id'],
            'nombre'          => $lista['nombre'] !== null ? (string) $lista['nombre'] : null,
            'slug'            => $lista['slug']   !== null ? (string) $lista['slug']   : null,
            'descripcion'     => $lista['descripcion'] !== null ? (string) $lista['descripcion'] : null,
            'suscriptos'      => $lista['suscriptos']  !== null ? (int) $lista['suscriptos']     : null,
            'proyecto_id'     => $lista['proyecto_id'] !== null ? (int) $lista['proyecto_id']    : null,
            'proyecto_nombre' => $lista['proyecto_nombre'] !== null ? (string) $lista['proyecto_nombre'] : null,
        ],
        'lista_id'    => $listaId,
        'medio'       => $medio,
        'total_lista' => $totalLista,
        'total'       => $total,
        'sin_dato'    => $conteoSinDato,
        'items'       => $st->fetchAll(),
    ]);
}

// Catalogos para los <select> del formulario y del modal de filtros.
// `medio` y `proyecto` son opcionales y recortan listas / plantillas / canales
// a lo que aplica: el formulario los vuelve a pedir cuando el operador cambia
// el medio, para no ofrecer canales de WhatsApp en una campaña de correo.
function handleLookupsCampana(PDO $pdo, array $q): void {
    $medio    = trim((string) ($q['medio']    ?? ''));
    $proyecto = trim((string) ($q['proyecto'] ?? ''));
    $proyId   = ($proyecto !== '' && ctype_digit($proyecto)) ? (int) $proyecto : null;

    // Los proyectos se filtran por `tipo = 'I'` (internos): las campanas salen
    // de los productos del grupo — mismo recorte que embudos y redes sociales.
    $proyectos = $pdo->query(
        "SELECT id, COALESCE(NULLIF(TRIM(nombre), ''), CONCAT('#', id)) AS nombre
           FROM proyectos WHERE tipo = 'I' ORDER BY nombre ASC, id ASC"
    )->fetchAll();

    $catalogo = function (string $campo) use ($pdo): array {
        $st = $pdo->prepare('SELECT valor, texto FROM estados WHERE campo = :c ORDER BY orden ASC, id ASC');
        $st->execute([':c' => $campo]);
        return array_map(fn($r) => [
            'valor' => (string) ($r['valor'] ?? ''),
            'texto' => (string) ($r['texto'] ?? ''),
        ], $st->fetchAll());
    };

    // Listas: se recortan por proyecto si vino uno. `suscriptos` viaja para que
    // el formulario pueda mostrar el alcance estimado al elegir la lista.
    $sqlLi = 'SELECT id, nombre, suscriptos FROM datarocket_listas';
    $prm   = [];
    if ($proyId !== null) { $sqlLi .= ' WHERE proyecto_id = :p'; $prm[':p'] = $proyId; }
    $sqlLi .= ' ORDER BY nombre ASC, id ASC';
    $stLi = $pdo->prepare($sqlLi);
    $stLi->execute($prm);
    $listas = array_map(fn($r) => [
        'id'         => (int) $r['id'],
        'nombre'     => (string) ($r['nombre'] ?? ''),
        'suscriptos' => (int) ($r['suscriptos'] ?? 0),
    ], $stLi->fetchAll());

    // Plantillas: se recortan por medio (traducido a la letra legacy) y por
    // proyecto. Telegram no tiene letra, asi que devuelve vacio a proposito.
    $plantillas = [];
    if ($medio === '' || isset(DRCA_MEDIO_A_LETRA[$medio])) {
        $sqlPl = 'SELECT id, nombre, medio FROM datarocket_plantillas';
        $wPl   = [];
        $pPl   = [];
        if ($medio !== '') { $wPl[] = 'medio = :m';       $pPl[':m'] = DRCA_MEDIO_A_LETRA[$medio]; }
        if ($proyId !== null) { $wPl[] = 'proyecto_id = :p'; $pPl[':p'] = $proyId; }
        if ($wPl) $sqlPl .= ' WHERE ' . implode(' AND ', $wPl);
        $sqlPl .= ' ORDER BY nombre ASC, id ASC LIMIT 1000';
        $stPl = $pdo->prepare($sqlPl);
        $stPl->execute($pPl);
        $plantillas = array_map(fn($r) => [
            'id'     => (int) $r['id'],
            'nombre' => (string) ($r['nombre'] ?? ''),
            'medio'  => (string) ($r['medio']  ?? ''),
        ], $stPl->fetchAll());
    }

    // Canales: solo los del medio pedido. Sin medio se devuelve vacio — no
    // tiene sentido mezclar canales de SES con instancias de Evolution en un
    // mismo combo, y el formulario siempre sabe su medio antes de pedirlos.
    $canales = [];
    if ($medio !== '' && isset(DRCA_CANALES_POR_MEDIO[$medio])) {
        $cfg   = DRCA_CANALES_POR_MEDIO[$medio];
        $sqlCa = 'SELECT id, nombre FROM ' . $cfg['tabla'];
        $wCa   = [];
        $pCa   = [];
        // Solo `aws_canales` carece de columna de proyecto; en las otras dos se
        // recorta si el operador ya eligio uno.
        if ($cfg['proyecto'] !== null && $proyId !== null) {
            $wCa[] = $cfg['proyecto'] . ' = :p';
            $pCa[':p'] = $proyId;
        }
        // Las tres tablas de canales usan el mismo flag legacy `habilitado`
        // varchar(1): '1' habilitado. Un canal deshabilitado no debe ofrecerse
        // como destino de una campaña nueva.
        $wCa[] = "(habilitado = '1' OR habilitado IS NULL)";
        if ($wCa) $sqlCa .= ' WHERE ' . implode(' AND ', $wCa);
        $sqlCa .= ' ORDER BY nombre ASC, id ASC';
        $stCa = $pdo->prepare($sqlCa);
        $stCa->execute($pCa);
        $canales = array_map(fn($r) => [
            'id'     => (int) $r['id'],
            'nombre' => (string) ($r['nombre'] ?? ''),
        ], $stCa->fetchAll());
    }

    jsonOk([
        'proyectos'      => array_map(fn($r) => [
            'id'     => (int) $r['id'],
            'nombre' => (string) $r['nombre'],
        ], $proyectos),
        'medios'         => $catalogo(DRCA_CAMPO_MEDIO),
        'estados'        => $catalogo(DRCA_CAMPO_ESTADO),
        'estados_padron' => $catalogo(DRCA_CAMPO_ESTADO_PADRON),
        'resultados'     => $catalogo(DRCA_CAMPO_RESULTADO_PADRON),
        'listas'         => $listas,
        'plantillas'     => $plantillas,
        'canales'        => $canales,
    ]);
}

// ----------------------------------------------------------------------------
// Handlers — escritura
// ----------------------------------------------------------------------------

function handleCreateCampana(PDO $pdo, array $body): void {
    $p = sanitizePayloadCampana($pdo, $body, true);

    if ($p['slug'] === '') {
        jsonError('No se pudo derivar un slug a partir del nombre. Cargalo manualmente.', 400);
    }
    drcaValidarSlugUnico($pdo, $p['slug']);
    drcaValidarCoherencia($pdo, $p);

    // Una campana recien creada nunca arranca sola: `iniciada`, `completada` y
    // los contadores quedan en su default. Los escribe el expansor.
    $st = $pdo->prepare(
        'INSERT INTO ' . DRCA_TABLA . '
            (proyecto_id, nombre, slug, descripcion, asunto, medio, canal_id, lista_id,
             plantilla_id, prioridad, estado, programada, observaciones)
         VALUES
            (:proyecto_id, :nombre, :slug, :descripcion, :asunto, :medio, :canal_id, :lista_id,
             :plantilla_id, :prioridad, :estado, :programada, :observaciones)'
    );
    $st->execute([
        ':proyecto_id'   => $p['proyecto_id'],
        ':nombre'        => $p['nombre'],
        ':slug'          => $p['slug'],
        ':descripcion'   => $p['descripcion'],
        ':asunto'        => $p['asunto'],
        ':medio'         => $p['medio'],
        ':canal_id'      => $p['canal_id'],
        ':lista_id'      => $p['lista_id'],
        ':plantilla_id'  => $p['plantilla_id'],
        ':prioridad'     => $p['prioridad'],
        ':estado'        => $p['estado'],
        ':programada'    => $p['programada'],
        ':observaciones' => $p['observaciones'],
    ]);

    $id = (int) $pdo->lastInsertId();
    registrarSuceso($pdo, 'datarocket_campanas', 'info',
        "Alta campaña #{$id} — \"{$p['nombre']}\" ({$p['medio']})");

    handleGetOneCampana($pdo, $id);
}

function handleUpdateCampana(PDO $pdo, int $id, array $body): void {
    $st = $pdo->prepare('SELECT ' . DRCA_COLS . ' FROM ' . DRCA_TABLA . ' WHERE id = :id LIMIT 1');
    $st->execute([':id' => $id]);
    $prev = $st->fetch();
    if (!$prev) jsonError('Campaña no encontrada', 404);

    $p = sanitizePayloadCampana($pdo, $body, false);

    // Estado resultante = lo que ya estaba, pisado por lo que vino en el body.
    // Se valida sobre esto y no sobre el body porque un PUT parcial que solo
    // cambia `medio` puede invalidar el `plantilla_id` guardado.
    $f = [
        'medio'        => array_key_exists('medio', $body)        && $p['medio'] !== '' ? $p['medio']        : (string) $prev['medio'],
        'canal_id'     => array_key_exists('canal_id', $body)     ? $p['canal_id']     : ($prev['canal_id']     !== null ? (int) $prev['canal_id']     : null),
        'lista_id'     => array_key_exists('lista_id', $body)     ? $p['lista_id']     : ($prev['lista_id']     !== null ? (int) $prev['lista_id']     : null),
        'plantilla_id' => array_key_exists('plantilla_id', $body) ? $p['plantilla_id'] : ($prev['plantilla_id'] !== null ? (int) $prev['plantilla_id'] : null),
    ];

    // Una campana ya arrancada no puede cambiar de configuracion: el padron
    // expandido quedaria apuntando a otra lista / plantilla / canal que el que
    // realmente se uso. Cambiar de estado (pausar, cancelar) SI se permite.
    if (in_array((string) $prev['estado'], DRCA_ESTADOS_ARRANCADOS, true)) {
        foreach (['medio', 'canal_id', 'lista_id', 'plantilla_id'] as $campo) {
            if (!array_key_exists($campo, $body)) continue;
            $antes = $campo === 'medio'
                ? (string) $prev['medio']
                : ($prev[$campo] !== null ? (int) $prev[$campo] : null);
            if ($f[$campo] !== $antes) {
                jsonError('La campaña ya arrancó: no se puede cambiar su medio, canal, lista ni plantilla. Cancelala y creá una nueva.', 409);
            }
        }
    }

    if (array_key_exists('slug', $body)) {
        if ($p['slug'] === '') jsonError('El slug no puede quedar vacío.', 400);
        if ($p['slug'] !== (string) $prev['slug']) drcaValidarSlugUnico($pdo, $p['slug'], $id);
    }

    drcaValidarCoherencia($pdo, $f);

    // Update parcial: solo se tocan las claves presentes en el body.
    $sets   = [];
    $params = [':id' => $id];

    $asignar = function (string $campo, $valor) use (&$sets, &$params) {
        $sets[] = "{$campo} = :{$campo}";
        $params[":{$campo}"] = $valor;
    };

    if (array_key_exists('nombre', $body) && $p['nombre'] !== '') $asignar('nombre',        $p['nombre']);
    if (array_key_exists('slug', $body))                          $asignar('slug',          $p['slug']);
    if (array_key_exists('descripcion', $body))                   $asignar('descripcion',   $p['descripcion']);
    if (array_key_exists('asunto', $body))                        $asignar('asunto',        $p['asunto']);
    if (array_key_exists('medio', $body) && $p['medio'] !== '')   $asignar('medio',         $p['medio']);
    if (array_key_exists('canal_id', $body))                      $asignar('canal_id',      $p['canal_id']);
    if (array_key_exists('lista_id', $body))                      $asignar('lista_id',      $p['lista_id']);
    if (array_key_exists('plantilla_id', $body))                  $asignar('plantilla_id',  $p['plantilla_id']);
    if (array_key_exists('proyecto_id', $body))                   $asignar('proyecto_id',   $p['proyecto_id']);
    if (array_key_exists('prioridad', $body))                     $asignar('prioridad',     $p['prioridad']);
    if (array_key_exists('estado', $body) && $p['estado'] !== '') $asignar('estado',        $p['estado']);
    if (array_key_exists('programada', $body))                    $asignar('programada',    $p['programada']);
    if (array_key_exists('observaciones', $body))                 $asignar('observaciones', $p['observaciones']);

    if (empty($sets)) jsonError('No hay campos para actualizar.', 400);

    $st = $pdo->prepare('UPDATE ' . DRCA_TABLA . ' SET ' . implode(', ', $sets) . ' WHERE id = :id');
    $st->execute($params);

    registrarSuceso($pdo, 'datarocket_campanas', 'info',
        "Modificación campaña #{$id} — \"{$prev['nombre']}\"");

    handleGetOneCampana($pdo, $id);
}

// El borrado arrastra el padron por la FK ON DELETE CASCADE. Las filas ya
// encoladas en la cola del canal NO se tocan: son mensajes que ya existen y
// que el motor va a despachar igual — borrar la campana no los desencola.
function handleDeleteCampana(PDO $pdo, int $id): void {
    $st = $pdo->prepare('SELECT nombre, estado FROM ' . DRCA_TABLA . ' WHERE id = :id LIMIT 1');
    $st->execute([':id' => $id]);
    $prev = $st->fetch();
    if (!$prev) jsonError('Campaña no encontrada', 404);

    // Una campana en pleno envio no se borra: el expansor la tiene tomada y
    // quedarian filas de cola huerfanas apuntando a una campana inexistente.
    if (in_array((string) $prev['estado'], ['expandiendo', 'enviando'], true)) {
        jsonError('La campaña está en curso. Pausala o cancelala antes de eliminarla.', 409);
    }

    $sc = $pdo->prepare('SELECT COUNT(*) FROM ' . DRCA_TABLA_PADRON . ' WHERE campana_id = :id');
    $sc->execute([':id' => $id]);
    $padron = (int) $sc->fetchColumn();

    $sd = $pdo->prepare('DELETE FROM ' . DRCA_TABLA . ' WHERE id = :id');
    $sd->execute([':id' => $id]);

    registrarSuceso($pdo, 'datarocket_campanas', 'info',
        "Baja campaña #{$id} — \"{$prev['nombre']}\" ({$padron} destinatarios del padrón)");

    jsonOk(['id' => $id, 'padron_eliminado' => $padron]);
}

// Larga la campana. NO envia ni expande nada: valida que este completa y la
// deja en el estado que el expansor busca — 'programada' con `programada` =
// ahora, que es la condicion de su barrido (estado='programada' AND
// programada <= NOW()). El envio real lo hace el job en su proxima corrida.
//
// Que se valida antes de largar, y por que cada cosa:
//   - estado: solo desde 'borrador' o 'programada'. Una campana en curso ya
//     esta largada y una completada/cancelada terminaria pisando su padron.
//   - lista: sin lista no hay a quien.
//   - canal: sin canal no hay por donde.
//   - plantilla: sin plantilla no hay que mandar. Vale tambien para Telegram,
//     que hoy no tiene plantillas propias — de ahi que una campana de Telegram
//     no se pueda largar todavia, que es lo correcto y no un bug.
//   - suscriptos: se cuentan sobre la tabla puente y NO sobre el contador
//     denormalizado `datarocket_listas.suscriptos`, que puede estar atrasado.
//     Bloquear un envio por un contador viejo seria peor que la consulta de mas.
function handleIniciarCampana(PDO $pdo, int $id): void {
    $st = $pdo->prepare('SELECT ' . DRCA_COLS . ' FROM ' . DRCA_TABLA . ' WHERE id = :id LIMIT 1');
    $st->execute([':id' => $id]);
    $c = $st->fetch();
    if (!$c) jsonError('Campaña no encontrada', 404);

    $estado = (string) $c['estado'];
    if (!in_array($estado, ['borrador', 'programada'], true)) {
        jsonError("La campaña está en estado \"{$estado}\": solo se pueden iniciar las que están en borrador o programadas.", 409);
    }

    $faltan = [];
    if ($c['lista_id']     === null) $faltan[] = 'la lista de distribución';
    if ($c['canal_id']     === null) $faltan[] = 'el canal de salida';
    if ($c['plantilla_id'] === null) $faltan[] = 'la plantilla';
    if ($faltan) {
        jsonError('No se puede iniciar: falta ' . implode(', ', $faltan) . '.', 409);
    }

    $sc = $pdo->prepare('SELECT COUNT(*) FROM datarocket_prospectos_listas WHERE lista_id = :l');
    $sc->execute([':l' => (int) $c['lista_id']]);
    $suscriptos = (int) $sc->fetchColumn();
    if ($suscriptos === 0) {
        jsonError('No se puede iniciar: la lista no tiene prospectos suscriptos.', 409);
    }

    // `iniciada` se sella aca y no en el expansor: es el momento en que el
    // operador dio la orden, que es el dato que se quiere auditar. El expansor
    // marcara `completada` cuando no queden pendientes en el padron.
    $up = $pdo->prepare(
        'UPDATE ' . DRCA_TABLA . "
            SET estado = 'programada', programada = NOW(), iniciada = NOW()
          WHERE id = :id"
    );
    $up->execute([':id' => $id]);

    registrarSuceso($pdo, 'datarocket_campanas', 'info',
        "Inicio de campaña #{$id} — \"{$c['nombre']}\" ({$c['medio']}, {$suscriptos} suscriptos en la lista)");

    handleGetOneCampana($pdo, $id);
}

// Pone al dia el padron y los contadores. Idempotente.
//
// No es solo un COUNT: la reconciliacion SINCRONIZA primero cada renglon del
// padron con la fila de cola a la que apunta por `mensaje_id`, porque es esa
// fila la que sabe si el motor del canal ya despacho el mensaje. Los motores no
// conocen a las campanas — y no hace falta que las conozcan — asi que el padron
// se entera por JOIN. Ver drcaCampanaReconciliar() en la lib.
//
// Tambien cierra la campana si ya no queda nada pendiente ni en vuelo.
function handleRecalcularCampana(PDO $pdo, int $id): void {
    $st = $pdo->prepare('SELECT nombre FROM ' . DRCA_TABLA . ' WHERE id = :id LIMIT 1');
    $st->execute([':id' => $id]);
    $prev = $st->fetch();
    if (!$prev) jsonError('Campaña no encontrada', 404);

    $r = drcaCampanaReconciliar($pdo, $id);

    registrarSuceso($pdo, 'datarocket_campanas', 'info',
        "Recálculo de la campaña #{$id} — \"{$prev['nombre']}\""
        . " (enviados {$r['enviados']}, fallidos {$r['fallidos']}, pendientes {$r['pendientes']})");

    handleGetOneCampana($pdo, $id);
}
