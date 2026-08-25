<?php
// api/datacount_analiticas.php
// Agregaciones de solo lectura sobre datos de Datacount, para el modulo
// "Analiticas" del panel cloud (Sistemas > Datacount > Analiticas).
//
// La vista es multi-pestana y cada pestana pide su serie propia mediante
// `?action=<nombre>`. Todas comparten filtro por empresa y devuelven la
// serie ya agregada en formato listo para pintar (evita mover miles de
// filas al navegador).
//
//   GET api/datacount_analiticas.php?action=<facturas|notas|pagos>
//                                    &empresa=<id>          (requerido)
//                                    &rango=all|12|24|36|year  (default: all)
//                                    &anio=YYYY             (solo con rango=year)
//     -> { serie: [{ mes: 'YYYY-MM', total: N, cantidad: N }, ...],
//          resumen: { total: N, cantidad: N, total_rango: N, cantidad_rango: N,
//                     primero: 'YYYY-MM'|null, ultimo: 'YYYY-MM'|null,
//                     meses: N, sin_fecha_cant: N, sin_fecha_total: N },
//          rango: { modo: 'all'|'ultimos'|'year', desde: 'YYYY-MM',
//                   hasta: 'YYYY-MM', meses?: N },
//          anios: [YYYY, ...] }
//
//   GET api/datacount_analiticas.php?action=resultados   (mismos filtros)
//     -> { serie: [{ mes: 'YYYY-MM', ingresos: N, egresos: N, resultado: N,
//                    cant_ingresos: N, cant_egresos: N }, ...],
//          resumen: { ingresos_rango, egresos_rango, resultado_rango,
//                     cant_ingresos_rango, cant_egresos_rango,
//                     ingresos, egresos, resultado,
//                     cant_ingresos, cant_egresos,
//                     primero, ultimo, meses,
//                     mejor_mes: {mes, resultado}|null,
//                     peor_mes:  {mes, resultado}|null,
//                     meses_positivos, meses_negativos,
//                     sin_fecha_ingresos_cant, sin_fecha_egresos_cant },
//          rango: { ..., piso: 'YYYY-MM', recortado?: true },
//          anios: [...] }
//
//   GET api/datacount_analiticas.php?action=consistencia  (mismos filtros)
//     -> { serie: [{ mes: 'YYYY-MM', facturado: N, notas: N, facturado_neto: N,
//                    acreditado: N, diferencia: N, desvio_pct: N|null,
//                    cant_facturas: N, cant_notas: N, cant_movimientos: N }, ...],
//          resumen: { facturado_rango, notas_rango, facturado_neto_rango,
//                     acreditado_rango, diferencia_rango, cobertura_pct|null,
//                     cant_facturas_rango, cant_notas_rango,
//                     cant_movimientos_rango, meses_con_datos,
//                     mes_mayor_desvio: {mes, desvio_pct, diferencia}|null,
//                     primero, ultimo, meses, sin_fecha_facturas_cant },
//          excluidos: { internas_total, internas_cant,
//                       financieros_total, financieros_cant,
//                       tarjeta_total, tarjeta_cant, otra_moneda_cant,
//                       cuentas_empresa, cuentas_sin_empresa },
//          rango: { ..., piso: 'YYYY-MM', recortado?: true },
//          anios: [...] }
//
// Fuentes:
//   - facturas / notas: `datacount_comprobantes`, filtradas por
//     estado='3' (Autorizado por AFIP) y por prefijo de tipo:
//       * facturas -> tipo LIKE 'F%'  (FA, FB, FC, FM)
//       * notas    -> tipo LIKE 'N%'  (NA, NB, NC, NM)
//   - pagos: `datacount_pagos`, sin filtro de estado (todas las ordenes
//     registradas suman). Se suma la columna `valor` (monto en pesos, con
//     la conversion dolar ya aplicada por convencion del ABM:
//     valor = monto * cotizacion). La cotizacion para pagos en dolares
//     se backfillea desde `dolarhoy_cotizaciones` y `valor` se recalcula
//     via migraciones 20260802_1400_... y 20260802_1500_...
//   - resultados: cruce mes a mes de las dos anteriores. Ingresos = misma
//     definicion que la pestana "Facturas" (comprobantes autorizados con
//     tipo LIKE 'F%'); egresos = misma definicion que "Ordenes de pago".
//     Las notas de credito NO se restan: la pestana replica exactamente lo
//     que muestran las pestanas de origen.
//     Piso historico: solo cuenta lo emitido desde 2025-01 (ver
//     DCA_CRUCE_MES_MIN). Los registros sin fecha de emision quedan
//     fuera de los totales — no se pueden ubicar respecto del piso — y solo
//     se reportan como conteo informativo.
//   - consistencia: cruce mes a mes de lo FACTURADO contra lo efectivamente
//     ACREDITADO en las cuentas de fondos (`datacount_bancos_movimientos`).
//     Sirve para detectar meses donde el dinero que entro no se parece a lo
//     que se emitio: facturacion sin cobrar, cobranzas sin facturar, o carga
//     incompleta de alguno de los dos lados.
//     A diferencia de `resultados`, aca el facturado SI va neto de notas de
//     credito: una NC anula parte de una factura y esa plata nunca se
//     acredita, asi que dejarla adentro generaria un desvio permanente que
//     no es una inconsistencia real.
//     Mismo piso historico que `resultados`.
//
// Respuesta siempre {ok: true, data: ...} u {ok: false, error: '...'}.
// Un unico verbo `.consultar` cubre todas las acciones — es un modulo
// puramente de visualizacion.

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/lib/auth_check.php';

