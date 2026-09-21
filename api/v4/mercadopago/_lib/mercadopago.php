<?php
/**
 * api/v4/mercadopago/_lib/mercadopago.php
 *
 * Nucleo de dominio del microservicio Mercado Pago v4. Los siete endpoints de
 * `api/v4/mercadopago/` validan la entrada y delegan aca: no hay una sola
 * consulta SQL ni una sola llamada a la API de Mercado Pago escrita dos veces
 * en el arbol.
 *
 * ---------------------------------------------------------------------------
 * DE DONDE SALE ESTE CODIGO
 * ---------------------------------------------------------------------------
 * Es el port del microservicio legacy `databox-api/v2/mercadopago` (repo
 * databox_legacy), que hoy sigue en produccion. Aquel se apoyaba en el
 * framework interno (`/framework/insertar.php`) y en los modelos activos de
 * `databox-api/modulos/mercadopago.php` (`mcMercadopagoCuenta`,
 * `mcMercadopagoPago`, `mcMercadopagoSuscripcion`, `mcMercadopagoDebito`,
 * `mcMercadopagoRegistro`, `mcMercadopagoApi`). Aca no hay framework: PDO
 * preparado + curl.
 *
 * El objetivo declarado del port es que un cliente pueda cambiar `/v2/` por
 * `/v4/` en su URL y no tocar NADA mas. Por eso se conservan, aun cuando no
 * sean lo que uno escribiria hoy:
 *
 *   * El sobre de respuesta legacy (`{"respuesta":{"codigo":N,"mensaje":"..."}}`)
 *     en vez del `{ok:true,data:...}` de la casa. Ver mpResponder()/mpRevelar().
 *   * Los codigos de estado no-HTTP (601, 602, 603, 604, 610).
 *   * El `apikey` por query string ademas del Bearer.
 *   * Los uuid de 16 caracteres alfanumericos generados a mano, no UUID v4.
 *   * El centinela de fecha '1500-01-01' para "todavia no paso".
 *   * Dos quirks de la URL de imputacion que estan mal pero que los sistemas
 *     origen ya consumen tal cual. Estan marcados con QUIRK LEGACY mas abajo.
 *
 * Las tablas son las MISMAS filas que lee el panel cloud (Plataformas >
 * Mercadopago) y las mismas que escribe el microservicio legacy: `mercadopagocuentas`,
 * `mercadopagopagos`, `mercadopagosuscripciones`, `mercadopagodebitos` y
 * `mercadopagoregistros`. Son tablas legacy (sin guion bajo) compartidas con el
 * stack viejo — no se renombran ni se migran aca. Esquema en db/schema.sql.
 *
 * Consecuencia importante: mientras el legacy siga publicado, los dos
 * microservicios escriben sobre las mismas filas. Conviven sin pisarse porque
 * cada pago/suscripcion lo toca solo el que lo creo, pero el webhook de una
 * cuenta debe apuntar a UNO de los dos, nunca a los dos.
 *
 * ---------------------------------------------------------------------------
 * MODO TESTING / PRODUCCION
 * ---------------------------------------------------------------------------
 * `mercadopagocuentas.modo` decide que par de credenciales se usa:
 *   '1' -> testing    (publicKeyTesting + accessTokenTesting)
 *   '2' -> produccion (publicKey + accessToken)
 *
 * OJO: eso vale para el checkout (pagar/procesar). El webhook y el alta de
 * suscripciones usan SIEMPRE `accessToken` (produccion), igual que el legacy.
 * No es un olvido del port: cambiarlo haria que una cuenta en modo testing
 * dejara de resolver notificaciones que hoy resuelve.
 */

declare(strict_types=1);

require_once dirname(__DIR__, 4) . '/cloud/api/db.php';
require_once dirname(__DIR__, 4) . '/cloud/api/lib/apikey_auth.php';

// ---------------------------------------------------------------------------
// Constantes
// ---------------------------------------------------------------------------

/** Centinela de "todavia no ocurrio". El legacy lo produce con cTiempo::genesis(). */
const MP_GENESIS = '1500-01-01 00:00:00';

/** Base de la API de Mercado Pago. */
const MP_API = 'https://api.mercadopago.com';

/** Techo de espera de cualquier llamada saliente, en segundos. */
const MP_TIMEOUT = 30;

/**
 * Tipos de fila de `mercadopagoregistros`. Son los mismos cinco del legacy y
 * no hay un catalogo en `estados` que los declare — la columna es varchar(50)
 * libre. Se dejan como constantes para que el grep encuentre todos los usos.
 */
const MP_REG_PAGO        = 'P';   // notificacion cruda de un pago
const MP_REG_SUSCRIPCION = 'S';   // notificacion cruda de una suscripcion
const MP_REG_DEBITO      = 'D';   // notificacion cruda de un debito
const MP_REG_INFO        = 'I';   // linea de contexto en prosa
const MP_REG_DESCONOCIDO = 'N';   // `type` que el selector no reconoce

