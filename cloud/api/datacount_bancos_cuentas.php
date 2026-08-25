<?php
// api/datacount_bancos_cuentas.php
// Datacount > Bancos > Cuentas (CRUD). Lee/escribe sobre la tabla
// `datacount_bancos_cuentas` definida en db/schema.sql — cada fila es una
// cuenta de fondos de una empresa: una cuenta bancaria, una billetera virtual,
// una caja en efectivo, una tarjeta o una wallet cripto.
//
// Bancos y billeteras comparten tabla porque son la misma entidad contable
// (disponibilidades). Lo que las diferencia es `tipo`, que ademas decide que
// medios de pago admite cada una en el extracto — ver DCBM_MEDIOS_POR_TIPO en
// datacount_bancos_movimientos.php. La justificacion larga esta en la
// migracion 20260824_1100_datacount_bancos_modulo.sql.
//
//   GET    api/datacount_bancos_cuentas.php[?q=..&empresa=..&tipo=..&banco=..&activa=..&limite=100&orden=id&dir=desc]
//                                            -> listado + stats
//   GET    api/datacount_bancos_cuentas.php?id=N        -> registro individual
//   GET    api/datacount_bancos_cuentas.php?lookups=1   -> catalogos
//   POST   api/datacount_bancos_cuentas.php             -> alta (JSON body)
//   PUT    api/datacount_bancos_cuentas.php?id=N        -> modificacion
//   DELETE api/datacount_bancos_cuentas.php?id=N        -> baja
//
// Respuesta siempre {ok: true, data: ...} u {ok: false, error: '...'} (STACK.md sec. 10).

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/lib/auth_check.php';
require_once __DIR__ . '/lib/sucesos.php';

requireAuth();
header('Content-Type: application/json; charset=utf-8');

const DCBC_ORDENES = ['id', 'nombre', 'tipo', 'saldo', 'activa', 'created_at'];
const DCBC_TIPOS   = ['banco', 'billetera', 'efectivo', 'tarjeta', 'cripto'];
const DCBC_COLS    = 'id, empresa_id, proyecto_id, banco_id, tipo, nombre, moneda, cbu, alias,
                      numero, titular, cuit, correo, celular, contrasena, cuenta_contable_id,
                      saldo, saldo_fecha, import_config, observaciones, activa,
                      created_at, updated_at';

try {
    requirePermCrud('datacount.bancos.cuentas');
    $pdo    = db();
    $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
    $id     = isset($_GET['id']) ? (int) $_GET['id'] : 0;

    if ($method === 'GET' && ($_GET['lookups'] ?? '') !== '') {
        handleLookupsCuentaBanco($pdo);
    } elseif ($method === 'GET' && $id > 0) {
        handleGetOneCuentaBanco($pdo, $id);
    } elseif ($method === 'GET') {
        handleListCuentasBanco($pdo, $_GET);
    } elseif ($method === 'POST') {
        handleCreateCuentaBanco($pdo, readJsonBody());
    } elseif ($method === 'PUT') {
        if ($id <= 0) jsonError('Falta id', 400);
        handleUpdateCuentaBanco($pdo, $id, readJsonBody());
    } elseif ($method === 'DELETE') {
        if ($id <= 0) jsonError('Falta id', 400);
        handleDeleteCuentaBanco($pdo, $id);
    } else {
        jsonError('Método no soportado', 405);
    }
} catch (Throwable $e) {
    jsonError($e->getMessage(), 500);
}

// ----------------------------------------------------------------------------
// Helpers
// ----------------------------------------------------------------------------

