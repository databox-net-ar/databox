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

    if ($modo === 'year') {
        if ($anio < 1900 || $anio > 2999) {
            jsonError('Anio invalido.', 400);
        }
        $desde = sprintf('%04d-01', $anio);
        $hasta = sprintf('%04d-12', $anio);
        $rangoInfo = ['modo' => 'year', 'desde' => $desde, 'hasta' => $hasta];
    } elseif (in_array($modo, ['12', '24', '36'], true)) {
        $n     = (int)$modo;
        // Ancla en el mes actual (America/Argentina/Buenos_Aires). La conexion
        // MySQL ya esta en '-03:00'; DateTime toma la TZ del proceso PHP.
        $tz    = new DateTimeZone('America/Argentina/Buenos_Aires');
        $end   = new DateTime('first day of this month', $tz);
        $start = (clone $end)->modify('-' . ($n - 1) . ' months');
        $desde = $start->format('Y-m');
        $hasta = $end->format('Y-m');
        $rangoInfo = ['modo' => 'ultimos', 'desde' => $desde, 'hasta' => $hasta, 'meses' => $n];
    } else {
        // 'all' (default) o cualquier otro valor: usar la extension real.
        if ($primero === null) {
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
                'rango'   => ['modo' => 'all', 'desde' => null, 'hasta' => null],
                'anios'   => [],
            ]);
        }
        $desde = $primero;
        $hasta = $ultimo;
        $rangoInfo = ['modo' => 'all', 'desde' => $desde, 'hasta' => $hasta];
    }

    // Padding a meses continuos dentro del rango pedido.
    $serie  = [];
    $cursor = DateTime::createFromFormat('Y-m-d', $desde . '-01');
    $fin    = DateTime::createFromFormat('Y-m-d', $hasta . '-01');
    if ($cursor === false || $fin === false) {
        jsonError('Rango invalido.', 400);
    }
    while ($cursor <= $fin) {
        $k = $cursor->format('Y-m');
        $serie[] = [
            'mes'      => $k,
            'total'    => $porMes[$k]['total']    ?? 0.0,
            'cantidad' => $porMes[$k]['cantidad'] ?? 0,
        ];
        $cursor->modify('+1 month');
    }

    // Total del rango pedido (util para el subtitulo del grafico cuando el
    // rango no es 'all').
    $totalRango    = 0.0;
    $cantidadRango = 0;
    foreach ($serie as $s) {
        $totalRango    += $s['total'];
        $cantidadRango += $s['cantidad'];
    }

    // Lista de anios con datos, para poblar el selector "Anio" del frontend.
    $anios = [];
    foreach (array_keys($porMes) as $k) {
        $y = (int)substr($k, 0, 4);
        $anios[$y] = true;
    }
    $anios = array_keys($anios);
    sort($anios);

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
        'anios'   => $anios,
    ]);
}