// ---------------------------------------------------------------------------
// Utilidades que reemplazan al framework legacy
// ---------------------------------------------------------------------------

/**
 * Lee una ruta anidada de un JSON crudo devolviendo '' cuando falta.
 *
 * Equivalente a `cJson::leer($bloque, $v1, $v2, ...)`: JSON invalido, clave
 * ausente o valor null dan '' — nunca null ni excepcion. Medio microservicio
 * depende de esa semantica (`if ($x != '')`), asi que se replica tal cual en
 * vez de usar el operador de coalescencia directo.
 */
function mpJsonLeer(string $bloque, string ...$ruta): mixed {
    $nodo = json_decode($bloque, true);
    if (!is_array($nodo)) return '';
    foreach ($ruta as $clave) {
        if (!is_array($nodo) || !array_key_exists($clave, $nodo)) return '';
        $nodo = $nodo[$clave];
    }
    return $nodo ?? '';
}

/** Equivalente a `cNumero::cero()`: lo que no sea numerico vale 0. */
function mpCero(mixed $valor): int|float|string {
    return is_numeric($valor) ? $valor : 0;
}

/** `Y-m-d H:i:s` en la zona del contenedor (America/Argentina/Buenos_Aires). */
function mpAhora(): string {
    return date('Y-m-d H:i:s');
}

/**
 * Cadena aleatoria de 16 caracteres [0-9A-Za-z], que es lo que el legacy usa
 * como `uuid` de pagos y debitos (cCadena::aleatoria(16, '0Aa')).
 *
 * NO es un UUID: no tiene guiones ni version, y las columnas `uuid` de esas
 * dos tablas no son UNIQUE. Se mantiene el formato porque los sistemas origen
 * guardan el valor y lo muestran, y porque el panel cloud lista esa columna.
 * random_int() en vez del rand() del framework — mismo alfabeto, mejor fuente.
 */
function mpUuid(int $largo = 16): string {
    $alfabeto = '0123456789ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz';
    $tope     = strlen($alfabeto) - 1;
    $salida   = '';
    for ($i = 0; $i < $largo; $i++) $salida .= $alfabeto[random_int(0, $tope)];
    return $salida;
}

// ---------------------------------------------------------------------------
// Sobres de respuesta legacy
// ---------------------------------------------------------------------------
//
// El stack nuevo contesta {ok:true,data:...} / {ok:false,error:"..."} y eso
// vale para todo `api/v4` MENOS este modulo. Aca la respuesta es la del
// legacy porque los clientes ya la parsean:
//
//   mpResponder() -> SIEMPRE envuelve:
//       {"respuesta":{"codigo":200,"mensaje":"OK"}}
//   mpRevelar()   -> envuelve SOLO si el codigo != 200; con 200 devuelve el
//       payload pelado, sin sobre.
//
// Y el codigo viaja tambien como status line HTTP, incluidos los que no son
// codigos HTTP validos (601..610). El legacy hace `header('HTTP/1.1 603 ')` y
// los clientes leen `respuesta.codigo`, no el status — pero alguno podria
// estar mirando el status, asi que se replica.

/** Escribe el status line tal cual lo hace el legacy, codigos raros incluidos. */
function mpStatus(int $codigo): void {
    header('HTTP/1.1 ' . $codigo . ' ');
}

/** Respuesta siempre envuelta. Es la de webhook.php. */
function mpResponder(int $codigo, string $mensaje, array $datos = []): never {
    mpStatus($codigo);
    header('Content-Type: application/json; charset=utf-8');
    $datos['respuesta'] = ['codigo' => $codigo, 'mensaje' => $mensaje];
    echo json_encode($datos, JSON_UNESCAPED_UNICODE);
    exit;
}

/** Respuesta envuelta solo en error. Es la de suscripcionCrear.php. */
function mpRevelar(int $codigo, string $mensaje, array $datos = []): never {
    mpStatus($codigo);
    header('Content-Type: application/json; charset=utf-8');
    if ($codigo !== 200) {
        $datos['respuesta'] = ['codigo' => $codigo, 'mensaje' => $mensaje];
    }
    echo json_encode($datos, JSON_UNESCAPED_UNICODE);
    exit;
}

/**
 * Pagina de error en texto plano para los endpoints que atiende un NAVEGADOR
 * (pagar, aprobado, pendiente, rechazado). Devolver JSON ahi seria mostrarle
 * un `{"ok":false}` a una persona. El legacy hace `echo` y sale con 200; aca
 * se manda el status que corresponde para que el error quede en el Visor de
 * sucesos, pero el texto visible es el mismo.
 */
