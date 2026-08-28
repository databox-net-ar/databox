<?php
/**
 * cloud/datarocket/interacciones/index.php
 * Ficha PUBLICA de una consulta pendiente + boton para marcarla como atendida.
 *
 * https://cloud.databox.net.ar/datarocket/interacciones/?t=<token firmado>
 *
 * QUE ES
 * ------
 * El destino del enlace que viaja en el WhatsApp del aviso de pendientes
 * (jobs/datarocket_interacciones_avisar_pendientes.php). El responsable recibe
 * el aviso en el celular, toca el link, ve la ficha completa del prospecto —
 * quien es, como contactarlo, que preguntó, de que negocio se trata — y con un
 * solo boton deja la consulta cerrada. Sin login, sin buscarla en el ABM.
 *
 * POR QUE NO PIDE AUTENTICACION
 * -----------------------------
 * Porque se abre desde WhatsApp en un telefono, donde no hay sesion del panel.
 * Pedir login ahi convierte "un toque" en "acordarse la clave, tipearla en el
 * navegador embebido y despues encontrar la fila", que es exactamente el
 * trabajo que este enlace existe para evitar.
 *
 * Lo que reemplaza a la sesion es el token firmado del enlace
 * (api/lib/datarocket_interacciones_enlace.php): HMAC con APP_KEY_CLOUD sobre
 * el id de la interaccion y un vencimiento. No se puede fabricar sin la clave y
 * cada uno abre UNA sola interaccion — no hay forma de recorrer el padron
 * cambiando un numero en la URL. Es el mismo modelo de los links de baja de las
 * campañas: la URL ES la credencial.
 *
 * Consecuencia a tener presente: quien tenga acceso a ese chat de WhatsApp
 * tiene acceso a esta ficha mientras el token no venza. Por eso vencen (30 dias
 * por defecto), la pagina manda `noindex` y `no-store`, y no se linkea desde
 * ningun lado del panel.
 *
 * POR QUE ESTA RENDERIZADA EN EL SERVIDOR
 * ---------------------------------------
 * El resto de cloud es una SPA que pinta todo desde app.js (STACK.md §4). Esta
 * pagina NO: es HTML armado en PHP, y el boton es un `<form method="post">`
 * comun con patron POST-Redirect-GET. La razon es el contexto de uso — el
 * navegador embebido de WhatsApp, en la calle, con señal mala. Un fetch que
 * falla en silencio ahi significa una consulta que quedo sin cerrar y nadie se
 * entera. Un form nativo funciona aunque el JS no cargue.
 *
 * Corolario importante: el GET NUNCA modifica nada. Los previsualizadores de
 * enlaces (el propio WhatsApp arma la tarjeta del link cuando se manda el
 * mensaje), los antivirus y los escaneres corporativos hacen GET automatico. Si
 * marcar dependiera del GET, cada aviso se marcaria como atendido solo.
 *
 * QUE HACEN LOS BOTONES
 * ---------------------
 * Son dos salidas, porque no toda consulta se contesta.
 *
 * "Marcar como atendido" (primaria):
 *   1. `datarocket_interacciones.respondida = NOW()` — es lo que saca la
 *      consulta de las pendientes y corta los recordatorios. Mismo efecto que
 *      "Marcar como respondida" del menu contextual del ABM.
 *   2. Si la interaccion cuelga de una oportunidad todavia sin `atendido`, le
 *      sella `atendido` con el `asignado` de esa oportunidad. Esa columna es
 *      "quien la agarro": el responsable acaba de demostrar que la agarro.
 *
 * "Descartar" (secundaria, migracion 20260828_1900):
 *   `descartada = NOW()`, para spam / formulario en blanco / mensaje sin
 *   pregunta. Tambien corta los recordatorios, pero NO dice que alguien
 *   contesto: sin esta opcion, la unica forma de callar el aviso era marcar
 *   como atendida una consulta que nadie atendio — mentira que ademas se
 *   colaba en el promedio de demora del panel. No toca `atendido`: descartar
 *   spam no es haber agarrado el negocio.
 *
 * Los dos estados son excluyentes y las tres escrituras son idempotentes
 * (`WHERE ... IS NULL` sobre las dos columnas): recargar la pagina, tocar dos
 * veces o llegar tarde con el otro boton no pisa nada ya sellado.
 *
 * Queda constancia en `sucesos` con origen `datarocket_ficha` — es la unica
 * traza de que la accion vino de aca y no del panel, porque `respondida` es una
 * fecha sola y no guarda autor.
 */

require_once __DIR__ . '/../../api/db.php';
require_once __DIR__ . '/../../api/lib/sucesos.php';
require_once __DIR__ . '/../../api/lib/datarocket_interacciones_enlace.php';

// La pagina muestra datos personales de un tercero (nombre, telefono, correo,
// domicilio). Nada de cache compartida, nada de indexar, nada de filtrar la URL
// —que ES la credencial— por Referer a los sitios que se abran desde aca.
header('Content-Type: text/html; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, private');
header('Pragma: no-cache');
header('X-Robots-Tag: noindex, nofollow, noarchive');
header('Referrer-Policy: no-referrer');

