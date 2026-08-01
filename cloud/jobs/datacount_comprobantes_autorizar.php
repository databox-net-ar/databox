<?php
/**
 * cloud/jobs/datacount_comprobantes_autorizar.php
 * Cron worker que autoriza contra AFIP los comprobantes fiscales pendientes
 * de `datacount_comprobantes` disparando el microservicio v4:
 *
 *   POST http://localhost:8114/v4/arca/autorizar  (Bearer con apikey de `aplicaciones`)
 *
 * Selecciona comprobantes con:
 *   tipo IN ('FA','FB','FC','FM')  (SOLO facturas -- notas de credito se manejan aparte)
 *   fiscal = '1'                   (los no-fiscales -- prefacturas, presupuestos -- no van a AFIP)
 *   estado = '2'                   (Pendiente segun DC_TRANSICIONES_ESTADO)
 *   caeres IS NULL OR = ''         (no intentados aun; retries manuales al limpiarlo)
 *
 * Por corrida procesa hasta DCC_AUT_LOTE comprobantes (arranca en 1; el cron
 * define la cadencia). El microservicio autonumera el CbteNro pidiendo el
 * ultimo autorizado + 1 -- no le mandamos cbte_desde/hasta.
 *
 * Reglas de cierre por comprobante:
 *   OK:    estado='3', caenro=CAE, caevto=CAEFchVto, serie=cbte_nro,
 *          autorizado=NOW(), caeres="OK CAE <n>"
 *   ERR:   estado queda en '2' (no forzamos '0' -- el operador anula o corrige),
 *          caeres = mensaje de error. Asi el proximo tick se saltea este
 *          comprobante hasta que alguien limpie caeres.
 *
 * El microservicio tambien registra cada intento en `arca_autorizaciones`, por
 * lo que la auditoria tecnica vive alli; aca solo dejamos rastro en el log del
 * cron y en `sucesos` con el resumen humano.
 *
 * Se registra desde el Programador de tareas (tabla `tareas`) apuntando
 * `script` = "datacount_comprobantes_autorizar".
 */

require_once __DIR__ . '/_bootstrap.php';

const DCC_AUT_ENDPOINT   = 'http://localhost:8114/v4/arca/autorizar';
const DCC_AUT_TIMEOUT_S  = 60;    // AFIP WSFEv1 suele responder en < 5s pero WSAA cold puede tardar.
const DCC_AUT_CONNECT_S  = 5;
const DCC_AUT_LOTE       = 1;     // batch size por corrida (subir cuando se estabilice).

// Letra de la factura -> CbteTipo AFIP (WSFEv1). Solo facturas -- las notas
// de credito (NA/NB/NC/NM) se autorizan aparte (requieren `cbtes_asoc`).
// Cualquier otro codigo (FX/PX/etc) es no-fiscal y ni deberia llegar aca por
// el gate `fiscal='1'`, pero si llega el filtro SQL lo descarta.
const DCC_TIPO_A_AFIP = [
    'FA' =>  1,   // Factura A
    'FB' =>  6,   // Factura B
    'FC' => 11,   // Factura C
    'FM' => 51,   // Factura M
];

// Alicuota IVA (formato "0.00" con 2 decimales) -> Id AFIP (FEParamGetTiposIva).
// Si un renglon usa otro %, cerramos el comprobante con error -- AFIP rechazaria.
const DCC_IVA_ID_AFIP = [
    '0.00'  => 3,   // 0%
    '10.50' => 4,   // 10.5%
    '21.00' => 5,   // 21%
    '27.00' => 6,   // 27%
    '5.00'  => 8,   // 5%
    '2.50'  => 9,   // 2.5%
];

$ORIGEN = 'cron/datacount_comprobantes_autorizar';