// `contrasena` va cifrada con encriptar()/desencriptar() (db.php) por
// compatibilidad con el resto del grupo — mismo criterio que
// `usuarios.contrasena`. Se devuelve en claro porque el ABM la muestra con un
// boton "ver": es una credencial de homebanking que el operador necesita, no
// un hash de autenticacion del panel.
function normalizarFilaCuentaBanco(array $r): array {
    $pass = (string) ($r['contrasena'] ?? '');
    return [
        'id'                 => (int) ($r['id'] ?? 0),
        'empresa_id'         => $r['empresa_id']         !== null ? (int) $r['empresa_id']         : null,
        'proyecto_id'        => $r['proyecto_id']        !== null ? (int) $r['proyecto_id']        : null,
        'banco_id'           => $r['banco_id']           !== null ? (int) $r['banco_id']           : null,
        'tipo'               => (string) ($r['tipo'] ?? 'banco'),
        'nombre'             => (string) ($r['nombre'] ?? ''),
        'moneda'             => (string) ($r['moneda'] ?? 'P'),
        'cbu'                => $r['cbu']                !== null ? (string) $r['cbu']            : null,
        'alias'              => $r['alias']              !== null ? (string) $r['alias']          : null,
        'numero'             => $r['numero']             !== null ? (string) $r['numero']         : null,
        'titular'            => $r['titular']            !== null ? (string) $r['titular']        : null,
        'cuit'               => $r['cuit']               !== null ? (string) $r['cuit']           : null,
        'correo'             => $r['correo']             !== null ? (string) $r['correo']         : null,
        'celular'            => $r['celular']            !== null ? (string) $r['celular']        : null,
        'contrasena'         => $pass !== '' ? desencriptar($pass) : null,
        'cuenta_contable_id' => $r['cuenta_contable_id'] !== null ? (int) $r['cuenta_contable_id'] : null,
        'saldo'              => (float) ($r['saldo'] ?? 0),
        'saldo_fecha'        => $r['saldo_fecha']        !== null ? (string) $r['saldo_fecha']    : null,
        'import_config'      => dcbcDecodeImportConfig($r['import_config'] ?? null),
        'observaciones'      => $r['observaciones']      !== null ? (string) $r['observaciones']  : null,
        'activa'             => (int) ($r['activa'] ?? 1),
        'created_at'         => $r['created_at'] ?? null,
        'updated_at'         => $r['updated_at'] ?? null,
    ];
}

// `import_config` se guarda como TEXT con JSON adentro. Si quedo corrupto
// (edicion manual desde el Explorador DB, por ejemplo) devolvemos null en vez
// de romper el listado entero: la cuenta sigue siendo usable, solo pierde el
// mapeo y el importador vuelve a pedirlo.
function dcbcDecodeImportConfig(?string $raw): ?array {
    if ($raw === null || trim($raw) === '') return null;
    $j = json_decode($raw, true);
    return is_array($j) ? $j : null;
}

function dcbcIntOpt(mixed $v): ?int {
    if ($v === '' || $v === null || $v === false) return null;
    $n = (int) $v;
    return $n === 0 ? null : $n;
}

function dcbcTexto(mixed $v, int $max, string $etiqueta): ?string {
    $s = trim((string) ($v ?? ''));
    if ($s === '') return null;
    if (mb_strlen($s) > $max) jsonError("{$etiqueta} no puede superar los {$max} caracteres.", 400);
    return $s;
}

