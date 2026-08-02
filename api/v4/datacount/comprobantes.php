<?php
// api/v4/datacount/comprobantes.php
// Microservicio de alta de comprobantes Datacount.
//
//   POST /v4/datacount/comprobantes   (JSON body) -> {ok:true, data:{id, uuid, ...}}
//
// Simplificacion respecto al ABM `cloud/api/datacount_comprobantes.php`:
// el caller solo debe pasar `talonario` (id) — de ahi salen proyecto, empresa,
// tipo, punto y fiscal. Todo lo demas es opcional; los renglones definen el
// importe (neto/iva/total se recalculan server-side).
//
// El comprobante se inserta en estado '2' (Pendiente) y se autoriza contra
// AFIP EN LA MISMA REQUEST, delegando en la lib compartida
// `cloud/api/lib/datacount_comprobantes_autorizar.php::dccAutAutorizar()` --
// mismo canal que usa el cron `jobs/datacount_comprobantes_autorizar.php` y
// el boton "Autorizar" del ABM. Si AFIP responde OK, el comprobante queda
// en estado '3' con `cae`, `caevto`, `serie` y `autorizado` seteados; la
// response HTTP incluye esos datos.
//
// Si AFIP rechaza o falla, el comprobante queda persistido en estado '2'
// con el error en `caeres`, y la response es HTTP 422 con el mensaje +
// el id/uuid para que el caller pueda referenciarlo despues (el cron lo
// va a reintentar segun su ciclo).
//
// Si el talonario NO es fiscal, o el caller pisa el `estado` con algo
// distinto de '2' (por ej. '1' Preparacion para un borrador), NO se
// intenta autorizar -- se devuelve el comprobante como fue creado.
//
// El `concepto` por defecto es 3 (Productos + Servicios) — el uso tipico de
// esta ingesta v4 es facturacion mixta. Se puede pisar en el body con 1
// (Productos) o 2 (Servicios) si el caller sabe que va a facturar puro.
//
// Auth: Bearer con apikey de la tabla `aplicaciones` (mismo esquema que el
// resto de los microservicios v4).

declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');

require_once dirname(__DIR__, 3) . '/env.php';
require_once dirname(__DIR__, 3) . '/cloud/api/db.php';
require_once dirname(__DIR__, 3) . '/cloud/api/lib/datacount_comprobantes_autorizar.php';
require_once dirname(__DIR__, 3) . '/cloud/api/lib/parametros.php';
require_once dirname(__DIR__, 3) . '/cloud/api/lib/sucesos.php';

// ---------------------------------------------------------------------------
// Auth
// ---------------------------------------------------------------------------

function dcReadBearer(): string {
    $auth = trim((string)($_SERVER['HTTP_AUTHORIZATION']
                       ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION']
                       ?? ''));
    if ($auth === '' && function_exists('getallheaders')) {
        foreach (getallheaders() as $k => $v) {
            if (strcasecmp($k, 'Authorization') === 0) { $auth = trim((string)$v); break; }
        }
    }
    return stripos($auth, 'Bearer ') === 0 ? trim(substr($auth, 7)) : '';
}

function dcRequireApp(): array {
    $token = dcReadBearer();
    if ($token === '') jsonError('Bearer token ausente', 401);

    $pdo = db();
    $st  = $pdo->prepare('SELECT id, nombre, habilitada FROM aplicaciones WHERE apikey = :k LIMIT 1');
    $st->execute([':k' => $token]);
    $app = $st->fetch();
    if (!$app)                              jsonError('API key desconocida', 401);
    if ((string)$app['habilitada'] !== '1') jsonError('Aplicacion deshabilitada', 401);

    // Contador de uso -- best effort, un fallo aca no debe tumbar el request.
    try {
        $pdo->prepare('UPDATE aplicaciones SET usos = COALESCE(usos,0)+1 WHERE id = :id')
            ->execute([':id' => (int)$app['id']]);
    } catch (Throwable) { /* ignore */ }

    return $app;
}

// ---------------------------------------------------------------------------
// Ruteo
// ---------------------------------------------------------------------------

