<?php
// api/datarocketdominios.php
// Dominios Datarocket (CRUD). Lee/escribe sobre la tabla `datarocket_dominios`
// definida en db/schema.sql — cada fila representa un dominio DNS
// administrado por Databox con su titular WHOIS, entidad registrante,
// responsable operativo (Databox / Cliente), fechas del ciclo de vida
// (registro, ultima renovacion, proxima renovacion) y costo de renovacion
// con su moneda ISO 4217.
//
//   GET    api/datarocketdominios.php[?q=...&responsable=...&limite=100&orden=id&dir=desc]
//                                      -> listado + stats por responsable
//   GET    api/datarocketdominios.php?id=N
//                                      -> registro individual
//   POST   api/datarocketdominios.php     -> alta (JSON body)
//   PUT    api/datarocketdominios.php?id=N
//                                      -> modificacion (JSON body)
//   DELETE api/datarocketdominios.php?id=N
//                                      -> baja
//
// Respuesta siempre {ok: true, data: ...} u {ok: false, error: '...'} (STACK.md sec. 10).

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/lib/auth_check.php';
require_once __DIR__ . '/lib/sucesos.php';

requireAuth();
header('Content-Type: application/json; charset=utf-8');

const DRDO_RESPONSABLES = ['Databox', 'Cliente'];
const DRDO_MONEDAS      = ['ARS', 'USD', 'EUR', 'BRL', 'CLP', 'UYU'];
const DRDO_ORDENES      = ['id', 'dominio', 'titular_dominio', 'entidad_registrante',
                           'fecha_registro', 'fecha_siguiente_renovacion', 'costo_renovacion'];
const DRDO_COLS         = 'id, dominio, titular_dominio, entidad_registrante, responsable, '
                        . 'fecha_registro, fecha_ultima_renovacion, fecha_siguiente_renovacion, '
                        . 'costo_renovacion, moneda, actualizado, fecha_creacion';

try {
    requirePermCrud('datarocket.dominios');
    $pdo    = db();
    $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
    $id     = isset($_GET['id']) ? (int)$_GET['id'] : 0;

    if ($method === 'GET' && $id > 0) {
        handleGetOneDominio($pdo, $id);
    } elseif ($method === 'GET') {
        handleListDominios($pdo, $_GET);
    } elseif ($method === 'POST') {
        handleCreateDominio($pdo, readJsonBody());
    } elseif ($method === 'PUT') {
        if ($id <= 0) jsonError('Falta id', 400);
        handleUpdateDominio($pdo, $id, readJsonBody());
    } elseif ($method === 'DELETE') {
        if ($id <= 0) jsonError('Falta id', 400);
        handleDeleteDominio($pdo, $id);
    } else {
        jsonError('Metodo no soportado', 405);
    }
} catch (Throwable $e) {
    jsonError($e->getMessage(), 500);
}

// ----------------------------------------------------------------------------
// Helpers
// ----------------------------------------------------------------------------

function normalizarFilaDominio(array $r): array {
    return [
        'id'                          => (int)($r['id'] ?? 0),
        'dominio'                     => (string)($r['dominio'] ?? ''),
        'titular_dominio'             => $r['titular_dominio']     !== null ? (string)$r['titular_dominio']     : null,
        'entidad_registrante'         => $r['entidad_registrante'] !== null ? (string)$r['entidad_registrante'] : null,
        'responsable'                 => (string)($r['responsable'] ?? 'Databox'),
        'fecha_registro'              => $r['fecha_registro']              ?: null,
        'fecha_ultima_renovacion'     => $r['fecha_ultima_renovacion']     ?: null,
        'fecha_siguiente_renovacion'  => $r['fecha_siguiente_renovacion']  ?: null,
        'costo_renovacion'            => $r['costo_renovacion'] !== null ? (float)$r['costo_renovacion'] : null,
        'moneda'                      => (string)($r['moneda'] ?? 'ARS'),
        'actualizado'                 => $r['actualizado']    ?? null,
        'fecha_creacion'              => $r['fecha_creacion'] ?? null,
    ];
}