try {
    $pdo = db();

    // Apikey para el loopback al v4 (mismo patron que telegramcanales/telegram_mensajes).
    $apikey = obtenerApikeyLoopback($pdo);
    if ($apikey === null) {
        $msg = 'No hay ninguna aplicacion habilitada con apikey para el loopback';
        registrarSuceso($pdo, $ORIGEN, 'error', $msg);
        marcarEjecucionError(new RuntimeException($msg));
        exit(1);
    }

    $pendientes = seleccionarPendientes($pdo, DCC_AUT_LOTE);
    $total      = count($pendientes);
    anotarLog('Comprobantes pendientes a autorizar: ' . $total . ' (lote=' . DCC_AUT_LOTE . ')');

    if ($total === 0) {
        marcarEjecucionOk('sin pendientes');
        return;
    }

    $okC = 0; $errC = 0;
    foreach ($pendientes as $i => $c) {
        $prefix = sprintf('[%d/%d] cbte#%d', $i + 1, $total, (int)$c['id']);
        try {
            $ok = autorizarUno($pdo, $apikey, $c, $ORIGEN, $prefix);
            $ok ? $okC++ : $errC++;
        } catch (Throwable $e) {
            // Ultimo dique: si algo escapa del handler por-comprobante, lo dejamos
            // registrado y seguimos con el siguiente. No abortamos la corrida.
            $errC++;
            $msg = 'excepcion no controlada: ' . $e->getMessage();
            marcarErrorComprobante($pdo, (int)$c['id'], $msg);
            registrarSuceso($pdo, $ORIGEN, 'error', "cbte#{$c['id']}: {$msg}");
            anotarLog("{$prefix} - EXC: {$msg}");
        }
    }

    $resumen = "{$okC} OK / {$errC} err / {$total} total";
    anotarLog("Finalizado: {$resumen}");
    marcarEjecucionOk($resumen);
} catch (Throwable $e) {
    anotarLog('ERROR fatal: ' . $e->getMessage());
    marcarEjecucionError($e);
    throw $e;
}

// ============================================================================
// Seleccion + orquestacion
// ============================================================================

