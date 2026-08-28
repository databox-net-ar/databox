<?php
// api/datarocket_listas_bajas.php
// Datarocket > Listas > Bajas: historial de desuscripciones. SOLO LECTURA.
//
//   GET api/datarocket_listas_bajas.php?lista=N[&motivo=..&q=..&desde=..&hasta=..&limite=200]
//                                          -> historial + conteo por motivo
//   GET api/datarocket_listas_bajas.php?stats=1[&lista=N&desde=..&hasta=..]
//                                          -> serie por motivo y por mes
//
// NO expone POST/PUT/DELETE a proposito: `datarocket_listas_bajas` es un LOG.
// Editarlo o borrarlo a mano destruiria exactamente la evidencia que la tabla
// existe para conservar — que fue el motivo de crearla (ver la migracion
// 20260828_1400). Volver a suscribir a alguien se hace desde el editor de
// suscriptos de la lista, y eso deja su propio renglon de historial; no se
// "deshace" borrando el registro de la baja.
//
// Las filas sobreviven al borrado de la campana que las origino
// (`fk_drlb_campana ... ON DELETE SET NULL`), asi que `campana_id` y el nombre
// de la campana pueden venir en NULL en registros viejos. El LEFT JOIN es
// obligatorio por eso.
//
// Respuesta siempre {ok: true, data: ...} u {ok: false, error: '...'} (STACK.md sec. 10).

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/lib/auth_check.php';

requireAuth();
requirePermission('datarocket.listas.bajas.consultar');
header('Content-Type: application/json; charset=utf-8');

const DRLB_TABLA        = 'datarocket_listas_bajas';
const DRLB_CAMPO_MOTIVO = 'datarocket_lista_baja_motivo';

try {
    $pdo = db();
    if (($_GET['stats'] ?? '') !== '') {
        handleStatsBajas($pdo, $_GET);
    } else {
        handleListBajas($pdo, $_GET);
    }
} catch (Throwable $e) {
    jsonError($e->getMessage(), 500);
}

/**
 * Filtros comunes al listado y a las estadisticas, para que las dos vistas
 * hablen del mismo recorte. Devuelve [where[], params[]].
 */
function drlbFiltros(array $q): array {
    $where  = [];
    $params = [];

    $lista = trim((string) ($q['lista'] ?? ''));
    if ($lista !== '' && ctype_digit($lista)) {
        $where[] = 'b.lista_id = :lista';
        $params[':lista'] = (int) $lista;
    }

    $motivo = trim((string) ($q['motivo'] ?? ''));
    if ($motivo !== '') {
        $where[] = 'b.motivo = :motivo';
        $params[':motivo'] = $motivo;
    }

    // Rango por dia completo: `hasta` incluye el dia entero, si no un filtro
    // "del 1 al 5" perderia todo lo del dia 5 salvo la medianoche exacta.
    $desde = trim((string) ($q['desde'] ?? ''));
    if ($desde !== '') { $where[] = 'b.fecha >= :desde'; $params[':desde'] = $desde . ' 00:00:00'; }
    $hasta = trim((string) ($q['hasta'] ?? ''));
    if ($hasta !== '') { $where[] = 'b.fecha <= :hasta'; $params[':hasta'] = $hasta . ' 23:59:59'; }

    // Las columnas son utf8mb4_general_ci, que pliega mayusculas y acentos.
    $search = trim((string) ($q['q'] ?? ''));
    if ($search !== '') {
        $where[] = '(b.destino LIKE :s1 OR p.nombre LIKE :s2 OR b.detalle LIKE :s3)';
        foreach (['s1', 's2', 's3'] as $k) $params[":{$k}"] = "%{$search}%";
    }

    return [$where, $params];
}

function handleListBajas(PDO $pdo, array $q): void {
    $limite = max(1, min(1000, (int) ($q['limite'] ?? 200)));
    [$where, $params] = drlbFiltros($q);
    $sqlWhere = $where ? ('WHERE ' . implode(' AND ', $where)) : '';

    $sql = "
        SELECT b.id, b.lista_id, b.prospecto_id, b.destino, b.motivo, b.detalle,
               b.origen, b.usuario_id, b.campana_id, b.mensaje_id, b.fecha,
               p.nombre  AS prospecto_nombre,
               li.nombre AS lista_nombre,
               c.nombre  AS campana_nombre,
               u.nombre  AS usuario_nombre,
               (SELECT e.texto FROM estados e
                 WHERE e.campo = '" . DRLB_CAMPO_MOTIVO . "' AND e.valor = b.motivo
                 ORDER BY e.orden ASC, e.id ASC LIMIT 1) AS motivo_texto
          FROM " . DRLB_TABLA . " b
          LEFT JOIN datarocket_prospectos p  ON p.id  = b.prospecto_id
          LEFT JOIN datarocket_listas     li ON li.id = b.lista_id
          -- LEFT y no INNER: la campana pudo borrarse (FK ON DELETE SET NULL).
          -- Ese es justamente el caso que la tabla existe para sobrevivir.
          LEFT JOIN datarocket_campanas   c  ON c.id  = b.campana_id
          LEFT JOIN usuarios              u  ON u.id  = b.usuario_id
        {$sqlWhere}
         ORDER BY b.fecha DESC, b.id DESC
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
        'campana_id'       => $r['campana_id'] !== null ? (int) $r['campana_id'] : null,
        // NULL con `campana_id` no nulo no puede pasar; NULL con campana_id
        // NULL significa "la campana que la origino ya no existe".
        'campana_nombre'   => $r['campana_nombre'] !== null ? (string) $r['campana_nombre'] : null,
        'mensaje_id'       => $r['mensaje_id'] !== null ? (int) $r['mensaje_id'] : null,
        'fecha'            => $r['fecha'] ?? null,
    ], $st->fetchAll());

    // Conteo por motivo sobre el recorte COMPLETO (sin el LIMIT): los chips
    // tienen que decir cuantas hay de cada tipo, no cuantas entraron en la
    // pagina. Se saca el filtro de motivo para que cada chip muestre su propio
    // total aunque haya uno activo.
    [$whereSinMotivo, $paramsSinMotivo] = drlbFiltros(array_diff_key($q, ['motivo' => null]));
    $sqlWhereSM = $whereSinMotivo ? ('WHERE ' . implode(' AND ', $whereSinMotivo)) : '';
    $sc = $pdo->prepare("
        SELECT b.motivo, COUNT(*) AS c
          FROM " . DRLB_TABLA . " b
          LEFT JOIN datarocket_prospectos p ON p.id = b.prospecto_id
        {$sqlWhereSM}
         GROUP BY b.motivo
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
 * Serie mensual por motivo. Es lo que responde "¿estamos quemando la lista?":
 * un mes con un salto de rebotes duros suele significar padron viejo, y uno con
 * salto de spam, un problema de contenido o de frecuencia.
 */
function handleStatsBajas(PDO $pdo, array $q): void {
    [$where, $params] = drlbFiltros($q);
    $sqlWhere = $where ? ('WHERE ' . implode(' AND ', $where)) : '';

    $st = $pdo->prepare("
        SELECT DATE_FORMAT(b.fecha, '%Y-%m') AS mes, b.motivo, COUNT(*) AS c
          FROM " . DRLB_TABLA . " b
          LEFT JOIN datarocket_prospectos p ON p.id = b.prospecto_id
        {$sqlWhere}
         GROUP BY DATE_FORMAT(b.fecha, '%Y-%m'), b.motivo
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
