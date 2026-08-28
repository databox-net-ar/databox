<?php
/**
 * cloud/jobs/datarocket_interacciones_avisar_pendientes.php
 * Aviso por WhatsApp al responsable de cada oportunidad con interacciones
 * entrantes sin responder.
 *
 * UN MENSAJE POR CONSULTA
 * -----------------------
 * No es un resumen: sale un WhatsApp por cada interaccion pendiente, y cada uno
 * se basta a si mismo. Lleva quien pregunto, como contactarlo (whatsapp,
 * celular, telefono, correo), de que negocio se trata y el texto completo de lo
 * que consulto, para que el responsable pueda contestar desde el propio
 * WhatsApp sin entrar al panel.
 *
 * ENLACE A LA FICHA
 * -----------------
 * Cada mensaje cierra con un link firmado a cloud/datarocket/interacciones/,
 * una pagina publica (sin login) que muestra la ficha completa del prospecto y
 * ofrece un boton para marcar la consulta como atendida. Es lo que cierra el
 * circuito: hasta que existio, contestar era facil pero MARCAR obligaba a
 * entrar al panel y buscar la fila, asi que las pendientes se contestaban y
 * seguian avisando igual. El token lo emite y lo valida
 * api/lib/datarocket_interacciones_enlace.php.
 *
 * QUE CUENTA COMO PENDIENTE
 * -------------------------
 * Una interaccion `sentido='entrante'` con `respondida IS NULL` Y
 * `descartada IS NULL`. Es exactamente el mismo criterio del filtro
 * `?pendiente=1` del ABM y de la tarjeta "Sin responder" del landing de
 * Datarocket (api/datarocket_indicadores.php): si los tres no dicen el mismo
 * numero, hay un bug.
 *
 * La descartada (migracion 20260828_1900) es la que NO hay que contestar: spam,
 * formulario en blanco, mensaje sin pregunta. Queda fuera de esta cola a
 * proposito — antes del tercer estado, esas generaban un recordatorio por hora,
 * para siempre, por algo que nadie iba a responder nunca. Es el escape que
 * tiene el vendedor cuando el aviso que le llega no amerita respuesta.
 *
 * A QUIEN LE AVISA
 * ----------------
 * Al `asignado` de la OPORTUNIDAD de la interaccion. La interaccion no tiene
 * dueño propio — el responsable de un evento es quien tiene asignado el negocio
 * (misma regla que la columna "Asignado" del listado). Por eso las pendientes
 * sin oportunidad, o con una oportunidad sin asignar, no tienen a quien avisarle:
 * se cuentan y se anotan como suceso 'alerta' para que no queden invisibles.
 *
 * COMO ENVIA
 * ----------
 * POST al microservicio api/v4/evolution/mensajes (documentado en
 * api/v4/evolution/mensajes.md), el mismo punto de entrada que usan las apps
 * externas del grupo. NO escribe `evolution_mensajes` a mano ni llama al lib
 * compartido: encola por HTTP y el despacho sigue siendo del motor
 * evolution_mensajes_enviar.php, con su rate limit y su gate manual.
 *
 * CADENCIA DEL RECORDATORIO
 * -------------------------
 * El aviso se repite EN CADA CORRIDA hasta que la consulta se atienda. No hay
 * ventana anti-repeticion: es deliberado. Una consulta sin responder no deja de
 * estarlo porque ya se aviso una vez, y el vendedor tiene que seguir viendola en
 * el celular hasta que la cierre.
 *
 * Consecuencia directa, y hay que tenerla presente: el UNICO freno de la
 * cadencia es el `cron_expr` de la tarea. Hoy es `0 9-19 * * 1-6` — una pasada
 * por hora en horario laboral, o sea un recordatorio por hora y por consulta
 * pendiente. Si alguien pone la tarea cada minuto, salen avisos cada minuto, y
 * una cuenta de WhatsApp que dispara ese volumen se gana un bloqueo. Para
 * espaciar o apretar los recordatorios se toca el cron; no hay ningun otro
 * parametro que lo module.
 *
 * Lo que si sigue acotando el VOLUMEN de una corrida es el tope por responsable
 * (ver mas abajo): limita cuantos avisos recibe una persona de una sentada, no
 * cada cuanto los recibe.
 *
 * Cada aviso igual queda etiquetado `datarocket_pendientes:<id de la
 * interaccion>` en `evolution_mensajes.tags`. Ya no se usa para frenar nada:
 * queda como rastro, para poder reconstruir despues que se aviso y cuando.
 *
 * TOPE POR RESPONSABLE
 * --------------------
 * `datarocket.interacciones.aviso.max_por_responsable` (default 10) corta
 * cuantos avisos recibe una misma persona por corrida. Es una proteccion contra
 * la avalancha —una cuenta de WhatsApp que dispara 200 mensajes seguidos se
 * gana un bloqueo— y contra el ruido. Lo que queda afuera se anota en el log y
 * como suceso: nunca se descarta en silencio, y la corrida siguiente lo levanta.
 *
 * SIMULACION
 * ----------
 * Con `AVISO_DRY_RUN=1` en el entorno hace todo el trabajo —consulta, agrupado
 * por responsable, armado del texto— pero no encola nada: escribe en el log los
 * mensajes que habria mandado y a que numero. Es la unica forma de probar el
 * job sin escribirle al celular de una persona real, y desde que no hay ventana
 * anti-repeticion es tambien la unica forma de correrlo sin que salga un aviso:
 *
 *   AVISO_DRY_RUN=1 php cloud/jobs/datarocket_interacciones_avisar_pendientes.php
 *
 * Se registra desde el Programador de tareas (tabla `tareas`) apuntando
 * `script` = "jobs/datarocket_interacciones_avisar_pendientes.php".
 */

