<?php
// api/v4/arca/autorizar.php
// POST /v4/arca/autorizar
//   body: {
//     empresa:        "wescom-srl",         // slug de datacount_empresas (requerido)
//     punto:          1,                    // PtoVta (requerido)
//     tipo:           6,                    // CbteTipo AFIP (requerido). Ver /parametros?catalogo=tipos_cbte
//     concepto:       1,                    // 1=Productos 2=Servicios 3=Prod+Serv (default 1)
//     cbte_desde:     null,                 // opcional: si viene null hacemos FECompUltimoAutorizado+1
//     cbte_hasta:     null,                 // opcional: por defecto == cbte_desde (1 comprobante)
//     cbte_fch:       "20260731",           // YYYYMMDD (default hoy en zona AR)
//     imp_neto:       100.00,
//     imp_iva:        21.00,
//     imp_total:      121.00,
//     imp_tot_conc:   0,                    // opcional (no gravado)
//     imp_op_ex:      0,                    // opcional (exento)
//     imp_trib:       0,                    // opcional (otros tributos)
//     mon_id:         "PES",                // default 'PES'
//     mon_cotiz:      1,                    // default 1
//     doc_tipo:       80,                   // 80=CUIT 96=DNI 99=ConsFinal
//     doc_nro:        "20111111112",        // sin guiones (0 si ConsFinal)
//     iva: [                                // opcional. Cada item: {id, base, importe}. id: 3=0%, 4=10.5%, 5=21%, 6=27%
//       {"id":5, "base":100.00, "importe":21.00}
//     ],
//     cbtes_asoc: [                         // opcional (notas de credito/debito): {tipo, punto, nro, cuit?, fecha?}
//       {"tipo":6, "punto":1, "nro":42}
//     ]
//   }
//   -> {ok:true, empresa, punto, tipo, cbte_nro, cae, cae_vto, resultado}
//
// Autoriza UN comprobante contra WSFEv1 (FECAESolicitar) y devuelve el CAE
// + fecha de vencimiento. Si el body no especifica cbte_desde/hasta, se
// autonumera pidiendole a AFIP el ultimo autorizado + 1.
//
// Errores AFIP (Resultado='R', Observaciones, Errors.Err) se propagan como
// jsonError con el codigo/msg tal cual devuelve AFIP -- el cliente sabe
// interpretarlos.
//
// Auth: Bearer con apikey de `aplicaciones`.

declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');

require_once dirname(__DIR__, 3) . '/env.php';
require_once dirname(__DIR__, 3) . '/cloud/api/db.php';
require_once __DIR__ . '/_lib/auth.php';
require_once __DIR__ . '/_lib/log.php';
require_once __DIR__ . '/_lib/afip_factory.php';
require_once __DIR__ . '/_lib/autorizaciones.php';

// Leemos el body ANTES de registrar el log handler para poder usar los
// campos como contexto (asi si algo explota, sabemos que factura estaba
// intentando emitir).
$in     = readJsonBody();
$inBody = is_array($in) ? $in : [];
arcaInitLog('autorizar', [
    'empresa'   => $inBody['empresa']   ?? '',
    'punto'     => $inBody['punto']     ?? '',
    'tipo'      => $inBody['tipo']      ?? '',
    'imp_total' => $inBody['imp_total'] ?? '',
    'doc_nro'   => $inBody['doc_nro']   ?? '',
]);

try {
    $app    = arcaRequireApp();
    $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
    if ($method !== 'POST') jsonError('Metodo no soportado', 405);

    handleAutorizar($inBody, (int)$app['id']);
} catch (Throwable $e) {
    jsonError($e->getMessage(), 500);
}

// ---------------------------------------------------------------------------
// Handler
// ---------------------------------------------------------------------------

