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
//     que muestran las pestanas de origen. UNICA diferencia: ignora todo lo
//     anterior a DCA_RESULTADOS_DESDE (ver la constante).
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

// Piso de la accion `resultados`: nada anterior a este mes entra en el cruce
// ingresos/egresos (ni en la serie, ni en los totales, ni en el selector de
// anios). Antes de 2025 el cruce no es representativo — hay ordenes de pago
// cargadas desde 2023 contra facturas que recien arrancan en septiembre 2024,
// asi que los meses previos daban resultados negativos que no reflejan la
// operacion real. Las pestanas facturas / notas / pagos NO tienen este piso:
// siguen mostrando su historia completa.
const DCA_RESULTADOS_DESDE = '2025-01';

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

    $sql = "
        SELECT DATE_FORMAT(emision, '%Y-%m') AS mes,
               SUM(COALESCE(total, 0))        AS total,
               COUNT(*)                        AS cantidad
          FROM datacount_comprobantes
         WHERE empresa = :emp
           AND estado  = :est
           AND tipo    LIKE :tipo
           AND emision IS NOT NULL
         GROUP BY mes
         ORDER BY mes ASC
    ";
    $st = $pdo->prepare($sql);
    $st->execute([
        ':emp'  => $empresaId,
        ':est'  => DCA_ESTADO_AUTORIZADO,
        ':tipo' => $tipoLike,
    ]);
    $porMes = [];
    foreach ($st->fetchAll() as $row) {
        $porMes[(string)$row['mes']] = [
            'total'    => (float)$row['total'],
            'cantidad' => (int)$row['cantidad'],
        ];
    }

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
// Todo lo anterior a DCA_RESULTADOS_DESDE queda afuera: las dos queries lo
// filtran en el WHERE, asi que no entra ni en la serie, ni en los totales
// historicos, ni en el selector de anios. Si el rango pedido empieza antes
// del piso se recorta y se marca `rango.recortado = true` para que el
// frontend pueda decirlo.

function handleResultados(PDO $pdo, array $q): void {
    $empresaId = (int)($q['empresa'] ?? 0);
    if ($empresaId <= 0) {
        jsonError('Falta parametro `empresa`.', 400);
    }

    $modo     = (string)($q['rango'] ?? 'all');
    $anio     = isset($q['anio']) ? (int)$q['anio'] : 0;
    $pisoDate = DCA_RESULTADOS_DESDE . '-01';

    // --- Ingresos (facturas autorizadas) ---
    $st = $pdo->prepare("
        SELECT DATE_FORMAT(emision, '%Y-%m') AS mes,
               SUM(COALESCE(total, 0))        AS total,
               COUNT(*)                        AS cantidad
          FROM datacount_comprobantes
         WHERE empresa = :emp
           AND estado  = :est
           AND tipo    LIKE :tipo
           AND emision >= :piso
         GROUP BY mes
         ORDER BY mes ASC
    ");
    $st->execute([':emp' => $empresaId, ':est' => DCA_ESTADO_AUTORIZADO, ':tipo' => 'F%', ':piso' => $pisoDate]);
    $ingPorMes = [];
    foreach ($st->fetchAll() as $row) {
        $ingPorMes[(string)$row['mes']] = [
            'total'    => (float)$row['total'],
            'cantidad' => (int)$row['cantidad'],
        ];
    }

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
    $st->execute([':emp' => $empresaId, ':piso' => $pisoDate]);
    $egrPorMes = [];
    foreach ($st->fetchAll() as $row) {
        $egrPorMes[(string)$row['mes']] = [
            'total'    => (float)$row['total'],
            'cantidad' => (int)$row['cantidad'],
        ];
    }

    // --- Totales de todo el periodo considerado (desde el piso hasta hoy) ---
    // No suman los registros sin fecha de emision: al no tener periodo no se
    // puede decidir si caen dentro o fuera del piso. Se informan aparte para
    // que quede explicito por que estos numeros no cierran contra las
    // pestanas Facturas / Ordenes de pago.
    $ingTotalHist = 0.0; $ingCantHist = 0;
    foreach ($ingPorMes as $m) { $ingTotalHist += $m['total']; $ingCantHist += $m['cantidad']; }
    $egrTotalHist = 0.0; $egrCantHist = 0;
    foreach ($egrPorMes as $m) { $egrTotalHist += $m['total']; $egrCantHist += $m['cantidad']; }

    $st = $pdo->prepare("
        SELECT COUNT(*) AS n
          FROM datacount_comprobantes
         WHERE empresa = :emp AND estado = :est AND tipo LIKE :tipo AND emision IS NULL
    ");
    $st->execute([':emp' => $empresaId, ':est' => DCA_ESTADO_AUTORIZADO, ':tipo' => 'F%']);
    $ingSinFechaN = (int)(($st->fetch()['n']) ?? 0);

    $st = $pdo->prepare("
        SELECT COUNT(*) AS n
          FROM datacount_pagos
         WHERE empresa = :emp AND emision IS NULL
    ");
    $st->execute([':emp' => $empresaId]);
    $egrSinFechaN = (int)(($st->fetch()['n']) ?? 0);

    // --- Rango: la union de los meses de ambas fuentes ---
    $mesesUnion = array_keys($ingPorMes + $egrPorMes);
    sort($mesesUnion);
    $primero = $mesesUnion ? $mesesUnion[0] : null;
    $ultimo  = $mesesUnion ? $mesesUnion[count($mesesUnion) - 1] : null;

    $rango     = dcaCalcularRango($primero, $ultimo, $modo, $anio);
    $rangoInfo = $rango['info'];

    // Recorte contra el piso. Un rango que termina antes del piso (p.ej.
    // ?rango=year&anio=2024) queda directamente sin serie.
    $sinDatos = ($rango['desde'] === null)
             || ($rango['hasta'] !== null && $rango['hasta'] < DCA_RESULTADOS_DESDE);
    if (!$sinDatos && $rango['desde'] < DCA_RESULTADOS_DESDE) {
        $rango['desde']         = DCA_RESULTADOS_DESDE;
        $rangoInfo['desde']     = DCA_RESULTADOS_DESDE;
        $rangoInfo['recortado'] = true;
    }
    $rangoInfo['piso'] = DCA_RESULTADOS_DESDE;

    if ($sinDatos) {
        if ($rango['desde'] !== null) {
            $rangoInfo['recortado'] = true;
        }
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
// Helpers de rango compartidos por todas las acciones.
// ----------------------------------------------------------------------------

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
