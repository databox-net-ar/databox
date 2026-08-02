<?php
/**
 * cloud/api/lib/datacount_comprobantes_autorizar.php
 *
 * Logica compartida para autorizar UN comprobante fiscal contra AFIP via el
 * microservicio v4 (loopback a /v4/arca/autorizar). La consumen:
 *
 *   - cloud/jobs/datacount_comprobantes_autorizar.php  (cron en batch)
 *   - cloud/api/datacount_comprobantes.php action=autorizar (accion manual
 *     del operador desde el menu contextual del ABM)
 *
 * La lib es "tonta": arma el body, hace el POST, y si sale OK cierra el
 * comprobante en BD (estado='3', cae, caevto, serie, autorizado, caeres) y
 * adelanta el correlativo del talonario. Los concerns de:
 *
 *   - clasificar el error como transitorio/permanente,
 *   - grabar `caeres` en caso de error,
 *   - detener el motor automatico (parametros.datacount.comprobantes.autorizar),
 *   - loguear/registrar sucesos,
 *
 * son responsabilidad del CALLER. Asi el cron aplica su circuit breaker y
 * el endpoint manual muestra un toast al operador sin frenar el pipeline.
 *
 * El microservicio v4 tambien registra cada intento en `arca_autorizaciones`
 * con timing y resultado -- la auditoria tecnica fina vive alli.
 */

declare(strict_types=1);

// ============================================================================
// Constantes de configuracion
// ============================================================================

// Loopback interno al microservicio v4 (mismo contenedor, puerto 8114).
// Ver [../../../CLAUDE.md] para el split de api.databox.net.ar y por que
// preferimos localhost:8114 antes que la URL publica.
const DCC_AUT_ENDPOINT  = 'http://localhost:8114/v4/arca/autorizar';
const DCC_AUT_TIMEOUT_S = 60;    // AFIP WSFEv1 responde en <5s pero WSAA cold puede tardar.
const DCC_AUT_CONNECT_S = 5;

// Letra del comprobante -> CbteTipo AFIP (WSFEv1). Facturas y notas de credito.
// Cualquier otro codigo (FX/PX/MX/DX/RX) es no-fiscal y no deberia llegar aca
// gracias al gate `fiscal='1'` del caller; si llega, cae en "Tipo sin mapeo AFIP".
const DCC_TIPO_A_AFIP = [
    'FA' =>  1,   // Factura A
    'FB' =>  6,   // Factura B
    'FC' => 11,   // Factura C
    'FM' => 51,   // Factura M
    'NA' =>  3,   // Nota de Credito A
    'NB' =>  8,   // Nota de Credito B
    'NC' => 13,   // Nota de Credito C
    'NM' => 53,   // Nota de Credito M
];

// Tipos que AFIP considera nota de credito -- para ellos hay que armar el
// array `cbtes_asoc` con el comprobante original (ver dccAutBuscarAsociado).
const DCC_TIPOS_NC = ['NA', 'NB', 'NC', 'NM'];

// Alicuota IVA (formato "0.00" con 2 decimales) -> Id AFIP (FEParamGetTiposIva).
// Si un renglon usa otro %, se cierra con error -- AFIP rechazaria.
const DCC_IVA_ID_AFIP = [
    '0.00'  => 3,   // 0%
    '10.50' => 4,   // 10.5%
    '21.00' => 5,   // 21%
    '27.00' => 6,   // 27%
    '5.00'  => 8,   // 5%
    '2.50'  => 9,   // 2.5%
];

// ============================================================================
// Carga y validacion
// ============================================================================

/**
 * Trae el comprobante con el JOIN a `datacount_empresas` para tener el slug.
 * Retorna null si no existe.
 */