function mpCortarNavegador(string $mensaje, int $codigo = 400): never {
    http_response_code($codigo);
    header('Content-Type: text/html; charset=utf-8');
    echo '<!doctype html><meta charset="utf-8">'
       . '<div style="font:16px/1.5 system-ui,sans-serif;padding:2rem;max-width:34rem;margin:auto">'
       . htmlspecialchars($mensaje, ENT_QUOTES, 'UTF-8')
       . '</div>';
    exit;
}

// ---------------------------------------------------------------------------
// Autenticacion
// ---------------------------------------------------------------------------

/**
 * Autoriza una aplicacion contra la tabla `aplicaciones` aceptando las DOS
 * formas: el `apikey` por query string / body-form del legacy y el
 * `Authorization: Bearer` del stack nuevo.
 *
 * La doble puerta es justamente lo que permite mover un cliente de `/v2/` a
 * `/v4/` cambiando una sola linea: su `?apikey=` sigue funcionando, y cuando
 * quiera pasarse a Bearer no necesita otro deploy del servicio.
 *
 * Codigos: 601 sin apikey, 602 apikey invalida o deshabilitada — los del
 * legacy. Devuelve la fila de `aplicaciones` e incrementa `usos` (esto ultimo
 * el legacy no lo hacia; se agrega para que el ABM de aplicaciones muestre
 * actividad igual que con el resto de los endpoints v4).
 */
