<?php
// api/v4/evolution/mensajes.php
// Microservicio de ingesta de mensajes de Evolution API.
// Alta a la cola de envio y consulta del estado de un mensaje encolado.
//
//   POST /v4/evolution/mensajes           (JSON body) -> encola, devuelve {id, estado, encolado, programado}
//   GET  /v4/evolution/mensajes?id=N                  -> estado actual del mensaje N
//
// Auth: Bearer con apikey de la tabla `aplicaciones` (mismo esquema que el resto
// del stack — ver cloud/api/lib/apikey_auth.php).
//
// Tabla destino: `evolution_mensajes` (schema en db/schema.sql).
//
// Punto UNICO de entrada: la insercion se delega a
// `cloud/api/lib/evolution_mensajes.php::encolarEvolutionMensaje()`, la misma
// funcion que usa el ABM cloud. Asi ambos callers aplican las mismas reglas
// de sanitizacion, obligatorios y defaults, y ambos levantan la bandera
// tri-estado `parametros.evolution.mensajes.enviar` a '2' (ENVIANDO) para
// despertar al sender worker — salvo que el operador la haya dejado en '0'
// (DETENIDO / pausa manual desde el UI), en cuyo caso el mensaje queda
// pendiente pero no se despierta al motor. Semantica del flag:
//   '0' = DETENIDO  '1' = ESPERANDO  '2' = ENVIANDO
//
// Datarocket (prospecto + interaccion): NO se toca por defecto. Encolar un
// mensaje es un acto de envio, no de CRM. Para que el envio ademas de/resuelva
// el prospecto en `datarocket_prospectos` y le cuelgue la interaccion en
// `datarocket_interacciones`, el body tiene que traer el opt-in explicito:
//
//   { ..., "registrar_prospecto": true }
//
// Sin ese flag el mensaje se encola y se envia igual, pero no deja rastro en
// Datarocket. Un `prospecto_id` explicito en el body se guarda siempre (con
// flag o sin el) — el flag solo gobierna el alta automatica y la interaccion.
// Ver debeRegistrarProspecto() en cloud/api/lib/datarocket_interacciones.php.
//
// (Cuando v4 se mueva a otro DocumentRoot habra que reajustar el include del
// require_once — es el unico acoplamiento con el runtime del panel.)

header('Content-Type: application/json; charset=utf-8');

require_once dirname(__DIR__, 3) . '/env.php';
require_once dirname(__DIR__, 3) . '/cloud/api/lib/evolution_mensajes.php';
require_once dirname(__DIR__) . '/_lib/log.php';
require_once dirname(__DIR__, 3) . '/cloud/api/lib/apikey_auth.php';

// Todo error de este endpoint queda registrado en `sucesos` (Visor de sucesos
// del panel). Va antes de la auth para que los 401 tambien caigan adentro.
v4InitLog('v4/evolution.mensajes');
// ---------------------------------------------------------------------------
// Auth
// ---------------------------------------------------------------------------
// Puerta unica del stack: requireAppApikey() vive en
// cloud/api/lib/apikey_auth.php. Lee `Authorization: Bearer <apikey>`, valida
// contra la tabla `aplicaciones`, rechaza con 401 (token ausente / apikey
// desconocida / aplicacion deshabilitada) e incrementa `aplicaciones.usos`.
//
// Hasta la consolidacion cada microservicio arrastraba su propia copia de este
// bloque (8 variantes con nombres prefijados). Si hace falta tocar la auth
// -- scope por aplicacion, rate limit, rotacion de apikey -- se toca la lib.

// Etiquetas de estado alineadas con la tabla `estados` (evolution_mensaje_estado).
// Solo se usan para el campo `estado_label` de conveniencia — la fuente de
// verdad del valor sigue siendo `evolution_mensajes.estado` (varchar 20).
// Se declara aca (antes del dispatcher) porque `const` a nivel de archivo
// se registra cuando la ejecucion pasa por esa linea, no al compilar.
const EVO_MSG_ESTADO_LABEL = [
    'pendiente' => 'Pendiente',
    'enviando'  => 'Enviando',
    'enviado'   => 'Enviado',
    'anulado'   => 'Anulado',
    'error'     => 'Error',
];

// ---------------------------------------------------------------------------
// Ruteo
// ---------------------------------------------------------------------------

try {
    v4LogApp(requireAppApikey());
    $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

    if ($method === 'POST') {
        handleEnqueue(readJsonBody());
    } elseif ($method === 'GET') {
        $id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
        if ($id <= 0) jsonError('Falta id (int > 0)', 400);
        handleStatus($id);
    } else {
        jsonError('Metodo no soportado', 405);
    }
} catch (Throwable $e) {
    jsonError($e->getMessage(), 500);
}

// ---------------------------------------------------------------------------
// POST /v4/evolution/mensajes  -> encolar
// ---------------------------------------------------------------------------
//
// Toda la logica de sanitizacion, validacion, defaults e INSERT vive en la
// funcion compartida encolarEvolutionMensaje() (cloud/api/lib/evolution_mensajes.php).
// El handler v4 solo mapea la respuesta HTTP y traduce las excepciones a los
// codigos de status apropiados.

function handleEnqueue(array $in): void {
    try {
        $id = encolarEvolutionMensaje(db(), $in);
    } catch (InvalidArgumentException $e) {
        jsonError($e->getMessage(), 400);
        return; // inalcanzable — jsonError() hace exit; aca solo para el analizador estatico.
    }

    // Releemos las columnas system-managed (fecha/encolado/programado que
    // el encolador puede haber defaulteado a NOW) para que la respuesta
    // sea fiel a lo persistido — asi el cliente v4 no tiene que adivinar.
    $st = db()->prepare("SELECT fecha, encolado, programado, estado
                           FROM evolution_mensajes WHERE id = :id LIMIT 1");
    $st->execute([':id' => $id]);
    $r = $st->fetch() ?: [];

    jsonOk([
        'id'         => $id,
        'estado'     => $r['estado']     ?? 'pendiente',
        'fecha'      => $r['fecha']      ?? null,
        'encolado'   => $r['encolado']   ?? null,
        'programado' => $r['programado'] ?? null,
    ], 201);
}

// ---------------------------------------------------------------------------
// GET /v4/evolution/mensajes?id=N  -> consultar estado
// ---------------------------------------------------------------------------

function handleStatus(int $id): void {
    $pdo = db();
    $st  = $pdo->prepare(
        "SELECT id, canal_id, destino, estado, error, encolado, programado, enviado, demora
         FROM evolution_mensajes WHERE id = :id LIMIT 1"
    );
    $st->execute([':id' => $id]);
    $row = $st->fetch();
    if (!$row) jsonError('Mensaje no encontrado', 404);

    $row['id']           = (int)$row['id'];
    $row['canal_id']     = $row['canal_id'] !== null ? (int)$row['canal_id'] : null;
    $row['demora']       = $row['demora'] !== null ? (int)$row['demora'] : null;
    $row['estado_label'] = EVO_MSG_ESTADO_LABEL[$row['estado']] ?? $row['estado'];

    jsonOk($row);
}