function dccAutLoadComprobante(PDO $pdo, int $id): ?array {
    $st = $pdo->prepare("
        SELECT c.id, c.tipo, c.punto, c.condicion, c.cuit, c.emision,
               c.neto, c.iva, c.total, c.empresa, c.talonario, c.asociado,
               c.fiscal, c.estado,
               e.slug AS empresa_slug
          FROM datacount_comprobantes c
          LEFT JOIN datacount_empresas e ON e.id = c.empresa
         WHERE c.id = :id
         LIMIT 1
    ");
    $st->execute([':id' => $id]);
    $row = $st->fetch();
    return $row ?: null;
}

/**
 * Trae el comprobante asociado a una NC. Solo devuelve datos si el asociado
 * esta autorizado por AFIP (estado='3'), es fiscal y es una factura mapeable.
 * Devuelve null si no cumple los requisitos -- el caller cierra con error.
 */
function dccAutBuscarAsociado(PDO $pdo, int $id): ?array {
    $st = $pdo->prepare("
        SELECT tipo, punto, serie
          FROM datacount_comprobantes
         WHERE id      = :id
           AND fiscal  = '1'
           AND estado  = '3'
           AND tipo    IN ('FA','FB','FC','FM')
           AND serie   IS NOT NULL
           AND punto   IS NOT NULL
         LIMIT 1
    ");
    $st->execute([':id' => $id]);
    $row = $st->fetch();
    return $row ?: null;
}

/**
 * Discrimina IVA por alicuota desde los renglones del comprobante.
 * Aunque tipos B/C no muestran IVA discriminado al cliente, WSFEv1 igual
 * exige el array. Retorna ['items' => [...]] o ['error' => 'msg'].
 */
function dccAutCalcularIva(PDO $pdo, int $comprobanteId): array {
    $st = $pdo->prepare("
        SELECT iva AS alicuota, SUM(monto) AS base
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

/**
 * Devuelve una apikey de `aplicaciones` para hacer el loopback al v4.
 * Preferimos la app "Kernel"; si no existe, agarramos cualquiera habilitada.
 * Mismo patron que telegramcanales_actualizar_estados.php.
 */
function dccAutObtenerApikey(PDO $pdo): ?string {
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

/**
 * POST al microservicio v4 con Bearer $apikey. Devuelve una estructura
 * normalizada {ok, data|error}. Errores AFIP (Errors.Err, Observaciones con
 * Resultado='R'), errores tecnicos (SoapFault, timeout, cert vencido) y
 * validaciones nuestras llegan igual como {ok:false, error}.
 */
function dccAutPostAutorizar(string $apikey, array $body): array {
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

// ============================================================================
// Clasificacion transitorio vs permanente
// ============================================================================

/**
 * Clasifica un mensaje de error como transitorio (AFIP/Apache caidos, TA
 * stale, timeout de red) o permanente (todo lo demas). Compartida por el
 * cron (circuit breaker) y el endpoint manual (idem, para frenar el motor
 * cuando un fallo sistemico afectaria tambien al cron automatico).
 *
 * Patrones cubiertos:
 *   /^cURL:/                     fallas de red del propio caller
 *   /HTTP 50[234]/               gateway/upstream caido (Apache saturado, etc.)
 *   /Service Unavailable/i       AFIP o Apache no disponible
 *   /Server was unable to.../i   SoapFault tipico de AFIP caido
 *   /Ya existe TA valido/i       race WSAA code 25
 *   /^\(60[01]\)/                Token o Sign invalido -- regenerar TA lo arregla
 */
function dccAutEsTransitorio(string $msg): bool {
    static $patrones = [
        '/^cURL:/',
        '/HTTP 50[234]/',
        '/Service Unavailable/i',
        '/Server was unable to process/i',
        '/Ya existe TA valido/i',
        '/^\(60[01]\)/',
    ];
    foreach ($patrones as $p) {
        if (preg_match($p, $msg)) return true;
    }
    return false;
}

// ============================================================================
// Escrituras auxiliares
// ============================================================================

/**
 * Graba un mensaje de error en `caeres` (con cap a 60000 chars por si viene
 * un dump XML gigante). No cambia el `estado`. El caller decide cuando llamar
 * esto (el cron NO lo llama en errores transitorios; el manual siempre).
 */
function dccAutMarcarCaeres(PDO $pdo, int $id, string $msg): void {
    $st = $pdo->prepare("UPDATE datacount_comprobantes SET caeres = :m WHERE id = :id");
    $st->execute([':m' => substr($msg, 0, 60000), ':id' => $id]);
}

// ============================================================================
// Punto de entrada: autorizar UN comprobante
// ============================================================================

/**
 * Autoriza el comprobante `$c` contra AFIP via el microservicio v4.
 * Espera la fila cargada por dccAutLoadComprobante() (con `empresa_slug` del
 * JOIN). NO valida `fiscal`/`estado`: eso es responsabilidad del caller
 * (cron: seleccionarPendientes; endpoint: pre-check antes de invocar).
 *
 * En caso de exito, actualiza en BD:
 *   - `datacount_comprobantes`: estado='3', caenro, caevto, serie=cbte_nro,
 *     autorizado=NOW(), caeres="OK CAE <n> (vto <fch>)".
 *   - `datacount_talonarios`: serie = GREATEST(serie, cbte_nro) para no
 *     retroceder por si otro proceso ya avanzo el correlativo.
 *
 * En caso de error, NO toca `caeres` -- eso lo decide el caller (el cron
 * discrimina transitorio/permanente; el manual siempre lo graba).
 *
 * @return array Alguno de:
 *   ['ok'=>true, 'cae'=>string, 'cae_vto'=>string, 'cbte_nro'=>int]
 *   ['ok'=>false, 'error'=>string, 'fuente'=>'validacion'|'microservicio']
 *     'validacion'    = falla nuestra ANTES de llamar al microservicio.
 *     'microservicio' = falla del microservicio o de AFIP.
 */
function dccAutAutorizar(PDO $pdo, string $apikey, array $c): array {
    $id = (int)$c['id'];

    // --- sanity checks previos a llamar al microservicio ---
    $slug = trim((string)($c['empresa_slug'] ?? ''));
    if ($slug === '') {
        return ['ok' => false, 'error' => 'Empresa sin slug en datacount_empresas', 'fuente' => 'validacion'];
    }

    $tipoCod  = strtoupper(trim((string)($c['tipo'] ?? '')));
    $tipoAfip = DCC_TIPO_A_AFIP[$tipoCod] ?? 0;
    if ($tipoAfip === 0) {
        return ['ok' => false, 'error' => "Tipo '{$tipoCod}' sin mapeo AFIP", 'fuente' => 'validacion'];
    }

    $punto = (int)($c['punto'] ?? 0);
    if ($punto <= 0) {
        return ['ok' => false, 'error' => 'Punto de venta invalido', 'fuente' => 'validacion'];
    }

    // Documento del comprador. CF o sin CUIT -> Consumidor Final; resto = CUIT.
    $cond    = strtoupper(trim((string)($c['condicion'] ?? '')));
    $cuitS   = preg_replace('/\D+/', '', (string)($c['cuit'] ?? ''));
    $docTipo = ($cond === 'CF' || $cuitS === '') ? 99 : 80;
    $docNro  = $docTipo === 99 ? 0 : (int)$cuitS;

    // Fecha de emision -> YYYYMMDD (formato que exige AFIP CbteFch).
    $cbteFch = null;
    $emision = (string)($c['emision'] ?? '');
    if (preg_match('/^(\d{4})-(\d{2})-(\d{2})/', $emision, $m)) {
        $cbteFch = "{$m[1]}{$m[2]}{$m[3]}";
    }

    // IVA discriminado.
    $iva = dccAutCalcularIva($pdo, $id);
    if (isset($iva['error'])) {
        return ['ok' => false, 'error' => $iva['error'], 'fuente' => 'validacion'];
    }

    $body = [
        'empresa'   => $slug,
        'punto'     => $punto,
        'tipo'      => $tipoAfip,
        'concepto'  => 1,                     // Productos. TODO: derivar si aparece columna.
        'imp_neto'  => (float)($c['neto']  ?? 0),
        'imp_iva'   => (float)($c['iva']   ?? 0),
        'imp_total' => (float)($c['total'] ?? 0),
        'doc_tipo'  => $docTipo,
        'doc_nro'   => (string)$docNro,
        'iva'       => $iva['items'],
    ];
    if ($cbteFch !== null) $body['cbte_fch'] = $cbteFch;

    // Notas de credito: AFIP exige `cbtes_asoc` con los datos del comprobante
    // original. Lo derivamos de `asociado` (id del comprobante que se acredita).
    // El asociado tiene que estar autorizado (estado='3') y ser factura fiscal.
    if (in_array($tipoCod, DCC_TIPOS_NC, true)) {
        $asocId = (int)($c['asociado'] ?? 0);
        if ($asocId <= 0) {
            return ['ok' => false, 'error' => 'NC sin `asociado` -- no se puede armar cbtes_asoc', 'fuente' => 'validacion'];
        }
        $asoc = dccAutBuscarAsociado($pdo, $asocId);
        if ($asoc === null) {
            return ['ok' => false,
                    'error' => "NC apunta a asociado#{$asocId} inexistente o no autorizado (requiere estado='3', fiscal='1', tipo factura)",
                    'fuente' => 'validacion'];
        }
        $body['cbtes_asoc'] = [[
            'tipo'  => DCC_TIPO_A_AFIP[$asoc['tipo']],
            'punto' => (int)$asoc['punto'],
            'nro'   => (int)$asoc['serie'],
        ]];
    }

    $resp = dccAutPostAutorizar($apikey, $body);
    if (!$resp['ok']) {
        return ['ok' => false, 'error' => (string)$resp['error'], 'fuente' => 'microservicio'];
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

    // Adelantamos el correlativo del talonario. GREATEST protege contra
    // reintentos concurrentes que pudieran subir el numero primero.
    $talId = (int)($c['talonario'] ?? 0);
    if ($talId > 0 && $nroAf > 0) {
        $pdo->prepare('UPDATE datacount_talonarios SET serie = GREATEST(COALESCE(serie, 0), :nro) WHERE id = :id')
            ->execute([':nro' => $nroAf, ':id' => $talId]);
    }

    return [
        'ok'       => true,
        'cae'      => $cae,
        'cae_vto'  => $vto,
        'cbte_nro' => $nroAf,
    ];
}