function mpAutorizarAplicacion(): array {
    $token = trim((string)($_GET['apikey'] ?? $_POST['apikey'] ?? ''));
    $enUrl = $token !== '';
    if ($token === '') $token = readBearerToken();

    // Tapa la apikey en REQUEST_URI ANTES de cualquier salida. El registro de
    // errores de v4 (_lib/log.php) guarda la URI completa en `sucesos.detalle`
    // dando por sentado que la apikey viaja en el header Authorization — cierto
    // para el resto del arbol, falso para este endpoint, que acepta tambien la
    // forma legacy `?apikey=`. Sin esto, cada 4xx deja la credencial escrita en
    // el Visor de sucesos del panel.
    if ($enUrl && isset($_SERVER['REQUEST_URI'])) {
        $_SERVER['REQUEST_URI'] = str_replace($token, '***', (string)$_SERVER['REQUEST_URI']);
    }

    if ($token === '') mpRevelar(601, 'Falta apikey');

    $pdo = db();
    $st  = $pdo->prepare("SELECT id, nombre, habilitada FROM aplicaciones
                           WHERE apikey = :k LIMIT 1");
    $st->execute([':k' => $token]);
    $app = $st->fetch();

    // Un solo codigo para "no existe" y para "deshabilitada", igual que el
    // legacy: distinguirlos le dice al que prueba apikeys cual existe.
    if (!$app || (string)$app['habilitada'] !== '1') mpRevelar(602, 'Apikey invalida');

    try {
        $pdo->prepare("UPDATE aplicaciones SET usos = COALESCE(usos,0)+1 WHERE id = :id")
            ->execute([':id' => (int)$app['id']]);
    } catch (Throwable) { /* contador best-effort */ }

    return $app;
}

// ---------------------------------------------------------------------------
// Cuentas
// ---------------------------------------------------------------------------

/** Fila de `mercadopagocuentas` por uuid publico, o null. */
function mpCuentaPorUuid(string $uuid): ?array {
    if ($uuid === '') return null;
    $st = db()->prepare("SELECT * FROM mercadopagocuentas WHERE uuid = :u LIMIT 1");
    $st->execute([':u' => $uuid]);
    return $st->fetch() ?: null;
}

/** Fila de `mercadopagocuentas` por id interno, o null. */
function mpCuentaPorId(int $id): ?array {
    if ($id <= 0) return null;
    $st = db()->prepare("SELECT * FROM mercadopagocuentas WHERE id = :i LIMIT 1");
    $st->execute([':i' => $id]);
    return $st->fetch() ?: null;
}

/**
 * Par [publicKey, accessToken] segun `modo`. Ver la nota del encabezado: esto
 * lo usa SOLO el checkout; el webhook y el alta de suscripciones van derecho a
 * `accessToken`.
 */
function mpCredenciales(array $cuenta): array {
    return ((string)($cuenta['modo'] ?? '')) === '1'
        ? [(string)($cuenta['publicKeyTesting'] ?? ''), (string)($cuenta['accessTokenTesting'] ?? '')]
        : [(string)($cuenta['publicKey']        ?? ''), (string)($cuenta['accessToken']        ?? '')];
}

/**
 * Dispara el callback de imputacion del sistema origen, si la cuenta lo tiene
 * configurado. Es el mecanismo por el que el comercio se entera de que el
 * movimiento quedo acreditado.
 *
 * Best-effort a proposito: si el sistema origen esta caido NO se puede fallar
 * la respuesta al webhook, porque Mercado Pago reintentaria la notificacion y
 * volveriamos a procesar el mismo pago.
 *
 * SOLO se registra cuando sale mal. El legacy no registra nada y una
 * imputacion fallida es hoy invisible — pero dejar una fila por cada
 * imputacion exitosa duplicaria el volumen de `mercadopagoregistros` sin
 * agregar nada: el exito ya se ve en el estado del pago.
 *
 * El legacy usa file_get_contents(); aca va curl con timeout, porque un
 * sistema origen que no cierra la conexion colgaba el worker de Apache hasta
 * el default_socket_timeout (60 s).
 */
function mpImputar(string $url): void {
    if ($url === '') return;
    [$cuerpo, $http, $error] = mpHttp('GET', $url);

    if ($error !== '') {
        mpRegistrar(MP_REG_INFO, 'Imputacion fallida (' . $error . '): ' . $url);
        return;
    }
    if ($http < 200 || $http > 299) {
        mpRegistrar(MP_REG_INFO, 'Imputacion HTTP ' . $http . ' en ' . $url
            . ' -> ' . substr($cuerpo, 0, 1000));
    }
}

// ---------------------------------------------------------------------------
// Registros (`mercadopagoregistros`)
// ---------------------------------------------------------------------------

/**
 * Agrega una linea al log crudo. Equivale a `mcMercadopagoRegistro::registrar()`.
 * Nunca lanza: perder una linea de log no puede convertirse en un 500 que haga
 * a Mercado Pago reintentar la notificacion.
 */
function mpRegistrar(string $tipo, mixed $cuerpo): void {
    try {
        if (!is_string($cuerpo)) $cuerpo = json_encode($cuerpo, JSON_UNESCAPED_UNICODE);
        db()->prepare("INSERT INTO mercadopagoregistros (fecha, tipo, cuerpo)
                       VALUES (:f, :t, :c)")
            ->execute([':f' => mpAhora(), ':t' => $tipo, ':c' => (string)$cuerpo]);
    } catch (Throwable) { /* el log no rompe el flujo */ }
}

// ---------------------------------------------------------------------------
// Pagos (`mercadopagopagos`)
// ---------------------------------------------------------------------------

/**
 * Alta de un pago. Devuelve el id nuevo.
 *
 * Los defaults son los de `mcMercadopagoPago::nuevo()`: uuid aleatorio,
 * `iniciado` = ahora, `finalizado` = centinela, `estado` = 'I' (iniciado).
 */
function mpPagoAlta(array $c): int {
    $pdo = db();
    $st  = $pdo->prepare(
        "INSERT INTO mercadopagopagos
            (uuid, cuenta, factura, recibo, iniciado, finalizado, concepto, monto,
             operacion, retorno, estado, notificacion, propiedades)
         VALUES
            (:uuid, :cuenta, :factura, :recibo, :iniciado, :finalizado, :concepto, :monto,
             :operacion, :retorno, :estado, :notificacion, :propiedades)"
    );
    $st->execute([
        ':uuid'         => $c['uuid']         ?? mpUuid(),
        ':cuenta'       => (int)($c['cuenta'] ?? 0),
        ':factura'      => (int)($c['factura'] ?? 0),
        ':recibo'       => (int)($c['recibo']  ?? 0),
        ':iniciado'     => $c['iniciado']      ?? mpAhora(),
        ':finalizado'   => $c['finalizado']    ?? MP_GENESIS,
        ':concepto'     => (string)($c['concepto'] ?? ''),
        ':monto'        => mpCero($c['monto'] ?? 0),
        ':operacion'    => (string)($c['operacion'] ?? ''),
        ':retorno'      => (string)($c['retorno']   ?? ''),
        ':estado'       => (string)($c['estado']    ?? 'I'),
        ':notificacion' => (string)($c['notificacion'] ?? ''),
        ':propiedades'  => (string)($c['propiedades']  ?? ''),
    ]);
    return (int)$pdo->lastInsertId();
}

/** Fila de `mercadopagopagos` por id, o null. */
function mpPagoPorId(int $id): ?array {
    if ($id <= 0) return null;
    $st = db()->prepare("SELECT * FROM mercadopagopagos WHERE id = :i LIMIT 1");
    $st->execute([':i' => $id]);
    return $st->fetch() ?: null;
}

/**
 * UPDATE parcial sobre las columnas indicadas.
 *
 * El legacy reescribe la fila entera en cada `modificar()` (SET de las 13
 * columnas con lo que tenga el objeto en memoria). Aca se tocan solo las que
 * se pasan: el resultado observable es el mismo cuando el objeto venia de un
 * `leer()`, y evita el modo de falla del legacy — cualquier campo que quedo
 * sin cargar se escribia como cadena vacia sobre el dato bueno.
 */
function mpPagoActualizar(int $id, array $campos): void {
    $validas = ['uuid', 'cuenta', 'factura', 'recibo', 'iniciado', 'finalizado',
                'concepto', 'monto', 'operacion', 'retorno', 'estado',
                'notificacion', 'propiedades'];
    mpActualizar('mercadopagopagos', $validas, $id, $campos);
}

// ---------------------------------------------------------------------------
// Suscripciones (`mercadopagosuscripciones`)
// ---------------------------------------------------------------------------

/** Fila de `mercadopagosuscripciones` por uuid (= `preapproval_id` de MP), o null. */
function mpSuscripcionPorUuid(string $uuid): ?array {
    if ($uuid === '') return null;
    $st = db()->prepare("SELECT * FROM mercadopagosuscripciones WHERE uuid = :u LIMIT 1");
    $st->execute([':u' => $uuid]);
    return $st->fetch() ?: null;
}

/** Fila de `mercadopagosuscripciones` por id interno, o null. */
function mpSuscripcionPorId(int $id): ?array {
    if ($id <= 0) return null;
    $st = db()->prepare("SELECT * FROM mercadopagosuscripciones WHERE id = :i LIMIT 1");
    $st->execute([':i' => $id]);
    return $st->fetch() ?: null;
}

/**
 * Mapea el JSON de un preapproval de Mercado Pago a las columnas de
 * `mercadopagosuscripciones`. Port de `mcMercadopagoSuscripcion::traducir()`.
 *
 * `$estadoActual` es el estado que la fila tiene AHORA en la base: las marcas
 * de tiempo del ciclo de vida (`iniciada`, `pausada`, `reactivada`,
 * `finalizada`) se sellan por TRANSICION, no por estado final. Una suscripcion
 * que ya estaba `authorized` y vuelve a notificar `authorized` no re-sella
 * `iniciada`, que es lo que hace util a esa columna.
 *
 * Devuelve el array de columnas a escribir. No toca la base.
 */
function mpSuscripcionTraducir(string $propiedades, string $estadoActual): array {
    $periodo = (string)mpJsonLeer($propiedades, 'auto_recurring', 'frequency_type');

    $pruebaPeriodo = (string)mpJsonLeer($propiedades, 'auto_recurring', 'free_trial', 'frequency_type');
    if ($pruebaPeriodo === '') $pruebaPeriodo = $periodo;

    $pruebaFrecuencia = mpJsonLeer($propiedades, 'auto_recurring', 'free_trial', 'frequency');
    if ($pruebaFrecuencia === '') $pruebaFrecuencia = 0;

    $estadoNuevo = (string)mpJsonLeer($propiedades, 'status');

    $campos = [
        'uuid'             => (string)mpJsonLeer($propiedades, 'id'),
        'referencia'       => (string)mpJsonLeer($propiedades, 'external_reference'),
        'concepto'         => (string)mpJsonLeer($propiedades, 'reason'),
        'monto'            => mpCero(mpJsonLeer($propiedades, 'auto_recurring', 'transaction_amount')),
        'periodo'          => $periodo,
        'frecuencia'       => (string)mpJsonLeer($propiedades, 'auto_recurring', 'frequency'),
        'pruebaPeriodo'    => $pruebaPeriodo,
        'pruebaFrecuencia' => (string)$pruebaFrecuencia,
        'destino'          => (string)mpJsonLeer($propiedades, 'back_url'),
        'actualizada'      => mpAhora(),
        'estado'           => $estadoNuevo,
        'propiedades'      => $propiedades,
    ];

    // Transiciones del ciclo de vida. Mismo juego de reglas que el legacy.
    if ($estadoActual === 'pending'   && $estadoNuevo === 'authorized') $campos['iniciada']   = mpAhora();
    if ($estadoActual === 'authorized' && $estadoNuevo === 'paused')    $campos['pausada']    = mpAhora();
    if ($estadoActual === 'paused'     && $estadoNuevo === 'authorized') $campos['reactivada'] = mpAhora();
    if (in_array($estadoActual, ['pending', 'authorized', 'paused'], true)
        && $estadoNuevo === 'cancelled')                                $campos['finalizada'] = mpAhora();

    return $campos;
}

/**
 * Alta de una suscripcion. Devuelve el id nuevo.
 * Defaults de `mcMercadopagoSuscripcion::nuevo()`.
 */
function mpSuscripcionAlta(array $c): int {
    $pdo = db();
    $st  = $pdo->prepare(
        "INSERT INTO mercadopagosuscripciones
            (uuid, cuenta, nombre, celular, correo, referencia, concepto, monto,
             periodo, frecuencia, pruebaPeriodo, pruebaFrecuencia, destino,
             registrada, actualizada, iniciada, pausada, reactivada, finalizada,
             estado, propiedades)
         VALUES
            (:uuid, :cuenta, :nombre, :celular, :correo, :referencia, :concepto, :monto,
             :periodo, :frecuencia, :pruebaPeriodo, :pruebaFrecuencia, :destino,
             :registrada, :actualizada, :iniciada, :pausada, :reactivada, :finalizada,
             :estado, :propiedades)"
    );
    $st->execute([
        ':uuid'             => (string)($c['uuid'] ?? ''),
        ':cuenta'           => (int)($c['cuenta'] ?? 0),
        ':nombre'           => (string)($c['nombre']  ?? ''),
        ':celular'          => (string)($c['celular'] ?? ''),
        ':correo'           => (string)($c['correo']  ?? ''),
        ':referencia'       => (string)($c['referencia'] ?? ''),
        ':concepto'         => (string)($c['concepto']   ?? ''),
        ':monto'            => mpCero($c['monto'] ?? 0),
        ':periodo'          => (string)($c['periodo']    ?? 'months'),
        ':frecuencia'       => (string)($c['frecuencia'] ?? '1'),
        ':pruebaPeriodo'    => (string)($c['pruebaPeriodo']    ?? 'months'),
        ':pruebaFrecuencia' => (string)($c['pruebaFrecuencia'] ?? '0'),
        ':destino'          => (string)($c['destino'] ?? ''),
        ':registrada'       => $c['registrada']  ?? mpAhora(),
        ':actualizada'      => $c['actualizada'] ?? mpAhora(),
        ':iniciada'         => $c['iniciada']    ?? MP_GENESIS,
        ':pausada'          => $c['pausada']     ?? MP_GENESIS,
        ':reactivada'       => $c['reactivada']  ?? MP_GENESIS,
        ':finalizada'       => $c['finalizada']  ?? MP_GENESIS,
        ':estado'           => (string)($c['estado'] ?? 'pending'),
        ':propiedades'      => (string)($c['propiedades'] ?? ''),
    ]);
    return (int)$pdo->lastInsertId();
}

/** UPDATE parcial sobre `mercadopagosuscripciones`. */
function mpSuscripcionActualizar(int $id, array $campos): void {
    $validas = ['uuid', 'cuenta', 'nombre', 'celular', 'correo', 'referencia',
                'concepto', 'monto', 'periodo', 'frecuencia', 'pruebaPeriodo',
                'pruebaFrecuencia', 'destino', 'registrada', 'actualizada',
                'iniciada', 'pausada', 'reactivada', 'finalizada', 'estado',
                'propiedades'];
    mpActualizar('mercadopagosuscripciones', $validas, $id, $campos);
}

// ---------------------------------------------------------------------------
// Debitos (`mercadopagodebitos`)
// ---------------------------------------------------------------------------

/**
 * Mapea el JSON de un authorized_payment a las columnas de `mercadopagodebitos`.
 * Port de `mcMercadopagoDebito::traducir()`, mapeo de estado incluido:
 *
 *   approved -> A    rejected -> R    pending -> P
 *   cancelled -> C   refunded -> F    (cualquier otro) -> ''
 */
function mpDebitoTraducir(string $propiedades): array {
    $suscripcionUuid = (string)mpJsonLeer($propiedades, 'preapproval_id');
    $suscripcion     = mpSuscripcionPorUuid($suscripcionUuid);

    $estado = match ((string)mpJsonLeer($propiedades, 'payment', 'status')) {
        'approved'  => 'A',
        'rejected'  => 'R',
        'pending'   => 'P',
        'cancelled' => 'C',
        'refunded'  => 'F',
        default     => '',
    };

    return [
        'suscripcion' => (int)($suscripcion['id'] ?? 0),
        'operacion'   => (string)mpJsonLeer($propiedades, 'payment', 'id'),
        'concepto'    => (string)mpJsonLeer($propiedades, 'reason'),
        'referencia'  => (string)mpJsonLeer($propiedades, 'external_reference'),
        'monto'       => mpCero(mpJsonLeer($propiedades, 'transaction_amount')),
        'estado'      => $estado,
        'propiedades' => $propiedades,
    ];
}

/** Alta de un debito. Devuelve el id nuevo. Defaults de `mcMercadopagoDebito::nuevo()`. */
function mpDebitoAlta(array $c): int {
    $pdo = db();
    $st  = $pdo->prepare(
        "INSERT INTO mercadopagodebitos
            (uuid, cuenta, suscripcion, referencia, recibo, fecha, concepto,
             monto, operacion, estado, propiedades)
         VALUES
            (:uuid, :cuenta, :suscripcion, :referencia, :recibo, :fecha, :concepto,
             :monto, :operacion, :estado, :propiedades)"
    );
    $st->execute([
        ':uuid'        => $c['uuid'] ?? mpUuid(),
        ':cuenta'      => (int)($c['cuenta'] ?? 0),
        ':suscripcion' => (int)($c['suscripcion'] ?? 0),
        ':referencia'  => (string)($c['referencia'] ?? ''),
        ':recibo'      => (int)($c['recibo'] ?? 0),
        ':fecha'       => $c['fecha'] ?? mpAhora(),
        ':concepto'    => (string)($c['concepto'] ?? ''),
        ':monto'       => mpCero($c['monto'] ?? 0),
        ':operacion'   => (string)($c['operacion'] ?? ''),
        ':estado'      => (string)($c['estado'] ?? 'I'),
        ':propiedades' => (string)($c['propiedades'] ?? ''),
    ]);
    return (int)$pdo->lastInsertId();
}

// ---------------------------------------------------------------------------
// UPDATE parcial generico
// ---------------------------------------------------------------------------

/**
 * `UPDATE <tabla> SET ... WHERE id = :id` con lista blanca de columnas.
 *
 * La lista blanca no es decorativa: las claves de `$campos` vienen de
 * funciones de traduccion que a su vez leen JSON de Mercado Pago. Sin el
 * filtro, un campo inesperado en ese JSON se colaria al SQL.
 */
function mpActualizar(string $tabla, array $validas, int $id, array $campos): void {
    if ($id <= 0) return;
    $sets = [];
    $bind = [':id' => $id];
    foreach ($campos as $col => $val) {
        if (!in_array($col, $validas, true)) continue;
        $sets[] = "`{$col}` = :{$col}";
        $bind[":{$col}"] = $val;
    }
    if (!$sets) return;
    db()->prepare("UPDATE `{$tabla}` SET " . implode(', ', $sets) . " WHERE id = :id")
        ->execute($bind);
}

// ---------------------------------------------------------------------------
// Cliente HTTP
// ---------------------------------------------------------------------------

/**
 * Una sola funcion para todas las llamadas salientes.
 * Devuelve `[cuerpo, httpCode, error]`; `error` es '' cuando la conexion salio
 * bien, sin importar el status HTTP (un 404 de Mercado Pago es una respuesta
 * valida que hay que guardar en `propiedades`, no un fallo de transporte).
 */
function mpHttp(string $metodo, string $url, ?string $cuerpo = null, array $headers = []): array {
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_CUSTOMREQUEST  => $metodo,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => MP_TIMEOUT,
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_HTTPHEADER     => $headers,
    ]);
    if ($cuerpo !== null) curl_setopt($ch, CURLOPT_POSTFIELDS, $cuerpo);

    $respuesta = curl_exec($ch);
    $http      = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $error     = curl_error($ch);
    curl_close($ch);

    return [is_string($respuesta) ? $respuesta : '', $http, $error];
}