function sanitizePayloadDominio(array $in, bool $esAlta): array {
    $dominio             = strtolower(trim((string)($in['dominio'] ?? '')));
    $titular             = trim((string)($in['titular_dominio']     ?? ''));
    $entidad             = trim((string)($in['entidad_registrante'] ?? ''));
    $responsable         = trim((string)($in['responsable']         ?? ''));
    $fechaRegistro       = trim((string)($in['fecha_registro']              ?? ''));
    $fechaUltimaRenov    = trim((string)($in['fecha_ultima_renovacion']     ?? ''));
    $fechaSiguienteRenov = trim((string)($in['fecha_siguiente_renovacion']  ?? ''));
    $moneda              = strtoupper(trim((string)($in['moneda'] ?? '')));

    $costoRaw = $in['costo_renovacion'] ?? null;
    if (is_string($costoRaw)) $costoRaw = trim($costoRaw);
    $costo = ($costoRaw === '' || $costoRaw === null) ? null : (float)$costoRaw;

    if ($esAlta) {
        if ($dominio === '') jsonError('El dominio es obligatorio.', 400);
        if ($responsable === '') $responsable = 'Databox';
        if ($moneda === '')      $moneda      = 'ARS';
    }

    if ($dominio !== '' && mb_strlen($dominio) > 255) {
        jsonError('El dominio no puede superar los 255 caracteres.', 400);
    }
    if ($dominio !== '' && !preg_match('/^[a-z0-9]([a-z0-9\-\.]*[a-z0-9])?$/i', $dominio)) {
        jsonError('El dominio tiene caracteres invalidos.', 400);
    }
    if ($titular !== '' && mb_strlen($titular) > 200) {
        jsonError('El titular no puede superar los 200 caracteres.', 400);
    }
    if ($entidad !== '' && mb_strlen($entidad) > 200) {
        jsonError('La entidad registrante no puede superar los 200 caracteres.', 400);
    }
    if ($responsable !== '' && !in_array($responsable, DRDO_RESPONSABLES, true)) {
        jsonError('Responsable invalido (debe ser Databox o Cliente).', 400);
    }
    if ($moneda !== '' && !in_array($moneda, DRDO_MONEDAS, true)) {
        jsonError('Moneda invalida.', 400);
    }
    foreach (['fecha_registro' => $fechaRegistro, 'fecha_ultima_renovacion' => $fechaUltimaRenov, 'fecha_siguiente_renovacion' => $fechaSiguienteRenov] as $campo => $val) {
        if ($val !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $val)) {
            jsonError("La {$campo} debe tener formato YYYY-MM-DD.", 400);
        }
    }
    if ($costo !== null && $costo < 0) {
        jsonError('El costo no puede ser negativo.', 400);
    }

    return [
        'dominio'                     => $dominio,
        'titular_dominio'             => $titular === '' ? null : $titular,
        'entidad_registrante'         => $entidad === '' ? null : $entidad,
        'responsable'                 => $responsable,
        'fecha_registro'              => $fechaRegistro       === '' ? null : $fechaRegistro,
        'fecha_ultima_renovacion'     => $fechaUltimaRenov    === '' ? null : $fechaUltimaRenov,
        'fecha_siguiente_renovacion'  => $fechaSiguienteRenov === '' ? null : $fechaSiguienteRenov,
        'costo_renovacion'            => $costo,
        'moneda'                      => $moneda,
    ];
}

// ----------------------------------------------------------------------------
// Handlers
// ----------------------------------------------------------------------------