function sanitizePayloadCuentaBanco(array $in, bool $esAlta): array {
    $nombre = trim((string) ($in['nombre'] ?? ''));
    if ($esAlta && $nombre === '') jsonError('El nombre es obligatorio.', 400);
    if ($nombre !== '' && mb_strlen($nombre) > 255) {
        jsonError('El nombre no puede superar los 255 caracteres.', 400);
    }

    $tipo = trim((string) ($in['tipo'] ?? ''));
    if ($tipo !== '' && !in_array($tipo, DCBC_TIPOS, true)) {
        jsonError('Tipo de cuenta invalido.', 400);
    }
    if ($esAlta && $tipo === '') $tipo = 'banco';

    $moneda = strtoupper(trim((string) ($in['moneda'] ?? '')));
    if ($moneda !== '' && mb_strlen($moneda) > 1) jsonError('Moneda invalida.', 400);
    if ($esAlta && $moneda === '') $moneda = 'P';

    // CBU/CVU: 22 digitos. Se aceptan con espacios o guiones (se copia y pega
    // del homebanking) y se guardan normalizados a digitos.
    $cbu = preg_replace('/[^0-9]/', '', (string) ($in['cbu'] ?? ''));
    if ($cbu === '') {
        $cbu = null;
    } elseif (strlen($cbu) !== 22) {
        jsonError('El CBU/CVU debe tener 22 digitos.', 400);
    }

    $correo = dcbcTexto($in['correo'] ?? null, 100, 'El correo');
    if ($correo !== null && !filter_var($correo, FILTER_VALIDATE_EMAIL)) {
        jsonError('El correo no es valido.', 400);
    }

    // CUIT: se acepta con o sin guiones y se guarda solo con digitos.
    $cuit = preg_replace('/[^0-9]/', '', (string) ($in['cuit'] ?? ''));
    if ($cuit === '') {
        $cuit = null;
    } elseif (strlen($cuit) !== 11) {
        jsonError('El CUIT debe tener 11 digitos.', 400);
    }

    $saldo = null;
    if (array_key_exists('saldo', $in) && $in['saldo'] !== '' && $in['saldo'] !== null) {
        $saldo = round((float) $in['saldo'], 2);
    }

    $saldoFecha = trim((string) ($in['saldo_fecha'] ?? ''));
    if ($saldoFecha !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $saldoFecha)) {
        jsonError('La fecha de saldo debe tener formato YYYY-MM-DD.', 400);
    }

    // La contraseña llega en claro desde el ABM y se guarda cifrada.
    $passRaw = (string) ($in['contrasena'] ?? '');
    $pass    = trim($passRaw) === '' ? null : encriptar($passRaw);

    $importConfig = null;
    if (array_key_exists('import_config', $in) && $in['import_config'] !== null) {
        if (is_array($in['import_config'])) {
            $importConfig = json_encode($in['import_config'], JSON_UNESCAPED_UNICODE);
        } elseif (trim((string) $in['import_config']) !== '') {
            $importConfig = (string) $in['import_config'];
        }
    }

    return [
        'empresa_id'         => dcbcIntOpt($in['empresa_id']  ?? null),
        'proyecto_id'        => dcbcIntOpt($in['proyecto_id'] ?? null),
        'banco_id'           => dcbcIntOpt($in['banco_id']    ?? null),
        'tipo'               => $tipo   === '' ? null : $tipo,
        'nombre'             => $nombre === '' ? null : $nombre,
        'moneda'             => $moneda === '' ? null : $moneda,
        'cbu'                => $cbu,
        'alias'              => dcbcTexto($in['alias']         ?? null, 50,  'El alias'),
        'numero'             => dcbcTexto($in['numero']        ?? null, 50,  'El numero de cuenta'),
        'titular'            => dcbcTexto($in['titular']       ?? null, 255, 'El titular'),
        'cuit'               => $cuit,
        'correo'             => $correo,
        'celular'            => dcbcTexto($in['celular']       ?? null, 100, 'El celular'),
        'contrasena'         => $pass,
        'cuenta_contable_id' => dcbcIntOpt($in['cuenta_contable_id'] ?? null),
        'saldo'              => $saldo,
        'saldo_fecha'        => $saldoFecha === '' ? null : $saldoFecha,
        'import_config'      => $importConfig,
        'observaciones'      => dcbcTexto($in['observaciones'] ?? null, 5000, 'Las observaciones'),
        'activa'             => array_key_exists('activa', $in) ? (int) (bool) $in['activa'] : null,
    ];
}

// ----------------------------------------------------------------------------
// Handlers
// ----------------------------------------------------------------------------