require_once __DIR__ . '/_bootstrap.php';
require_once __DIR__ . '/../api/lib/parametros.php';
require_once __DIR__ . '/../api/lib/prospectos_normalizar.php';
require_once __DIR__ . '/../api/lib/datarocket_interacciones_enlace.php';

// Destino del encole. Interno a proposito: `api/` lo sirve el mismo contenedor
// en el 8114 (ver STACK.md), asi que el aviso no sale a internet para volver a
// entrar, y no depende ni del DNS publico ni del certificado.
const AVISO_URL_DEFAULT = 'http://localhost:8114/v4/evolution/mensajes';

// Proyecto / canal / remite del aviso. Fijos: es un mensaje del panel, no de
// una campaña — no hay nada que elegir por corrida.
const AVISO_PROYECTO_SLUG = 'databox';
const AVISO_CANAL_SLUG    = 'databox-bot';
const AVISO_REMITE        = '1163219578';

// Prefijo de la etiqueta con la que quedan marcados los avisos en
// `evolution_mensajes`. Se completa con el id de la interaccion
// (`datarocket_pendientes:1041`). Es rastro, no control: desde que se saco la
// ventana anti-repeticion nadie lo consulta para decidir si avisar — sirve para
// reconstruir despues cuantas veces y cuando se aviso por una consulta.
const AVISO_TAG_PREFIJO = 'datarocket_pendientes:';

// Prioridad del encole (1 a 5). 4 = Alta: es un aviso operativo con vencimiento
// —una consulta esperando—, va delante de las campañas pero detras de lo urgente.
const AVISO_PRIORIDAD = 4;

// Hasta cuantos caracteres del texto de la consulta entran en el WhatsApp. Lo
// que sobra se recorta con aviso explicito: `mensaje` es MEDIUMTEXT y un mail
// reenviado entero no se lee en un celular.
const AVISO_MAX_MENSAJE = 900;