/** Llamada autenticada a la API de Mercado Pago. Devuelve el JSON crudo. */
function mpApi(string $metodo, string $ruta, string $token, ?array $payload = null): string {
    $headers = ['Authorization: Bearer ' . $token, 'Accept: application/json'];
    $cuerpo  = null;
    if ($payload !== null) {
        $headers[] = 'Content-Type: application/json';
        $cuerpo    = json_encode($payload, JSON_UNESCAPED_UNICODE);
    }
    [$respuesta, , $error] = mpHttp($metodo, MP_API . $ruta, $cuerpo, $headers);

    // Un fallo de transporte se devuelve como JSON para que el caller lo pueda
    // guardar en `propiedades` y verlo despues en el panel. El legacy guardaba
    // la cadena vacia y no quedaba rastro de por que.
    if ($error !== '' && $respuesta === '') {
        return json_encode(['error' => 'curl', 'message' => $error], JSON_UNESCAPED_UNICODE);
    }
    return $respuesta;
}

/** `GET /v1/payments/{id}` — datos del pago manual que notifico el webhook. */
function mpApiPagoObtener(string $token, string $pagoMpId): string {
    return mpApi('GET', '/v1/payments/' . rawurlencode($pagoMpId), $token);
}

/** `GET /preapproval/{uuid}` — estado actual de una suscripcion. */
function mpApiSuscripcionObtener(string $token, string $uuid): string {
    return mpApi('GET', '/preapproval/' . rawurlencode($uuid), $token);
}