function handleListDominios(PDO $pdo, array $q): void {
    $search      = trim((string)($q['q'] ?? ''));
    $responsable = trim((string)($q['responsable'] ?? ''));
    $limite      = max(1, min(1000, (int)($q['limite'] ?? 100)));
    $orden       = in_array(($q['orden'] ?? ''), DRDO_ORDENES, true) ? $q['orden'] : 'id';
    $dir         = strtolower((string)($q['dir'] ?? 'desc')) === 'asc' ? 'ASC' : 'DESC';

    $where  = [];
    $params = [];

    if ($search !== '') {
        $where[] = '(dominio LIKE :s_dom OR titular_dominio LIKE :s_tit OR entidad_registrante LIKE :s_ent)';
        $params[':s_dom'] = "%{$search}%";
        $params[':s_tit'] = "%{$search}%";
        $params[':s_ent'] = "%{$search}%";
    }
    if ($responsable !== '' && in_array($responsable, DRDO_RESPONSABLES, true)) {
        $where[] = 'responsable = :responsable';
        $params[':responsable'] = $responsable;
    }

    $sqlWhere = $where ? ('WHERE ' . implode(' AND ', $where)) : '';
    $sql = 'SELECT ' . DRDO_COLS . " FROM datarocket_dominios {$sqlWhere} ORDER BY {$orden} {$dir} LIMIT {$limite}";
    $st = $pdo->prepare($sql);
    $st->execute($params);
    $rows = array_map('normalizarFilaDominio', $st->fetchAll());

    $stats = [
        'total'      => (int)$pdo->query('SELECT COUNT(*) FROM datarocket_dominios')->fetchColumn(),
        'databox'    => (int)$pdo->query("SELECT COUNT(*) FROM datarocket_dominios WHERE responsable = 'Databox'")->fetchColumn(),
        'cliente'    => (int)$pdo->query("SELECT COUNT(*) FROM datarocket_dominios WHERE responsable = 'Cliente'")->fetchColumn(),
        'por_vencer' => (int)$pdo->query('SELECT COUNT(*) FROM datarocket_dominios '
                        . 'WHERE fecha_siguiente_renovacion IS NOT NULL '
                        . 'AND fecha_siguiente_renovacion <= DATE_ADD(CURDATE(), INTERVAL 30 DAY) '
                        . 'AND fecha_siguiente_renovacion >= CURDATE()')->fetchColumn(),
        'vencidos'   => (int)$pdo->query('SELECT COUNT(*) FROM datarocket_dominios '
                        . 'WHERE fecha_siguiente_renovacion IS NOT NULL '
                        . 'AND fecha_siguiente_renovacion < CURDATE()')->fetchColumn(),
    ];

    jsonOk(['items' => $rows, 'stats' => $stats]);
}

function handleGetOneDominio(PDO $pdo, int $id): void {
    $st = $pdo->prepare('SELECT ' . DRDO_COLS . ' FROM datarocket_dominios WHERE id = :id LIMIT 1');
    $st->execute([':id' => $id]);
    $row = $st->fetch();
    if (!$row) jsonError('Dominio no encontrado', 404);
    jsonOk(normalizarFilaDominio($row));
}

function handleCreateDominio(PDO $pdo, array $body): void {
    $p = sanitizePayloadDominio($body, true);

    $st = $pdo->prepare('SELECT id FROM datarocket_dominios WHERE dominio = :d LIMIT 1');
    $st->execute([':d' => $p['dominio']]);
    if ($st->fetch()) jsonError('Ya existe un dominio con ese nombre.', 409);

    $st = $pdo->prepare(
        'INSERT INTO datarocket_dominios
            (dominio, titular_dominio, entidad_registrante, responsable,
             fecha_registro, fecha_ultima_renovacion, fecha_siguiente_renovacion,
             costo_renovacion, moneda)
         VALUES
            (:dominio, :titular, :entidad, :responsable,
             :fecha_registro, :fecha_ultima_renovacion, :fecha_siguiente_renovacion,
             :costo, :moneda)'
    );
    $st->execute([
        ':dominio'                    => $p['dominio'],
        ':titular'                    => $p['titular_dominio'],
        ':entidad'                    => $p['entidad_registrante'],
        ':responsable'                => $p['responsable'],
        ':fecha_registro'             => $p['fecha_registro'],
        ':fecha_ultima_renovacion'    => $p['fecha_ultima_renovacion'],
        ':fecha_siguiente_renovacion' => $p['fecha_siguiente_renovacion'],
        ':costo'                      => $p['costo_renovacion'],
        ':moneda'                     => $p['moneda'],
    ]);

    $id = (int)$pdo->lastInsertId();
    registrarSuceso($pdo, 'datarocketdominios', 'info',
        "Alta dominio #{$id} — \"{$p['dominio']}\"");

    handleGetOneDominio($pdo, $id);
}