// Techo de filas que levanta la consulta. Es una red de contencion, no un
// limite de negocio: si se alcanza, los avisos salen igual pero incompletos y
// queda anotado en el log y en sucesos.
const AVISO_MAX_FILAS = 5000;

$ORIGEN = 'cron/datarocket_interacciones_avisar_pendientes';

try {
    $pdo = db();

    // --- Configuracion --------------------------------------------------
    // Se siembra sola la primera corrida para que aparezca en Herramientas >
    // Editor de parametros sin que nadie tenga que crearla a mano.
    parametroAsegurar($pdo, 'datarocket.interacciones.aviso.url', AVISO_URL_DEFAULT,
        'URL del microservicio v4 al que el aviso de interacciones pendientes encola los WhatsApp.');
    parametroAsegurar($pdo, 'datarocket.interacciones.aviso.aplicacion', 'Kernel',
        'Nombre de la fila de `aplicaciones` de la que sale la apikey con la que el aviso de pendientes llama al microservicio v4.');
    // Ojo: NO hay parametro de ventana anti-repeticion. Se saco a proposito
    // (migracion 20260828_1800) — el aviso se repite en cada corrida hasta que
    // la consulta se atienda, y la cadencia la define el cron de la tarea.
    parametroAsegurar($pdo, 'datarocket.interacciones.aviso.max_por_responsable', '10',
        'Tope de avisos de pendientes que recibe una misma persona por corrida. Lo que queda afuera se anota y sale en la corrida siguiente.');

    // Base y vigencia del enlace a la ficha publica que va adentro del mensaje.
    // Los dos parametros los siembra el propio helper (ver
    // api/lib/datarocket_interacciones_enlace.php).
    $enlace = drIntEnlaceConfig($pdo);

    $url    = trim((string) parametroLeer($pdo, 'datarocket.interacciones.aviso.url', AVISO_URL_DEFAULT));
    $appNom = trim((string) parametroLeer($pdo, 'datarocket.interacciones.aviso.aplicacion', 'Kernel'));
    $tope   = (int) parametroLeer($pdo, 'datarocket.interacciones.aviso.max_por_responsable', '10');
    if ($tope < 1) $tope = 1;
    if ($url === '') throw new RuntimeException('El parametro datarocket.interacciones.aviso.url esta vacio.');

    // La apikey se resuelve desde `aplicaciones` y NO vive en el repo ni en una
    // migracion: es distinta en dev y en prod, y una migracion que la sembrara
    // filtraria el secreto de un entorno al otro.
    $st = $pdo->prepare("SELECT apikey FROM aplicaciones WHERE nombre = :n AND habilitada = '1' LIMIT 1");
    $st->execute([':n' => $appNom]);
    $apikey = (string) ($st->fetchColumn() ?: '');
    if ($apikey === '') {
        throw new RuntimeException(
            "No hay una aplicacion habilitada llamada \"{$appNom}\" de la que sacar la apikey. "
            . 'Corregi el parametro datarocket.interacciones.aviso.aplicacion o habilita esa fila en Aplicaciones.'
        );
    }

    // --- Pendientes -----------------------------------------------------
    // Una sola consulta con TODO lo que va adentro del mensaje. Los JOIN son
    // todos LEFT: el aviso tiene que salir aunque al prospecto le falte la
    // mitad de la ficha o la oportunidad no tenga etapa — el armador omite las
    // lineas vacias. `usuarios` en LEFT ademas deja pasar las pendientes
    // huerfanas (sin oportunidad o sin asignado) para poder contarlas.
    $limite = AVISO_MAX_FILAS;
    $filas = $pdo->query("
        SELECT i.id, i.fecha, i.canal, i.asunto, i.mensaje,
               TIMESTAMPDIFF(MINUTE, i.fecha, NOW()) AS espera_minutos,
               o.id        AS oportunidad_id,
               o.producto  AS oportunidad_producto,
               o.monto     AS oportunidad_monto,
               o.moneda    AS oportunidad_moneda,
               o.asignado  AS usuario_id,
               u.nombre    AS usuario_nombre,
               u.celular   AS usuario_celular,
               u.estado    AS usuario_estado,
               p.id             AS prospecto_id,
               p.nombre         AS prospecto_nombre,
               p.empresa_nombre AS prospecto_empresa,
               p.whatsapp       AS prospecto_whatsapp,
               p.celular        AS prospecto_celular,
               p.telefono       AS prospecto_telefono,
               p.correo         AS prospecto_correo,
               p.web            AS prospecto_web,
               p.domicilio      AS prospecto_domicilio,
               p.ciudad         AS prospecto_ciudad,
               p.ubicacion      AS prospecto_ubicacion,
               loc.nombre       AS prospecto_localidad,
               prov.nombre      AS prospecto_provincia,
               pai.nombre       AS prospecto_pais,
               pr.nombre   AS proyecto_nombre,
               em.nombre   AS embudo_nombre,
               et.nombre   AS etapa_nombre
          FROM datarocket_interacciones i
          LEFT JOIN datarocket_oportunidades o  ON o.id  = i.oportunidad_id
          LEFT JOIN usuarios                 u  ON u.id  = o.asignado
          LEFT JOIN datarocket_prospectos    p  ON p.id  = i.prospecto_id
          LEFT JOIN proyectos                pr ON pr.id = o.proyecto_id
          LEFT JOIN datarocket_embudos       em ON em.id = o.embudo_id
          LEFT JOIN datarocket_etapas        et ON et.id = o.etapa_id
          -- Geo resuelta a nombre en el mismo SELECT: el mensaje lo lee una
          -- persona en un celular, no le sirve `provincia_id = 12`.
          LEFT JOIN localidades              loc  ON loc.id  = p.localidad_id
          LEFT JOIN provincias               prov ON prov.id = p.provincia_id
          LEFT JOIN paises                   pai  ON pai.id  = p.pais_id
         WHERE i.sentido = 'entrante' AND i.respondida IS NULL
                                      AND i.descartada IS NULL
         ORDER BY i.fecha ASC
         LIMIT {$limite}
    ")->fetchAll();

    if (count($filas) >= AVISO_MAX_FILAS) {
        anotarLog('AVISO: se alcanzo el tope de ' . AVISO_MAX_FILAS . ' pendientes; esta corrida no las cubre todas.');
        registrarSuceso($pdo, 'datarocket_interacciones', 'alerta',
            'El aviso de pendientes alcanzo el tope de ' . AVISO_MAX_FILAS . ' filas.');
    }

    if (!$filas) {
        anotarLog('No hay interacciones entrantes sin responder.');
        marcarEjecucionOk('Sin pendientes: no se envio ningun aviso.');
        return;
    }

    // Agrupado por responsable, para poder aplicarle el tope a cada uno. Las
    // que no tienen dueño van todas juntas al balde `0`, que no se avisa pero
    // si se reporta. El orden de `filas` (mas vieja primero) se conserva dentro
    // de cada grupo: si el tope recorta, recorta lo mas nuevo.
    $porUsuario = [];
    foreach ($filas as $f) {
        $uid = (int) ($f['usuario_id'] ?? 0);
        if (!isset($porUsuario[$uid])) {
            $porUsuario[$uid] = [
                'nombre'  => (string) ($f['usuario_nombre']  ?? ''),
                'celular' => (string) ($f['usuario_celular'] ?? ''),
                'estado'  => (string) ($f['usuario_estado']  ?? ''),
                'filas'   => [],
            ];
        }
        $porUsuario[$uid]['filas'][] = $f;
    }

    $huerfanas = isset($porUsuario[0]) ? count($porUsuario[0]['filas']) : 0;
    unset($porUsuario[0]);
    if ($huerfanas > 0) {
        anotarLog("{$huerfanas} pendientes sin responsable asignado: nadie recibe aviso por ellas.");
        registrarSuceso($pdo, 'datarocket_interacciones', 'alerta',
            "{$huerfanas} interacciones entrantes sin responder no tienen responsable asignado: no se avisa a nadie.");
    }

    // --- Un aviso por consulta pendiente --------------------------------
    $dryRun = getenv('AVISO_DRY_RUN') === '1';
    if ($dryRun) anotarLog('MODO SIMULACION (AVISO_DRY_RUN=1): no se encola ningun mensaje.');
    $enviados   = 0;
    $simulados  = 0;
    $omitidos   = 0;
    $fallidos   = 0;
    $diferidos  = 0;

    foreach ($porUsuario as $uid => $u) {
        $nombre = $u['nombre'] !== '' ? $u['nombre'] : "usuario #{$uid}";
        $cuenta = count($u['filas']);

        // Deshabilitado: `usuarios.estado` es '1' habilitado / '0' deshabilitado.
        // Alguien que ya no opera el panel no tiene por que recibir avisos.
        if ($u['estado'] !== '1') {
            anotarLog("{$nombre}: {$cuenta} pendientes, pero el usuario esta deshabilitado. Se omite.");
            $omitidos += $cuenta;
            continue;
        }

        // El destino viaja como los 10 digitos nacionales, igual que el padron
        // de las campañas: el prefijo del pais lo pone el sender segun el canal
        // (normalizarDestinoEvolution en cloud/api/lib/mensajes_enviar.php).
        $destino = prospectoNormalizarTelefono($u['celular']);
        if ($destino === null || !prospectoTelefonoEsValido($destino)) {
            anotarLog("{$nombre}: {$cuenta} pendientes, pero no tiene un celular valido cargado. Se omite.");
            registrarSuceso($pdo, 'datarocket_interacciones', 'alerta',
                "No se pudo avisar a {$nombre} (#{$uid}) por sus {$cuenta} interacciones pendientes: celular invalido o vacio.");
            $omitidos += $cuenta;
            continue;
        }

        // Contadores del responsable en curso. `$diferidosU` es aparte del
        // global: sin el, un responsable que mando justo `$tope` avisos sin
        // dejar nada afuera se comia el mensaje de tope de OTRO responsable.
        $mandados    = 0;
        $diferidosU  = 0;
        foreach ($u['filas'] as $f) {
            $intId = (int) $f['id'];

            // Tope por responsable: no se descarta, se posterga. Los que no
            // entran hoy siguen pendientes y los levanta la proxima corrida.
            if ($mandados >= $tope) {
                $diferidos++;
                $diferidosU++;
                continue;
            }

            // Un token por consulta y por corrida: el vencimiento se cuenta
            // desde que se emite, asi que el recordatorio de mañana lleva un
            // enlace fresco aunque el de hoy ya este por caducar.
            $ficha  = drIntEnlaceUrl($enlace['base'], $intId, $enlace['dias']);
            $cuerpo = drAvisoArmarCuerpo($f, $ficha);

            if ($dryRun) {
                $simulados++;
                $mandados++;
                anotarLog("[simulacion] {$nombre} <- {$destino} (interaccion #{$intId}). Mensaje:\n{$cuerpo}");
                continue;
            }

            try {
                $msgId = drAvisoEncolar($url, $apikey, $destino, $nombre, $cuerpo, $intId);
                $enviados++;
                $mandados++;
                anotarLog("{$nombre} <- {$destino}: interaccion #{$intId} -> WhatsApp #{$msgId} encolado.");
            } catch (Throwable $e) {
                $fallidos++;
                anotarLog("{$nombre}: fallo el encole del aviso de la interaccion #{$intId} — " . $e->getMessage());
                registrarSuceso($pdo, 'datarocket_interacciones', 'error',
                    "Fallo el aviso de la interaccion #{$intId} a {$nombre} (#{$uid}): " . $e->getMessage());
            }
        }

        if ($mandados > 0 && !$dryRun) {
            registrarSuceso($pdo, 'datarocket_interacciones', 'info',
                "Encolados {$mandados} avisos de consultas pendientes para {$nombre} (#{$uid}).");
        }
        if ($diferidosU > 0) {
            anotarLog("{$nombre}: alcanzo el tope de {$tope} avisos por corrida; quedan {$diferidosU} para la proxima.");
            registrarSuceso($pdo, 'datarocket_interacciones', 'alerta',
                "{$nombre} (#{$uid}) tiene mas consultas pendientes que el tope por corrida: se avisaron {$tope} y {$diferidosU} quedan para la proxima.");
        }
    }

    $resumen = count($filas) . ' pendientes, ' . count($porUsuario) . ' responsables'
             . ($dryRun ? ", {$simulados} avisos simulados (no se encolo nada)"
                        : ", {$enviados} avisos encolados")
             . ", {$omitidos} sin poder avisar"
             . ", {$diferidos} diferidos, {$fallidos} fallidos"
             . ($huerfanas > 0 ? ", {$huerfanas} sin responsable" : '');
    anotarLog($resumen);
    marcarEjecucionOk($resumen);
} catch (Throwable $e) {
    registrarSuceso(db(), 'datarocket_interacciones', 'error',
        'Falla del cron de aviso de pendientes: ' . $e->getMessage());
    marcarEjecucionError($e);
    throw $e;
}

// ----------------------------------------------------------------------------
// Helpers
// ----------------------------------------------------------------------------

/** Minutos -> "45 min" / "3 h" / "2 d". Espejo de drIntFmtMinutos() del panel. */
function drAvisoFmtEspera(?int $min): string {
    $n = max(0, (int) $min);
    if ($n < 60)   return "{$n} min";
    if ($n < 1440) return intdiv($n, 60) . ' h';
    return intdiv($n, 1440) . ' d';
}

/**
 * Minutos -> "hace 12 horas, 49 minutos". Version larga para el renglon de
 * Ingreso del mensaje: ahi la precision se agradece —no es lo mismo "hace 1
 * dia" que "hace 1 dia, 20 horas"— mientras que en el listado del panel alcanza
 * con la corta. Corta en dos unidades: el tercer termino no aporta nada.
 */
function drAvisoHace(?int $min): string {
    $n = max(0, (int) $min);
    if ($n < 1) return 'recién';

    $dias  = intdiv($n, 1440);
    $horas = intdiv($n % 1440, 60);
    $mins  = $n % 60;

    $plural = fn(int $v, string $sing, string $plur) => $v . ' ' . ($v === 1 ? $sing : $plur);
    $partes = [];
    if ($dias  > 0) $partes[] = $plural($dias,  'día',    'días');
    if ($horas > 0) $partes[] = $plural($horas, 'hora',   'horas');
    // Los minutos solo si no hay dias: "3 días, 4 horas" ya es suficientemente
    // preciso, y "3 días, 4 horas, 12 minutos" es ruido.
    if ($mins  > 0 && $dias === 0) $partes[] = $plural($mins, 'minuto', 'minutos');

    return 'hace ' . implode(', ', array_slice($partes, 0, 2));
}

/** Etiqueta legible del canal por el que entro la consulta. */
function drAvisoCanalTexto(?string $canal): string {
    $map = [
        'correo'     => 'Correo',
        'whatsapp'   => 'WhatsApp',
        'telegram'   => 'Telegram',
        'sms'        => 'SMS',
        'web'        => 'Web',
        'telefono'   => 'Teléfono',
        'presencial' => 'Presencial',
    ];
    $c = (string) ($canal ?? '');
    return $map[$c] ?? ($c !== '' ? ucfirst($c) : '');
}

/**
 * Texto del WhatsApp de UNA consulta pendiente. Se basta a si mismo: con esto
 * en la mano el responsable puede contestarle al prospecto sin abrir el panel.
 *
 * FORMATO
 * -------
 * Sigue el modelo del aviso "PROSPECTO ASIGNADO" del sistema legacy —mismos
 * renglones y mismo orden, que es lo que los vendedores ya saben leer de un
 * vistazo— traducido al esquema actual:
 *
 *   Ingreso      -> `datarocket_interacciones`.`fecha` (cuando entro la consulta)
 *                   + cuanto lleva esperando
 *   Organizacion -> `datarocket_prospectos`.`empresa_nombre`
 *   Contacto     -> `datarocket_prospectos`.`nombre`
 *   Comentarios  -> `datarocket_interacciones`.`mensaje` (lo que pregunto)
 *   Estado       -> nombre de la etapa de la oportunidad. El `estado` legacy
 *                   (1 Esperando / 2 Atendido / 3 Despachado) se dropeo en la
 *                   migracion 20260817_2900 y quedo reemplazado por `etapa_id`.
 *
 * UNICA LICENCIA sobre el modelo: los renglones sin dato NO se imprimen. El
 * original mostraba "Producto:", "Web:", "Pais:" y demas vacios; en un celular
 * eso es media pantalla de nada entre el nombre y la consulta. Si se prefiere
 * el formato fijo, alcanza con sacar los `if` de cada linea.
 *
 * `$ficha` es la URL firmada de cloud/datarocket/interacciones/, que abre esta
 * misma ficha en el navegador y ofrece el boton para marcarla atendida sin
 * pasar por el login del panel. Va al pie, despues del texto de la consulta:
 * primero se lee que preguntaron, despues se decide que hacer.
 */
function drAvisoArmarCuerpo(array $f, string $ficha = ''): string {
    $val = function ($k) use ($f): string { return trim((string) ($f[$k] ?? '')); };
    $L = [];
    // `linea()` es lo que implementa "vacio no se imprime": un solo lugar donde
    // cambiarlo si se quiere volver al formato fijo del legacy.
    $linea = function (string $label, string $valor) use (&$L): void {
        if ($valor !== '') $L[] = $label . ': ' . $valor;
    };

    $L[] = '*PROSPECTO PENDIENTE DE ATENCIÓN*';

    // Ingreso: cuando entro la consulta y cuanto lleva esperando. La fecha va
    // completa (no "13/08 16:21") para que sirva de referencia exacta al
    // buscarla en el panel.
    $fecha  = $val('fecha');
    $espera = drAvisoHace(isset($f['espera_minutos']) ? (int) $f['espera_minutos'] : null);
    if ($fecha !== '') $L[] = "Ingreso: {$fecha} ({$espera})";

    $linea('Proyecto',     $val('proyecto_nombre'));
    $linea('Producto',     $val('oportunidad_producto'));
    $linea('Asunto',       $val('asunto'));
    $linea('Organizacion', $val('prospecto_empresa'));
    $linea('Contacto',     $val('prospecto_nombre'));

    // El celular es por donde se le contesta. `whatsapp` suele estar vacio y se
    // cae al celular — mismo criterio que el padron de las campañas
    // (DRCA_DESTINO_SQL). El renglon aparte solo si son numeros distintos.
    $celular  = $val('prospecto_celular') !== '' ? $val('prospecto_celular') : $val('prospecto_whatsapp');
    $whatsapp = $val('prospecto_whatsapp');
    $linea('Celular',  $celular);
    if ($whatsapp !== '' && $whatsapp !== $celular) $linea('WhatsApp', $whatsapp);
    $linea('Telefono', $val('prospecto_telefono'));
    $linea('Correo',   $val('prospecto_correo'));

    $linea('Web',        $val('prospecto_web'));
    $linea('Domicilio',  $val('prospecto_domicilio'));
    $linea('Ciudad',     $val('prospecto_ciudad'));
    $linea('Localidad',  $val('prospecto_localidad'));
    $linea('Provincia',  $val('prospecto_provincia'));
    $linea('Pais',       $val('prospecto_pais'));
    $linea('Ubicacion',  $val('prospecto_ubicacion'));

    // Comentarios = el texto de la consulta. Es lo que se viene a contestar, va
    // ultimo y con el recorte explicito si es largo.
    $mensaje = $val('mensaje');
    if ($mensaje !== '' && mb_strlen($mensaje) > AVISO_MAX_MENSAJE) {
        // Que se note que hay mas texto en el panel, en vez de dejar la frase
        // cortada al medio como si eso fuera todo.
        $mensaje = mb_substr($mensaje, 0, AVISO_MAX_MENSAJE) . '… (recortado, el texto completo está en el panel)';
    }
    $linea('Comentarios', $mensaje);
    $linea('Estado',      $val('etapa_nombre'));

    // Enlace a la ficha. Va en su propio renglon y sin nada pegado alrededor:
    // WhatsApp autolinkea por espacios, y un parentesis o un punto final
    // adosado se comen adentro del link y lo rompen.
    if ($ficha !== '') {
        $L[] = '';
        $L[] = '👉 Ver la ficha y marcarla como atendida:';
        $L[] = $ficha;
    }

    // Pie: el canal por donde entro y con que fila del panel se corresponde
    // esto. Sin el id no hay forma de encontrarla si el enlace no sirve.
    //
    // NO lleva instrucciones de que hacer si el enlace vencio: el boton esta a
    // un toque arriba y el pie es letra chica que nadie lee. Si algun dia el
    // enlace falla, el vendedor va a preguntar, no a leer el pie.
    $canal = drAvisoCanalTexto($f['canal'] ?? null);
    $L[] = '';
    $L[] = '_' . ($canal !== '' ? "Entró por {$canal}. " : '')
         . 'Interacción #' . (int) $f['id']
         . ($val('oportunidad_id') !== '' ? ' / oportunidad #' . $val('oportunidad_id') : '')
         . '._';
    return implode("\n", $L);
}

/**
 * Encola el WhatsApp contra el microservicio v4 y devuelve el id del mensaje.
 * Levanta RuntimeException con el detalle si el microservicio no acepta el alta
 * — el llamador la anota y sigue con la proxima consulta.
 */
function drAvisoEncolar(string $url, string $apikey, string $destino, string $destinatario,
                        string $cuerpo, int $interaccionId): int {
    $payload = [
        'proyecto_slug' => AVISO_PROYECTO_SLUG,
        'canal_slug'    => AVISO_CANAL_SLUG,
        'remite'        => AVISO_REMITE,
        'destino'       => $destino,
        'destinatario'  => $destinatario,
        'cuerpo'        => $cuerpo,
        'prioridad'     => AVISO_PRIORIDAD,
        // La etiqueta es tambien el registro anti-repeticion (drAvisoYaAvisado).
        'tags'          => AVISO_TAG_PREFIJO . $interaccionId,
    ];

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => json_encode($payload, JSON_UNESCAPED_UNICODE),
        CURLOPT_HTTPHEADER     => [
            'Content-Type: application/json; charset=utf-8',
            'Authorization: Bearer ' . $apikey,
        ],
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CONNECTTIMEOUT => 5,
        CURLOPT_TIMEOUT        => 20,
    ]);
    $body = curl_exec($ch);
    $err  = curl_error($ch);
    $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);

    if ($body === false) throw new RuntimeException("no se pudo llamar al microservicio ({$err})");

    $j = json_decode((string) $body, true);
    if (!is_array($j) || empty($j['ok'])) {
        $detalle = is_array($j) && isset($j['error']) ? (string) $j['error'] : substr((string) $body, 0, 200);
        throw new RuntimeException("HTTP {$code} — {$detalle}");
    }
    return (int) ($j['data']['id'] ?? 0);
}
