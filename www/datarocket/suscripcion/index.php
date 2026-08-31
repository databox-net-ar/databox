<?php
/**
 * www/datarocket/suscripcion/index.php
 * Pagina PUBLICA de gestion de la suscripcion a una lista de distribucion.
 *
 * https://www.databox.net.ar/datarocket/suscripcion/?t=<token firmado>
 *
 * La carpeta se llama `suscripcion` y no `listas` porque es lo que el
 * destinatario lee en la barra del navegador: "estoy gestionando mi
 * suscripcion". `listas` es vocabulario interno del CRM y ademas se confunde
 * con el ABM de listas del panel.
 *
 * QUE ES
 * ------
 * El destino del enlace "Si no querés recibir más estos correos" que va al pie
 * de los mails de campana. Muestra de que lista se trata y a que direccion le
 * llego, y ofrece SIEMPRE LA ACCION CONTRARIA A SU ESTADO DE HOY: al que sigue
 * suscripto le ofrece darse de baja, y al que ya no lo esta, volver a
 * suscribirse. Sin login: quien la abre es un destinatario, no un usuario del
 * panel.
 *
 * POR QUE TAMBIEN DA EL ALTA
 * --------------------------
 * La baja se toca por error — un dedo en el celular, alguien que reenvia el
 * correo y toca el pie sin leerlo — y hasta ahora la unica salida que ofrecia
 * esta misma pantalla era "respondé el correo y lo resolvemos": la persona tenia
 * que escribir un mail y esperar a que un operador la volviera a cargar a mano
 * desde el ABM. Con el enlace que ya tiene en la mano, y que justamente prueba
 * que llego a su casilla, se resuelve sola.
 *
 * El alta es SOLO de la lista del token y solo reactiva a un prospecto que ya
 * existe: no crea prospectos, no lo mete en otras listas y no acepta ninguna
 * direccion escrita a mano. Esta pagina NO es un formulario de suscripcion
 * publico — sin el enlace firmado no hay forma de meter a nadie en una lista.
 *
 * POR QUE VIVE EN www/ Y NO EN cloud/
 * -----------------------------------
 * Mismo motivo que la ficha de interaccion (www/datarocket/prospecto/):
 * cloud.databox.net.ar es el dominio del panel, donde todo exige sesion. Una
 * URL anonima no pertenece ahi. Ademas, un enlace de baja aparece en el pie de
 * cada correo que sale: conviene que apunte al sitio publico y no al admin.
 *
 * EL GET NO ESCRIBE. NUNCA. NI LA BAJA NI EL ALTA.
 * ------------------------------------------------
 * Esto no es una preferencia de estilo, es la diferencia entre que la funcion
 * sirva o destruya la base. Los clientes de correo y las suites de seguridad
 * corporativas hacen GET automatico sobre todos los links de un mail para
 * escanearlos — Outlook Safe Links, Proofpoint, Mimecast, el propio antivirus
 * del destinatario. Si el GET diera de baja, media lista se desuscribiria sola
 * el dia que sale la campana, sin que nadie tocara nada, y no habria forma de
 * distinguir esas bajas de las genuinas.
 *
 * Con el alta es todavia peor: el escaner de un correo viejo reactivaria una
 * suscripcion que la persona dio de baja, o sea volveriamos a mandarle correo a
 * quien pidio expresamente no recibirlo. Eso ya no es un numero mal contado, es
 * la proxima denuncia de spam — y encima con el historial diciendo que se
 * resuscribio ella misma.
 *
 * Por eso las dos acciones son un `<form method="post">` con confirmacion
 * explicita, y el GET solo muestra. Es tambien la razon de que no se use el
 * header `List-Unsubscribe: <https://...>` a secas sin `List-Unsubscribe-Post`.
 *
 * POR QUE UN FORM Y NO FETCH
 * --------------------------
 * Igual que la ficha: se abre en el navegador embebido de un cliente de correo,
 * a veces viejo, a veces con el JS restringido. Un form nativo funciona aunque
 * el JS no cargue. Un fetch que falla en silencio ahi es una persona que quiso
 * darse de baja, cree que lo hizo, y sigue recibiendo correos — o sea, la
 * proxima denuncia de spam.
 *
 * QUE ESCRIBE
 * -----------
 * Nada por su cuenta: llama a drListaSuscribir() / drListaDesuscribir(), la
 * unica puerta de entrada y salida de las listas
 * (api/lib/datarocket_listas_suscripciones.php). Esas funciones escriben el
 * historial ANTES de tocar la puente y recalculan el denormalizado
 * `datarocket_listas.suscriptos` en la misma transaccion. Lo unico que aporta
 * esta pagina es el contexto: motivo 'solicitada' en los dos catalogos, origen
 * 'www/datarocket_listas_alta' / 'www/datarocket_listas_baja' y el destino real
 * al que se mando.
 *
 * La accion es sobre UNA lista, la del token. El mismo prospecto puede estar en
 * varias y darse de baja de la que le llego no puede sacarlo de las otras: eso
 * seria decidir por el mas de lo que pidio. Lo mismo al revés — volver a
 * suscribirse a esta no lo devuelve a ninguna otra de la que se haya ido.
 *
 * IDEMPOTENTE
 * -----------
 * Las dos funciones devuelven cuantos cambiaron REALMENTE. Si la persona ya
 * estaba en el estado que pidio (toco dos veces, o la dieron de baja antes por
 * rebote), devuelven 0, no se registra nada en el historial y la pantalla lo
 * cuenta como lo que es en vez de fingir que acaba de hacer algo.
 *
 * Queda constancia en `sucesos` con origen `datarocket_alta` / `datarocket_baja`.
 */

