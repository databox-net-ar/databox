<?php
// api/datacount_chequeras.php
// Chequeras Datacount (CRUD). Lee/escribe sobre la tabla
// `datacount_bancos_chequeras` definida en db/schema.sql — cada fila es un
// talonario de cheques emitido contra una cuenta corriente del módulo Bancos.
//
// La chequera guarda DOS cosas propias: contra que cuenta se emitio
// (`cuenta_id`) y si trae cheques comunes (a la vista) o diferidos (con fecha
// de pago futura). Todo lo demas -- nombre, banco, numero de cuenta, empresa --
// sale del JOIN a `datacount_bancos_cuentas` y por eso no se duplica acá:
// renombrar una cuenta actualiza sus chequeras sin backfill, y no hay forma de
// que una chequera quede con el banco de una cuenta y el numero de otra.
//
//   GET    api/datacount_chequeras.php[?q=...&empresa=...&cuenta=...&tipo=...&activa=...&limite=100&orden=id&dir=desc]
//                                       -> listado + stats
//   GET    api/datacount_chequeras.php?id=N
//                                       -> registro individual
//   GET    api/datacount_chequeras.php?lookups=1
//                                       -> catalogos (cuentas, tipos)
//   POST   api/datacount_chequeras.php  -> alta (JSON body)
//   PUT    api/datacount_chequeras.php?id=N
//                                       -> modificacion (JSON body)
//   DELETE api/datacount_chequeras.php?id=N
//                                       -> baja
//
// Respuesta siempre {ok: true, data: ...} u {ok: false, error: '...'} (STACK.md sec. 10).

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/lib/auth_check.php';
require_once __DIR__ . '/lib/sucesos.php';

requireAuth();
header('Content-Type: application/json; charset=utf-8');

// `nombre` y `empresa_id` no son columnas de la chequera: ordenar por ellos es
// ordenar por la cuenta, y el ORDER BY se arma con el alias correspondiente.
const DCCH_ORDENES = ['id', 'nombre', 'numero_cuenta', 'tipo', 'activa', 'created_at'];
const DCCH_TIPOS   = ['comun', 'diferido'];

// El banco sale de la legacy `datacountbancos` (sin guion bajo), el catalogo de
// instituciones del grupo — el mismo al que apunta
// `datacount_bancos_cuentas.banco_id`. No existe ninguna `datacount_bancos`.
const DCCH_SELECT = "
    SELECT ch.id, ch.cuenta_id, ch.tipo, ch.observaciones, ch.activa,
           ch.created_at, ch.updated_at,
           cu.nombre     AS nombre,
           cu.numero     AS numero_cuenta,
           cu.moneda     AS moneda,
           cu.empresa_id AS empresa_id,
           cu.banco_id   AS banco_id,
           NULLIF(TRIM(COALESCE(b.nombre, '')), '') AS banco_nombre,
           NULLIF(TRIM(COALESCE(e.nombre, '')), '') AS empresa_nombre
      FROM datacount_bancos_chequeras ch
      INNER JOIN datacount_bancos_cuentas cu ON cu.id = ch.cuenta_id
      LEFT  JOIN datacountbancos          b  ON b.id  = cu.banco_id
      LEFT  JOIN datacount_empresas       e  ON e.id  = cu.empresa_id
";

try {
    requirePermCrud('datacount.bancos.chequeras');
    $pdo    = db();
    $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
    $id     = isset($_GET['id']) ? (int)$_GET['id'] : 0;

    if ($method === 'GET' && ($_GET['lookups'] ?? '') !== '') {
        handleLookupsChequera($pdo);
    } elseif ($method === 'GET' && $id > 0) {
        handleGetOneChequera($pdo, $id);
    } elseif ($method === 'GET') {
        handleListChequeras($pdo, $_GET);
    } elseif ($method === 'POST') {
        handleCreateChequera($pdo, readJsonBody());
    } elseif ($method === 'PUT') {
        if ($id <= 0) jsonError('Falta id', 400);
        handleUpdateChequera($pdo, $id, readJsonBody());
    } elseif ($method === 'DELETE') {
        if ($id <= 0) jsonError('Falta id', 400);
        handleDeleteChequera($pdo, $id);
    } else {
        jsonError('Método no soportado', 405);
    }
} catch (Throwable $e) {
    jsonError($e->getMessage(), 500);
}

// ----------------------------------------------------------------------------
// Helpers
// ----------------------------------------------------------------------------