/** `GET /authorized_payments/{id}` — datos de un debito recurrente. */
function mpApiDebitoObtener(string $token, string $debitoId): string {
    return mpApi('GET', '/authorized_payments/' . rawurlencode($debitoId), $token);
}

/**
 * `POST /preapproval` — alta de suscripcion.
 * `free_trial` va en null cuando `pruebaFrecuencia` es 0, que es como Mercado
 * Pago espera "sin periodo de prueba" (mandar `{"frequency":0}` da 400).
 */
function mpApiSuscripcionAgregar(
    string $token, string $correo, string $referencia, string $concepto,
    mixed $monto, string $periodo, mixed $frecuencia,
    string $pruebaPeriodo, mixed $pruebaFrecuencia, string $destino
): string {
    $prueba = ((int)mpCero($pruebaFrecuencia) > 0)
        ? ['frequency' => (int)$pruebaFrecuencia, 'frequency_type' => $pruebaPeriodo]
        : null;

    return mpApi('POST', '/preapproval', $token, [
        'reason'             => $concepto,
        'external_reference' => $referencia,
        'payer_email'        => $correo,
        'back_url'           => $destino,
        'auto_recurring'     => [
            'frequency'          => (int)mpCero($frecuencia),
            'frequency_type'     => $periodo,
            'transaction_amount' => (float)mpCero($monto),
            'free_trial'         => $prueba,
            'currency_id'        => 'ARS',
        ],
    ]);
}

