<?php
// api/arca_autorizaciones.php
// Visor read-only de la tabla `arca_autorizaciones` (log tecnico de los
// FECAESolicitar que dispara `/v4/arca/autorizar.php`). El microservicio
// escribe; este endpoint solo lee.
//
//   GET api/arca_autorizaciones.php          -> listado con filtros (query string)
//   GET api/arca_autorizaciones.php?id=N     -> detalle completo
//
// Respuesta siempre {ok: true, data: ...} u {ok: false, error: '...'}.

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/lib/auth_check.php';

requireAuth();
header('Content-Type: application/json; charset=utf-8');

const ARCA_AUT_RESULTADOS = ['A', 'R', 'error'];

// SELECT con JOIN a `datacount_empresas` para traer slug/nombre y a
// `aplicaciones` para traer el nombre. Asi el visor no tiene que hacer
// N+1 lookups en el cliente.
const ARCA_AUT_COLS = 'a.id, a.fecha, a.aplicacion_id, a.empresa_id, a.punto, a.tipo, a.cbte_nro,
                       a.resultado, a.cae, a.cae_vto, a.errores, a.duracion_ms,
                       e.slug   AS empresa_slug,
                       e.nombre AS empresa_nombre,
                       ap.nombre AS aplicacion_nombre';
const ARCA_AUT_FROM = 'arca_autorizaciones a
                       LEFT JOIN datacount_empresas e  ON e.id  = a.empresa_id
                       LEFT JOIN aplicaciones      ap ON ap.id = a.aplicacion_id';

try {
    requirePermCrud('plataformas.arca.autorizaciones');
    $pdo    = db();
    $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
    $id     = isset($_GET['id']) ? (int)$_GET['id'] : 0;

    if ($method === 'GET' && $id > 0) {
        handleGetOne($pdo, $id);
    } elseif ($method === 'GET') {
        handleList($pdo, $_GET);
    } else {
        jsonError('Metodo no soportado', 405);
    }
} catch (Throwable $e) {
    jsonError($e->getMessage(), 500);
}

function normalizarFilaArcaAut(array $r): array {
    return [
        'id'                 => (int)($r['id'] ?? 0),
        'fecha'              => $r['fecha']              !== null ? (string)$r['fecha']              : null,
        'aplicacion_id'      => (int)($r['aplicacion_id'] ?? 0),
        'aplicacion_nombre'  => $r['aplicacion_nombre']  !== null ? (string)$r['aplicacion_nombre']  : null,
        'empresa_id'         => (int)($r['empresa_id']    ?? 0),
        'empresa_slug'       => $r['empresa_slug']       !== null ? (string)$r['empresa_slug']       : null,
        'empresa_nombre'     => $r['empresa_nombre']     !== null ? (string)$r['empresa_nombre']     : null,
        'punto'              => (int)($r['punto']    ?? 0),
        'tipo'               => (int)($r['tipo']     ?? 0),
        'cbte_nro'           => (int)($r['cbte_nro'] ?? 0),
        'resultado'          => (string)($r['resultado'] ?? 'error'),
        'cae'                => $r['cae']      !== null ? (string)$r['cae']      : null,
        'cae_vto'            => $r['cae_vto']  !== null ? (string)$r['cae_vto']  : null,
        'errores'            => $r['errores']  !== null ? (string)$r['errores']  : null,
        'duracion_ms'        => $r['duracion_ms'] !== null ? (int)$r['duracion_ms'] : null,
    ];
}

function handleList(PDO $pdo, array $q): void {
    $search    = trim((string)($q['q']         ?? ''));
    $resultado = trim((string)($q['resultado'] ?? ''));
    $empresaId = isset($q['empresa_id']) && $q['empresa_id'] !== '' ? (int)$q['empresa_id'] : null;
    $desde     = trim((string)($q['desde']     ?? ''));
    $hasta     = trim((string)($q['hasta']     ?? ''));
    $limite    = isset($q['limite']) ? (int)$q['limite'] : 200;
    if ($limite < 1 || $limite > 2000) $limite = 200;

    $where  = [];
    $params = [];

    if ($search !== '') {
        // Buscamos en cae / errores / empresa_slug / cbte_nro (como texto).
        // Placeholders distintos para cada columna (EMULATE_PREPARES=false).
        $where[] = '(a.cae LIKE :s1 OR a.errores LIKE :s2 OR e.slug LIKE :s3 OR CAST(a.cbte_nro AS CHAR) LIKE :s4)';
        $like = "%{$search}%";
        $params[':s1'] = $like;
        $params[':s2'] = $like;
        $params[':s3'] = $like;
        $params[':s4'] = $like;
    }
    if ($resultado !== '' && in_array($resultado, ARCA_AUT_RESULTADOS, true)) {
        $where[] = 'a.resultado = :resultado';
        $params[':resultado'] = $resultado;
    }
    if ($empresaId !== null && $empresaId > 0) {
        $where[] = 'a.empresa_id = :empresa_id';
        $params[':empresa_id'] = $empresaId;
    }
    if ($desde !== '') {
        $where[] = 'a.fecha >= :desde';
        $params[':desde'] = $desde . ' 00:00:00';
    }
    if ($hasta !== '') {
        $where[] = 'a.fecha <= :hasta';
        $params[':hasta'] = $hasta . ' 23:59:59';
    }

    $sqlWhere = $where ? ('WHERE ' . implode(' AND ', $where)) : '';

    // Stats globales (SIN filtros -- indicadores del recurso completo).
    $stats = $pdo->query('
        SELECT
            COUNT(*)                                            AS total,
            SUM(CASE WHEN resultado = \'A\'     THEN 1 ELSE 0 END) AS aceptadas,
            SUM(CASE WHEN resultado = \'R\'     THEN 1 ELSE 0 END) AS rechazadas,
            SUM(CASE WHEN resultado = \'error\' THEN 1 ELSE 0 END) AS errores
        FROM arca_autorizaciones
    ')->fetch();

    $sql = 'SELECT ' . ARCA_AUT_COLS . ' FROM ' . ARCA_AUT_FROM
         . " {$sqlWhere} ORDER BY a.id DESC LIMIT {$limite}";
    $st  = $pdo->prepare($sql);
    $st->execute($params);
    $rows = array_map('normalizarFilaArcaAut', $st->fetchAll());

    jsonOk([
        'stats' => [
            'total'      => (int)($stats['total']      ?? 0),
            'aceptadas'  => (int)($stats['aceptadas']  ?? 0),
            'rechazadas' => (int)($stats['rechazadas'] ?? 0),
            'errores'    => (int)($stats['errores']    ?? 0),
        ],
        'items' => $rows,
    ]);
}

function handleGetOne(PDO $pdo, int $id): void {
    $st = $pdo->prepare('SELECT ' . ARCA_AUT_COLS . ' FROM ' . ARCA_AUT_FROM . ' WHERE a.id = :id LIMIT 1');
    $st->execute([':id' => $id]);
    $row = $st->fetch();
    if (!$row) jsonError('Autorizacion no encontrada', 404);
    jsonOk(normalizarFilaArcaAut($row));
}