function handleUpdateDominio(PDO $pdo, int $id, array $body): void {
    $st = $pdo->prepare('SELECT ' . DRDO_COLS . ' FROM datarocket_dominios WHERE id = :id LIMIT 1');
    $st->execute([':id' => $id]);
    $prev = $st->fetch();
    if (!$prev) jsonError('Dominio no encontrado', 404);

    $p = sanitizePayloadDominio($body, false);

    if (array_key_exists('dominio', $body) && $p['dominio'] !== '' && $p['dominio'] !== $prev['dominio']) {
        $st = $pdo->prepare('SELECT id FROM datarocket_dominios WHERE dominio = :d AND id <> :id LIMIT 1');
        $st->execute([':d' => $p['dominio'], ':id' => $id]);
        if ($st->fetch()) jsonError('Ya existe otro dominio con ese nombre.', 409);
    }

    $sets   = [];
    $params = [':id' => $id];

    if (array_key_exists('dominio', $body) && $p['dominio'] !== '') {
        $sets[] = 'dominio = :dominio';
        $params[':dominio'] = $p['dominio'];
    }
    if (array_key_exists('titular_dominio', $body)) {
        $sets[] = 'titular_dominio = :titular';
        $params[':titular'] = $p['titular_dominio'];
    }
    if (array_key_exists('entidad_registrante', $body)) {
        $sets[] = 'entidad_registrante = :entidad';
        $params[':entidad'] = $p['entidad_registrante'];
    }
    if (array_key_exists('responsable', $body) && $p['responsable'] !== '') {
        $sets[] = 'responsable = :responsable';
        $params[':responsable'] = $p['responsable'];
    }
    if (array_key_exists('fecha_registro', $body)) {
        $sets[] = 'fecha_registro = :fecha_registro';
        $params[':fecha_registro'] = $p['fecha_registro'];
    }
    if (array_key_exists('fecha_ultima_renovacion', $body)) {
        $sets[] = 'fecha_ultima_renovacion = :fecha_ultima_renovacion';
        $params[':fecha_ultima_renovacion'] = $p['fecha_ultima_renovacion'];
    }
    if (array_key_exists('fecha_siguiente_renovacion', $body)) {
        $sets[] = 'fecha_siguiente_renovacion = :fecha_siguiente_renovacion';
        $params[':fecha_siguiente_renovacion'] = $p['fecha_siguiente_renovacion'];
    }
    if (array_key_exists('costo_renovacion', $body)) {
        $sets[] = 'costo_renovacion = :costo';
        $params[':costo'] = $p['costo_renovacion'];
    }
    if (array_key_exists('moneda', $body) && $p['moneda'] !== '') {
        $sets[] = 'moneda = :moneda';
        $params[':moneda'] = $p['moneda'];
    }

    if (empty($sets)) jsonError('No hay campos para actualizar.', 400);

    $sql = 'UPDATE datarocket_dominios SET ' . implode(', ', $sets) . ' WHERE id = :id';
    $st  = $pdo->prepare($sql);
    $st->execute($params);

    registrarSuceso($pdo, 'datarocketdominios', 'info',
        "Modificacion dominio #{$id} — \"{$prev['dominio']}\"");

    handleGetOneDominio($pdo, $id);
}

function handleDeleteDominio(PDO $pdo, int $id): void {
    $st = $pdo->prepare('SELECT dominio FROM datarocket_dominios WHERE id = :id LIMIT 1');
    $st->execute([':id' => $id]);
    $prev = $st->fetch();
    if (!$prev) jsonError('Dominio no encontrado', 404);

    $sd = $pdo->prepare('DELETE FROM datarocket_dominios WHERE id = :id');
    $sd->execute([':id' => $id]);

    registrarSuceso($pdo, 'datarocketdominios', 'info',
        "Baja dominio #{$id} — \"{$prev['dominio']}\"");

    jsonOk(['id' => $id]);
}
