<?php
/**
 * cloud/jobs/evolution_canales_reiniciar.php
 * Reinicio programado de las instancias de Evolution API: una vez por dia
 * hace, canal por canal, lo mismo que el operador hacia a mano en el
 * dashboard del Manager -- apretar RESTART y despues el icono de refresco
 * para confirmar que volvio a "Connected".
 *
 * POR QUE UN REINICIO DIARIO
 * -------------------------
 * El socket de WhatsApp se degrada con los dias: la instancia sigue
 * respondiendo `state: open` pero deja de recibir o de despachar. El reinicio
 * recrea la conexion sin tocar las credenciales, asi que es barato y no pide
 * QR. Se corre de madrugada porque el canal queda unos segundos abajo.
 *
 * QUE HACE POR CANAL
 * ------------------
 *   1. POST /instance/restart/{slug}
 *   2. Sondea /instance/connectionState/{slug} hasta verlo en 'open' o hasta
 *      agotar el plazo de verificacion.
 *   3. Refresca `online` / `latido` / `actualizado` en `evolution_canales`.
 *
 * El trabajo real vive en cloud/api/lib/evolution_canales_reiniciar.php: este
 * archivo es solo la SELECCION (que canales entran) y el ritmo (pausa entre
 * uno y otro, presupuesto de la corrida) -- misma division que
 * datarocket_campanas_expandir.php.
 *
 * QUE CANALES ENTRAN
 * ------------------
 * Todos los de `evolution_canales` con `habilitado = '1'` y slug + token
 * cargados. Se puede acotar sin tocar codigo con el parametro
 * `evolution.canales.reiniciar.slugs` (CSV de slugs; vacio = todos), editable
 * desde Herramientas > Editor de parametros.
 *
 * Los canales deshabilitados quedan afuera a proposito: son instancias que el
 * operador apago, y reiniciarlas equivaldria a re-encenderlas por la ventana.
 *
 * UN CANAL CAIDO NO FRENA A LOS DEMAS
 * -----------------------------------
 * Cada canal se maneja aparte y su fallo queda como suceso 'alerta'. El caso
 * mas comun es una sesion deslogueada, que no se arregla reiniciando: hay que
 * escanear el QR. El job lo dice explicitamente en el suceso en vez de
 * reintentar en vano.
 *
 * Se registra desde el Programador de tareas (tabla `tareas`) apuntando
 * `script` = "jobs/evolution_canales_reiniciar.php".
 */

require_once __DIR__ . '/_bootstrap.php';
require_once __DIR__ . '/../api/lib/evolution_canales_reiniciar.php';
require_once __DIR__ . '/../api/lib/parametros.php';

// Pausa entre un canal y el siguiente. No es un rate limit de Evolution sino
// prudencia operativa: 12 restarts simultaneos contra el mismo servidor son un
// pico de reconexiones evitable, y escalonarlos hace que nunca esten todos los
// bots abajo al mismo tiempo.
const PAUSA_ENTRE_CANALES = 5;

// Presupuesto de la corrida. Deliberadamente menor que el `timeout_seg` de la
// tarea (900 s) para cortar limpio y dejar el resumen escrito, en vez de que
// el `timeout` de coreutils mate el proceso a la mitad. Lo que no entro se
// reinicia en la corrida del dia siguiente.
const PRESUPUESTO_SEG = 780;

$ORIGEN_SUCESO = 'cron/evolution_canales_reiniciar';