function handleAutorizar(array $in, int $aplicacionId): void {
    $slug = trim((string)($in['empresa'] ?? ''));

    // Fecha por defecto = hoy en zona Buenos Aires. AFIP no acepta otro
    // formato ni otra zona en `CbteFch`.
    $tzAR   = new DateTimeZone('America/Argentina/Buenos_Aires');
    $hoyStr = (new DateTimeImmutable('now', $tzAR))->format('Ymd');

    $punto = (int)($in['punto'] ?? 0);
    $tipo  = (int)($in['tipo']  ?? 0);
    if ($punto <= 0) jsonError('Falta `punto` (PtoVta > 0).', 400);
    if ($tipo  <= 0) jsonError('Falta `tipo` (CbteTipo AFIP > 0).', 400);

    // Numeros de comprobante: si no vinieron, autopedimos ultimo+1.
    $cbteDesde = isset($in['cbte_desde']) && $in['cbte_desde'] !== '' ? (int)$in['cbte_desde'] : null;
    $cbteHasta = isset($in['cbte_hasta']) && $in['cbte_hasta'] !== '' ? (int)$in['cbte_hasta'] : null;

    $bag  = arcaFor($slug);
    $ebil = $bag['arca']->ElectronicBilling;

    if ($cbteDesde === null) {
        $ultimo    = $ebil->GetLastVoucher($punto, $tipo);
        $cbteDesde = $ultimo + 1;
    }
    if ($cbteHasta === null) $cbteHasta = $cbteDesde;
    if ($cbteHasta < $cbteDesde) jsonError('`cbte_hasta` no puede ser menor que `cbte_desde`.', 400);

    // Documento del comprador. Si no vino, asumimos consumidor final (99/0).
    $docTipo = isset($in['doc_tipo']) && $in['doc_tipo'] !== '' ? (int)$in['doc_tipo'] : 99;
    $docNroS = isset($in['doc_nro']) ? preg_replace('/\D+/', '', (string)$in['doc_nro']) : '';
    $docNro  = $docNroS === '' ? 0 : (int)$docNroS;

    $data = [
        // Cabecera (los saca el CreateVoucher y los mueve a FeCabReq).
        'CantReg'   => $cbteHasta - $cbteDesde + 1,
        'PtoVta'    => $punto,
        'CbteTipo'  => $tipo,
        // Detalle.
        'Concepto'  => (int)($in['concepto'] ?? 1),
        'DocTipo'   => $docTipo,
        'DocNro'    => $docNro,
        'CbteDesde' => $cbteDesde,
        'CbteHasta' => $cbteHasta,
        'CbteFch'   => (string)($in['cbte_fch'] ?? $hoyStr),
        'ImpTotal'  => (float)($in['imp_total']   ?? 0),
        'ImpTotConc'=> (float)($in['imp_tot_conc'] ?? 0),
        'ImpNeto'   => (float)($in['imp_neto']    ?? 0),
        'ImpOpEx'   => (float)($in['imp_op_ex']   ?? 0),
        'ImpIVA'    => (float)($in['imp_iva']     ?? 0),
        'ImpTrib'   => (float)($in['imp_trib']    ?? 0),
        'MonId'     => (string)($in['mon_id']     ?? 'PES'),
        'MonCotiz'  => (float)($in['mon_cotiz']   ?? 1),
    ];

    // IVA por alicuota (opcional). El SDK espera claves PascalCase.
    if (!empty($in['iva']) && is_array($in['iva'])) {
        $data['Iva'] = [];
        foreach ($in['iva'] as $al) {
            if (!is_array($al)) continue;
            $data['Iva'][] = [
                'Id'      => (int)($al['id'] ?? 0),
                'BaseImp' => (float)($al['base']    ?? 0),
                'Importe' => (float)($al['importe'] ?? 0),
            ];
        }
        if (empty($data['Iva'])) unset($data['Iva']);
    }

    // Comprobantes asociados (obligatorio para notas de credito/debito).
    if (!empty($in['cbtes_asoc']) && is_array($in['cbtes_asoc'])) {
        $data['CbtesAsoc'] = [];
        foreach ($in['cbtes_asoc'] as $ca) {
            if (!is_array($ca)) continue;
            $item = [
                'Tipo'  => (int)($ca['tipo']  ?? 0),
                'PtoVta'=> (int)($ca['punto'] ?? 0),
                'Nro'   => (int)($ca['nro']   ?? 0),
            ];
            if (!empty($ca['cuit']))  $item['Cuit']  = preg_replace('/\D+/', '', (string)$ca['cuit']);
            if (!empty($ca['fecha'])) $item['CbteFch'] = (string)$ca['fecha'];
            $data['CbtesAsoc'][] = $item;
        }
        if (empty($data['CbtesAsoc'])) unset($data['CbtesAsoc']);
    }

    // Disparo. Si AFIP rechaza (Errors, Observaciones, Resultado='R'), la
    // clase levanta Exception con el codigo AFIP y el mensaje. El try/catch/
    // finally envuelve SOLO la llamada AFIP -- asi cada intento (exitoso o
    // fallido) queda registrado en `arca_autorizaciones` con timing. Fallos
    // previos (empresa sin cert, GetLastVoucher, etc) NO se registran ahi:
    // no llegamos a intentar FECAESolicitar, van solo a `sucesos`.
    $t0        = microtime(true);
    $res       = null;
    $resultado = 'error';
    $errMsg    = null;
    $excep     = null;
    try {
        $res       = $ebil->CreateVoucher($data);
        $resultado = 'A';
    } catch (Throwable $e) {
        // Exceptions con code > 0 son errores semanticos AFIP (Errors.Err u
        // Observaciones.Obs con Resultado='R'). Code = 0 = fallo tecnico
        // (SoapFault, timeout, cert vencido, WSAA rechazo, etc).
        $resultado = ((int)$e->getCode() > 0) ? 'R' : 'error';
        $errMsg    = $e->getMessage();
        $excep     = $e;
    } finally {
        arcaAutorizacionLog([
            'aplicacion_id' => $aplicacionId,
            'empresa_id'    => (int)$bag['empresa']['id'],
            'punto'         => $punto,
            'tipo'          => $tipo,
            'cbte_nro'      => $cbteDesde,
            'resultado'     => $resultado,
            'cae'           => $res['CAE']       ?? null,
            'cae_vto'       => $res['CAEFchVto'] ?? null,
            'errores'       => $errMsg,
            'duracion_ms'   => (int)((microtime(true) - $t0) * 1000),
        ]);
    }
    if ($excep !== null) {
        throw $excep;  // lo agarra el catch externo -> jsonError con el msg AFIP
    }

    // Log del exito con detalles suficientes para auditoria: quien facturo
    // a quien por cuanto, y el CAE emitido. Se persiste ANTES de jsonOk()
    // para que quede en sucesos aunque el response al cliente se pierda.
    arcaLogInfo('autorizar', sprintf(
        'CAE %s (vto %s) - empresa=%s tipo=%d punto=%d nro=%d total=%.2f doc=%s/%s',
        (string)$res['CAE'],
        (string)$res['CAEFchVto'],
        (string)$bag['empresa']['slug'],
        $tipo,
        $punto,
        $cbteDesde,
        (float)$data['ImpTotal'],
        (string)$docTipo,
        (string)$docNro
    ));

    jsonOk([
        'empresa'   => [
            'slug' => (string)$bag['empresa']['slug'],
            'cuit' => (string)$bag['empresa']['cuit'],
        ],
        'punto'     => $punto,
        'tipo'      => $tipo,
        'cbte_nro'  => $cbteDesde,           // asumimos 1 comprobante (uso tipico); si vienen varios, ver AFIP
        'cae'       => (string)$res['CAE'],
        'cae_vto'   => (string)$res['CAEFchVto'],
        'resultado' => 'A',
    ]);
}