function normalizarFilaChequera(array $r): array {
    return [
        'id'             => (int)($r['id'] ?? 0),
        'cuenta_id'      => (int)($r['cuenta_id'] ?? 0),
        // Derivados de la cuenta — solo lectura, el ABM no los manda de vuelta.
        'nombre'         => (string)($r['nombre'] ?? ''),
        'numero_cuenta'  => $r['numero_cuenta']  !== null ? (string)$r['numero_cuenta']  : null,
        'moneda'         => $r['moneda']         !== null ? (string)$r['moneda']         : null,
        'empresa_id'     => $r['empresa_id']     !== null ? (int)$r['empresa_id']        : null,
        'empresa_nombre' => $r['empresa_nombre'] !== null ? (string)$r['empresa_nombre'] : null,
        'banco_id'       => $r['banco_id']       !== null ? (int)$r['banco_id']          : null,
        'banco_nombre'   => $r['banco_nombre']   !== null ? (string)$r['banco_nombre']   : null,
        // Propios de la chequera.
        'tipo'           => (string)($r['tipo'] ?? 'comun'),
        'observaciones'  => $r['observaciones']  !== null ? (string)$r['observaciones']  : null,
        'activa'         => (int)($r['activa'] ?? 1),
        'created_at'     => $r['created_at']     !== null ? (string)$r['created_at']     : null,
        'updated_at'     => $r['updated_at']     !== null ? (string)$r['updated_at']     : null,
    ];
}

function sanitizePayloadChequera(array $in, bool $esAlta): array {
    $tipo   = trim((string)($in['tipo']          ?? ''));
    $observ = trim((string)($in['observaciones'] ?? ''));

    $cuentaId = null;
    if (array_key_exists('cuenta_id', $in)) {
        $cuentaId = (int)$in['cuenta_id'];
        if ($cuentaId <= 0) $cuentaId = null;
    }

    if ($esAlta) {
        if ($cuentaId === null) jsonError('La cuenta es obligatoria.', 400);
        if ($tipo === '')       $tipo = 'comun';
    }

    if ($tipo !== '' && !in_array($tipo, DCCH_TIPOS, true)) {
        jsonError('El tipo solo admite "comun" o "diferido".', 400);
    }

    // `activa` es tinyint(1). En el alta el default de la tabla es 1; en el PUT
    // solo se toca si el body la trae (null = "no la mandes en el UPDATE").
    $activa = array_key_exists('activa', $in) ? (int)(bool)$in['activa'] : ($esAlta ? 1 : null);

    return [
        'cuenta_id'     => $cuentaId,
        'tipo'          => $tipo   === '' ? null : $tipo,
        'observaciones' => $observ === '' ? null : $observ,
        'activa'        => $activa,
    ];
}

// El FK ya rechaza una cuenta inexistente, pero con un error de MySQL crudo.
// Validarla acá devuelve un 400 que el ABM puede mostrar tal cual.
function asegurarCuentaChequera(PDO $pdo, int $cuentaId): void {
    $st = $pdo->prepare('SELECT id FROM datacount_bancos_cuentas WHERE id = :id LIMIT 1');
    $st->execute([':id' => $cuentaId]);
    if (!$st->fetch()) jsonError('La cuenta seleccionada no existe.', 400);
}

// ----------------------------------------------------------------------------
// Handlers
// ----------------------------------------------------------------------------