try {
    dcRequireApp();
    $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
    if ($method !== 'POST') jsonError('Metodo no soportado', 405);

    handleCreate(readJsonBody());
} catch (Throwable $e) {
    jsonError($e->getMessage(), 500);
}

// ---------------------------------------------------------------------------
// Handler
// ---------------------------------------------------------------------------

function handleCreate(array $in): void {
    $talonarioId = isset($in['talonario']) && $in['talonario'] !== ''
                        ? (int)$in['talonario'] : 0;
    if ($talonarioId <= 0) jsonError('Falta `talonario` (id > 0).', 400);

    $pdo = db();

    // Datos heredados del talonario: proyecto, empresa, tipo, punto, fiscal.
    // El caller no los pisa -- son atributos del talonario.
    $tSt = $pdo->prepare(
        'SELECT id, proyecto, empresa, tipo, punto, fiscal
           FROM datacount_talonarios WHERE id = :id LIMIT 1'
    );
    $tSt->execute([':id' => $talonarioId]);
    $tal = $tSt->fetch();
    if (!$tal) jsonError("Talonario {$talonarioId} no existe.", 404);

    // Renglones son obligatorios: sin ellos no hay como calcular neto/iva/total.
    $renglones = $in['renglones'] ?? null;
    if (!is_array($renglones) || !$renglones) {
        jsonError('Falta `renglones` (array con al menos 1 item).', 400);
    }
    $r = dcSanitizeRenglones($renglones);
    if (!$r['lineas']) {
        jsonError('`renglones` no contiene items validos.', 400);
    }

    // Defaults: emision = hoy AR, vencimiento = hoy+7, concepto = 1 (Productos).
    $tzAR    = new DateTimeZone('America/Argentina/Buenos_Aires');
    $ahora   = new DateTime('now', $tzAR);
    $emision = dcNullableDate($in['emision'] ?? null) ?? $ahora->format('Y-m-d');
    $vto     = dcNullableDate($in['vencimiento'] ?? null)
                    ?? (clone $ahora)->modify('+7 days')->format('Y-m-d');
    $concepto = isset($in['concepto']) && $in['concepto'] !== '' ? (int)$in['concepto'] : 3;
    if (!in_array($concepto, [1, 2, 3], true)) {
        jsonError('`concepto` debe ser 1 (Productos), 2 (Servicios) o 3 (Prod+Serv).', 400);
    }

    // Estado inicial = '2' (Pendiente) -- el comprobante entra directo a la
    // cola de autorizacion AFIP para que el job lo tome en el proximo tick.
    // El caller puede pisarlo con otro valor del catalogo
    // `datacount_comprobante_estado` si tiene motivo (ej. '1' Preparacion
    // para dejarlo en borrador y no dispararlo automaticamente).
    $estado = dcNullableStr($in['estado'] ?? null, 1) ?? '2';

    $uuid       = substr(bin2hex(random_bytes(8)), 0, 10);
    $registrado = $ahora->format('Y-m-d H:i:s');

    $pdo->beginTransaction();
    try {
        $sql = "
            INSERT INTO datacount_comprobantes
                (uuid, talonario, proyecto, empresa, tipo, punto, serie, fiscal,
                 caenro, caevto, caeres, emision, vencimiento, concepto,
                 asociado, contrato, cliente, razon, condicion, cuit, domicilio,
                 correo, celular, neto, iva, total, observaciones, comentarios,
                 medio, registrado, autorizado, estado)
            VALUES
                (:uuid, :talonario, :proyecto, :empresa, :tipo, :punto, NULL, :fiscal,
                 NULL, NULL, NULL, :emision, :vencimiento, :concepto,
                 :asociado, :contrato, :cliente, :razon, :condicion, :cuit, :domicilio,
                 :correo, :celular, :neto, :iva, :total, :observaciones, :comentarios,
                 :medio, :registrado, NULL, :estado)
        ";
        $ins = $pdo->prepare($sql);
        $ins->execute([
            ':uuid'          => $uuid,
            ':talonario'     => $talonarioId,
            ':proyecto'      => $tal['proyecto'] !== null ? (int)$tal['proyecto'] : null,
            ':empresa'       => $tal['empresa']  !== null ? (int)$tal['empresa']  : null,
            ':tipo'          => $tal['tipo'],
            ':punto'         => $tal['punto']    !== null ? (int)$tal['punto']    : null,
            ':fiscal'        => $tal['fiscal'],
            ':emision'       => $emision,
            ':vencimiento'   => $vto,
            ':concepto'      => $concepto,
            ':asociado'      => dcNullableInt($in['asociado'] ?? null),
            ':contrato'      => dcNullableInt($in['contrato'] ?? null),
            ':cliente'       => dcNullableInt($in['cliente']  ?? null),
            ':razon'         => dcNullableStr($in['razon']     ?? null, 250),
            ':condicion'     => dcNullableStr($in['condicion'] ?? null, 2),
            ':cuit'          => dcNullableStr($in['cuit']      ?? null, 50),
            ':domicilio'     => dcNullableStr($in['domicilio'] ?? null, 250),
            ':correo'        => dcNullableStr($in['correo']    ?? null, 100),
            ':celular'       => dcNullableStr($in['celular']   ?? null, 100),
            ':neto'          => $r['neto'],
            ':iva'           => $r['iva'],
            ':total'         => $r['total'],
            ':observaciones' => dcNullableStr($in['observaciones'] ?? null, 2000),
            ':comentarios'   => dcNullableStr($in['comentarios']   ?? null, 2000),
            ':medio'         => dcNullableInt($in['medio'] ?? null),
            ':registrado'    => $registrado,
            ':estado'        => $estado,
        ]);
        $id = (int)$pdo->lastInsertId();

        dcInsertarRenglones($pdo, $id, $r['lineas']);
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    }

    // Base de la response -- lo que devolvemos SIEMPRE, haya o no autorizacion.
    // Los campos de CAE (cae, cae_vto, cbte_nro, autorizado, serie, caeres) y
    // el estado final ('3' Autorizado) se pisan mas abajo si autorizamos.
    $out = [
        'id'          => $id,
        'uuid'        => $uuid,
        'talonario'   => $talonarioId,
        'proyecto'    => $tal['proyecto'] !== null ? (int)$tal['proyecto'] : null,
        'empresa'     => $tal['empresa']  !== null ? (int)$tal['empresa']  : null,
        'tipo'        => $tal['tipo'],
        'punto'       => $tal['punto']    !== null ? (int)$tal['punto']    : null,
        'fiscal'      => $tal['fiscal'],
        'concepto'    => $concepto,
        'emision'     => $emision,
        'vencimiento' => $vto,
        'neto'        => $r['neto'],
        'iva'         => $r['iva'],
        'total'       => $r['total'],
        'estado'      => $estado,
        'registrado'  => $registrado,
        'cae'         => null,
        'cae_vto'     => null,
        'cbte_nro'    => null,
        'serie'       => null,
        'autorizado'  => null,
        'caeres'      => null,
    ];

    // Autorizacion sincronica -- solo si el comprobante es fiscal y quedo
    // en estado '2' (Pendiente). Si el caller dejo el estado en '1'
    // (Preparacion) o el talonario no es fiscal, devolvemos sin CAE y punto.
    if ((string)$tal['fiscal'] === '1' && $estado === '2') {
        dcAutorizarSincrono($pdo, $id, $out);
        return; // dcAutorizarSincrono() ya emitio la response (OK o error).
    }

    jsonOk($out, 201);
}

