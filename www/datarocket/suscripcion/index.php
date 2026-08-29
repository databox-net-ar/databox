<?php
/**
 * www/datarocket/suscripcion/index.php
 * Pagina PUBLICA de baja de una lista de distribucion.
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
 * llego, y ofrece un boton para darse de baja. Sin login: quien la abre es un
 * destinatario, no un usuario del panel.
 *
 * POR QUE VIVE EN www/ Y NO EN cloud/
 * -----------------------------------
 * Mismo motivo que la ficha de interaccion (www/datarocket/prospecto/):
 * cloud.databox.net.ar es el dominio del panel, donde todo exige sesion. Una
 * URL anonima no pertenece ahi. Ademas, un enlace de baja aparece en el pie de
 * cada correo que sale: conviene que apunte al sitio publico y no al admin.
 *
 * EL GET NO DA DE BAJA. NUNCA.
 * ----------------------------
 * Esto no es una preferencia de estilo, es la diferencia entre que la funcion
 * sirva o destruya la base. Los clientes de correo y las suites de seguridad
 * corporativas hacen GET automatico sobre todos los links de un mail para
 * escanearlos — Outlook Safe Links, Proofpoint, Mimecast, el propio antivirus
 * del destinatario. Si el GET diera de baja, media lista se desuscribiria sola
 * el dia que sale la campana, sin que nadie tocara nada, y no habria forma de
 * distinguir esas bajas de las genuinas.
 *
 * Por eso la baja es un `<form method="post">` con confirmacion explicita, y el
 * GET solo muestra. Es tambien la razon de que no se use el header
 * `List-Unsubscribe: <https://...>` a secas sin `List-Unsubscribe-Post`.
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
 * Nada por su cuenta: llama a drListaDesuscribir(), la unica puerta de salida
 * de las listas (api/lib/datarocket_listas_suscripciones.php). Esa funcion
 * escribe el historial ANTES de borrar de la puente y recalcula el
 * denormalizado `datarocket_listas.suscriptos` en la misma transaccion. Lo
 * unico que aporta esta pagina es el contexto: motivo 'solicitada', origen
 * 'www/datarocket_listas_baja' y el destino real al que se mando.
 *
 * La baja es de UNA lista, la del token. El mismo prospecto puede estar en
 * varias y darse de baja de la que le llego no puede sacarlo de las otras: eso
 * seria decidir por el mas de lo que pidio.
 *
 * IDEMPOTENTE
 * -----------
 * drListaDesuscribir() devuelve cuantos se dieron de baja REALMENTE. Si la
 * persona ya no estaba (toco dos veces, o la dieron de baja antes por rebote),
 * devuelve 0 y la pagina dice "ya estabas dado de baja" en vez de fingir que
 * acaba de hacer algo.
 *
 * Queda constancia en `sucesos` con origen `datarocket_baja`.
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

const DRB_ORIGEN = 'datarocket_baja';

function h(?string $s): string {
    return htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');
}

/**
 * Trae lista + prospecto y si sigue suscripto. Devuelve null si la lista o el
 * prospecto ya no existen — un token viejo puede apuntar a algo borrado.
 */
function drBajaCargar(PDO $pdo, int $prospectoId, int $listaId): ?array {
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
 * Ejecuta la baja por la puerta compartida. Devuelve cuantos se dieron de baja
 * (0 si ya no estaba).
 */
function drBajaEjecutar(PDO $pdo, array $f): int {
    $n = drListaDesuscribir($pdo, (int) $f['lista_id'], [(int) $f['prospecto_id']], [
        'motivo' => DR_LBAJA_MOTIVO,
        'origen' => 'www/datarocket_listas_baja',
        // El destino real al que se mando. Se denormaliza en el historial: si
        // manana se corrige el correo del prospecto, el registro sigue diciendo
        // a que direccion le llego el mail del que se dio de baja.
        'por_prospecto' => [
            (int) $f['prospecto_id'] => ['destino' => $f['prospecto_correo']],
        ],
    ]);

    if ($n > 0) {
        registrarSuceso($pdo, DRB_ORIGEN, 'info',
            "Baja solicitada por el destinatario: prospecto #{$f['prospecto_id']}"
            . " ({$f['prospecto_correo']}) salio de la lista #{$f['lista_id']}"
            . " \"{$f['lista_nombre']}\".");
    }
    return $n;
}

$token = (string) ($_GET['t'] ?? '');
// Distingue "vengo de tocar el boton" de "abri el enlace y ya estaba dado de
// baja": el mensaje es distinto y confundirlos hace dudar de si funciono.
$hecho = (string) ($_GET['hecho'] ?? '') === '1';

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
                  'Este enlace de baja ya caducó. Respondé el correo pidiendo la baja y la '
                  . 'gestionamos a mano.'];
    } else {
        $pdo = db();
        $f   = drBajaCargar($pdo, (int) $tk['prospecto_id'], (int) $tk['lista_id']);

        if ($f === null) {
            http_response_code(404);
            $aviso = ['info', '📭', 'Esta lista ya no existe',
                      'La lista a la que apunta este enlace fue eliminada, así que no vas a '
                      . 'recibir más correos de ella.'];
        } elseif (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST'
                  && (string) ($_POST['accion'] ?? '') === 'baja') {
            drBajaEjecutar($pdo, $f);
            // POST-Redirect-GET: sin esto, un F5 reenvia el form y el navegador
            // pregunta si quiere reenviar los datos — que es la forma mas rapida
            // de que alguien piense que no funciono y lo intente de nuevo.
            $self = strtok((string) ($_SERVER['REQUEST_URI'] ?? ''), '?') ?: './';
            header('Location: ' . $self . '?t=' . rawurlencode($token) . '&hecho=1', true, 303);
            exit;
        }
    }
} catch (Throwable $e) {
    http_response_code(500);
    // El detalle real va al log, no a la pantalla: quien abre esto es el
    // destinatario de un mail y un stack trace no le dice nada.
    error_log('datarocket baja: ' . $e->getMessage());
    $aviso = ['error', '⚠️', 'No pudimos procesar la baja',
              'Hubo un problema de nuestro lado. Probá de nuevo en un rato, o respondé el '
              . 'correo pidiendo la baja.'];
}