const DRF_ORIGEN     = 'datarocket_ficha';
const DRF_HIST_LIMIT = 12;   // interacciones previas del prospecto que se listan

// Prefijo internacional para armar los links `wa.me` a partir de un numero
// nacional de 10 digitos. Argentina, igual que el resto del stack de contacto
// (ver CONTACTO_PREFIJOS_VALIDOS en api/lib/prospectos_normalizar.php): un
// numero guardado con mas digitos se asume que ya trae el suyo y se respeta.
const DRF_PREFIJO_WA = '549';

$token = (string) ($_GET['t'] ?? '');
// `hecho` distingue "vengo de tocar el boton" de "abri el enlace y ya estaba
// resuelta": el banner de confirmacion es distinto. Dos valores porque son dos
// acciones — '1' atendida, 'descartada' descartada.
$hecho          = (string) ($_GET['hecho'] ?? '') === '1';
$hechoDescartada = (string) ($_GET['hecho'] ?? '') === 'descartada';

$aviso  = null;   // ['tipo','icono','titulo','detalle'] — pantalla sin ficha
$f      = null;   // fila de la interaccion + prospecto + oportunidad
$previas = [];    // historial del prospecto
$etiquetas = [];

try {
    $tk = drIntEnlaceVerificar($token);

    if ($tk === null) {
        http_response_code(403);
        $aviso = ['error', '🔒', 'Enlace no válido',
                  'El enlace que abriste no es correcto o está incompleto. Si lo copiaste a mano '
                  . 'desde WhatsApp, probá tocándolo directamente en el mensaje.'];
    } elseif ($tk['vencido']) {
        http_response_code(410);
        $aviso = ['warn', '⌛', 'El enlace venció',
                  'Por seguridad estos enlaces caducan. Entrá al panel, buscá la consulta en '
                  . 'Datarocket › Interacciones y marcala como respondida desde ahí.'];
    } else {
        $pdo = db();
        $f   = drFichaCargar($pdo, (int) $tk['id']);

        if ($f === null) {
            http_response_code(404);
            $aviso = ['error', '🗂️', 'La consulta ya no existe',
                      'La interacción a la que apunta este enlace fue eliminada del panel.'];
        } else {
            // Estado mutable -> solo por POST. Ver la nota sobre los
            // previsualizadores de enlaces en la cabecera del archivo.
            $accion = (string) ($_POST['accion'] ?? '');
            if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST'
                && ($accion === 'atender' || $accion === 'descartar')) {
                if ($accion === 'atender') drFichaAtender($pdo, $f);
                else                       drFichaDescartar($pdo, $f);
                // POST-Redirect-GET: sin esto, un "actualizar" del navegador
                // reenvia el form y el celular pregunta si quiere reenviar los
                // datos, que es la forma mas rapida de que alguien piense que
                // no funciono.
                $self = strtok((string) ($_SERVER['REQUEST_URI'] ?? ''), '?') ?: './';
                header('Location: ' . $self . '?t=' . rawurlencode($token)
                       . '&hecho=' . ($accion === 'atender' ? '1' : 'descartada'), true, 303);
                exit;
            }

            [$previas, $etiquetas] = drFichaContexto($pdo, $f);
        }
    }
} catch (Throwable $e) {
    http_response_code(500);
    $aviso = ['error', '⚠️', 'No pudimos abrir la ficha',
              'Hubo un problema al consultar los datos. Probá de nuevo en un momento.'];
    // El detalle real va al log del panel, nunca a la pantalla: esta pagina la
    // ve gente fuera de la sesion y un stack trace ahi es informacion regalada.
    try {
        registrarSuceso(db(), DRF_ORIGEN, 'error',
            'Falla al renderizar la ficha publica de interaccion: ' . $e->getMessage());
    } catch (Throwable $_) { /* si ni el log anda, la pantalla ya dice lo suyo */ }
}

// ----------------------------------------------------------------------------
// Datos
// ----------------------------------------------------------------------------

/**
 * La interaccion con TODO lo que la ficha muestra, en una sola consulta.
 *
 * Todos los JOIN son LEFT y por el mismo motivo que en el job del aviso: la
 * ficha tiene que abrir aunque al prospecto le falte media carga o la
 * oportunidad no tenga etapa. Lo que falta se muestra como "—", no rompe.
 */