function handleListChequeras(PDO $pdo, array $q): void {
    $search  = trim((string)($q['q']       ?? ''));
    $empresa = trim((string)($q['empresa'] ?? ''));
    $cuenta  = trim((string)($q['cuenta']  ?? ''));
    $tipo    = trim((string)($q['tipo']    ?? ''));
    $activa  = trim((string)($q['activa']  ?? ''));
    $limite  = max(1, min(1000, (int)($q['limite'] ?? 100)));
    $orden   = in_array(($q['orden'] ?? ''), DCCH_ORDENES, true) ? $q['orden'] : 'id';
    $dir     = strtolower((string)($q['dir'] ?? 'desc')) === 'asc' ? 'ASC' : 'DESC';

    // `nombre` y `numero_cuenta` viven en la cuenta, el resto en la chequera.
    $ordenSql = in_array($orden, ['nombre', 'numero_cuenta'], true)
        ? ('cu.' . ($orden === 'nombre' ? 'nombre' : 'numero'))
        : ('ch.' . $orden);

    $where  = [];
    $params = [];

    // `utf8mb4_general_ci` ya pliega mayusculas y acentos: el LIKE crudo alcanza
    // para que "Galicia" matchee "galícia".
    if ($search !== '') {
        $where[] = '(cu.nombre LIKE :s_nom OR cu.numero LIKE :s_num OR b.nombre LIKE :s_ban)';
        $params[':s_nom'] = "%{$search}%";
        $params[':s_num'] = "%{$search}%";
        $params[':s_ban'] = "%{$search}%";
    }
    if ($empresa !== '' && ctype_digit($empresa)) {
        $where[] = 'cu.empresa_id = :empresa';
        $params[':empresa'] = (int)$empresa;
    }
    if ($cuenta !== '' && ctype_digit($cuenta)) {
        $where[] = 'ch.cuenta_id = :cuenta';
        $params[':cuenta'] = (int)$cuenta;
    }
    if ($tipo !== '' && in_array($tipo, DCCH_TIPOS, true)) {
        $where[] = 'ch.tipo = :tipo';
        $params[':tipo'] = $tipo;
    }
    if ($activa !== '' && ctype_digit($activa)) {
        $where[] = 'ch.activa = :activa';
        $params[':activa'] = (int)$activa;
    }

    $sqlWhere = $where ? ('WHERE ' . implode(' AND ', $where)) : '';
    $st = $pdo->prepare(DCCH_SELECT . " {$sqlWhere} ORDER BY {$ordenSql} {$dir} LIMIT {$limite}");
    $st->execute($params);
    $rows = array_map('normalizarFilaChequera', $st->fetchAll());

    // Stats acotadas a la empresa activa: el listado tambien lo esta, y un total
    // global al lado de un listado filtrado se lee como si faltaran filas.
    $sw = '';
    $sp = [];
    if ($empresa !== '' && ctype_digit($empresa)) {
        $sw = 'WHERE cu.empresa_id = :empresa';
        $sp[':empresa'] = (int)$empresa;
    }
    $base = 'SELECT COUNT(*) FROM datacount_bancos_chequeras ch
             INNER JOIN datacount_bancos_cuentas cu ON cu.id = ch.cuenta_id';
    $stats = [];
    foreach ([
        'total'     => '',
        'comunes'   => "ch.tipo = 'comun'",
        'diferidas' => "ch.tipo = 'diferido'",
        'activas'   => 'ch.activa = 1',
    ] as $clave => $cond) {
        $sql = "{$base} {$sw}";
        if ($cond !== '') $sql .= ($sw === '' ? ' WHERE ' : ' AND ') . $cond;
        $s = $pdo->prepare($sql);
        $s->execute($sp);
        $stats[$clave] = (int)$s->fetchColumn();
    }

    jsonOk(['items' => $rows, 'stats' => $stats]);
}

function handleGetOneChequera(PDO $pdo, int $id): void {
    $st = $pdo->prepare(DCCH_SELECT . ' WHERE ch.id = :id LIMIT 1');
    $st->execute([':id' => $id]);
    $row = $st->fetch();
    if (!$row) jsonError('Chequera no encontrada', 404);
    jsonOk(normalizarFilaChequera($row));
}

// Catalogos para poblar los <select> del formulario y del modal de filtros:
// - cuentas: `datacount_bancos_cuentas` con su banco y empresa ya resueltos,
//   para que el ABM arme la etiqueta del combo y filtre por la empresa activa
//   sin invalidar el cache al cambiar de empresa.
// - tipos: catalogo `estados` con campo=`datacount_bancos_chequera_tipo`.
function handleLookupsChequera(PDO $pdo): void {
    $cuentas = $pdo->query(
        "SELECT cu.id,
                COALESCE(NULLIF(TRIM(cu.nombre), ''), CONCAT('Cuenta #', cu.id)) AS nombre,
                cu.numero, cu.moneda, cu.tipo, cu.activa, cu.empresa_id, cu.banco_id,
                NULLIF(TRIM(COALESCE(b.nombre, '')), '') AS banco_nombre
           FROM datacount_bancos_cuentas cu
           LEFT JOIN datacountbancos b ON b.id = cu.banco_id
          ORDER BY cu.nombre ASC, cu.id ASC"
    )->fetchAll();

    $tipos = $pdo->query(
        "SELECT valor, texto FROM estados
          WHERE campo = 'datacount_bancos_chequera_tipo'
          ORDER BY orden ASC, id ASC"
    )->fetchAll();

    jsonOk([
        'cuentas' => array_map(fn($r) => [
            'id'           => (int)$r['id'],
            'nombre'       => (string)$r['nombre'],
            'numero'       => $r['numero']       !== null ? (string)$r['numero']       : null,
            'moneda'       => $r['moneda']       !== null ? (string)$r['moneda']       : null,
            'tipo'         => $r['tipo']         !== null ? (string)$r['tipo']         : null,
            'activa'       => (int)$r['activa'],
            'empresa_id'   => $r['empresa_id']   !== null ? (int)$r['empresa_id']      : null,
            'banco_id'     => $r['banco_id']     !== null ? (int)$r['banco_id']        : null,
            'banco_nombre' => $r['banco_nombre'] !== null ? (string)$r['banco_nombre'] : null,
        ], $cuentas),
        'tipos' => array_map(fn($r) => [
            'valor' => (string)($r['valor'] ?? ''),
            'texto' => (string)($r['texto'] ?? ''),
        ], $tipos),
    ]);
}