/**
 * `POST /checkout/preferences` — crea la preferencia del Checkout Pro.
 *
 * El legacy usaba el SDK oficial (`MercadoPago\SDK` + `MercadoPago\Preference`).
 * Aca va curl directo: el arbol `api/` no tiene composer ni vendor, y arrastrar
 * el SDK entero para un POST de un objeto no se paga. El body es el mismo que
 * arma el SDK para ese caso.
 */
function mpApiPreferenciaCrear(
    string $token, string $titulo, mixed $cantidad, mixed $precio,
    string $referenciaExterna, array $backUrls
): string {
    return mpApi('POST', '/checkout/preferences', $token, [
        'items' => [[
            'title'      => $titulo,
            'quantity'   => (int)mpCero($cantidad),
            'unit_price' => (float)mpCero($precio),
        ]],
        'back_urls'          => $backUrls,
        'auto_return'        => 'approved',
        'external_reference' => $referenciaExterna,
    ]);
}

// ---------------------------------------------------------------------------
// URL publica del microservicio
// ---------------------------------------------------------------------------

/**
 * Base publica de ESTE microservicio, que es lo que se le declara a Mercado
 * Pago como `back_urls`. Tiene que ser una URL que Mercado Pago pueda resolver
 * desde afuera: `auto_return=approved` rechaza la preferencia si `success` no
 * es alcanzable, asi que en desarrollo hay que apuntar a un tunel.
 *
 * Orden de resolucion:
 *   1. `MP_PUBLIC_BASE` del .env — lo que se usa para un tunel en desarrollo.
 *   2. `https://api.databox.net.ar` en produccion.
 *   3. `http://localhost:8114` en desarrollo (sirve para probar el HTML del
 *      boton; el checkout real no va a completar).
 */