function drFichaCargar(PDO $pdo, int $id): ?array {
    $st = $pdo->prepare("
        SELECT i.id, i.fecha, i.canal, i.sentido, i.asunto, i.mensaje, i.respondida, i.descartada,
               i.prospecto_id, i.oportunidad_id,
               TIMESTAMPDIFF(MINUTE, i.fecha, NOW())         AS espera_minutos,
               TIMESTAMPDIFF(MINUTE, i.fecha, i.respondida)  AS respuesta_minutos,

               p.uuid, p.tipo, p.nombre AS p_nombre,
               p.empresa_nombre, p.empresa_rubro, p.empresa_actividad, p.empresa_cargo,
               p.persona_nombre, p.persona_genero, p.persona_nacimiento, p.persona_dni,
               p.celular, p.whatsapp, p.telefono, p.correo, p.web,
               p.facebook, p.instagram, p.tiktok,
               p.domicilio, p.ciudad, p.ubicacion,
               p.comentarios, p.extraccion_url, p.extraccion_autor, p.registrado,
               loc.nombre  AS localidad_nombre,
               prov.nombre AS provincia_nombre,
               pai.nombre  AS pais_nombre,

               o.id AS o_id, o.ingreso AS o_ingreso, o.producto, o.monto, o.moneda,
               o.cierre_esperado, o.calificacion, o.asunto AS o_asunto,
               o.asignado, o.atendido, o.aplazado,
               pr.nombre AS proyecto_nombre,
               em.nombre AS embudo_nombre,
               et.nombre AS etapa_nombre,
               ua.nombre AS asignado_nombre,
               ut.nombre AS atendido_nombre
          FROM datarocket_interacciones i
          LEFT JOIN datarocket_prospectos    p    ON p.id    = i.prospecto_id
          LEFT JOIN datarocket_oportunidades o    ON o.id    = i.oportunidad_id
          LEFT JOIN proyectos                pr   ON pr.id   = o.proyecto_id
          LEFT JOIN datarocket_embudos       em   ON em.id   = o.embudo_id
          LEFT JOIN datarocket_etapas        et   ON et.id   = o.etapa_id
          LEFT JOIN usuarios                 ua   ON ua.id   = o.asignado
          LEFT JOIN usuarios                 ut   ON ut.id   = o.atendido
          -- Geo resuelta a nombre acá y no en el cliente: la ficha la lee una
          -- persona, no le sirve `provincia_id = 12`.
          LEFT JOIN localidades              loc  ON loc.id  = p.localidad_id
          LEFT JOIN provincias               prov ON prov.id = p.provincia_id
          LEFT JOIN paises                   pai  ON pai.id  = p.pais_id
         WHERE i.id = :id
         LIMIT 1
    ");
    $st->execute([':id' => $id]);
    $row = $st->fetch();
    return $row ?: null;
}

/** Historial previo del prospecto + sus etiquetas. Devuelve [previas, etiquetas]. */
function drFichaContexto(PDO $pdo, array $f): array {
    $pid = (int) ($f['prospecto_id'] ?? 0);
    if ($pid <= 0) return [[], []];

    $lim = DRF_HIST_LIMIT;
    $st = $pdo->prepare("
        SELECT id, fecha, sentido, canal, asunto, mensaje, respondida
          FROM datarocket_interacciones
         WHERE prospecto_id = :p AND id <> :id
         ORDER BY fecha DESC, id DESC
         LIMIT {$lim}
    ");
    $st->execute([':p' => $pid, ':id' => (int) $f['id']]);
    $previas = $st->fetchAll();

    $se = $pdo->prepare("
        SELECT e.nombre
          FROM datarocket_prospectos_etiquetas pe
          JOIN datarocket_etiquetas e ON e.id = pe.etiqueta_id
         WHERE pe.prospecto_id = :p
         ORDER BY e.nombre
    ");
    $se->execute([':p' => $pid]);
    $etiquetas = array_map(static fn($r) => (string) $r['nombre'], $se->fetchAll());

    return [$previas, $etiquetas];
}

/**
 * Marca la consulta como atendida. Ver la cabecera del archivo para el detalle
 * de las dos escrituras; las dos llevan su `WHERE ... IS NULL` para ser
 * idempotentes, asi que tocar el boton dos veces no pisa nada.
 */
function drFichaAtender(PDO $pdo, array $f): void {
    $id = (int) $f['id'];

    // Solo las entrantes esperan respuesta — misma regla que handleResponder()
    // del ABM (api/datarocketinteracciones.php).
    if ((string) ($f['sentido'] ?? '') !== 'entrante') return;

    // El `descartada IS NULL` es la guarda de exclusion: una consulta ya
    // descartada no se puede marcar atendida por atras — misma regla que el 409
    // de handleResponder() en el ABM.
    $upd = $pdo->prepare("
        UPDATE datarocket_interacciones
           SET respondida = NOW()
         WHERE id = :id AND sentido = 'entrante'
           AND respondida IS NULL AND descartada IS NULL
    ");
    $upd->execute([':id' => $id]);
    if ($upd->rowCount() === 0) return;   // ya estaba marcada: no hay nada que anotar

    $quien = trim((string) ($f['asignado_nombre'] ?? ''));
    $opoId = (int) ($f['o_id'] ?? 0);
    $uid   = (int) ($f['asignado'] ?? 0);

    // `atendido` = quien agarro la oportunidad. No se pisa si ya tiene alguien:
    // el primero que la atendio es el dato que vale.
    if ($opoId > 0 && $uid > 0) {
        $uo = $pdo->prepare("
            UPDATE datarocket_oportunidades
               SET atendido = :u, actualizado = NOW()
             WHERE id = :id AND (atendido IS NULL OR atendido = 0)
        ");
        $uo->execute([':u' => $uid, ':id' => $opoId]);
    }

    registrarSuceso($pdo, DRF_ORIGEN, 'info',
        "Interacción #{$id} marcada como atendida desde el enlace público"
        . ($quien !== '' ? " (responsable: {$quien}" . ($uid > 0 ? " #{$uid}" : '') . ')' : '')
        . ($opoId > 0 ? " — oportunidad #{$opoId}" : '') . '.');
}

/**
 * Descarta la consulta: el tercer estado (migracion 20260828_1900), para lo que
 * NO hay que contestar — spam, formulario en blanco, mensaje sin ninguna
 * pregunta.
 *
 * Es la salida honesta del vendedor cuando el aviso que le llego no amerita
 * respuesta. Sin ella, la unica forma de dejar de recibir el recordatorio por
 * hora era marcarla como atendida, o sea mentir sobre trabajo que no se hizo y
 * ensuciar el promedio de demora del panel con consultas que nadie contesto.
 *
 * A diferencia de atender, NO toca `atendido` de la oportunidad: descartar spam
 * no es haber agarrado el negocio.
 *
 * Idempotente por el `WHERE ... IS NULL`, igual que drFichaAtender(). El
 * `respondida IS NULL` ademas hace de guarda de exclusion: una consulta ya
 * respondida no se puede descartar por atras — misma regla que el 409 de
 * handleDescartar() en el ABM.
 */
function drFichaDescartar(PDO $pdo, array $f): void {
    $id = (int) $f['id'];

    if ((string) ($f['sentido'] ?? '') !== 'entrante') return;

    $upd = $pdo->prepare("
        UPDATE datarocket_interacciones
           SET descartada = NOW()
         WHERE id = :id AND sentido = 'entrante'
           AND respondida IS NULL AND descartada IS NULL
    ");
    $upd->execute([':id' => $id]);
    if ($upd->rowCount() === 0) return;   // ya resuelta: no hay nada que anotar

    $quien = trim((string) ($f['asignado_nombre'] ?? ''));
    $uid   = (int) ($f['asignado'] ?? 0);
    $opoId = (int) ($f['o_id'] ?? 0);

    registrarSuceso($pdo, DRF_ORIGEN, 'info',
        "Interacción #{$id} descartada desde el enlace público"
        . ($quien !== '' ? " (responsable: {$quien}" . ($uid > 0 ? " #{$uid}" : '') . ')' : '')
        . ($opoId > 0 ? " — oportunidad #{$opoId}" : '') . '.');
}

// ----------------------------------------------------------------------------
// Formateo
// ----------------------------------------------------------------------------

function h(?string $s): string {
    return htmlspecialchars((string) $s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

/** Valor de columna ya trimeado; '' cuando es NULL. */
function v(array $f, string $k): string {
    return trim((string) ($f[$k] ?? ''));
}

/** 'AAAA-MM-DD HH:MM:SS' -> '13/08/2026 16:21'. Devuelve '' si no parsea. */
function drFechaCorta(?string $s, bool $conHora = true): string {
    $s = trim((string) $s);
    if ($s === '' || str_starts_with($s, '0000')) return '';
    $ts = strtotime($s);
    if ($ts === false) return '';
    return date($conHora ? 'd/m/Y H:i' : 'd/m/Y', $ts);
}

/**
 * Minutos -> "hace 12 horas, 49 minutos". Mismo criterio que drAvisoHace() del
 * job del aviso: dos unidades como maximo, y los minutos solo si no hay dias.
 */
function drHace(?int $min): string {
    $n = max(0, (int) $min);
    if ($n < 1) return 'recién';

    $dias  = intdiv($n, 1440);
    $horas = intdiv($n % 1440, 60);
    $mins  = $n % 60;

    $plural = fn(int $x, string $sing, string $plur) => $x . ' ' . ($x === 1 ? $sing : $plur);
    $partes = [];
    if ($dias  > 0) $partes[] = $plural($dias,  'día',    'días');
    if ($horas > 0) $partes[] = $plural($horas, 'hora',   'horas');
    if ($mins  > 0 && $dias === 0) $partes[] = $plural($mins, 'minuto', 'minutos');

    return 'hace ' . implode(', ', array_slice($partes, 0, 2));
}

/** Etiqueta legible del canal. Espejo de drAvisoCanalTexto() del job. */
function drCanalTexto(?string $canal): string {
    $map = [
        'correo' => '📧 Correo',   'whatsapp' => '💬 WhatsApp', 'telegram'   => '✈️ Telegram',
        'sms'    => '📱 SMS',      'web'      => '🌐 Web',      'telefono'   => '📞 Teléfono',
        'presencial' => '🤝 Presencial',
    ];
    $c = (string) ($canal ?? '');
    return $map[$c] ?? ($c !== '' ? ucfirst($c) : '');
}

function drSentidoTexto(?string $s): string {
    return match ((string) $s) {
        'entrante' => 'Entrante', 'saliente' => 'Saliente', 'interna' => 'Interna',
        default    => '—',
    };
}

function drTipoTexto(?string $t): string {
    return match ((string) $t) {
        'persona' => 'Persona', 'empresa' => 'Empresa', default => '',
    };
}

function drGeneroTexto(?string $g): string {
    return match (strtoupper(trim((string) $g))) {
        'M' => 'Masculino', 'F' => 'Femenino', 'X' => 'No binario', default => '',
    };
}

/** Monto + moneda. NUNCA se mezclan monedas — se imprime la que tiene la fila. */
function drMonto(array $f): string {
    $m = $f['monto'] ?? null;
    if ($m === null || $m === '') return '';
    $moneda = v($f, 'moneda') !== '' ? v($f, 'moneda') : 'ARS';
    return $moneda . ' ' . number_format((float) $m, 2, ',', '.');
}

/**
 * `https://wa.me/<numero>` a partir de lo que haya cargado. Devuelve '' si no
 * quedan digitos suficientes: un link roto es peor que ningun link.
 */
function drLinkWa(string $numero): string {
    $d = preg_replace('/\D+/', '', $numero) ?? '';
    if (strlen($d) < 8) return '';
    if (strlen($d) === 10) $d = DRF_PREFIJO_WA . $d;
    return 'https://wa.me/' . $d;
}

/** La `web` del prospecto se guarda SIN esquema — hay que reponerlo para el href. */
function drLinkWeb(string $web): string {
    if ($web === '') return '';
    return preg_match('~^https?://~i', $web) ? $web : 'https://' . $web;
}

/**
 * Un `.data-row`, o cadena vacia si no hay dato.
 *
 * LO VACIO NO SE IMPRIME — misma regla que el WhatsApp del aviso
 * (`linea()` en jobs/datarocket_interacciones_avisar_pendientes.php), y por el
 * mismo motivo: la ficha tiene ~35 campos y la mayoria de los prospectos tienen
 * cargados diez. Con placeholders, el resto es media pantalla de guiones entre
 * el nombre y el telefono, justo en el dispositivo donde menos pantalla hay.
 *
 * El costo asumido es que la ausencia de un campo deja de ser visible: quien
 * quiera confirmar que "Correo" esta vacio y no que se lo comio la vista, tiene
 * el ABM. En esta pagina se decidio a favor de leer rapido.
 */
function fila(string $label, string $valor, string $href = '', bool $full = false): string {
    if ($valor === '') return '';
    $inner = $href !== ''
        ? '<a class="pub-link" href="' . h($href) . '" target="_blank" rel="noopener noreferrer">' . h($valor) . '</a>'
        : h($valor);
    return '<div class="data-row' . ($full ? ' full' : '') . '">'
         . '<dt class="data-label">' . h($label) . '</dt>'
         . '<dd class="data-value">' . $inner . '</dd></div>';
}

/**
 * Subseccion de la tarjeta grande. Se omite ENTERA —titulo incluido— si
 * ninguna de sus filas tiene dato: sin esto, un prospecto sin geo cargada
 * mostraba un "Ubicación" con una lista vacia debajo.
 */
function bloque(string $titulo, array $filas): string {
    $cuerpo = implode('', array_filter($filas, static fn(string $x) => $x !== ''));
    if ($cuerpo === '') return '';
    return '<h2 class="pub-subtitulo">' . h($titulo) . '</h2>'
         . '<dl class="data-list">' . $cuerpo . '</dl>';
}

// ----------------------------------------------------------------------------
// Render
// ----------------------------------------------------------------------------

$cssVer = @filemtime(__DIR__ . '/../../assets/css/style.css') ?: time();

// Datos derivados que la vista usa mas de una vez.
// Pendiente = entrante sin resolver por ninguno de los dos caminos. Desde la
// migracion 20260828_1900 hay un tercer estado, `descartada`, para lo que no
// hay que contestar.
$pendiente = $f !== null && v($f, 'sentido') === 'entrante'
             && v($f, 'respondida') === '' && v($f, 'descartada') === '';
$celular   = $f !== null ? (v($f, 'celular') !== '' ? v($f, 'celular') : v($f, 'whatsapp')) : '';
$waNumero  = $f !== null ? (v($f, 'whatsapp') !== '' ? v($f, 'whatsapp') : $celular) : '';
$titular   = $f !== null
    ? (v($f, 'p_nombre') !== '' ? v($f, 'p_nombre')
        : (v($f, 'empresa_nombre') !== '' ? v($f, 'empresa_nombre') : 'Prospecto sin nombre'))
    : '';
?>
<!doctype html>
<html lang="es">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <meta name="robots" content="noindex, nofollow, noarchive">
  <title>Prospecto pendiente de atención — Databox</title>
  <!-- Las tarjetas de previsualizacion de WhatsApp / Telegram se arman con
       estas etiquetas. A proposito NO llevan el nombre ni el telefono del
       prospecto: la vista previa queda visible en la lista de chats. -->
  <meta property="og:title" content="Prospecto pendiente de atención">
  <meta property="og:description" content="Ficha del prospecto y confirmación de atención. Databox Cloud.">
  <meta property="og:type" content="website">
  <link rel="icon" href="../../favicon.ico">
  <link rel="stylesheet" href="../../assets/css/style.css?v=<?= $cssVer ?>">
</head>
<body class="pub-body">

<header class="pub-topbar">
  <img src="../../assets/img/logo_light.png" class="pub-logo" alt="Databox">
</header>

<main class="pub-wrap">
<?php if ($aviso !== null): ?>

  <?php [$tipo, $icono, $titulo, $detalle] = $aviso; ?>
  <div class="pub-estado">
    <div class="pub-estado-icono"><?= $icono ?></div>
    <h1 class="pub-estado-titulo"><?= h($titulo) ?></h1>
    <p class="pub-estado-detalle"><?= h($detalle) ?></p>
    <a class="btn btn-secondary" href="https://cloud.databox.net.ar/#/datarocketinteracciones">Abrir el panel</a>
  </div>

<?php else: ?>

  <?php /* --- Banner de estado ----------------------------------------
       `hecho` viene del redirect propio, pero se contrasta igual contra la
       fila: un `&hecho=1` pegado a mano o un enlace reenviado no tiene que
       poder mostrar "quedó atendido" sobre una consulta que sigue abierta. */ ?>
  <?php if ($hecho && v($f, 'respondida') !== ''): ?>
    <div class="pub-banner pub-banner-ok">
      <div class="pub-banner-icono">✅</div>
      <div>
        <div class="pub-banner-titulo">Listo, quedó atendido</div>
        <div class="pub-banner-sub">No vas a recibir más recordatorios por esta consulta.</div>
      </div>
    </div>
  <?php elseif ($hechoDescartada && v($f, 'descartada') !== ''): ?>
    <div class="pub-banner pub-banner-info">
      <div class="pub-banner-icono">🚫</div>
      <div>
        <div class="pub-banner-titulo">Listo, quedó descartada</div>
        <div class="pub-banner-sub">No vas a recibir más recordatorios por esta consulta.</div>
      </div>
    </div>
  <?php elseif ($pendiente): ?>
    <div class="pub-banner pub-banner-warn">
      <div class="pub-banner-icono">⏳</div>
      <div>
        <div class="pub-banner-titulo">Pendiente de atención</div>
        <div class="pub-banner-sub">Esperando respuesta <?= h(drHace((int) ($f['espera_minutos'] ?? 0))) ?>.</div>
      </div>
    </div>
  <?php elseif (v($f, 'descartada') !== ''): ?>
    <div class="pub-banner pub-banner-info">
      <div class="pub-banner-icono">🚫</div>
      <div>
        <div class="pub-banner-titulo">Descartada</div>
        <div class="pub-banner-sub">
          Marcada el <?= h(drFechaCorta(v($f, 'descartada'))) ?> como consulta que no requería respuesta.
        </div>
      </div>
    </div>
  <?php elseif (v($f, 'respondida') !== ''): ?>
    <div class="pub-banner pub-banner-ok">
      <div class="pub-banner-icono">✅</div>
      <div>
        <div class="pub-banner-titulo">Ya está atendida</div>
        <div class="pub-banner-sub">
          Marcada el <?= h(drFechaCorta(v($f, 'respondida'))) ?>
          <?php if (($f['respuesta_minutos'] ?? null) !== null): ?>
            · respondida <?= h(drHace((int) $f['respuesta_minutos'])) ?> de haber entrado
          <?php endif; ?>
        </div>
      </div>
    </div>
  <?php else: ?>
    <div class="pub-banner pub-banner-info">
      <div class="pub-banner-icono">ℹ️</div>
      <div>
        <div class="pub-banner-titulo">Esta interacción no espera respuesta</div>
        <div class="pub-banner-sub">Es <?= h(strtolower(drSentidoTexto(v($f, 'sentido')))) ?>: solo las entrantes se marcan como atendidas.</div>
      </div>
    </div>
  <?php endif; ?>

  <?php /* --- Tarjeta unica: quien es, que preguntó y todos sus datos ---
       Una sola tarjeta a proposito. El prospecto, su consulta y su negocio son
       UNA cosa para quien la lee — "de quien es esto y que quiere" — y partirla
       en cuatro obligaba a barrer la pantalla del celular para juntar el nombre
       con el telefono. Las subsecciones (`pub-subtitulo`, con su separador)
       ordenan adentro sin volver a cortar el bloque. */ ?>
  <section class="pub-card">
    <h1 class="pub-titulo"><?= h($titular) ?></h1>
    <div class="pub-sub">
      <?php if (drTipoTexto(v($f, 'tipo')) !== ''): ?>
        <span class="badge badge-muted"><?= h(drTipoTexto(v($f, 'tipo'))) ?></span>
      <?php endif; ?>
      <?php if (v($f, 'empresa_nombre') !== '' && v($f, 'empresa_nombre') !== $titular): ?>
        <span class="pub-sub-item">🏢 <?= h(v($f, 'empresa_nombre')) ?></span>
      <?php endif; ?>
      <?php if (v($f, 'empresa_cargo') !== ''): ?>
        <span class="pub-sub-item"><?= h(v($f, 'empresa_cargo')) ?></span>
      <?php endif; ?>
      <?php if (v($f, 'proyecto_nombre') !== ''): ?>
        <span class="pub-sub-item">📁 <?= h(v($f, 'proyecto_nombre')) ?></span>
      <?php endif; ?>
    </div>
    <?php if ($etiquetas): ?>
      <div class="pub-etiquetas">
        <?php foreach ($etiquetas as $et): ?>
          <span class="badge badge-info"><?= h($et) ?></span>
        <?php endforeach; ?>
      </div>
    <?php endif; ?>

    <?php /* Solo el texto del mensaje, nada mas.
         La linea de referencia (fecha · canal · id) y el `asunto` se sacaron a
         pedido: el asunto de las consultas web es una etiqueta generica
         ("Consulta Web") que repite el canal, y la antiguedad ya la dice el
         banner de arriba ("Esperando respuesta hace 7 días"). El id de la
         interaccion sigue viajando en el WhatsApp del aviso, que es de donde
         se saca para buscarla en el ABM. */ ?>
    <h2 class="pub-subtitulo">Mensaje recibido</h2>
    <?php if (v($f, 'mensaje') !== ''): ?>
      <div class="pub-mensaje"><?= h(v($f, 'mensaje')) ?></div>
    <?php endif; ?>

    <?php /* Ficha del prospecto y de su negocio. Cada bloque se arma con
         bloque(), que lo omite entero —titulo incluido— si ninguna de sus
         filas trajo dato; y fila() omite las filas vacias una por una. Efecto
         neto: lo que se ve es exactamente lo que hay cargado, igual que el
         WhatsApp del aviso. */ ?>
    <?= bloque('Identidad', [
        fila('Nombre',     v($f, 'p_nombre')),
        fila('Tipo',       drTipoTexto(v($f, 'tipo'))),
        fila('Persona',    v($f, 'persona_nombre')),
        fila('Empresa',    v($f, 'empresa_nombre')),
        fila('Cargo',      v($f, 'empresa_cargo')),
        fila('Rubro',      v($f, 'empresa_rubro')),
        fila('Actividad',  v($f, 'empresa_actividad')),
        fila('Género',     drGeneroTexto(v($f, 'persona_genero'))),
        fila('Nacimiento', v($f, 'persona_nacimiento')),
        fila('DNI',        v($f, 'persona_dni')),
    ]) ?>

    <?= bloque('Datos de contacto', [
        fila('Celular',   v($f, 'celular')),
        fila('WhatsApp',  v($f, 'whatsapp')),
        fila('Teléfono',  v($f, 'telefono')),
        fila('Correo',    v($f, 'correo'), v($f, 'correo') !== '' ? 'mailto:' . v($f, 'correo') : ''),
        fila('Web',       v($f, 'web'),       drLinkWeb(v($f, 'web'))),
        fila('Facebook',  v($f, 'facebook'),  drLinkWeb(v($f, 'facebook'))),
        fila('Instagram', v($f, 'instagram'), drLinkWeb(v($f, 'instagram'))),
        fila('TikTok',    v($f, 'tiktok'),    drLinkWeb(v($f, 'tiktok'))),
    ]) ?>

    <?= bloque('Ubicación', [
        fila('Domicilio', v($f, 'domicilio')),
        fila('Ciudad',    v($f, 'ciudad')),
        fila('Localidad', v($f, 'localidad_nombre')),
        fila('Provincia', v($f, 'provincia_nombre')),
        fila('País',      v($f, 'pais_nombre')),
        fila('Coordenadas', v($f, 'ubicacion'),
             v($f, 'ubicacion') !== '' ? 'https://maps.google.com/?q=' . rawurlencode(v($f, 'ubicacion')) : ''),
    ]) ?>

    <?= bloque('Registro', [
        fila('Código', (int) ($f['prospecto_id'] ?? 0) > 0 ? '#' . (int) $f['prospecto_id'] : ''),
        fila('Registrado',   drFechaCorta(v($f, 'registrado'))),
        fila('Extraído de',  v($f, 'extraccion_url'), v($f, 'extraccion_url'), true),
        fila('Extraído por', v($f, 'extraccion_autor')),
        fila('Comentarios',  v($f, 'comentarios'), '', true),
    ]) ?>

    <?= bloque('Oportunidad', [
        fila('Código', (int) ($f['o_id'] ?? 0) > 0 ? '#' . (int) $f['o_id'] : ''),
        fila('Proyecto',        v($f, 'proyecto_nombre')),
        fila('Producto',        v($f, 'producto')),
        fila('Embudo',          v($f, 'embudo_nombre')),
        fila('Etapa',           v($f, 'etapa_nombre')),
        fila('Monto',           drMonto($f)),
        fila('Cierre esperado', drFechaCorta(v($f, 'cierre_esperado'), false)),
        fila('Ingreso',         drFechaCorta(v($f, 'o_ingreso'))),
        fila('Asignado a',      v($f, 'asignado_nombre')),
        fila('Atendido por',    v($f, 'atendido_nombre')),
        fila('Calificación',    ($f['calificacion'] ?? null) !== null ? (string) (int) $f['calificacion'] : ''),
        fila('Aplazado hasta',  drFechaCorta(v($f, 'aplazado'))),
        fila('Asunto y notas',  v($f, 'o_asunto'), '', true),
    ]) ?>
  </section>

  <?php /* --- Contacto rapido: primero contestar, despues marcar ------- */ ?>
  <?php if ($waNumero !== '' || $celular !== '' || v($f, 'telefono') !== '' || v($f, 'correo') !== ''): ?>
  <section class="pub-card">
    <h2 class="pub-section-titulo">Contactar</h2>
    <div class="pub-quick">
      <?php if ($waNumero !== '' && drLinkWa($waNumero) !== ''): ?>
        <a class="pub-quick-btn" href="<?= h(drLinkWa($waNumero)) ?>" target="_blank" rel="noopener noreferrer">
          <span class="pub-quick-icono">💬</span>
          <span><span class="pub-quick-label">WhatsApp</span><span class="pub-quick-dato"><?= h($waNumero) ?></span></span>
        </a>
      <?php endif; ?>
      <?php if ($celular !== ''): ?>
        <a class="pub-quick-btn" href="tel:<?= h(preg_replace('/[^\d+]+/', '', $celular) ?? '') ?>">
          <span class="pub-quick-icono">📱</span>
          <span><span class="pub-quick-label">Llamar al celular</span><span class="pub-quick-dato"><?= h($celular) ?></span></span>
        </a>
      <?php endif; ?>
      <?php if (v($f, 'telefono') !== ''): ?>
        <a class="pub-quick-btn" href="tel:<?= h(preg_replace('/[^\d+]+/', '', v($f, 'telefono')) ?? '') ?>">
          <span class="pub-quick-icono">📞</span>
          <span><span class="pub-quick-label">Llamar al teléfono</span><span class="pub-quick-dato"><?= h(v($f, 'telefono')) ?></span></span>
        </a>
      <?php endif; ?>
      <?php if (v($f, 'correo') !== ''): ?>
        <a class="pub-quick-btn" href="mailto:<?= h(v($f, 'correo')) ?><?= v($f, 'asunto') !== '' ? '?subject=' . rawurlencode('Re: ' . v($f, 'asunto')) : '' ?>">
          <span class="pub-quick-icono">📧</span>
          <span><span class="pub-quick-label">Responder por correo</span><span class="pub-quick-dato"><?= h(v($f, 'correo')) ?></span></span>
        </a>
      <?php endif; ?>
    </div>
  </section>
  <?php endif; ?>

  <?php /* --- La accion ------------------------------------------------ */ ?>
  <?php /* Dos salidas, no una. "Atendido" es el caso normal; "Descartar" es
       para lo que no hay que contestar —spam, un formulario en blanco, un
       mensaje sin pregunta—. Sin esa segunda opción la única forma de cortar
       los recordatorios era marcar como atendido algo que nadie atendió, o sea
       mentir sobre el trabajo hecho y ensuciar el promedio de demora del panel.

       Cada una en su propio <form>: dos submits distintos, sin JS de por medio.
       "Atendido" en verde es la primaria; "Descartar" va en naranja (`btn-warn`)
       y separado por una línea: se lee como lo que es —una salida legítima pero
       que no cuenta como trabajo hecho— sin confundirse con la normal ni
       leerse como un borrado. */ ?>
  <?php if ($pendiente): ?>
  <section class="pub-card pub-card-accion">
    <form method="post" class="pub-form">
      <input type="hidden" name="accion" value="atender">
      <button type="submit" class="btn btn-primary pub-cta">✅ Marcar como atendido</button>
    </form>
    <p class="pub-nota">
      Al confirmarlo, esta consulta deja de figurar como pendiente y no vas a recibir más
      recordatorios por WhatsApp.
    </p>
    <form method="post" class="pub-form pub-form-secundaria">
      <input type="hidden" name="accion" value="descartar">
      <button type="submit" class="btn btn-warn pub-cta-secundaria">🚫 Descartar (spam o sin consulta)</button>
    </form>
    <p class="pub-nota">
      Usalo cuando no haya nada que responder. Queda registrada como descartada —no como
      atendida— y también deja de recordarte.
    </p>
  </section>
  <?php endif; ?>

  <?php /* --- Historial ----------------------------------------------- */ ?>
  <?php if ($previas): ?>
  <section class="pub-card">
    <h2 class="pub-section-titulo">Historial del prospecto</h2>
    <ul class="pub-hist">
      <?php foreach ($previas as $pv): ?>
        <li class="pub-hist-item pub-hist-<?= h((string) $pv['sentido']) ?>">
          <div class="pub-hist-meta">
            <span class="pub-hist-fecha"><?= h(drFechaCorta((string) $pv['fecha'])) ?></span>
            <span class="badge badge-muted"><?= h(drSentidoTexto((string) $pv['sentido'])) ?></span>
            <?php if (drCanalTexto((string) $pv['canal']) !== ''): ?>
              <span class="pub-hist-canal"><?= h(drCanalTexto((string) $pv['canal'])) ?></span>
            <?php endif; ?>
            <?php if ((string) $pv['sentido'] === 'entrante' && ($pv['respondida'] ?? null) === null): ?>
              <span class="badge badge-warn">Sin responder</span>
            <?php endif; ?>
          </div>
          <?php $txt = trim((string) ($pv['asunto'] ?? '')) ?: trim((string) ($pv['mensaje'] ?? '')); ?>
          <?php if ($txt !== ''): ?>
            <div class="pub-hist-texto"><?= h(mb_strimwidth($txt, 0, 220, '…')) ?></div>
          <?php endif; ?>
        </li>
      <?php endforeach; ?>
    </ul>
    <p class="pub-nota">Se muestran las últimas <?= DRF_HIST_LIMIT ?>. El historial completo está en el panel.</p>
  </section>
  <?php endif; ?>

<?php endif; ?>
</main>

</body>
</html>
