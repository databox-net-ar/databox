<?php
// api/datarocket_listas_altas.php
// Datarocket > Listas > Altas: historial de suscripciones. SOLO LECTURA.
//
//   GET api/datarocket_listas_altas.php?lista=N[&motivo=..&q=..&desde=..&hasta=..&limite=200]
//                                          -> historial + conteo por motivo
//   GET api/datarocket_listas_altas.php?stats=1[&lista=N&desde=..&hasta=..]
//                                          -> serie por motivo y por mes
//
// Espejo de `datarocket_listas_bajas.php`. Las dos vistas se leen juntas: el
// crecimiento neto de una lista en un mes es altas menos bajas, y la serie
// mensual de las dos usa el mismo formato para que se puedan superponer.
//
// NO expone POST/PUT/DELETE a proposito: `datarocket_listas_altas` es un LOG.
// Las altas se dan de alta suscribiendo (editor de suscriptos de la lista o
// combo de listas en la ficha del prospecto) y eso escribe su propio renglon;
// no se agregan ni se corrigen desde aca.
//
// OJO AL LEER LOS NUMEROS: el historial arranca el dia que se aplico la
// migracion 20260828_2000 y no se pudo backfillear (la puente
// `datarocket_prospectos_listas` no guarda fecha). Una lista con 5.000
// suscriptos y 3 altas registradas no crecio 3: crecio 3 desde entonces.
//
// Respuesta siempre {ok: true, data: ...} u {ok: false, error: '...'} (STACK.md sec. 10).

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/lib/auth_check.php';

requireAuth();
requirePermission('datarocket.listas.altas.consultar');
header('Content-Type: application/json; charset=utf-8');

const DRLA_TABLA        = 'datarocket_listas_altas';
const DRLA_CAMPO_MOTIVO = 'datarocket_lista_alta_motivo';

try {
    $pdo = db();
    if (($_GET['stats'] ?? '') !== '') {
        handleStatsAltas($pdo, $_GET);
    } else {
        handleListAltas($pdo, $_GET);
    }
} catch (Throwable $e) {
    jsonError($e->getMessage(), 500);
}

/**
 * Filtros comunes al listado y a las estadisticas, para que las dos vistas
 * hablen del mismo recorte. Devuelve [where[], params[]].
 */
function drlaFiltros(array $q): array {
    $where  = [];
    $params = [];

    $lista = trim((string) ($q['lista'] ?? ''));
    if ($lista !== '' && ctype_digit($lista)) {
        $where[] = 'a.lista_id = :lista';
        $params[':lista'] = (int) $lista;
    }

    $motivo = trim((string) ($q['motivo'] ?? ''));
    if ($motivo !== '') {
        $where[] = 'a.motivo = :motivo';
        $params[':motivo'] = $motivo;
    }

    // Rango por dia completo: `hasta` incluye el dia entero, si no un filtro
    // "del 1 al 5" perderia todo lo del dia 5 salvo la medianoche exacta.
    $desde = trim((string) ($q['desde'] ?? ''));
    if ($desde !== '') { $where[] = 'a.fecha >= :desde'; $params[':desde'] = $desde . ' 00:00:00'; }
    $hasta = trim((string) ($q['hasta'] ?? ''));
    if ($hasta !== '') { $where[] = 'a.fecha <= :hasta'; $params[':hasta'] = $hasta . ' 23:59:59'; }

    // Las columnas son utf8mb4_general_ci, que pliega mayusculas y acentos.
    $search = trim((string) ($q['q'] ?? ''));
    if ($search !== '') {
        $where[] = '(a.destino LIKE :s1 OR p.nombre LIKE :s2 OR a.detalle LIKE :s3)';
        foreach (['s1', 's2', 's3'] as $k) $params[":{$k}"] = "%{$search}%";
    }

    return [$where, $params];
}