function handleCreateChequera(PDO $pdo, array $body): void {
    $p = sanitizePayloadChequera($body, true);
    asegurarCuentaChequera($pdo, $p['cuenta_id']);

    $st = $pdo->prepare(
        'INSERT INTO datacount_bancos_chequeras
            (cuenta_id, tipo, observaciones, activa)
         VALUES
            (:cuenta_id, :tipo, :observaciones, :activa)'
    );
    $st->execute([
        ':cuenta_id'     => $p['cuenta_id'],
        ':tipo'          => $p['tipo'] ?? 'comun',
        ':observaciones' => $p['observaciones'],
        ':activa'        => $p['activa'],
    ]);

    $id = (int)$pdo->lastInsertId();
    registrarSuceso($pdo, 'datacount_bancos_chequeras', 'info',
        "Alta chequera #{$id} — cuenta #{$p['cuenta_id']} ({$p['tipo']})");

    handleGetOneChequera($pdo, $id);
}

function handleUpdateChequera(PDO $pdo, int $id, array $body): void {
    $st = $pdo->prepare('SELECT id, cuenta_id FROM datacount_bancos_chequeras WHERE id = :id LIMIT 1');
    $st->execute([':id' => $id]);
    $prev = $st->fetch();
    if (!$prev) jsonError('Chequera no encontrada', 404);

    $p = sanitizePayloadChequera($body, false);

    $sets   = [];
    $params = [':id' => $id];

    // `cuenta_id` y `tipo` son NOT NULL: un '' del formulario no puede vaciarlos.
    if (array_key_exists('cuenta_id', $body) && $p['cuenta_id'] !== null) {
        asegurarCuentaChequera($pdo, $p['cuenta_id']);
        $sets[] = 'cuenta_id = :cuenta_id';
        $params[':cuenta_id'] = $p['cuenta_id'];
    }
    if (array_key_exists('tipo', $body) && $p['tipo'] !== null) {
        $sets[] = 'tipo = :tipo';
        $params[':tipo'] = $p['tipo'];
    }
    if (array_key_exists('observaciones', $body)) {
        $sets[] = 'observaciones = :observaciones';
        $params[':observaciones'] = $p['observaciones'];
    }
    if (array_key_exists('activa', $body)) {
        $sets[] = 'activa = :activa';
        $params[':activa'] = $p['activa'];
    }

    if (empty($sets)) jsonError('No hay campos para actualizar.', 400);

    $sql = 'UPDATE datacount_bancos_chequeras SET ' . implode(', ', $sets) . ' WHERE id = :id';
    $st  = $pdo->prepare($sql);
    $st->execute($params);

    registrarSuceso($pdo, 'datacount_bancos_chequeras', 'info',
        "Modificación chequera #{$id} — cuenta #{$prev['cuenta_id']}");

    handleGetOneChequera($pdo, $id);
}

function handleDeleteChequera(PDO $pdo, int $id): void {
    $st = $pdo->prepare('SELECT cuenta_id FROM datacount_bancos_chequeras WHERE id = :id LIMIT 1');
    $st->execute([':id' => $id]);
    $prev = $st->fetch();
    if (!$prev) jsonError('Chequera no encontrada', 404);

    $sd = $pdo->prepare('DELETE FROM datacount_bancos_chequeras WHERE id = :id');
    $sd->execute([':id' => $id]);

    registrarSuceso($pdo, 'datacount_bancos_chequeras', 'info',
        "Baja chequera #{$id} — cuenta #{$prev['cuenta_id']}");

    jsonOk(['id' => $id]);
}