function seleccionarPendientes(PDO $pdo, int $lote): array {
    // Solo fiscales pendientes que no hayan sido intentados (caeres vacio) --
    // asi los rechazos previos no se reprocesan hasta que el operador limpie
    // caeres o anule+reencole desde el ABM.
    $limite = max(1, (int)$lote);
    $st = $pdo->prepare("
        SELECT c.id, c.tipo, c.punto, c.condicion, c.cuit, c.emision,
               c.neto, c.iva, c.total, c.empresa, c.talonario,
               e.slug AS empresa_slug
          FROM datacount_comprobantes c
          LEFT JOIN datacount_empresas e ON e.id = c.empresa
         WHERE c.tipo   IN ('FA','FB','FC','FM')
           AND c.fiscal = '1'
           AND c.estado = '2'
           AND (c.caeres IS NULL OR c.caeres = '')
         ORDER BY c.id ASC
         LIMIT {$limite}
    ");
    $st->execute();
    return $st->fetchAll();
}

function autorizarUno(PDO $pdo, string $apikey, array $c, string $origen, string $prefix): bool {
    $id = (int)$c['id'];

    // --- sanity checks previos a llamar al microservicio ---
    $slug = trim((string)($c['empresa_slug'] ?? ''));
    if ($slug === '') {
        return cerrarConError($pdo, $id, 'Empresa sin slug en datacount_empresas', $origen, $prefix);
    }

    $tipoCod  = strtoupper(trim((string)($c['tipo'] ?? '')));
    $tipoAfip = DCC_TIPO_A_AFIP[$tipoCod] ?? 0;
    if ($tipoAfip === 0) {
        return cerrarConError($pdo, $id, "Tipo '{$tipoCod}' sin mapeo AFIP", $origen, $prefix);
    }

    $punto = (int)($c['punto'] ?? 0);
    if ($punto <= 0) {
        return cerrarConError($pdo, $id, 'Punto de venta invalido', $origen, $prefix);
    }

    // Documento del comprador. CF o sin CUIT -> Consumidor Final; el resto = CUIT.
    $cond    = strtoupper(trim((string)($c['condicion'] ?? '')));
    $cuitS   = preg_replace('/\D+/', '', (string)($c['cuit'] ?? ''));
    $docTipo = ($cond === 'CF' || $cuitS === '') ? 99 : 80;
    $docNro  = $docTipo === 99 ? 0 : (int)$cuitS;

    // Fecha de emision -> YYYYMMDD (formato exigido por AFIP CbteFch). Si el
    // comprobante no la tiene, cae en hoy (AR) via default del microservicio.
    $cbteFch = null;
    $emision = (string)($c['emision'] ?? '');
    if (preg_match('/^(\d{4})-(\d{2})-(\d{2})/', $emision, $m)) {
        $cbteFch = "{$m[1]}{$m[2]}{$m[3]}";
    }

    // Discriminacion IVA por alicuota desde los renglones. Aunque tipos B/C no
    // muestran IVA discriminado al cliente, WSFEv1 igual exige el array.
    $iva = calcularIvaPorAlicuota($pdo, $id);
    if (isset($iva['error'])) {
        return cerrarConError($pdo, $id, $iva['error'], $origen, $prefix);
    }

    $body = [
        'empresa'   => $slug,
        'punto'     => $punto,
        'tipo'      => $tipoAfip,
        'concepto'  => 1,                     // Productos. TODO: derivar si aparece la columna.
        'imp_neto'  => (float)($c['neto']  ?? 0),
        'imp_iva'   => (float)($c['iva']   ?? 0),
        'imp_total' => (float)($c['total'] ?? 0),
        'doc_tipo'  => $docTipo,
        'doc_nro'   => (string)$docNro,       // el endpoint hace preg_replace/\D+/ y castea a int
        'iva'       => $iva['items'],
    ];
    if ($cbteFch !== null) $body['cbte_fch'] = $cbteFch;

    anotarLog(sprintf(
        '%s empresa=%s tipo=%s(#%d) pto=%d total=%.2f doc=%d/%s -> POST /v4/arca/autorizar',
        $prefix, $slug, $tipoCod, $tipoAfip, $punto,
        (float)($c['total'] ?? 0), $docTipo, $docNro
    ));

    $resp = postAutorizar($apikey, $body);
    if (!$resp['ok']) {
        // Errores AFIP (Resultado='R', Observaciones, Errors.Err) y errores
        // tecnicos (SoapFault, timeout, cert vencido, empresa sin cert...)
        // llegan igual como {ok:false, error}. No los diferenciamos aca; el
        // detalle fino queda en `arca_autorizaciones` que escribe el microservicio.
        return cerrarConError($pdo, $id, $resp['error'], $origen, $prefix);
    }

    $d      = $resp['data'];
    $cae    = (string)($d['cae']     ?? '');
    $vto    = (string)($d['cae_vto'] ?? '');
    $nroAf  = (int)($d['cbte_nro']  ?? 0);
    $upd = $pdo->prepare("
        UPDATE datacount_comprobantes
           SET estado     = '3',
               caenro     = :cae,
               caevto     = :vto,
               serie      = :nro,
               autorizado = NOW(),
               caeres     = :res
         WHERE id = :id
    ");
    $upd->execute([
        ':cae' => $cae,
        ':vto' => $vto,
        ':nro' => $nroAf,
        ':res' => "OK CAE {$cae} (vto {$vto})",
        ':id'  => $id,
    ]);

    // Adelantamos el correlativo del talonario a lo que devolvio AFIP. Usamos
    // GREATEST para no retroceder por si otra corrida/proceso ya subio el
    // numero (p.ej. autorizacion concurrente contra el mismo pto+tipo).
    $talId = (int)($c['talonario'] ?? 0);
    if ($talId > 0 && $nroAf > 0) {
        $pdo->prepare('UPDATE datacount_talonarios SET serie = GREATEST(COALESCE(serie, 0), :nro) WHERE id = :id')
            ->execute([':nro' => $nroAf, ':id' => $talId]);
    }

    registrarSuceso($pdo, $origen, 'info',
        "cbte#{$id} autorizado - CAE={$cae} nro={$nroAf} vto={$vto}");
    anotarLog("{$prefix} - OK CAE={$cae} nro={$nroAf} vto={$vto}");
    return true;
}

/**
 * Cierra un comprobante con error: escribe caeres (para saltearlo en la
 * proxima corrida) y deja registro en el log del job y en `sucesos`.
 * Devuelve false para que el caller sume al contador de errores.
 */
function cerrarConError(PDO $pdo, int $id, string $msg, string $origen, string $prefix): bool {
    marcarErrorComprobante($pdo, $id, $msg);
    registrarSuceso($pdo, $origen, 'error', "cbte#{$id}: {$msg}");
    anotarLog("{$prefix} - ERROR: {$msg}");
    return false;
}

function marcarErrorComprobante(PDO $pdo, int $id, string $msg): void {
    // caeres es mediumtext -- cortamos por las dudas a un tamano razonable para
    // que un dump de AFIP con XML gigante no explote el UPDATE.
    $st = $pdo->prepare("UPDATE datacount_comprobantes SET caeres = :m WHERE id = :id");
    $st->execute([':m' => substr($msg, 0, 60000), ':id' => $id]);
}

// ============================================================================
// Renglones -> array `iva` para AFIP
// ============================================================================

function calcularIvaPorAlicuota(PDO $pdo, int $comprobanteId): array {
    $st = $pdo->prepare("
        SELECT iva AS alicuota,
               SUM(monto) AS base
          FROM datacount_comprobantes_renglones
         WHERE comprobante = :id
      GROUP BY iva
      ORDER BY iva ASC
    ");
    $st->execute([':id' => $comprobanteId]);
    $rows = $st->fetchAll();
    if (!$rows) return ['error' => 'Comprobante sin renglones -- nada para autorizar'];

    $items = [];
    foreach ($rows as $r) {
        $ali = number_format((float)$r['alicuota'], 2, '.', '');
        if (!isset(DCC_IVA_ID_AFIP[$ali])) {
            return ['error' => "Alicuota IVA {$ali}% sin mapeo AFIP"];
        }
        $base    = round((float)$r['base'], 2);
        $importe = round($base * (float)$ali / 100, 2);
        $items[] = [
            'id'      => DCC_IVA_ID_AFIP[$ali],
            'base'    => $base,
            'importe' => $importe,
        ];
    }
    return ['items' => $items];
}

// ============================================================================
// HTTP loopback + apikey
// ============================================================================

function postAutorizar(string $apikey, array $body): array {
    $ch = curl_init(DCC_AUT_ENDPOINT);
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => json_encode($body, JSON_UNESCAPED_UNICODE),
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => DCC_AUT_TIMEOUT_S,
        CURLOPT_CONNECTTIMEOUT => DCC_AUT_CONNECT_S,
        CURLOPT_HTTPHEADER     => [
            'Authorization: Bearer ' . $apikey,
            'Content-Type: application/json; charset=utf-8',
            'Accept: application/json',
        ],
    ]);
    $raw    = curl_exec($ch);
    $errStr = curl_error($ch);
    $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($raw === false) {
        return ['ok' => false, 'error' => 'cURL: ' . ($errStr !== '' ? $errStr : 'sin detalle')];
    }
    $decoded = json_decode((string)$raw, true);
    if (!is_array($decoded)) {
        return ['ok' => false, 'error' => "HTTP {$status}: respuesta no JSON: " . substr((string)$raw, 0, 400)];
    }
    if (!empty($decoded['ok']) && $status >= 200 && $status < 300) {
        $data = is_array($decoded['data'] ?? null) ? $decoded['data'] : $decoded;
        return ['ok' => true, 'data' => $data];
    }
    $errMsg = isset($decoded['error']) ? (string)$decoded['error'] : "HTTP {$status}";
    return ['ok' => false, 'error' => $errMsg];
}

/**
 * Devuelve una apikey de `aplicaciones` para hacer el loopback al v4.
 * Preferimos la app "Kernel"; si no existe, agarramos cualquiera habilitada.
 * Mirror del patron ya usado por telegramcanales_actualizar_estados.php y por
 * telegram_mensajes_enviar.php.
 */
function obtenerApikeyLoopback(PDO $pdo): ?string {
    static $cached = false;
    static $key    = null;
    if ($cached) return $key;
    $cached = true;

    $st = $pdo->prepare("
        SELECT apikey
          FROM aplicaciones
         WHERE habilitada = '1' AND apikey IS NOT NULL AND apikey <> ''
      ORDER BY (nombre = 'Kernel') DESC, id ASC
         LIMIT 1
    ");
    $st->execute();
    $val = $st->fetchColumn();
    $key = ($val === false || $val === null) ? null : (string)$val;
    return $key;
}