function handleListCuentasBanco(PDO $pdo, array $q): void {
    $search  = trim((string) ($q['q']       ?? ''));
    $empresa = trim((string) ($q['empresa'] ?? ''));
    $tipo    = trim((string) ($q['tipo']    ?? ''));
    $banco   = trim((string) ($q['banco']   ?? ''));
    $activa  = trim((string) ($q['activa']  ?? ''));
    $limite  = max(1, min(1000, (int) ($q['limite'] ?? 100)));
    $orden   = in_array(($q['orden'] ?? ''), DCBC_ORDENES, true) ? $q['orden'] : 'id';
    $dir     = strtolower((string) ($q['dir'] ?? 'desc')) === 'asc' ? 'ASC' : 'DESC';

    $where  = [];
    $params = [];

    // El LIKE va sobre columnas utf8mb4_general_ci, que pliega mayusculas y
    // acentos: "galicia" matchea "Galicia" y "Comisión" matchea "comision".
    if ($search !== '') {
        $where[] = '(nombre LIKE :s1 OR alias LIKE :s2 OR cbu LIKE :s3 OR titular LIKE :s4 OR numero LIKE :s5)';
        $params[':s1'] = "%{$search}%";
        $params[':s2'] = "%{$search}%";
        $params[':s3'] = "%{$search}%";
        $params[':s4'] = "%{$search}%";
        $params[':s5'] = "%{$search}%";
    }
    if ($empresa !== '' && ctype_digit($empresa)) {
        $where[] = 'empresa_id = :empresa';
        $params[':empresa'] = (int) $empresa;
    }
    if ($tipo !== '' && in_array($tipo, DCBC_TIPOS, true)) {
        $where[] = 'tipo = :tipo';
        $params[':tipo'] = $tipo;
    }
    if ($banco !== '' && ctype_digit($banco)) {
        $where[] = 'banco_id = :banco';
        $params[':banco'] = (int) $banco;
    }
    if ($activa !== '' && ctype_digit($activa)) {
        $where[] = 'activa = :activa';
        $params[':activa'] = (int) $activa;
    }

    $sqlWhere = $where ? ('WHERE ' . implode(' AND ', $where)) : '';
    $st = $pdo->prepare(
        'SELECT ' . DCBC_COLS . " FROM datacount_bancos_cuentas {$sqlWhere} ORDER BY {$orden} {$dir} LIMIT {$limite}"
    );
    $st->execute($params);
    $rows = array_map('normalizarFilaCuentaBanco', $st->fetchAll());

    // Las stats se calculan sobre el mismo recorte de empresa que el listado —
    // si no, el panel mostraria totales de todas las empresas mientras la tabla
    // muestra una sola.
    $wEmp = ($empresa !== '' && ctype_digit($empresa)) ? 'WHERE empresa_id = :e' : '';
    $pEmp = $wEmp !== '' ? [':e' => (int) $empresa] : [];

    $stats = ['total' => 0, 'bancos' => 0, 'billeteras' => 0, 'saldo_total' => 0.0];

    $s1 = $pdo->prepare("SELECT COUNT(*) FROM datacount_bancos_cuentas {$wEmp}");
    $s1->execute($pEmp);
    $stats['total'] = (int) $s1->fetchColumn();

    $s2 = $pdo->prepare(
        'SELECT COUNT(*) FROM datacount_bancos_cuentas '
        . ($wEmp !== '' ? "{$wEmp} AND" : 'WHERE') . " tipo = 'banco'"
    );
    $s2->execute($pEmp);
    $stats['bancos'] = (int) $s2->fetchColumn();

    $s3 = $pdo->prepare(
        'SELECT COUNT(*) FROM datacount_bancos_cuentas '
        . ($wEmp !== '' ? "{$wEmp} AND" : 'WHERE') . " tipo = 'billetera'"
    );
    $s3->execute($pEmp);
    $stats['billeteras'] = (int) $s3->fetchColumn();

    // Solo pesos: sumar cuentas en monedas distintas daria un numero sin
    // significado. Las cuentas en dolares se miran una por una.
    $s4 = $pdo->prepare(
        'SELECT COALESCE(SUM(saldo), 0) FROM datacount_bancos_cuentas '
        . ($wEmp !== '' ? "{$wEmp} AND" : 'WHERE') . " activa = 1 AND moneda = 'P'"
    );
    $s4->execute($pEmp);
    $stats['saldo_total'] = (float) $s4->fetchColumn();

    jsonOk(['items' => $rows, 'stats' => $stats]);
}

function handleGetOneCuentaBanco(PDO $pdo, int $id): void {
    $st = $pdo->prepare('SELECT ' . DCBC_COLS . ' FROM datacount_bancos_cuentas WHERE id = :id LIMIT 1');
    $st->execute([':id' => $id]);
    $row = $st->fetch();
    if (!$row) jsonError('Cuenta no encontrada', 404);
    jsonOk(normalizarFilaCuentaBanco($row));
}