// El estado se relee de la fila y no del `?hecho=1`: un `&hecho=1` pegado a mano
// o un enlace reenviado no tiene que mostrar una baja que no ocurrio.
$sigueSuscripto = $f !== null && (int) $f['suscripto'] > 0;
$cssVer = @filemtime(dirname(__DIR__, 3) . '/cloud/assets/css/style.css') ?: time();
?>
<!doctype html>
<html lang="es">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <meta name="robots" content="noindex, nofollow, noarchive">
  <title>Baja de la lista — Databox</title>
  <!-- Sin datos personales en las etiquetas de previsualizacion: la tarjeta del
       link queda visible en clientes de correo y chats. -->
  <meta property="og:title" content="Baja de la lista de correo">
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
         destinatario ya sabe de quien es el correo del que se quiere dar de
         baja — poner la marca arriba solo agrega chrome a una pantalla que
         gana siendo minima. */ ?>
<main class="pub-wrap">
<?php if ($aviso !== null): ?>

  <?php [$tipo, $icono, $titulo, $detalle] = $aviso; ?>
  <div class="pub-estado">
    <div class="pub-estado-icono"><?= $icono ?></div>
    <h1 class="pub-estado-titulo"><?= h($titulo) ?></h1>
    <p class="pub-estado-detalle"><?= h($detalle) ?></p>
  </div>

<?php elseif (!$sigueSuscripto): ?>

  <?php /* Cubre los dos caminos: acaba de darse de baja (viene del redirect) y
           abrio el enlace estando ya fuera. El texto es el mismo a proposito —
           lo que la persona necesita saber es que no va a recibir mas correos,
           no si el cambio ocurrio hace un segundo o hace un mes. */ ?>
  <div class="pub-estado">
    <div class="pub-estado-icono">✅</div>
    <h1 class="pub-estado-titulo"><?= $hecho ? 'Listo, te diste de baja' : 'Ya estabas dado de baja' ?></h1>
    <p class="pub-estado-detalle">
      No vas a recibir más correos de <strong><?= h($f['lista_nombre']) ?></strong>
      en <strong><?= h($f['prospecto_correo']) ?></strong>.
    </p>
    <p class="pub-nota">
      Si fue un error o querés volver a suscribirte, respondé el último correo que
      te enviamos y lo resolvemos.
    </p>
  </div>

<?php else: ?>

  <div class="pub-card">
    <?php /* Centrados con estilo inline y no tocando `.pub-titulo` / `.pub-sub`,
             que son compartidas con la ficha de prospecto: ahi el texto va
             alineado a la izquierda porque es una lectura larga. Aca son dos
             renglones y una sola accion. */ ?>
    <h1 class="pub-titulo" style="text-align:center">¿Querés dejar de recibir estos correos?</h1>
    <p class="pub-sub" style="justify-content:center;text-align:center">
      Te vas a desuscribir con la dirección
      <strong><?= h($f['prospecto_correo']) ?></strong>.
    </p>

    <?php /* El boton es POST. Ver la nota sobre los escaneres de enlaces en la
             cabecera: con GET, media lista se daria de baja sola.

             El margen va inline y no en `.pub-form` porque esa clase la comparte
             la ficha de prospecto, que no pidio este cambio. */ ?>
    <form class="pub-form" method="post" style="margin-top:10px"
          action="?t=<?= h(rawurlencode($token)) ?>">
      <input type="hidden" name="accion" value="baja">
      <?php /* `btn-info` y no `btn-primary`: el verde institucional es del
               chrome del panel y esta pagina no es el panel. */ ?>
      <button type="submit" class="btn btn-info pub-cta">
        Darme de baja
      </button>
    </form>
  </div>

<?php endif; ?>
</main>

</body>
</html>