// /var/www/cloud — el segundo mount de ./cloud, el mismo que usan las APIs v4 y
// la ficha de interaccion para compartir librerias del panel sin acoplarse a su
// DocumentRoot.
require_once dirname(__DIR__, 3) . '/cloud/api/db.php';
require_once dirname(__DIR__, 3) . '/cloud/api/lib/sucesos.php';
require_once dirname(__DIR__, 3) . '/cloud/api/lib/datarocket_listas_suscripciones.php';
require_once dirname(__DIR__, 3) . '/cloud/api/lib/datarocket_listas_baja_enlace.php';

// Base publica del vhost de cloud, de donde cuelgan CSS, favicon y logo. Por
// APP_ENV y no por el Host del request: el request llega a www, los assets
// viven en cloud.
const BAJA_CLOUD_BASE_PROD = 'https://cloud.databox.net.ar';
const BAJA_CLOUD_BASE_DEV  = 'http://localhost:8091';
$cloudBase = (defined('APP_ENV') && APP_ENV === 'production')
    ? BAJA_CLOUD_BASE_PROD
    : BAJA_CLOUD_BASE_DEV;

// La URL ES la credencial: no filtrarla por Referer, no cachearla, no indexarla.
header('Content-Type: text/html; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, private');
header('Pragma: no-cache');
header('X-Robots-Tag: noindex, nofollow, noarchive');
header('Referrer-Policy: no-referrer');

// Origen de `sucesos`. Uno por direccion y no uno solo para la pagina: el visor
// de sucesos filtra por origen, y "cuantos se dieron de baja solos" y "cuantos
// se arrepintieron" son dos preguntas distintas que conviene poder separar de
// un click.
const DRB_ORIGEN_BAJA = 'datarocket_baja';
const DRB_ORIGEN_ALTA = 'datarocket_alta';

function h(?string $s): string {
    return htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');
}

/**
 * Trae lista + prospecto y si sigue suscripto. Devuelve null si la lista o el
 * prospecto ya no existen — un token viejo puede apuntar a algo borrado.
 */