try {
    $pdo    = db();
    $inicio = time();

    // --- Parametros de la corrida ------------------------------------------
    // Se siembran aca (no en la migracion) para que aparezcan en el Editor de
    // parametros aunque nadie los haya creado a mano, y para que su valor sea
    // el del entorno y no el que quedo escrito en un .sql.
    parametroAsegurar($pdo, 'evolution.canales.reiniciar.slugs', '',
        'Canales que reinicia el job evolution_canales_reiniciar, como lista de ' .
        'slugs separados por coma (ej: "vigicom-bot,databox-bot"). Vacio = todos ' .
        'los canales habilitados. Ver cloud/jobs/evolution_canales_reiniciar.php.');

    parametroAsegurar($pdo, 'evolution.canales.reiniciar.verificar_seg', '45',
        'Segundos que el job evolution_canales_reiniciar espera, como maximo, a ' .
        'que un canal vuelva a estado "open" despues del reinicio. 0 = no ' .
        'verificar (solo dispara el restart y sigue).');

    $slugsRaw     = (string) parametroLeer($pdo, 'evolution.canales.reiniciar.slugs', '');
    $verificarSeg = (int)    parametroLeer($pdo, 'evolution.canales.reiniciar.verificar_seg', '45');
    if ($verificarSeg < 0) $verificarSeg = 0;

    // Filtro normalizado a minusculas: el operador escribe el parametro a mano
    // y no tiene por que acordarse de la caja exacta del slug.
    $filtro = [];
    foreach (explode(',', $slugsRaw) as $s) {
        $s = strtolower(trim($s));
        if ($s !== '') $filtro[$s] = true;
    }

    // --- Seleccion ----------------------------------------------------------
    $canales = $pdo->query("
        SELECT id, nombre, slug, token
          FROM evolution_canales
         WHERE habilitado = '1'
           AND slug  IS NOT NULL AND slug  <> ''
           AND token IS NOT NULL AND token <> ''
         ORDER BY id
    ")->fetchAll();

    if ($filtro) {
        $canales = array_values(array_filter($canales, static function (array $c) use ($filtro) {
            return isset($filtro[strtolower(trim((string) $c['slug']))]);
        }));

        // Un slug mal escrito en el parametro es silencioso por naturaleza: el
        // canal simplemente no se reinicia nunca y nadie se entera. Se avisa.
        $encontrados = [];
        foreach ($canales as $c) $encontrados[strtolower(trim((string) $c['slug']))] = true;
        $huerfanos = array_diff(array_keys($filtro), array_keys($encontrados));
        if ($huerfanos) {
            $lista = implode(', ', $huerfanos);
            anotarLog("AVISO: el parametro .slugs nombra canales que no existen o estan deshabilitados: {$lista}");
            registrarSuceso($pdo, $ORIGEN_SUCESO, 'alerta',
                'El parametro evolution.canales.reiniciar.slugs nombra canales ' .
                "inexistentes o deshabilitados: {$lista}");
        }
    }

    $total = count($canales);
    anotarLog('Canales a reiniciar: ' . $total
        . ($filtro ? ' (filtrados por parametro .slugs)' : ' (todos los habilitados)'));
    anotarLog("Verificacion de reconexion: {$verificarSeg}s por canal");

    if ($total === 0) {
        $msg = $filtro
            ? 'El filtro .slugs no matcheo ningun canal habilitado.'
            : 'Sin canales Evolution habilitados con slug y token.';
        anotarLog($msg);
        marcarEjecucionOk($msg);
        exit(0);
    }

    $okCount  = 0;
    $errCount = 0;
    $sinCorrer = 0;

    foreach ($canales as $i => $c) {
        $prefix = sprintf('[%d/%d] canal #%d (%s / %s)',
            $i + 1, $total, (int) $c['id'], $c['nombre'] ?? '', $c['slug']);

        // Corte por presupuesto: mejor un resumen honesto de lo que se hizo
        // que un proceso muerto por SIGKILL a mitad de un reinicio.
        if (time() - $inicio >= PRESUPUESTO_SEG) {
            $sinCorrer = $total - $i;
            anotarLog("Presupuesto de " . PRESUPUESTO_SEG . "s agotado: quedan {$sinCorrer} canales sin reiniciar.");
            registrarSuceso($pdo, $ORIGEN_SUCESO, 'alerta',
                "Corrida cortada por presupuesto: {$sinCorrer} canales quedaron sin reiniciar.");
            break;
        }

        if ($i > 0) sleep(PAUSA_ENTRE_CANALES);

        anotarLog("{$prefix} - reiniciando...");
        try {
            $r = evoCanalReiniciar($pdo, $c, 'anotarLog', ['verificar_seg' => $verificarSeg]);

            if ($r['ok']) {
                $detalle = $r['estado'] === null
                    ? 'reinicio disparado (sin verificar)'
                    : "reinicio OK, volvio a 'open' en {$r['segundos']}s";
                anotarLog("{$prefix} - {$detalle}");
                registrarSuceso($pdo, $ORIGEN_SUCESO, 'info',
                    "Evolution canal #{$c['id']} ({$c['nombre']}) - {$detalle}");
                $okCount++;
            } else {
                anotarLog("{$prefix} - fallo: {$r['error']}");
                registrarSuceso($pdo, $ORIGEN_SUCESO, 'alerta',
                    "Evolution canal #{$c['id']} ({$c['nombre']}) - {$r['error']}");
                $errCount++;
            }
        } catch (Throwable $e) {
            $msg = $e->getMessage();
            anotarLog("{$prefix} - excepcion: {$msg}");
            registrarSuceso($pdo, $ORIGEN_SUCESO, 'alerta',
                "Evolution canal #{$c['id']} ({$c['nombre']}) - excepcion: {$msg}");
            $errCount++;
        }
    }

    $resumen = "{$okCount} reiniciados OK | {$errCount} con problema"
             . ($sinCorrer > 0 ? " | {$sinCorrer} sin correr (presupuesto)" : '')
             . " | {$total} seleccionados";
    anotarLog("Finalizado: {$resumen}");
    marcarEjecucionOk($resumen);

} catch (Throwable $e) {
    anotarLog('ERROR fatal: ' . $e->getMessage());
    marcarEjecucionError($e);
    throw $e;
}