// Codigos de catalogo. Declarados arriba para no depender del orden en
// que PHP registra `const` en runtime (const a nivel archivo NO es
// compile-time — se procesa al ejecutar la linea; incidente 2026-08-02).
const DCA_ESTADO_AUTORIZADO = '3';  // datacount_comprobante_estado

// Piso historico de las acciones de cruce (`resultados` y `consistencia`).
// Todo lo anterior a 2025 se ignora: la carga de datos de esos anos quedo
// incompleta y cualquier cruce daria resultados enganosos. Afecta al
// historico completo, a las ventanas de N meses y a la lista de anios
// elegibles. NO aplica a las acciones facturas / notas / pagos, que siguen
// mostrando todo lo cargado.
//
// En `consistencia` el piso es todavia mas necesario que en `resultados`: los
// extractos bancarios importados llegan hasta 2016, epoca sin ninguna
// facturacion cargada, y esos meses apareceran como 100% de desvio siendo
// que el problema es de cobertura de datos y no de consistencia.
const DCA_CRUCE_MES_MIN   = '2025-01';
const DCA_CRUCE_FECHA_MIN = '2025-01-01';

// Medios de ingreso que NO son cobranzas de terceros y por lo tanto no tienen
// contrapartida en una factura emitida. Sumarlos inflaria el acreditado del
// mes y marcaria una inconsistencia inexistente. El catalogo completo de
// medios vive en `estados` (campo datacount_bancos_movimiento_medio).
const DCA_MEDIOS_NO_COBRANZA = ['rendimiento', 'interes', 'ajuste'];

requireAuth();
requirePermission('datacount.analiticas.consultar');
header('Content-Type: application/json; charset=utf-8');

try {
    $pdo    = db();
    $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
    $action = (string)($_GET['action'] ?? '');

    if ($method !== 'GET') {
        jsonError('Metodo no soportado', 405);
    }

    switch ($action) {
        case 'facturas':
            handleComprobantesPorTipo($pdo, $_GET, 'F%');
            break;
        case 'notas':
            handleComprobantesPorTipo($pdo, $_GET, 'N%');
            break;
        case 'pagos':
            handlePagos($pdo, $_GET);
            break;
        case 'resultados':
            handleResultados($pdo, $_GET);
            break;
        case 'consistencia':
            handleConsistencia($pdo, $_GET);
            break;
        default:
            jsonError('Accion no soportada', 400);
    }
} catch (Throwable $e) {
    jsonError($e->getMessage(), 500);
}

// ----------------------------------------------------------------------------
// Handler comun para facturas y notas de credito (`datacount_comprobantes`).
// ----------------------------------------------------------------------------
//
// Se cuentan SOLO los comprobantes autorizados por AFIP (estado = '3'). El
// filtro extra por prefijo de `tipo` distingue facturas (F%) de notas de
// credito (N%). Se agrupa por mes de `emision`. Autorizados sin fecha
// (caso raro) van al contador `sin_fecha` para no esconderlos.

