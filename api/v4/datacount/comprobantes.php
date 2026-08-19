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
// El endpoint SOLO da de alta el comprobante en estado '2' (Pendiente) y
// devuelve 201. La autorizacion contra AFIP es 100% asincronica: la hace
// el cron `cloud/jobs/datacount_comprobantes_autorizar.php` cuando le
// llega el turno al comprobante. Este endpoint NO llama a AFIP.
//
// Response: siempre 201 con el comprobante creado. Los campos
// `cae`, `cae_vto`, `cbte_nro`, `serie`, `autorizado` y `caeres` salen
// en `null` -- todavia no se autorizo. Para conocer el resultado final
// el caller espera el webhook (ver abajo) o consulta el ABM por `id`/`uuid`.
//
// El `concepto` por defecto es 3 (Productos + Servicios) -- el uso tipico
// de esta ingesta v4 es facturacion mixta. Se puede pisar en el body con
// 1 (Productos) o 2 (Servicios) si el caller sabe que va a facturar puro.
//
// Webhook opcional: si el caller manda `webhook_url` en el body, el
// comprobante queda con `webhook_estado = 'pendiente'`. Cuando el cron de
// autorizacion logre el CAE, el cron
// `cloud/jobs/datacount_comprobantes_notificar.php` hace POST JSON al
// receptor con el snapshot final del comprobante, reintentando hasta que
// devuelva 2xx. Ni el alta ni la autorizacion disparan el webhook -- todo
// pasa por el cron de notificacion.
//
// Auth: Bearer con apikey de la tabla `aplicaciones` (mismo esquema que el
// resto de los microservicios v4).

declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');

require_once dirname(__DIR__, 3) . '/env.php';
require_once dirname(__DIR__, 3) . '/cloud/api/db.php';
require_once dirname(__DIR__) . '/_lib/log.php';

// Todo error de este endpoint queda registrado en `sucesos` (Visor de sucesos
// del panel). Va antes de la auth para que los 401 tambien caigan adentro.
//
// Antes esto lo hacia un wrapper propio (`dcErrorAlta`) que llamaba a
// registrarSuceso() en cada rechazo. Se saco: el handler compartido cubre lo
// mismo sin depender de que cada rechazo nuevo se acuerde de usar el wrapper,
// y ademas atrapa los fatales PHP, que el wrapper no veia.
v4InitLog('v4/datacount.comprobantes');

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
    v4LogApp(dcRequireApp());
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

    // Webhook opcional -- si el caller manda `webhook_url`, dejamos el
    // comprobante marcado con `webhook_estado = 'pendiente'` para que el
    // proceso que dispara la notificacion post-autorizacion sepa que tiene
    // que hacer POST a esa URL cuando el comprobante quede en estado '3'.
    $webhookUrl    = dcNullableStr($in['webhook_url'] ?? null, 500);
    $webhookEstado = $webhookUrl !== null ? 'pendiente' : null;

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
                 medio, registrado, autorizado, estado, webhook_url, webhook_estado)
            VALUES
                (:uuid, :talonario, :proyecto, :empresa, :tipo, :punto, NULL, :fiscal,
                 NULL, NULL, NULL, :emision, :vencimiento, :concepto,
                 :asociado, :contrato, :cliente, :razon, :condicion, :cuit, :domicilio,
                 :correo, :celular, :neto, :iva, :total, :observaciones, :comentarios,
                 :medio, :registrado, NULL, :estado, :webhook_url, :webhook_estado)
        ";
        $ins = $pdo->prepare($sql);
        $ins->execute([
            ':uuid'           => $uuid,
            ':talonario'      => $talonarioId,
            ':proyecto'       => $tal['proyecto'] !== null ? (int)$tal['proyecto'] : null,
            ':empresa'        => $tal['empresa']  !== null ? (int)$tal['empresa']  : null,
            ':tipo'           => $tal['tipo'],
            ':punto'          => $tal['punto']    !== null ? (int)$tal['punto']    : null,
            ':fiscal'         => $tal['fiscal'],
            ':emision'        => $emision,
            ':vencimiento'    => $vto,
            ':concepto'       => $concepto,
            ':asociado'       => dcNullableInt($in['asociado'] ?? null),
            ':contrato'       => dcNullableInt($in['contrato'] ?? null),
            ':cliente'        => dcNullableInt($in['cliente']  ?? null),
            ':razon'          => dcNullableStr($in['razon']     ?? null, 250),
            ':condicion'      => dcNullableStr($in['condicion'] ?? null, 2),
            ':cuit'           => dcNullableStr($in['cuit']      ?? null, 50),
            ':domicilio'      => dcNullableStr($in['domicilio'] ?? null, 250),
            ':correo'         => dcNullableStr($in['correo']    ?? null, 100),
            ':celular'        => dcNullableStr($in['celular']   ?? null, 100),
            ':neto'           => $r['neto'],
            ':iva'            => $r['iva'],
            ':total'          => $r['total'],
            ':observaciones'  => dcNullableStr($in['observaciones'] ?? null, 2000),
            ':comentarios'    => dcNullableStr($in['comentarios']   ?? null, 2000),
            ':medio'          => dcNullableInt($in['medio'] ?? null),
            ':registrado'     => $registrado,
            ':estado'         => $estado,
            ':webhook_url'    => $webhookUrl,
            ':webhook_estado' => $webhookEstado,
        ]);
        $id = (int)$pdo->lastInsertId();

        dcInsertarRenglones($pdo, $id, $r['lineas']);
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    }

    // Response: comprobante recien creado, todavia sin CAE. La autorizacion
    // AFIP la hace el cron `datacount_comprobantes_autorizar.php` en su
    // proximo tick; si el caller cargo `webhook_url`, el cron
    // `datacount_comprobantes_notificar.php` avisara con el snapshot final
    // cuando se logre el CAE.
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
        'estado'         => $estado,
        'registrado'     => $registrado,
        'webhook_url'    => $webhookUrl,
        'webhook_estado' => $webhookEstado,
        'cae'         => null,
        'cae_vto'     => null,
        'cbte_nro'    => null,
        'serie'       => null,
        'autorizado'  => null,
        'caeres'      => null,
    ];

    jsonOk($out, 201);
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