// Catalogos para los <select> del formulario y del modal de filtros.
// `bancos` sale de la legacy `datacountbancos`, que sigue siendo el catalogo de
// instituciones del grupo (la comparten las UIs viejas); no se clono a
// snake_case porque no cambia y no vale la pena mantener dos copias.
function handleLookupsCuentaBanco(PDO $pdo): void {
    $empresas = $pdo->query(
        "SELECT id, COALESCE(NULLIF(TRIM(nombre), ''), CONCAT('#', id)) AS nombre
           FROM datacount_empresas ORDER BY nombre ASC, id ASC"
    )->fetchAll();

    $proyectos = $pdo->query(
        "SELECT id, COALESCE(NULLIF(TRIM(nombre), ''), CONCAT('#', id)) AS nombre
           FROM proyectos WHERE tipo = 'I' ORDER BY nombre ASC, id ASC"
    )->fetchAll();

    $bancos = $pdo->query(
        "SELECT id, COALESCE(NULLIF(TRIM(nombre), ''), CONCAT('#', id)) AS nombre
           FROM datacountbancos ORDER BY nombre ASC, id ASC"
    )->fetchAll();

    // Solo cuentas imputables y activas: son las unicas contra las que se puede
    // asentar, misma regla que aplica el ABM de Asientos.
    $contables = $pdo->query(
        "SELECT id, CONCAT(codigo, ' — ', nombre) AS nombre
           FROM datacount_cuentas
          WHERE imputable = 1 AND activa = 1
          ORDER BY codigo ASC"
    )->fetchAll();

    $tipos = $pdo->query(
        "SELECT valor, texto FROM estados
          WHERE campo = 'datacount_bancos_cuenta_tipo' ORDER BY orden ASC, id ASC"
    )->fetchAll();

    $monedas = $pdo->query(
        "SELECT valor, texto FROM estados
          WHERE campo = 'datacount_pago_moneda' ORDER BY orden ASC, id ASC"
    )->fetchAll();

    $mapSimple = fn($rows) => array_map(fn($r) => [
        'id'     => (int) $r['id'],
        'nombre' => (string) $r['nombre'],
    ], $rows);
    $mapEstado = fn($rows) => array_map(fn($r) => [
        'valor' => (string) ($r['valor'] ?? ''),
        'texto' => (string) ($r['texto'] ?? ''),
    ], $rows);

    jsonOk([
        'empresas'  => $mapSimple($empresas),
        'proyectos' => $mapSimple($proyectos),
        'bancos'    => $mapSimple($bancos),
        'contables' => $mapSimple($contables),
        'tipos'     => $mapEstado($tipos),
        'monedas'   => $mapEstado($monedas),
    ]);
}

function handleCreateCuentaBanco(PDO $pdo, array $body): void {
    $p = sanitizePayloadCuentaBanco($body, true);

    $st = $pdo->prepare(
        'INSERT INTO datacount_bancos_cuentas
            (empresa_id, proyecto_id, banco_id, tipo, nombre, moneda, cbu, alias, numero,
             titular, cuit, correo, celular, contrasena, cuenta_contable_id, saldo,
             saldo_fecha, import_config, observaciones, activa)
         VALUES
            (:empresa_id, :proyecto_id, :banco_id, :tipo, :nombre, :moneda, :cbu, :alias, :numero,
             :titular, :cuit, :correo, :celular, :contrasena, :cuenta_contable_id, :saldo,
             :saldo_fecha, :import_config, :observaciones, :activa)'
    );
    $st->execute([
        ':empresa_id'         => $p['empresa_id'],
        ':proyecto_id'        => $p['proyecto_id'],
        ':banco_id'           => $p['banco_id'],
        ':tipo'               => $p['tipo']   ?? 'banco',
        ':nombre'             => $p['nombre'],
        ':moneda'             => $p['moneda'] ?? 'P',
        ':cbu'                => $p['cbu'],
        ':alias'              => $p['alias'],
        ':numero'             => $p['numero'],
        ':titular'            => $p['titular'],
        ':cuit'               => $p['cuit'],
        ':correo'             => $p['correo'],
        ':celular'            => $p['celular'],
        ':contrasena'         => $p['contrasena'],
        ':cuenta_contable_id' => $p['cuenta_contable_id'],
        ':saldo'              => $p['saldo'] ?? 0,
        ':saldo_fecha'        => $p['saldo_fecha'],
        ':import_config'      => $p['import_config'],
        ':observaciones'      => $p['observaciones'],
        ':activa'             => $p['activa'] ?? 1,
    ]);

    $id = (int) $pdo->lastInsertId();
    registrarSuceso($pdo, 'datacount_bancos_cuentas', 'info',
        "Alta cuenta #{$id} — \"{$p['nombre']}\" ({$p['tipo']})");

    handleGetOneCuentaBanco($pdo, $id);
}

