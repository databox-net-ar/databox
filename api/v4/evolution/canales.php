<?php
// api/v4/evolution/canales.php
// Microservicio de consulta de canales (instancias de WhatsApp) de Evolution API.
//
//   GET /v4/evolution/canales                      -> listado desde la base
//   GET /v4/evolution/canales?canal_slug=vigicom-bot -> detalle + estado en vivo
//
// Auth: Bearer con apikey de la tabla `aplicaciones` (mismo esquema que el
// resto del stack — ver cloud/api/lib/apikey_auth.php).
//
// Port de `/v2/evolution/canalEstado` del legacy. El listado no existia ahi:
// se agrega porque sin el, un integrador tiene que saberse los slugs de
// memoria para poder llamar a cualquier otro endpoint de este microservicio.
//
// SOLO LECTURA. El alta y la edicion de canales viven en el ABM del panel
// cloud (`cloud/api/evolutioncanales.php`): un canal se da de alta una vez, a
// mano, cuando se escanea el QR — no es una operacion de integracion.
//
// La logica vive en cloud/api/lib/evolution_grupos.php; aca solo se valida el
// request y se serializa la respuesta.

header('Content-Type: application/json; charset=utf-8');

require_once dirname(__DIR__, 3) . '/env.php';
require_once dirname(__DIR__, 3) . '/cloud/api/lib/evolution_grupos.php';
require_once dirname(__DIR__) . '/_lib/log.php';
require_once dirname(__DIR__, 3) . '/cloud/api/lib/apikey_auth.php';

// Todo error de este endpoint queda registrado en `sucesos` (Visor de sucesos
// del panel). Va antes de la auth para que los 401 tambien caigan adentro.
v4InitLog('v4/evolution.canales');

try {
    v4LogApp(requireAppApikey());

    $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
    if ($method !== 'GET') {
        jsonError('Metodo no soportado. `/v4/evolution/canales` es de solo lectura: '
                . 'los canales se dan de alta desde el ABM del panel cloud.', 405);
    }

    $pdo = db();

    // Cualquiera de las tres formas de nombrar un canal dispara el detalle.
    $pideUno = trim((string)($_GET['canal_slug'] ?? '')) !== ''
            || (int)($_GET['canal_id'] ?? 0) > 0
            || trim((string)($_GET['canal'] ?? '')) !== '';

    if ($pideUno) handleDetalle($pdo, $_GET);
    else          handleListado($pdo, $_GET);

} catch (InvalidArgumentException $e) {
    // El caller mando algo que no corresponde (canal inexistente, filtro malo).
    jsonError($e->getMessage(), 400);
} catch (RuntimeException $e) {
    // Evolution no respondio o rechazo el pedido. No es culpa de quien llama.
    jsonError($e->getMessage(), 502);
} catch (Throwable $e) {
    jsonError($e->getMessage(), 500);
}

// ---------------------------------------------------------------------------
// GET /v4/evolution/canales  -> listado
// ---------------------------------------------------------------------------
//
// Sale de la base, no de Evolution: preguntarle el estado en vivo a N canales
// serian N requests HTTP secuenciales y el listado dejaria de contestar en
// tiempo razonable. Los campos `online` / `latido` son el cache que refresca
// el cron `evolution_canales_actualizar_estados.php` cada pocos minutos; para
// el estado del instante se pide el detalle de un canal.

function handleListado(PDO $pdo, array $q): void {
    $items = evoCanalesListar($pdo, $q);
    jsonOk([
        'total' => count($items),
        'items' => $items,
    ]);
}

// ---------------------------------------------------------------------------
// GET /v4/evolution/canales?canal_slug=X  -> detalle + estado en vivo
// ---------------------------------------------------------------------------
//
// Equivalente de `POST /v2/evolution/canalEstado`. `instancia` es lo que
// devuelve /instance/fetchInstances tal cual, sin renombrar campos: si
// Evolution agrega uno nuevo aparece solo. `online` y `connectionStatus` se
// suben al primer nivel porque es lo unico que mira el 90% de los callers.

function handleDetalle(PDO $pdo, array $q): void {
    $canal     = evoCanalResolver($pdo, $q);
    $instancia = evoCanalInstancia($canal);

    $estado = trim((string)($instancia['connectionStatus'] ?? ''));

    jsonOk([
        'canal'            => evoCanalPublico($canal),
        'online'           => $estado === 'open',
        'connectionStatus' => $estado !== '' ? $estado : null,
        'instancia'        => $instancia,
    ]);
}