// ---------------------------------------------------------------------------
// Autorizacion sincronica AFIP
// ---------------------------------------------------------------------------
//
// Delega en la lib compartida `dccAutAutorizar` -- mismo canal que el cron
// batch (`jobs/datacount_comprobantes_autorizar.php`) y el boton "Autorizar"
// del ABM (`cloud/api/datacount_comprobantes.php action=autorizar`). Asi las
// tres rutas convergen y no puede haber divergencia entre ellas.
//
// Politica de errores (espeja al ABM manual):
//   - `caeres` se graba siempre con el mensaje del ultimo intento (evidencia).
//   - Si el error es PERMANENTE, se detiene el motor automatico
//     (parametros.datacount.comprobantes.autorizar='0'), porque un fallo
//     sistemico (cert vencido, empresa mal configurada, rechazo AFIP) va a
//     romper igual al cron del proximo minuto.
//   - Se registra suceso 'info' / 'alerta' / 'error' segun corresponda.
//   - Se responde HTTP 422 con el mensaje + el id/uuid ya persistido para
//     que el caller pueda referenciarlo (el cron va a reintentar solo si
//     el error es transitorio; si es permanente, hay que rehabilitar).

function dcAutorizarSincrono(PDO $pdo, int $id, array $out): void {
    $c = dccAutLoadComprobante($pdo, $id);
    if ($c === null) {
        // Practicamente imposible -- lo acabamos de insertar. Pero si llega
        // a pasar, respondemos con lo que tenemos y sin CAE.
        jsonOk($out, 201);
    }

    $apikey = dccAutObtenerApikey($pdo);
    if ($apikey === null) {
        // Sin apikey no podemos hacer el loopback. Comprobante queda en '2';
        // el cron tampoco va a poder, pero al menos avisamos al caller.
        dcResponderErrorAuth($id, $out,
            'No hay ninguna aplicacion habilitada con apikey para el loopback',
            'validacion');
    }

    $r = dccAutAutorizar($pdo, $apikey, $c);

    if (!$r['ok']) {
        $errMsg = (string)$r['error'];
        dccAutMarcarCaeres($pdo, $id, $errMsg);

        // Circuit breaker igual que el cron y el endpoint manual: los
        // permanentes detienen el motor automatico.
        if (!dccAutEsTransitorio($errMsg)) {
            parametroEscribir($pdo, 'datacount.comprobantes.autorizar', '0');
            try {
                registrarSuceso($pdo, 'v4/datacount.comprobantes', 'error',
                    "cbte#{$id}: MOTOR DETENIDO tras alta v4 con error permanente ({$r['fuente']}): {$errMsg}");
            } catch (Throwable $_) { /* no romper el flujo */ }
        } else {
            try {
                registrarSuceso($pdo, 'v4/datacount.comprobantes', 'alerta',
                    "cbte#{$id}: alta v4 fallo autorizar transitoriamente ({$r['fuente']}): {$errMsg}");
            } catch (Throwable $_) { /* no romper el flujo */ }
        }
        dcResponderErrorAuth($id, $out, $errMsg, (string)$r['fuente']);
    }

    // Exito: dccAutAutorizar ya escribio estado='3', caenro, caevto, serie,
    // autorizado, caeres en la BD. Releemos los campos system-managed
    // (autorizado se seteo con NOW() del server MySQL, no en PHP).
    try {
        registrarSuceso($pdo, 'v4/datacount.comprobantes', 'info',
            "cbte#{$id} autorizado en alta v4 - CAE={$r['cae']} nro={$r['cbte_nro']} vto={$r['cae_vto']}");
    } catch (Throwable $_) { /* no romper el flujo */ }

    $st = $pdo->prepare('SELECT autorizado FROM datacount_comprobantes WHERE id = :id LIMIT 1');
    $st->execute([':id' => $id]);
    $autorizado = (string)($st->fetchColumn() ?: '');

    $out['estado']     = '3';
    $out['cae']        = $r['cae'];
    $out['cae_vto']    = $r['cae_vto'];
    $out['cbte_nro']   = $r['cbte_nro'];
    $out['serie']      = $r['cbte_nro'];
    $out['autorizado'] = $autorizado;
    $out['caeres']     = "OK CAE {$r['cae']} (vto {$r['cae_vto']})";

    jsonOk($out, 201);
}