function handleComprobantesPorTipo(PDO $pdo, array $q, string $tipoLike): void {
    $empresaId = (int)($q['empresa'] ?? 0);
    if ($empresaId <= 0) {
        jsonError('Falta parametro `empresa`.', 400);
    }

    $modo = (string)($q['rango'] ?? 'all');
    $anio = isset($q['anio']) ? (int)$q['anio'] : 0;

    $porMes = dcaComprobantesPorMes($pdo, $empresaId, $tipoLike, null);

    $st = $pdo->prepare("
        SELECT COUNT(*) AS n, SUM(COALESCE(total, 0)) AS t
          FROM datacount_comprobantes
         WHERE empresa = :emp
           AND estado  = :est
           AND tipo    LIKE :tipo
           AND emision IS NULL
    ");
    $st->execute([
        ':emp'  => $empresaId,
        ':est'  => DCA_ESTADO_AUTORIZADO,
        ':tipo' => $tipoLike,
    ]);
    $rowSf         = $st->fetch() ?: ['n' => 0, 't' => 0];
    $sinFechaCant  = (int)($rowSf['n'] ?? 0);
    $sinFechaTotal = (float)($rowSf['t'] ?? 0);

    dcaResponderSerie($porMes, $sinFechaCant, $sinFechaTotal, $modo, $anio);
}

// ----------------------------------------------------------------------------
// Handler de ordenes de pago (`datacount_pagos`).
// ----------------------------------------------------------------------------
//
// NO se filtra por estado — se cuentan todos los pagos registrados de la
// empresa (descartados / pendientes / contabilizados suman igual). Se
// agrupa por mes de `emision`.
//
// La query suma `valor` (columna con la conversion a pesos ya aplicada por
// convencion del ABM: valor = monto * cotizacion). No se hace ninguna
// conversion runtime; la cotizacion para pagos en dolares vive en
// `datacount_pagos.cotizacion` y `valor` se mantiene coherente via
// migraciones (20260802_1400_backfill_cotizacion_dolar +
// 20260802_1500_recalcular_valor_dolar).

function handlePagos(PDO $pdo, array $q): void {
    $empresaId = (int)($q['empresa'] ?? 0);
    if ($empresaId <= 0) {
        jsonError('Falta parametro `empresa`.', 400);
    }

    $modo = (string)($q['rango'] ?? 'all');
    $anio = isset($q['anio']) ? (int)$q['anio'] : 0;

    $sql = "
        SELECT DATE_FORMAT(emision, '%Y-%m') AS mes,
               SUM(COALESCE(valor, 0))        AS total,
               COUNT(*)                        AS cantidad
          FROM datacount_pagos
         WHERE empresa = :emp
           AND emision IS NOT NULL
         GROUP BY mes
         ORDER BY mes ASC
    ";
    $st = $pdo->prepare($sql);
    $st->execute([':emp' => $empresaId]);
    $porMes = [];
    foreach ($st->fetchAll() as $row) {
        $porMes[(string)$row['mes']] = [
            'total'    => (float)$row['total'],
            'cantidad' => (int)$row['cantidad'],
        ];
    }

    $st = $pdo->prepare("
        SELECT COUNT(*) AS n, SUM(COALESCE(valor, 0)) AS t
          FROM datacount_pagos
         WHERE empresa = :emp
           AND emision IS NULL
    ");
    $st->execute([':emp' => $empresaId]);
    $rowSf         = $st->fetch() ?: ['n' => 0, 't' => 0];
    $sinFechaCant  = (int)($rowSf['n'] ?? 0);
    $sinFechaTotal = (float)($rowSf['t'] ?? 0);

    dcaResponderSerie($porMes, $sinFechaCant, $sinFechaTotal, $modo, $anio);
}

// ----------------------------------------------------------------------------
// Formato de respuesta comun a las 3 pestanas.
// ----------------------------------------------------------------------------
//
// Recibe el crudo por mes + los registros sin fecha (autorizados/contabilizados
// pero sin `emision`) y devuelve la estructura completa con padding a meses
// continuos dentro del rango pedido.

function dcaResponderSerie(array $porMes, int $sinFechaCant, float $sinFechaTotal, string $modo, int $anio): void {
    $totalHistorico    = $sinFechaTotal;
    $cantidadHistorica = $sinFechaCant;
    foreach ($porMes as $m) {
        $totalHistorico    += $m['total'];
        $cantidadHistorica += $m['cantidad'];
    }

    // Rango de meses a devolver. Todos los rangos devuelven meses continuos
    // (sin huecos) para que el eje X del grafico quede prolijo.
    $primero = $porMes ? array_key_first($porMes) : null;
    $ultimo  = $porMes ? array_key_last($porMes)  : null;

    $rango     = dcaCalcularRango($primero, $ultimo, $modo, $anio);
    $rangoInfo = $rango['info'];

    if ($rango['desde'] === null) {
        // 'all' sin ningun registro con fecha: no hay eje que dibujar.
        jsonOk([
            'serie'   => [],
            'resumen' => [
                'total'           => $totalHistorico,
                'cantidad'        => $cantidadHistorica,
                'total_rango'     => 0.0,
                'cantidad_rango'  => 0,
                'primero'         => null,
                'ultimo'          => null,
                'meses'           => 0,
                'sin_fecha_cant'  => $sinFechaCant,
                'sin_fecha_total' => $sinFechaTotal,
            ],
            'rango'   => $rangoInfo,
            'anios'   => [],
        ]);
    }

    // Padding a meses continuos dentro del rango pedido.
    $serie = [];
    foreach (dcaMesesEntre($rango['desde'], $rango['hasta']) as $k) {
        $serie[] = [
            'mes'      => $k,
            'total'    => $porMes[$k]['total']    ?? 0.0,
            'cantidad' => $porMes[$k]['cantidad'] ?? 0,
        ];
    }

    // Total del rango pedido (util para el subtitulo del grafico cuando el
    // rango no es 'all').
    $totalRango    = 0.0;
    $cantidadRango = 0;
    foreach ($serie as $s) {
        $totalRango    += $s['total'];
        $cantidadRango += $s['cantidad'];
    }

    jsonOk([
        'serie'   => $serie,
        'resumen' => [
            'total'           => $totalHistorico,
            'cantidad'        => $cantidadHistorica,
            'total_rango'     => $totalRango,
            'cantidad_rango'  => $cantidadRango,
            'primero'         => $primero,
            'ultimo'          => $ultimo,
            'meses'           => count($serie),
            'sin_fecha_cant'  => $sinFechaCant,
            'sin_fecha_total' => $sinFechaTotal,
        ],
        'rango'   => $rangoInfo,
        'anios'   => dcaAniosDeMeses(array_keys($porMes)),
    ]);
}

// ----------------------------------------------------------------------------
// Handler de resultados (ingresos vs. egresos).
// ----------------------------------------------------------------------------
//
// Cruza mes a mes las dos fuentes que ya alimentan las pestanas existentes:
//   - Ingresos: `datacount_comprobantes` autorizados (estado='3') con
//     tipo LIKE 'F%'  -> identico a la pestana "Facturas".
//   - Egresos:  `datacount_pagos` sin filtro de estado, sumando `valor`
//     -> identico a la pestana "Ordenes de pago".
//
// El resultado de cada mes es `ingresos - egresos`. Deliberadamente NO se
// restan las notas de credito: la pestana es un cruce de las dos pestanas
// mencionadas y nada mas, para que los numeros cierren contra ellas.
//
// Todo lo emitido antes de DCA_RESULTADOS_MES_MIN se descarta en la query,
// asi que el piso rige por igual para la serie, los totales del rango, los
// historicos y la lista de anios elegibles.

function handleResultados(PDO $pdo, array $q): void {
    $empresaId = (int)($q['empresa'] ?? 0);
    if ($empresaId <= 0) {
        jsonError('Falta parametro `empresa`.', 400);
    }

    $modo = (string)($q['rango'] ?? 'all');
    $anio = isset($q['anio']) ? (int)$q['anio'] : 0;

    // --- Ingresos (facturas autorizadas) ---
    $ingPorMes = dcaComprobantesPorMes($pdo, $empresaId, 'F%', DCA_CRUCE_FECHA_MIN);

    // Registros sin fecha de emision: no se pueden ubicar respecto del piso,
    // asi que no suman en ningun total. Se cuentan solo para avisar en la UI.
    $st = $pdo->prepare("
        SELECT COUNT(*) AS n
          FROM datacount_comprobantes
         WHERE empresa = :emp
           AND estado  = :est
           AND tipo    LIKE :tipo
           AND emision IS NULL
    ");
    $st->execute([':emp' => $empresaId, ':est' => DCA_ESTADO_AUTORIZADO, ':tipo' => 'F%']);
    $ingSinFechaN = (int)(($st->fetch() ?: ['n' => 0])['n'] ?? 0);

    // --- Egresos (ordenes de pago) ---
    $st = $pdo->prepare("
        SELECT DATE_FORMAT(emision, '%Y-%m') AS mes,
               SUM(COALESCE(valor, 0))        AS total,
               COUNT(*)                        AS cantidad
          FROM datacount_pagos
         WHERE empresa = :emp
           AND emision >= :piso
         GROUP BY mes
         ORDER BY mes ASC
    ");
    $st->execute([':emp' => $empresaId, ':piso' => DCA_CRUCE_FECHA_MIN]);
    $egrPorMes = [];
    foreach ($st->fetchAll() as $row) {
        $egrPorMes[(string)$row['mes']] = [
            'total'    => (float)$row['total'],
            'cantidad' => (int)$row['cantidad'],
        ];
    }

    $st = $pdo->prepare("
        SELECT COUNT(*) AS n
          FROM datacount_pagos
         WHERE empresa = :emp
           AND emision IS NULL
    ");
    $st->execute([':emp' => $empresaId]);
    $egrSinFechaN = (int)(($st->fetch() ?: ['n' => 0])['n'] ?? 0);

    // --- Historicos (ya acotados al piso por la query) ---
    $ingTotalHist = 0.0; $ingCantHist = 0;
    foreach ($ingPorMes as $m) { $ingTotalHist += $m['total']; $ingCantHist += $m['cantidad']; }
    $egrTotalHist = 0.0; $egrCantHist = 0;
    foreach ($egrPorMes as $m) { $egrTotalHist += $m['total']; $egrCantHist += $m['cantidad']; }

    // --- Rango: la union de los meses de ambas fuentes ---
    $mesesUnion = array_keys($ingPorMes + $egrPorMes);
    sort($mesesUnion);
    $primero = $mesesUnion ? $mesesUnion[0] : null;
    $ultimo  = $mesesUnion ? $mesesUnion[count($mesesUnion) - 1] : null;

    $rango     = dcaRecortarAlPiso(dcaCalcularRango($primero, $ultimo, $modo, $anio));
    $rangoInfo = $rango['info'];

    if ($rango['desde'] === null) {
        jsonOk([
            'serie'   => [],
            'resumen' => dcaResumenResultados([], $ingTotalHist, $ingCantHist, $egrTotalHist, $egrCantHist,
                                              $primero, $ultimo, $ingSinFechaN, $egrSinFechaN),
            'rango'   => $rangoInfo,
            'anios'   => dcaAniosDeMeses($mesesUnion),
        ]);
    }

    $serie = [];
    foreach (dcaMesesEntre($rango['desde'], $rango['hasta']) as $k) {
        $ing = $ingPorMes[$k]['total'] ?? 0.0;
        $egr = $egrPorMes[$k]['total'] ?? 0.0;
        $serie[] = [
            'mes'           => $k,
            'ingresos'      => $ing,
            'egresos'       => $egr,
            'resultado'     => $ing - $egr,
            'cant_ingresos' => $ingPorMes[$k]['cantidad'] ?? 0,
            'cant_egresos'  => $egrPorMes[$k]['cantidad'] ?? 0,
        ];
    }

    jsonOk([
        'serie'   => $serie,
        'resumen' => dcaResumenResultados($serie, $ingTotalHist, $ingCantHist, $egrTotalHist, $egrCantHist,
                                          $primero, $ultimo, $ingSinFechaN, $egrSinFechaN),
        'rango'   => $rangoInfo,
        'anios'   => dcaAniosDeMeses($mesesUnion),
    ]);
}

// Arma el bloque `resumen` de la pestana Resultados: totales del rango,
// totales historicos, mejor/peor mes por resultado y conteo de meses en
// positivo / negativo. Con `$serie` vacia devuelve todo en cero pero
// conserva los historicos (que pueden venir solo de registros sin fecha).
function dcaResumenResultados(array $serie, float $ingHist, int $ingCantHist, float $egrHist, int $egrCantHist,
                              ?string $primero, ?string $ultimo, int $ingSinFecha, int $egrSinFecha): array {
    $ingRango = 0.0; $egrRango = 0.0;
    $ingCant  = 0;   $egrCant  = 0;
    $positivos = 0;  $negativos = 0;
    $mejor = null;   $peor = null;

    foreach ($serie as $s) {
        $ingRango += $s['ingresos'];
        $egrRango += $s['egresos'];
        $ingCant  += $s['cant_ingresos'];
        $egrCant  += $s['cant_egresos'];

        // Los meses sin movimiento (ingresos y egresos en cero) no cuentan
        // como positivos ni negativos, ni compiten por mejor/peor mes:
        // ensuciarian el resumen con el padding de meses vacios.
        if ($s['cant_ingresos'] === 0 && $s['cant_egresos'] === 0) continue;

        if ($s['resultado'] >= 0) $positivos++; else $negativos++;
        if ($mejor === null || $s['resultado'] > $mejor['resultado']) {
            $mejor = ['mes' => $s['mes'], 'resultado' => $s['resultado']];
        }
        if ($peor === null || $s['resultado'] < $peor['resultado']) {
            $peor = ['mes' => $s['mes'], 'resultado' => $s['resultado']];
        }
    }

    return [
        'ingresos_rango'          => $ingRango,
        'egresos_rango'           => $egrRango,
        'resultado_rango'         => $ingRango - $egrRango,
        'cant_ingresos_rango'     => $ingCant,
        'cant_egresos_rango'      => $egrCant,
        'ingresos'                => $ingHist,
        'egresos'                 => $egrHist,
        'resultado'               => $ingHist - $egrHist,
        'cant_ingresos'           => $ingCantHist,
        'cant_egresos'            => $egrCantHist,
        'primero'                 => $primero,
        'ultimo'                  => $ultimo,
        'meses'                   => count($serie),
        'mejor_mes'               => $mejor,
        'peor_mes'                => $peor,
        'meses_positivos'         => $positivos,
        'meses_negativos'         => $negativos,
        'sin_fecha_ingresos_cant' => $ingSinFecha,
        'sin_fecha_egresos_cant'  => $egrSinFecha,
    ];
}

// ----------------------------------------------------------------------------
// Handler de consistencia (facturado vs. acreditado en cuentas de fondos).
// ----------------------------------------------------------------------------
//
// Responde la pregunta "¿lo que entro al banco se parece a lo que emitimos?".
// Un desvio grande y sostenido en un mes es una senal de alarma: facturas que
// nunca se cobraron, cobranzas que nunca se facturaron, o un extracto que no
// se importo.
//
// QUE SE COMPARA
// --------------
//   Facturado neto = facturas autorizadas (F%) - notas de credito (N%).
//     A diferencia de `resultados`, aca las NC SI se restan: son plata que se
//     emitio y despues se anulo, y por lo tanto nunca va a aparecer en el
//     extracto. Dejarlas adentro daria un desvio permanente que no es una
//     inconsistencia real sino una diferencia de definicion.
//
//   Acreditado = ingresos de `datacount_bancos_movimientos` de las cuentas de
//     la empresa. Se excluyen tres cosas que son ingresos pero NO cobranzas:
//       1. Transferencias entre cuentas propias (`contrapartida_id` no nulo).
//          Es la misma plata cambiando de lugar; contarla duplicaria el mes.
//       2. Medios no-cobranza (DCA_MEDIOS_NO_COBRANZA): rendimientos de FCI,
//          intereses y ajustes. Los genera el banco, no un cliente.
//       3. Cuentas de tipo 'tarjeta': un ingreso ahi es el pago del resumen,
//          que sale de otra cuenta propia.
//     Los tres se reportan igual en `excluidos` para que el numero sea
//     auditable y no parezca que se perdieron movimientos.
//
// LIMITE CONOCIDO
// ---------------
// El cruce es por mes calendario y no aparea factura con cobro: una factura
// emitida a fin de mes se cobra el mes siguiente, asi que un desvio de un mes
// aislado es normal. La senal util es el desvio sostenido y la diferencia
// acumulada, que el frontend muestra en la tabla.

function handleConsistencia(PDO $pdo, array $q): void {
    $empresaId = (int)($q['empresa'] ?? 0);
    if ($empresaId <= 0) {
        jsonError('Falta parametro `empresa`.', 400);
    }

    $modo = (string)($q['rango'] ?? 'all');
    $anio = isset($q['anio']) ? (int)$q['anio'] : 0;

    $facPorMes = dcaComprobantesPorMes($pdo, $empresaId, 'F%', DCA_CRUCE_FECHA_MIN);
    $ncPorMes  = dcaComprobantesPorMes($pdo, $empresaId, 'N%', DCA_CRUCE_FECHA_MIN);
    $acrPorMes = dcaAcreditacionesPorMes($pdo, $empresaId, DCA_CRUCE_FECHA_MIN);

    // Facturas sin fecha de emision: no se pueden ubicar en ningun mes, asi
    // que no entran en el cruce. Se informan para que no parezca que faltan.
    $st = $pdo->prepare("
        SELECT COUNT(*) AS n
          FROM datacount_comprobantes
         WHERE empresa = :emp
           AND estado  = :est
           AND tipo    LIKE 'F%'
           AND emision IS NULL
    ");
    $st->execute([':emp' => $empresaId, ':est' => DCA_ESTADO_AUTORIZADO]);
    $sinFechaN = (int)(($st->fetch() ?: ['n' => 0])['n'] ?? 0);

    $excluidos = dcaAcreditacionesExcluidas($pdo, $empresaId, DCA_CRUCE_FECHA_MIN);

    // El rango es la union de los meses de las tres fuentes: un mes con
    // acreditaciones y sin facturacion (o al reves) es justamente el caso que
    // la pestana busca mostrar, asi que no puede quedar afuera del eje.
    $mesesUnion = array_keys($facPorMes + $ncPorMes + $acrPorMes);
    sort($mesesUnion);
    $primero = $mesesUnion ? $mesesUnion[0] : null;
    $ultimo  = $mesesUnion ? $mesesUnion[count($mesesUnion) - 1] : null;

    $rango     = dcaRecortarAlPiso(dcaCalcularRango($primero, $ultimo, $modo, $anio));
    $rangoInfo = $rango['info'];

    $serie = [];
    if ($rango['desde'] !== null) {
        foreach (dcaMesesEntre($rango['desde'], $rango['hasta']) as $k) {
            $fac  = $facPorMes[$k]['total'] ?? 0.0;
            $nc   = $ncPorMes[$k]['total']  ?? 0.0;
            $acr  = $acrPorMes[$k]['total'] ?? 0.0;
            $neto = $fac - $nc;
            $serie[] = [
                'mes'              => $k,
                'facturado'        => $fac,
                'notas'            => $nc,
                'facturado_neto'   => $neto,
                'acreditado'       => $acr,
                'diferencia'       => $acr - $neto,
                // Sin facturacion en el mes no hay base contra la cual medir un
                // porcentaje: se devuelve null y el frontend muestra '—'.
                'desvio_pct'       => $neto > 0 ? (($acr - $neto) / $neto) * 100 : null,
                'cant_facturas'    => $facPorMes[$k]['cantidad'] ?? 0,
                'cant_notas'       => $ncPorMes[$k]['cantidad']  ?? 0,
                'cant_movimientos' => $acrPorMes[$k]['cantidad'] ?? 0,
            ];
        }
    }

    jsonOk([
        'serie'     => $serie,
        'resumen'   => dcaResumenConsistencia($serie, $primero, $ultimo, $sinFechaN),
        'excluidos' => $excluidos,
        'rango'     => $rangoInfo,
        'anios'     => dcaAniosDeMeses($mesesUnion),
    ]);
}

// Bloque `resumen` de la pestana Consistencia: totales del rango, cobertura
// (que porcentaje de lo facturado efectivamente se acredito) y el mes con el
// desvio relativo mas grande, que es por donde conviene empezar a mirar.
function dcaResumenConsistencia(array $serie, ?string $primero, ?string $ultimo, int $sinFechaN): array {
    $fac = 0.0; $nc = 0.0; $acr = 0.0;
    $cFac = 0;  $cNc = 0;  $cMov = 0;
    $conDatos = 0;
    $mayor = null;

    foreach ($serie as $s) {
        $fac  += $s['facturado'];
        $nc   += $s['notas'];
        $acr  += $s['acreditado'];
        $cFac += $s['cant_facturas'];
        $cNc  += $s['cant_notas'];
        $cMov += $s['cant_movimientos'];

        // Los meses de puro padding (sin una sola factura ni un solo
        // movimiento) no son meses inconsistentes: no hay nada que comparar.
        if ($s['cant_facturas'] === 0 && $s['cant_notas'] === 0 && $s['cant_movimientos'] === 0) {
            continue;
        }
        $conDatos++;

        if ($s['desvio_pct'] !== null
            && ($mayor === null || abs($s['desvio_pct']) > abs($mayor['desvio_pct']))) {
            $mayor = [
                'mes'        => $s['mes'],
                'desvio_pct' => $s['desvio_pct'],
                'diferencia' => $s['diferencia'],
            ];
        }
    }

    $neto = $fac - $nc;

    return [
        'facturado_rango'        => $fac,
        'notas_rango'            => $nc,
        'facturado_neto_rango'   => $neto,
        'acreditado_rango'       => $acr,
        'diferencia_rango'       => $acr - $neto,
        'cobertura_pct'          => $neto > 0 ? ($acr / $neto) * 100 : null,
        'cant_facturas_rango'    => $cFac,
        'cant_notas_rango'       => $cNc,
        'cant_movimientos_rango' => $cMov,
        'meses_con_datos'        => $conDatos,
        'mes_mayor_desvio'       => $mayor,
        'primero'                => $primero,
        'ultimo'                 => $ultimo,
        'meses'                  => count($serie),
        'sin_fecha_facturas_cant' => $sinFechaN,
    ];
}

// Comprobantes autorizados de la empresa agrupados por mes de `emision`.
// `$piso` ('YYYY-MM-DD') recorta el extremo izquierdo; con null se traen todos
// los que tengan fecha. Devuelve ['YYYY-MM' => ['total' => f, 'cantidad' => n]].
function dcaComprobantesPorMes(PDO $pdo, int $empresaId, string $tipoLike, ?string $piso): array {
    $st = $pdo->prepare("
        SELECT DATE_FORMAT(emision, '%Y-%m') AS mes,
               SUM(COALESCE(total, 0))        AS total,
               COUNT(*)                        AS cantidad
          FROM datacount_comprobantes
         WHERE empresa = :emp
           AND estado  = :est
           AND tipo    LIKE :tipo
           AND " . ($piso !== null ? 'emision >= :piso' : 'emision IS NOT NULL') . "
         GROUP BY mes
         ORDER BY mes ASC
    ");
    $params = [':emp' => $empresaId, ':est' => DCA_ESTADO_AUTORIZADO, ':tipo' => $tipoLike];
    if ($piso !== null) {
        $params[':piso'] = $piso;
    }
    $st->execute($params);

    $porMes = [];
    foreach ($st->fetchAll() as $row) {
        $porMes[(string)$row['mes']] = [
            'total'    => (float)$row['total'],
            'cantidad' => (int)$row['cantidad'],
        ];
    }
    return $porMes;
}

// Acreditaciones (= ingresos que son cobranzas) por mes de `fecha`. Ver el
// docblock de handleConsistencia() para el detalle de las tres exclusiones.
// Solo suma movimientos en pesos: `importe` esta en la moneda de la cuenta y
// no hay cotizacion por movimiento con la cual llevarlos a una unica moneda.
function dcaAcreditacionesPorMes(PDO $pdo, int $empresaId, string $piso): array {
    [$phMedios, $params] = dcaMediosNoCobranzaBind();
    $params[':emp']  = $empresaId;
    $params[':piso'] = $piso;

    $st = $pdo->prepare("
        SELECT DATE_FORMAT(m.fecha, '%Y-%m') AS mes,
               SUM(COALESCE(m.importe, 0))    AS total,
               COUNT(*)                        AS cantidad
          FROM datacount_bancos_movimientos m
          JOIN datacount_bancos_cuentas c ON c.id = m.cuenta_id
         WHERE c.empresa_id       = :emp
           AND c.tipo            <> 'tarjeta'
           AND m.tipo             = 'ingreso'
           AND m.moneda           = 'P'
           AND m.contrapartida_id IS NULL
           AND (m.medio IS NULL OR m.medio NOT IN ($phMedios))
           AND m.fecha           >= :piso
         GROUP BY mes
         ORDER BY mes ASC
    ");
    $st->execute($params);

    $porMes = [];
    foreach ($st->fetchAll() as $row) {
        $porMes[(string)$row['mes']] = [
            'total'    => (float)$row['total'],
            'cantidad' => (int)$row['cantidad'],
        ];
    }
    return $porMes;
}

// Los ingresos que el cruce deja afuera, desglosados por motivo, mas dos
// indicadores de cobertura de datos (cuentas de la empresa y cuentas sin
// empresa asignada). Sin esto, un "acreditado" mas bajo de lo esperado no se
// puede distinguir de un bug.
function dcaAcreditacionesExcluidas(PDO $pdo, int $empresaId, string $piso): array {
    [$phMedios, $params] = dcaMediosNoCobranzaBind();
    $params[':emp']  = $empresaId;
    $params[':piso'] = $piso;

    // Cada ingreso se clasifica en UN solo motivo dentro de la derivada y
    // recien despues se agrega. Con `SUM(CASE ...)` sueltos habria que repetir
    // los placeholders del IN y ademas cada condicion tendria que negar a las
    // anteriores para no contar un movimiento dos veces; asi el orden de
    // prioridad queda escrito una sola vez y es el mismo que aplica
    // dcaAcreditacionesPorMes().
    $st = $pdo->prepare("
        SELECT motivo, SUM(importe) AS total, COUNT(*) AS cant
          FROM (
            SELECT CASE
                     WHEN m.moneda <> 'P'                THEN 'moneda'
                     WHEN c.tipo = 'tarjeta'             THEN 'tarjeta'
                     WHEN m.contrapartida_id IS NOT NULL THEN 'interna'
                     WHEN m.medio IN ($phMedios)         THEN 'financiero'
                     ELSE 'cobranza'
                   END                     AS motivo,
                   COALESCE(m.importe, 0)  AS importe
              FROM datacount_bancos_movimientos m
              JOIN datacount_bancos_cuentas c ON c.id = m.cuenta_id
             WHERE c.empresa_id = :emp
               AND m.tipo       = 'ingreso'
               AND m.fecha     >= :piso
          ) t
         GROUP BY motivo
    ");
    $st->execute($params);

    $porMotivo = [];
    foreach ($st->fetchAll() as $r) {
        $porMotivo[(string)$r['motivo']] = [
            'total' => (float)$r['total'],
            'cant'  => (int)$r['cant'],
        ];
    }

    $st = $pdo->prepare('SELECT COUNT(*) FROM datacount_bancos_cuentas WHERE empresa_id = :emp');
    $st->execute([':emp' => $empresaId]);
    $cuentasEmpresa = (int)$st->fetchColumn();

    // Cuentas huerfanas: sus movimientos no entran en NINGUN cruce por empresa.
    // Es la explicacion mas frecuente de un acreditado que "falta".
    $cuentasSinEmpresa = (int)$pdo->query(
        'SELECT COUNT(*) FROM datacount_bancos_cuentas WHERE empresa_id IS NULL'
    )->fetchColumn();

    return [
        'tarjeta_total'       => $porMotivo['tarjeta']['total']    ?? 0.0,
        'tarjeta_cant'        => $porMotivo['tarjeta']['cant']     ?? 0,
        'internas_total'      => $porMotivo['interna']['total']    ?? 0.0,
        'internas_cant'       => $porMotivo['interna']['cant']     ?? 0,
        'financieros_total'   => $porMotivo['financiero']['total'] ?? 0.0,
        'financieros_cant'    => $porMotivo['financiero']['cant']  ?? 0,
        // De los movimientos en otra moneda solo se informa el conteo: sumar
        // importes de monedas distintas no daria un numero interpretable.
        'otra_moneda_cant'    => $porMotivo['moneda']['cant']      ?? 0,
        'cuentas_empresa'     => $cuentasEmpresa,
        'cuentas_sin_empresa' => $cuentasSinEmpresa,
    ];
}

// Placeholders + params para el IN (...) de DCA_MEDIOS_NO_COBRANZA. Se arma en
// PHP y no con literales interpolados para que la lista se pueda ampliar sin
// pensar en escaping.
function dcaMediosNoCobranzaBind(): array {
    $ph     = [];
    $params = [];
    foreach (DCA_MEDIOS_NO_COBRANZA as $i => $medio) {
        $k        = ':medio' . $i;
        $ph[]     = $k;
        $params[$k] = $medio;
    }
    return [implode(', ', $ph), $params];
}

// ----------------------------------------------------------------------------
// Helpers de rango compartidos por todas las acciones.
// ----------------------------------------------------------------------------

// Aplica el piso historico a un rango ya calculado (acciones de cruce).
//
// Las ventanas de N meses y los anios anteriores al piso pueden arrancar antes
// de 2025: se recorta el extremo izquierdo para no pintar meses que las
// queries ya vaciaron. Si la ventana entera queda debajo del piso, `desde`
// vuelve null y no hay nada que mostrar. Deja constancia en `info`: `piso`
// siempre, `recortado` solo cuando efectivamente se movio el extremo.
function dcaRecortarAlPiso(array $rango): array {
    if ($rango['desde'] !== null && $rango['desde'] < DCA_CRUCE_MES_MIN) {
        if ($rango['hasta'] < DCA_CRUCE_MES_MIN) {
            $rango['desde']         = null;
            $rango['info']['desde'] = null;
            $rango['info']['hasta'] = null;
        } else {
            $rango['desde']             = DCA_CRUCE_MES_MIN;
            $rango['info']['desde']     = DCA_CRUCE_MES_MIN;
            $rango['info']['recortado'] = true;
        }
    }
    $rango['info']['piso'] = DCA_CRUCE_MES_MIN;
    return $rango;
}

// Resuelve el rango 'YYYY-MM' pedido. `$primero`/`$ultimo` son la extension
// real de los datos y solo se usan con modo 'all'. Devuelve
// ['desde' => 'YYYY-MM'|null, 'hasta' => 'YYYY-MM'|null, 'info' => {...}];
// `desde === null` significa "modo all sin ningun registro con fecha".
function dcaCalcularRango(?string $primero, ?string $ultimo, string $modo, int $anio): array {
    if ($modo === 'year') {
        if ($anio < 1900 || $anio > 2999) {
            jsonError('Anio invalido.', 400);
        }
        $desde = sprintf('%04d-01', $anio);
        $hasta = sprintf('%04d-12', $anio);
        return ['desde' => $desde, 'hasta' => $hasta,
                'info'  => ['modo' => 'year', 'desde' => $desde, 'hasta' => $hasta]];
    }

    if (in_array($modo, ['12', '24', '36'], true)) {
        $n     = (int)$modo;
        // Ancla en el mes actual (America/Argentina/Buenos_Aires). La conexion
        // MySQL ya esta en '-03:00'; DateTime toma la TZ del proceso PHP.
        $tz    = new DateTimeZone('America/Argentina/Buenos_Aires');
        $end   = new DateTime('first day of this month', $tz);
        $start = (clone $end)->modify('-' . ($n - 1) . ' months');
        $desde = $start->format('Y-m');
        $hasta = $end->format('Y-m');
        return ['desde' => $desde, 'hasta' => $hasta,
                'info'  => ['modo' => 'ultimos', 'desde' => $desde, 'hasta' => $hasta, 'meses' => $n]];
    }

    // 'all' (default) o cualquier otro valor: usar la extension real.
    if ($primero === null || $ultimo === null) {
        return ['desde' => null, 'hasta' => null,
                'info'  => ['modo' => 'all', 'desde' => null, 'hasta' => null]];
    }
    return ['desde' => $primero, 'hasta' => $ultimo,
            'info'  => ['modo' => 'all', 'desde' => $primero, 'hasta' => $ultimo]];
}

// Lista continua de meses 'YYYY-MM' entre dos extremos inclusive.
function dcaMesesEntre(string $desde, string $hasta): array {
    $cursor = DateTime::createFromFormat('Y-m-d', $desde . '-01');
    $fin    = DateTime::createFromFormat('Y-m-d', $hasta . '-01');
    if ($cursor === false || $fin === false) {
        jsonError('Rango invalido.', 400);
    }
    $meses = [];
    while ($cursor <= $fin) {
        $meses[] = $cursor->format('Y-m');
        $cursor->modify('+1 month');
    }
    return $meses;
}

// Anios distintos presentes en una lista de meses 'YYYY-MM', para poblar el
// selector "Anio" del frontend.
function dcaAniosDeMeses(array $meses): array {
    $anios = [];
    foreach ($meses as $k) {
        $anios[(int)substr((string)$k, 0, 4)] = true;
    }
    $anios = array_keys($anios);
    sort($anios);
    return $anios;
}