function mpBasePublica(): string {
    $base = trim((string)(getenv('MP_PUBLIC_BASE') ?: ''));
    if ($base !== '') return rtrim($base, '/');
    return (getenv('APP_ENV') === 'production')
        ? 'https://api.databox.net.ar'
        : 'http://localhost:8114';
}

/** URL absoluta de un endpoint del microservicio (sin `.php`). */
function mpUrl(string $endpoint): string {
    return mpBasePublica() . '/v4/mercadopago/' . ltrim($endpoint, '/');
}

// ---------------------------------------------------------------------------
// Sesion del checkout
// ---------------------------------------------------------------------------

/**
 * Abre la sesion PHP del checkout.
 *
 * Solo la usan `pagar` -> `procesar` -> `aprobado|pendiente|rechazado`, que son
 * los unicos endpoints que atiende un navegador. Nombre de cookie propio para
 * no compartir sesion con el panel cloud ni con el legacy: los tres corren
 * sobre el mismo dominio de segundo nivel y una colision de `PHPSESSID` haria
 * que un pago en curso pise al otro.
 *
 * `SameSite=Lax` y no `Strict` porque el usuario vuelve del checkout de Mercado
 * Pago por una navegacion cross-site: con `Strict` la cookie no viaja en ese
 * retorno y `aprobado` pierde el `pagoId` de la sesion.
 */
function mpSesion(): void {
    if (session_status() === PHP_SESSION_ACTIVE) return;
    session_name('DBXMP');
    session_set_cookie_params([
        'lifetime' => 0,
        'path'     => '/v4/mercadopago/',
        'secure'   => (($_SERVER['HTTPS'] ?? '') !== '')
                      || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https'),
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    @session_start();
}

/** Lectura de una variable de sesion con el '' por defecto del legacy. */
function mpSesionLeer(string $clave): string {
    mpSesion();
    return (string)($_SESSION[$clave] ?? '');
}

/** Escritura de una variable de sesion. */
function mpSesionEscribir(string $clave, mixed $valor): void {
    mpSesion();
    $_SESSION[$clave] = $valor;
}

/** Limpia las variables del pago en curso. Se llama al cerrar el circuito. */
function mpSesionLimpiar(): void {
    mpSesion();
    foreach (['pagoId', 'cuentaId', 'facturaId', 'facturaMonto', 'cuentaNombre',
              'cuentaPublicKey', 'cuentaAccessToken'] as $clave) {
        unset($_SESSION[$clave]);
    }
}