/**
 * Emite HTTP 422 con {ok:false, error, fuente, data:{id, uuid, ...}} para que
 * el caller sepa que el comprobante quedo persistido en estado '2' aunque
 * la autorizacion AFIP fallo. `caeres` en `data` refleja el mensaje que se
 * grabo en BD.
 */
function dcResponderErrorAuth(int $id, array $out, string $errMsg, string $fuente): void {
    http_response_code(422);
    header('Content-Type: application/json; charset=utf-8');
    $out['caeres'] = $errMsg;
    echo json_encode([
        'ok'     => false,
        'error'  => $errMsg,
        'fuente' => $fuente,
        'data'   => $out,
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

// ---------------------------------------------------------------------------
// Helpers (versiones locales de los del ABM cloud -- se copian aca para
// mantener el microservicio autocontenido y evitar acoplarlo al dispatch
// del endpoint de admin).
// ---------------------------------------------------------------------------

function dcNullableStr(mixed $v, ?int $max = null): ?string {
    if ($v === null) return null;
    $s = trim((string)$v);
    if ($s === '') return null;
    if ($max !== null) $s = substr($s, 0, $max);
    return $s;
}

function dcNullableInt(mixed $v): ?int {
    if ($v === null || $v === '') return null;
    return (int)$v;
}

function dcNullableDec(mixed $v): ?string {
    if ($v === null || $v === '') return null;
    $s = str_replace(',', '.', trim((string)$v));
    if (!is_numeric($s)) return null;
    return $s;
}

function dcNullableDate(mixed $v): ?string {
    $s = dcNullableStr($v);
    if ($s === null) return null;
    if (preg_match('/^(\d{4}-\d{2}-\d{2})/', $s, $m)) return $m[1];
    return null;
}

// Recalcula neto/iva/total desde las lineas. iva de cada linea es la alicuota
// en % (ej. 21 para 21%). El total nunca sale del server basado en lo que
// mando el cliente -- asi no hay forma de que la ingesta v4 grabe un
// comprobante con importes inconsistentes contra sus renglones.
function dcSanitizeRenglones(array $raw): array {
    $lineas = [];
    $neto   = 0.0;
    $ivaTot = 0.0;
    $orden  = 0;
    foreach ($raw as $r) {
        if (!is_array($r)) continue;
        $orden++;
        $cant = (float)(dcNullableDec($r['cantidad'] ?? null) ?? '0');
        $unit = (float)(dcNullableDec($r['unitario'] ?? null) ?? '0');
        $ali  = (float)(dcNullableDec($r['iva']      ?? null) ?? '0');
        $sub  = round($cant * $unit, 2);
        $ivaL = round($sub * $ali / 100, 2);
        $neto   += $sub;
        $ivaTot += $ivaL;
        $lineas[] = [
            'orden'    => (int)($r['orden'] ?? $orden),
            'cantidad' => number_format($cant, 2, '.', ''),
            'articulo' => dcNullableInt($r['articulo'] ?? null),
            'detalle'  => dcNullableStr($r['detalle'] ?? null),
            'iva'      => number_format($ali, 2, '.', ''),
            'unitario' => number_format($unit, 2, '.', ''),
            'monto'    => number_format($sub, 2, '.', ''),
            'estado'   => dcNullableStr($r['estado'] ?? null, 1),
        ];
    }
    return [
        'lineas' => $lineas,
        'neto'   => number_format($neto,       2, '.', ''),
        'iva'    => number_format($ivaTot,     2, '.', ''),
        'total'  => number_format($neto + $ivaTot, 2, '.', ''),
    ];
}

function dcInsertarRenglones(PDO $pdo, int $comprobanteId, array $lineas): void {
    if (!$lineas) return;
    $sql = "INSERT INTO datacount_comprobantes_renglones
              (comprobante, orden, cantidad, articulo, detalle, iva, unitario, monto, estado)
            VALUES
              (:comprobante, :orden, :cantidad, :articulo, :detalle, :iva, :unitario, :monto, :estado)";
    $stmt = $pdo->prepare($sql);
    foreach ($lineas as $l) {
        $stmt->execute([
            ':comprobante' => $comprobanteId,
            ':orden'       => $l['orden'],
            ':cantidad'    => $l['cantidad'],
            ':articulo'    => $l['articulo'],
            ':detalle'     => $l['detalle'],
            ':iva'         => $l['iva'],
            ':unitario'    => $l['unitario'],
            ':monto'       => $l['monto'],
            ':estado'      => $l['estado'],
        ]);
    }
}