function drSuscCargar(PDO $pdo, int $prospectoId, int $listaId): ?array {
    $st = $pdo->prepare('
        SELECT l.id            AS lista_id,
               l.nombre        AS lista_nombre,
               p.id            AS prospecto_id,
               p.nombre        AS prospecto_nombre,
               p.correo        AS prospecto_correo,
               (SELECT COUNT(*) FROM datarocket_prospectos_listas pl
                 WHERE pl.lista_id = l.id AND pl.prospecto_id = p.id) AS suscripto
          FROM datarocket_listas     l
          JOIN datarocket_prospectos p ON p.id = :pid
         WHERE l.id = :lid
         LIMIT 1
    ');
    $st->execute([':pid' => $prospectoId, ':lid' => $listaId]);
    $r = $st->fetch(PDO::FETCH_ASSOC);
    return $r ?: null;
}

/**
 * Ejecuta la accion pedida por la puerta compartida. Devuelve cuantos cambiaron
 * (0 si ya estaba en ese estado).
 *
 * Las dos direcciones comparten funcion en vez de tener una cada una porque lo
 * unico que cambia es el vocabulario: mismo contexto, mismo destino
 * denormalizado, mismo suceso. Partirlas era garantizar que dentro de un mes una
 * anotara algo que la otra no.
 */
function drSuscEjecutar(PDO $pdo, array $f, bool $alta): int {
    $lid = (int) $f['lista_id'];
    $pid = (int) $f['prospecto_id'];

    $ctx = [
        'motivo' => $alta ? DR_LALTA_MOTIVO : DR_LBAJA_MOTIVO,
        'origen' => $alta ? 'www/datarocket_listas_alta' : 'www/datarocket_listas_baja',
        // El destino real al que se mando. Se denormaliza en el historial: si
        // manana se corrige el correo del prospecto, el registro sigue diciendo
        // a que direccion le llego el mail que origino el cambio.
        'por_prospecto' => [$pid => ['destino' => $f['prospecto_correo']]],
    ];

    $n = $alta
        ? drListaSuscribir($pdo, $lid, [$pid], $ctx)
        : drListaDesuscribir($pdo, $lid, [$pid], $ctx);

    if ($n > 0) {
        registrarSuceso($pdo, $alta ? DRB_ORIGEN_ALTA : DRB_ORIGEN_BAJA, 'info',
            ($alta ? 'Alta solicitada' : 'Baja solicitada')
            . " por el destinatario: prospecto #{$pid} ({$f['prospecto_correo']})"
            . ($alta ? ' volvio a' : ' salio de')
            . " la lista #{$lid} \"{$f['lista_nombre']}\".");
    }
    return $n;
}

$token = (string) ($_GET['t'] ?? '');
// Distingue "vengo de tocar el boton" de "abri el enlace y ya estaba asi": el
// mensaje es distinto y confundirlos hace dudar de si funciono. Vale 'alta',
// 'baja' o nada; cualquier otra cosa (incluido el `hecho=1` de la version
// anterior, que puede estar en el historial de alguien) se ignora.
$hecho = (string) ($_GET['hecho'] ?? '');
if ($hecho !== 'alta' && $hecho !== 'baja') $hecho = '';

$aviso = null;   // ['tipo','icono','titulo','detalle'] — pantalla sin formulario
$f     = null;

try {
    $tk = drListaBajaVerificar($token);

    if ($tk === null) {
        http_response_code(403);
        $aviso = ['error', '🔒', 'Enlace no válido',
                  'El enlace que abriste no es correcto o está incompleto. Probá tocándolo '
                  . 'directamente en el correo, sin copiarlo a mano.'];
    } elseif ($tk['vencido']) {
        http_response_code(410);
        $aviso = ['warn', '⌛', 'El enlace venció',
                  'Este enlace ya caducó. Respondé el correo diciéndonos qué necesitás y lo '
                  . 'gestionamos a mano.'];
    } else {
        $pdo = db();
        $f   = drSuscCargar($pdo, (int) $tk['prospecto_id'], (int) $tk['lista_id']);

        if ($f === null) {
            http_response_code(404);
            $aviso = ['info', '📭', 'Esta lista ya no existe',
                      'La lista a la que apunta este enlace fue eliminada, así que no vas a '
                      . 'recibir más correos de ella.'];
        } elseif (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
            // La accion viene del form, no del estado leido: entre que se
            // dibujo la pantalla y se toco el boton pudo cambiar (dos pestanas,
            // una baja por rebote). Ejecutar "lo contrario a como esta ahora"
            // haria justo lo que la persona no pidio. Las funciones son
            // idempotentes, asi que pedir lo que ya esta no rompe nada.
            $accion = (string) ($_POST['accion'] ?? '');
            if ($accion === 'baja' || $accion === 'alta') {
                drSuscEjecutar($pdo, $f, $accion === 'alta');
                // POST-Redirect-GET: sin esto, un F5 reenvia el form y el
                // navegador pregunta si quiere reenviar los datos — que es la
                // forma mas rapida de que alguien piense que no funciono y lo
                // intente de nuevo.
                $self = strtok((string) ($_SERVER['REQUEST_URI'] ?? ''), '?') ?: './';
                header('Location: ' . $self . '?t=' . rawurlencode($token) . '&hecho=' . $accion, true, 303);
                exit;
            }
        }
    }
} catch (Throwable $e) {
    http_response_code(500);
    // El detalle real va al log, no a la pantalla: quien abre esto es el
    // destinatario de un mail y un stack trace no le dice nada.
    error_log('datarocket suscripcion: ' . $e->getMessage());
    $aviso = ['error', '⚠️', 'No pudimos procesar el cambio',
              'Hubo un problema de nuestro lado. Probá de nuevo en un rato, o respondé el '
              . 'correo diciéndonos qué necesitás.'];
}

// El estado se relee de la fila y no del `?hecho=`: un `&hecho=baja` pegado a
// mano o un enlace reenviado no tiene que mostrar un cambio que no ocurrio.
$sigueSuscripto = $f !== null && (int) $f['suscripto'] > 0;

// El banner de confirmacion sale solo si el redirect propio Y la fila dicen lo
// mismo. Si no coinciden (dos pestanas, un rebote en el medio), no hay banner:
// la tarjeta de abajo ya cuenta el estado real, que es lo unico que importa.
$confirmado = ($hecho === 'baja' && !$sigueSuscripto)
           || ($hecho === 'alta' && $sigueSuscripto);

$cssVer = @filemtime(dirname(__DIR__, 3) . '/cloud/assets/css/style.css') ?: time();
?>
<!doctype html>
<html lang="es">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <meta name="robots" content="noindex, nofollow, noarchive">
  <title>Tu suscripción — Databox</title>
  <!-- Sin datos personales en las etiquetas de previsualizacion: la tarjeta del
       link queda visible en clientes de correo y chats. -->
  <meta property="og:title" content="Suscripción a la lista de correo">
  <meta property="og:description" content="Gestión de suscripción. Databox.">
  <meta property="og:type" content="website">
  <link rel="icon" href="<?= h($cloudBase) ?>/favicon.ico">
  <link rel="stylesheet" href="<?= h($cloudBase) ?>/assets/css/style.css?v=<?= $cssVer ?>">
</head>
<?php /* `pub-light` redefine los tokens de color para esta pagina: fondo blanco,
         texto oscuro, sin barra verde. Se abre desde un cliente de correo, no
         desde el panel — ver la nota en assets/css/style.css. */ ?>
<body class="pub-body pub-light">

<?php /* Sin cabecera ni logo: la pagina es una sola pregunta con un boton. El
         destinatario ya sabe de quien es el correo que esta gestionando — poner
         la marca arriba solo agrega chrome a una pantalla que gana siendo
         minima. */ ?>
<main class="pub-wrap">
<?php if ($aviso !== null): ?>

  <?php [$tipo, $icono, $titulo, $detalle] = $aviso; ?>
  <div class="pub-estado">
    <div class="pub-estado-icono"><?= $icono ?></div>
    <h1 class="pub-estado-titulo"><?= h($titulo) ?></h1>
    <p class="pub-estado-detalle"><?= h($detalle) ?></p>
  </div>

<?php else: ?>

  <?php /* Acuse de lo que se acaba de hacer. Va aparte de la tarjeta —y no como
           titulo de ella— porque son dos cosas distintas: esto es "pasó", la
           tarjeta de abajo es "estás así, y podés cambiarlo". Mezclarlos daba
           una pantalla que felicitaba y preguntaba en el mismo renglon. */ ?>
  <?php if ($confirmado): ?>
    <div class="pub-banner pub-banner-ok">
      <div class="pub-banner-icono">✅</div>
      <div>
        <div class="pub-banner-titulo">
          <?= $sigueSuscripto ? 'Listo, volviste a suscribirte' : 'Listo, te diste de baja' ?>
        </div>
        <div class="pub-banner-sub">
          <?= $sigueSuscripto
                ? 'Vas a volver a recibir los correos de esta lista.'
                : 'No vas a recibir más correos de esta lista.' ?>
        </div>
      </div>
    </div>
  <?php endif; ?>

  <?php /* UNA tarjeta para los dos estados, con la accion contraria a como esta
           hoy. La version anterior tenia dos pantallas —la pregunta de baja, y
           un cartel de "ya estabas dado de baja" sin salida— y el que se
           desuscribia por error quedaba sin nada que tocar. */ ?>
  <div class="pub-card pub-card-accion">
    <?php /* Centrados con estilo inline y no tocando `.pub-titulo` / `.pub-sub`,
             que son compartidas con la ficha de prospecto: ahi el texto va
             alineado a la izquierda porque es una lectura larga. Aca son dos
             renglones y una sola accion. */ ?>
    <h1 class="pub-titulo" style="text-align:center">
      <?= $sigueSuscripto
            ? '¿Querés dejar de recibir estos correos?'
            : '¿Querés volver a recibir estos correos?' ?>
    </h1>
    <p class="pub-sub" style="justify-content:center;text-align:center">
      <?= $sigueSuscripto ? 'Estás recibiendo' : 'No estás recibiendo' ?>
      <strong><?= h($f['lista_nombre']) ?></strong> en
      <strong><?= h($f['prospecto_correo']) ?></strong>.
    </p>

    <?php /* El boton es POST en las dos direcciones. Ver la nota sobre los
             escaneres de enlaces en la cabecera: con GET, media lista se daria
             de baja sola — y la otra media se resuscribiria sola.

             El margen va inline y no en `.pub-form` porque esa clase la comparte
             la ficha de prospecto, que no pidio este cambio. */ ?>
    <form class="pub-form" method="post" style="margin-top:10px"
          action="?t=<?= h(rawurlencode($token)) ?>">
      <input type="hidden" name="accion" value="<?= $sigueSuscripto ? 'baja' : 'alta' ?>">
      <?php /* `btn-info` y no `btn-primary`: el verde institucional es del
               chrome del panel y esta pagina no es el panel. El mismo color para
               las dos acciones a proposito — ninguna de las dos es la "buena":
               la pantalla no empuja a quedarse ni a irse. */ ?>
      <button type="submit" class="btn btn-info pub-cta">
        <?= $sigueSuscripto ? 'Darme de baja' : 'Volver a suscribirme' ?>
      </button>
    </form>

    <p class="pub-nota">
      <?php if ($sigueSuscripto): ?>
        Dejás de recibir sólo los correos de esta lista; si estás en otras, siguen igual.
        Si te das de baja por error, con este mismo enlace podés volver a suscribirte.
      <?php else: ?>
        Volvés a recibir sólo los correos de esta lista, en esta misma dirección.
        Podés darte de baja de nuevo cuando quieras, desde este mismo enlace.
      <?php endif; ?>
    </p>
  </div>

<?php endif; ?>
</main>

</body>
</html>