function handleUpdateCuentaBanco(PDO $pdo, int $id, array $body): void {
    $st = $pdo->prepare('SELECT nombre FROM datacount_bancos_cuentas WHERE id = :id LIMIT 1');
    $st->execute([':id' => $id]);
    $prev = $st->fetch();
    if (!$prev) jsonError('Cuenta no encontrada', 404);

    $p = sanitizePayloadCuentaBanco($body, false);

    // Solo se tocan las columnas que el body menciona: el ABM manda el
    // formulario completo, pero el importador manda unicamente `import_config`
    // y no debe pisar el resto de la cuenta con nulls.
    $campos = [
        'empresa_id', 'proyecto_id', 'banco_id', 'tipo', 'nombre', 'moneda', 'cbu',
        'alias', 'numero', 'titular', 'cuit', 'correo', 'celular', 'cuenta_contable_id',
        'saldo', 'saldo_fecha', 'import_config', 'observaciones', 'activa',
    ];

    $sets   = [];
    $params = [':id' => $id];
    foreach ($campos as $c) {
        if (!array_key_exists($c, $body)) continue;
        // `nombre` es NOT NULL: si viene vacio se ignora en vez de romper.
        if ($c === 'nombre' && $p['nombre'] === null) continue;
        if ($c === 'tipo'   && $p['tipo']   === null) continue;
        $sets[]          = "{$c} = :{$c}";
        $params[":{$c}"] = $p[$c];
    }

    // La contraseña solo se pisa si vino algo: un PUT sin el campo (o con el
    // campo vacio) conserva la guardada, si no editar cualquier otro dato del
    // formulario la borraria.
    if (array_key_exists('contrasena', $body) && $p['contrasena'] !== null) {
        $sets[]                 = 'contrasena = :contrasena';
        $params[':contrasena']  = $p['contrasena'];
    }

    if (empty($sets)) jsonError('No hay campos para actualizar.', 400);

    $sql = 'UPDATE datacount_bancos_cuentas SET ' . implode(', ', $sets) . ' WHERE id = :id';
    $pdo->prepare($sql)->execute($params);

    registrarSuceso($pdo, 'datacount_bancos_cuentas', 'info',
        "Modificación cuenta #{$id} — \"{$prev['nombre']}\"");

    handleGetOneCuentaBanco($pdo, $id);
}

function handleDeleteCuentaBanco(PDO $pdo, int $id): void {
    $st = $pdo->prepare('SELECT nombre FROM datacount_bancos_cuentas WHERE id = :id LIMIT 1');
    $st->execute([':id' => $id]);
    $prev = $st->fetch();
    if (!$prev) jsonError('Cuenta no encontrada', 404);

    // El FK de movimientos es ON DELETE CASCADE, asi que borrar la cuenta se
    // lleva su extracto. Avisamos cuantos movimientos se van a perder para que
    // el front pueda pedir una confirmacion informada en vez de un "¿seguro?".
    $cnt = $pdo->prepare('SELECT COUNT(*) FROM datacount_bancos_movimientos WHERE cuenta_id = :id');
    $cnt->execute([':id' => $id]);
    $movs = (int) $cnt->fetchColumn();

    $pdo->prepare('DELETE FROM datacount_bancos_cuentas WHERE id = :id')->execute([':id' => $id]);

    registrarSuceso($pdo, 'datacount_bancos_cuentas', 'info',
        "Baja cuenta #{$id} — \"{$prev['nombre']}\" ({$movs} movimiento/s eliminados en cascada)");

    jsonOk(['id' => $id, 'movimientos_eliminados' => $movs]);
}