function handleListAltas(PDO $pdo, array $q): void {
    $limite = max(1, min(1000, (int) ($q['limite'] ?? 200)));
    [$where, $params] = drlaFiltros($q);
    $sqlWhere = $where ? ('WHERE ' . implode(' AND ', $where)) : '';

    $sql = "
        SELECT a.id, a.lista_id, a.prospecto_id, a.destino, a.motivo, a.detalle,
               a.origen, a.usuario_id, a.fecha,
               p.nombre  AS prospecto_nombre,
               li.nombre AS lista_nombre,
               u.nombre  AS usuario_nombre,
               (SELECT e.texto FROM estados e
                 WHERE e.campo = '" . DRLA_CAMPO_MOTIVO . "' AND e.valor = a.motivo
                 ORDER BY e.orden ASC, e.id ASC LIMIT 1) AS motivo_texto
          FROM " . DRLA_TABLA . " a
          -- LEFT en los tres: la FK de prospecto y lista es CASCADE, asi que en
          -- teoria siempre resuelven, pero `usuario_id` va sin FK (ninguna tabla
          -- del schema la tiene contra `usuarios`) y un usuario borrado no puede
          -- hacer desaparecer el renglon de historial.
          LEFT JOIN datarocket_prospectos p  ON p.id  = a.prospecto_id
          LEFT JOIN datarocket_listas     li ON li.id = a.lista_id
          LEFT JOIN usuarios              u  ON u.id  = a.usuario_id
        {$sqlWhere}
         ORDER BY a.fecha DESC, a.id DESC
         LIMIT {$limite}
    ";
    $st = $pdo->prepare($sql);
    $st->execute($params);

    $items = array_map(fn($r) => [
        'id'               => (int) $r['id'],
        'lista_id'         => (int) $r['lista_id'],
        'lista_nombre'     => $r['lista_nombre'] !== null ? (string) $r['lista_nombre'] : null,
        'prospecto_id'     => (int) $r['prospecto_id'],
        'prospecto_nombre' => $r['prospecto_nombre'] !== null ? (string) $r['prospecto_nombre'] : null,
        'destino'          => $r['destino'] !== null ? (string) $r['destino'] : null,
        'motivo'           => (string) $r['motivo'],
        'motivo_texto'     => $r['motivo_texto'] !== null ? (string) $r['motivo_texto'] : null,
        'detalle'          => $r['detalle'] !== null ? (string) $r['detalle'] : null,
        'origen'           => $r['origen']  !== null ? (string) $r['origen']  : null,
        'usuario_id'       => $r['usuario_id'] !== null ? (int) $r['usuario_id'] : null,
        'usuario_nombre'   => $r['usuario_nombre'] !== null ? (string) $r['usuario_nombre'] : null,
        'fecha'            => $r['fecha'] ?? null,
    ], $st->fetchAll());

    // Conteo por motivo sobre el recorte COMPLETO (sin el LIMIT): los chips
    // tienen que decir cuantas hay de cada tipo, no cuantas entraron en la
    // pagina. Se saca el filtro de motivo para que cada chip muestre su propio
    // total aunque haya uno activo.
    [$whereSinMotivo, $paramsSinMotivo] = drlaFiltros(array_diff_key($q, ['motivo' => null]));
    $sqlWhereSM = $whereSinMotivo ? ('WHERE ' . implode(' AND ', $whereSinMotivo)) : '';
    $sc = $pdo->prepare("
        SELECT a.motivo, COUNT(*) AS c
          FROM " . DRLA_TABLA . " a
          LEFT JOIN datarocket_prospectos p ON p.id = a.prospecto_id
        {$sqlWhereSM}
         GROUP BY a.motivo
    ");
    $sc->execute($paramsSinMotivo);
    $conteo = [];
    foreach ($sc->fetchAll() as $r) $conteo[(string) $r['motivo']] = (int) $r['c'];

    jsonOk([
        'items'  => $items,
        'conteo' => $conteo,
        'total'  => array_sum($conteo),
    ]);
}

/**
 * Serie mensual por motivo. Puesta al lado de la de bajas responde si la lista
 * crece o se recicla: 200 altas y 180 bajas en el mismo mes no es una lista que
 * crecio 20, es una lista que rota entera.
 */
function handleStatsAltas(PDO $pdo, array $q): void {
    [$where, $params] = drlaFiltros($q);
    $sqlWhere = $where ? ('WHERE ' . implode(' AND ', $where)) : '';

    $st = $pdo->prepare("
        SELECT DATE_FORMAT(a.fecha, '%Y-%m') AS mes, a.motivo, COUNT(*) AS c
          FROM " . DRLA_TABLA . " a
          LEFT JOIN datarocket_prospectos p ON p.id = a.prospecto_id
        {$sqlWhere}
         GROUP BY DATE_FORMAT(a.fecha, '%Y-%m'), a.motivo
         ORDER BY mes DESC
    ");
    $st->execute($params);

    $meses = [];
    foreach ($st->fetchAll() as $r) {
        $mes = (string) $r['mes'];
        if (!isset($meses[$mes])) $meses[$mes] = ['mes' => $mes, 'total' => 0];
        $meses[$mes][(string) $r['motivo']] = (int) $r['c'];
        $meses[$mes]['total'] += (int) $r['c'];
    }

    jsonOk(['meses' => array_values($meses)]);
}
