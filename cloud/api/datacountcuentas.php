<?php
// api/datacountcuentas.php
// Plan de Cuentas Datacount (CRUD). Lee/escribe sobre la tabla
// `datacount_cuentas` definida en db/schema.sql — mismo esquema que
// `repo`.`cuentas`: codigo unico, jerarquia por parent_id + nivel,
// imputable, naturaleza (deudora/acreedora), activa y saldo propagado.
//
//   GET    api/datacountcuentas.php[?q=...&tipo=...]
//                                       -> listado plano de cuentas + stats por tipo
//   GET    api/datacountcuentas.php?id=N
//                                       -> registro individual
//   POST   api/datacountcuentas.php     -> alta (JSON body)
//   PUT    api/datacountcuentas.php?id=N
//                                       -> modificacion (JSON body)
//   DELETE api/datacountcuentas.php?id=N
//                                       -> baja (solo si no tiene hijos)
//
// Auto-seed: en el primer request, si la tabla esta vacia, se carga un
// plan de cuentas estandar equivalente al del proyecto `repo`.
//
// Respuesta siempre {ok: true, data: ...} u {ok: false, error: '...'} (STACK.md sec. 10).

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/lib/auth_check.php';
require_once __DIR__ . '/lib/sucesos.php';

requireAuth();
header('Content-Type: application/json; charset=utf-8');

const DCC_TIPOS       = ['activo', 'pasivo', 'patrimonio', 'ingreso', 'egreso'];
const DCC_NATURALEZAS = ['deudora', 'acreedora'];
const DCC_COLS        = 'id, empresa_id, codigo, nombre, tipo, parent_id, nivel, imputable, naturaleza, descripcion, activa, saldo';

try {
    requirePermCrud('datacount.cuentas');
    $pdo    = db();
    $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
    $id     = isset($_GET['id']) ? (int)$_GET['id'] : 0;

    if ($method === 'GET' && $id > 0) {
        handleGetOne($pdo, $id);
    } elseif ($method === 'GET') {
        handleList($pdo, $_GET);
    } elseif ($method === 'POST') {
        handleCreate($pdo, readJsonBody());
    } elseif ($method === 'PUT') {
        if ($id <= 0) jsonError('Falta id', 400);
        handleUpdate($pdo, $id, readJsonBody());
    } elseif ($method === 'DELETE') {
        if ($id <= 0) jsonError('Falta id', 400);
        handleDelete($pdo, $id);
    } else {
        jsonError('Metodo no soportado', 405);
    }
} catch (Throwable $e) {
    jsonError($e->getMessage(), 500);
}

// ----------------------------------------------------------------------------
// Helpers
// ----------------------------------------------------------------------------

function normalizarFila(array $r): array {
    return [
        'id'          => (int)($r['id'] ?? 0),
        'empresa_id'  => (int)($r['empresa_id'] ?? 0),
        'codigo'      => (string)($r['codigo'] ?? ''),
        'nombre'      => (string)($r['nombre'] ?? ''),
        'tipo'        => (string)($r['tipo'] ?? ''),
        'parent_id'   => $r['parent_id'] !== null ? (int)$r['parent_id'] : null,
        'nivel'       => (int)($r['nivel'] ?? 1),
        'imputable'   => (int)($r['imputable'] ?? 0),
        'naturaleza'  => (string)($r['naturaleza'] ?? ''),
        'descripcion' => $r['descripcion'] !== null ? (string)$r['descripcion'] : null,
        'activa'      => (int)($r['activa'] ?? 0),
        'saldo'       => (float)($r['saldo'] ?? 0),
    ];
}

function sanitizePayload(array $in, bool $esAlta): array {
    $empresaId   = isset($in['empresa_id']) ? (int)$in['empresa_id'] : 0;
    $codigo      = trim((string)($in['codigo'] ?? ''));
    $nombre      = trim((string)($in['nombre'] ?? ''));
    $tipo        = trim((string)($in['tipo'] ?? ''));
    $naturaleza  = trim((string)($in['naturaleza'] ?? ''));
    $descripcion = trim((string)($in['descripcion'] ?? ''));
    $parentRaw   = $in['parent_id'] ?? null;
    $parentId    = ($parentRaw === '' || $parentRaw === null) ? null : (int)$parentRaw;
    $imputable   = !empty($in['imputable']) ? 1 : 0;
    $activa      = array_key_exists('activa', $in) ? (int)!!$in['activa'] : 1;

    if ($esAlta) {
        if ($empresaId <= 0)     jsonError('Falta empresa_id', 400);
        if ($codigo === '')      jsonError('El codigo es obligatorio.', 400);
        if ($nombre === '')      jsonError('El nombre es obligatorio.', 400);
        if ($tipo === '')        jsonError('El tipo es obligatorio.', 400);
        if ($naturaleza === '')  jsonError('La naturaleza es obligatoria.', 400);
    }

    if ($codigo !== '' && !preg_match('/^[0-9]+(\.[0-9]+)*$/', $codigo)) {
        jsonError('El codigo debe tener el formato N.N.N (solo digitos y puntos).', 400);
    }
    if ($codigo !== '' && strlen($codigo) > 20) {
        jsonError('El codigo no puede superar los 20 caracteres.', 400);
    }
    if ($nombre !== '' && strlen($nombre) > 160) {
        jsonError('El nombre no puede superar los 160 caracteres.', 400);
    }
    if ($tipo !== '' && !in_array($tipo, DCC_TIPOS, true)) {
        jsonError('Tipo invalido.', 400);
    }
    if ($naturaleza !== '' && !in_array($naturaleza, DCC_NATURALEZAS, true)) {
        jsonError('Naturaleza invalida.', 400);
    }

    return [
        'empresa_id'  => $empresaId,
        'codigo'      => $codigo,
        'nombre'      => $nombre,
        'tipo'        => $tipo,
        'naturaleza'  => $naturaleza,
        'descripcion' => $descripcion === '' ? null : $descripcion,
        'parent_id'   => $parentId,
        'imputable'   => $imputable,
        'activa'      => $activa,
    ];
}

// ----------------------------------------------------------------------------
// Handlers
// ----------------------------------------------------------------------------

function handleList(PDO $pdo, array $q): void {
    $empresaId = isset($q['empresa_id']) ? (int)$q['empresa_id'] : 0;
    if ($empresaId <= 0) jsonError('Falta empresa_id', 400);
    validarEmpresa($pdo, $empresaId);

    // Auto-seed: si esta empresa no tiene cuentas todavía, sembrar el
    // plan estándar. Permite que empresas creadas después del deploy
    // arranquen con el mismo plan inicial que las originales.
    $vacia = (int)$pdo->query(
        'SELECT COUNT(*) FROM datacount_cuentas WHERE empresa_id = ' . $empresaId
    )->fetchColumn() === 0;
    if ($vacia) {
        seedPlanCuentas($pdo, $empresaId);
    }

    $search = trim((string)($q['q'] ?? ''));
    $tipo   = trim((string)($q['tipo'] ?? ''));

    $where  = ['empresa_id = :emp'];
    $params = [':emp' => $empresaId];

    if ($search !== '') {
        // PDO con EMULATE_PREPARES=false no permite reusar el mismo :param
        // dos veces en el SQL — usamos dos placeholders distintos.
        $where[] = '(codigo LIKE :s_cod OR nombre LIKE :s_nom)';
        $params[':s_cod'] = "%{$search}%";
        $params[':s_nom'] = "%{$search}%";
    }
    if ($tipo !== '' && in_array($tipo, DCC_TIPOS, true)) {
        $where[] = 'tipo = :tipo';
        $params[':tipo'] = $tipo;
    }

    $sqlWhere = 'WHERE ' . implode(' AND ', $where);
    $sql = 'SELECT ' . DCC_COLS . " FROM datacount_cuentas {$sqlWhere} ORDER BY codigo ASC";
    $st = $pdo->prepare($sql);
    $st->execute($params);
    $rows = array_map('normalizarFila', $st->fetchAll());

    // Stats por tipo dentro de la empresa (los indicadores globales dejan
    // de tener sentido si cada empresa tiene su propio plan).
    $stats = [];
    foreach (DCC_TIPOS as $t) {
        $s = $pdo->prepare('SELECT COUNT(*) FROM datacount_cuentas WHERE empresa_id = :e AND tipo = :t');
        $s->execute([':e' => $empresaId, ':t' => $t]);
        $stats[$t] = (int)$s->fetchColumn();
    }
    $s = $pdo->prepare('SELECT COUNT(*) FROM datacount_cuentas WHERE empresa_id = :e');
    $s->execute([':e' => $empresaId]);
    $stats['total'] = (int)$s->fetchColumn();

    jsonOk(['items' => $rows, 'stats' => $stats]);
}

function validarEmpresa(PDO $pdo, int $empresaId): void {
    $st = $pdo->prepare('SELECT id FROM datacount_empresas WHERE id = :id LIMIT 1');
    $st->execute([':id' => $empresaId]);
    if (!$st->fetch()) jsonError('Empresa no encontrada', 400);
}

function handleGetOne(PDO $pdo, int $id): void {
    $st = $pdo->prepare('SELECT ' . DCC_COLS . ' FROM datacount_cuentas WHERE id = :id LIMIT 1');
    $st->execute([':id' => $id]);
    $row = $st->fetch();
    if (!$row) jsonError('Cuenta no encontrada', 404);
    jsonOk(normalizarFila($row));
}

function handleCreate(PDO $pdo, array $body): void {
    $p = sanitizePayload($body, true);
    validarEmpresa($pdo, $p['empresa_id']);

    // Nivel se calcula desde el padre; si no hay padre, nivel = 1.
    // Además el padre debe pertenecer a la misma empresa.
    $nivel = 1;
    if ($p['parent_id'] !== null) {
        $st = $pdo->prepare('SELECT nivel, empresa_id FROM datacount_cuentas WHERE id = :id');
        $st->execute([':id' => $p['parent_id']]);
        $padre = $st->fetch();
        if (!$padre) jsonError('Cuenta padre no encontrada', 400);
        if ((int)$padre['empresa_id'] !== $p['empresa_id']) {
            jsonError('La cuenta padre pertenece a otra empresa.', 400);
        }
        $nivel = (int)$padre['nivel'] + 1;
    }

    try {
        $st = $pdo->prepare(
            'INSERT INTO datacount_cuentas
                (empresa_id, codigo, nombre, tipo, parent_id, nivel, imputable, naturaleza, descripcion, activa)
             VALUES
                (:empresa_id, :codigo, :nombre, :tipo, :parent_id, :nivel, :imputable, :naturaleza, :descripcion, :activa)'
        );
        $st->execute([
            ':empresa_id'  => $p['empresa_id'],
            ':codigo'      => $p['codigo'],
            ':nombre'      => $p['nombre'],
            ':tipo'        => $p['tipo'],
            ':parent_id'   => $p['parent_id'],
            ':nivel'       => $nivel,
            ':imputable'   => $p['imputable'],
            ':naturaleza'  => $p['naturaleza'],
            ':descripcion' => $p['descripcion'],
            ':activa'      => $p['activa'],
        ]);
    } catch (PDOException $e) {
        if ($e->getCode() === '23000') {
            jsonError('Ya existe una cuenta con ese codigo en esta empresa.', 409);
        }
        throw $e;
    }

    $id = (int)$pdo->lastInsertId();
    registrarSuceso($pdo, 'datacountcuentas', 'info',
        "Alta cuenta #{$id} — empresa {$p['empresa_id']} — {$p['codigo']} {$p['nombre']}");

    handleGetOne($pdo, $id);
}

function handleUpdate(PDO $pdo, int $id, array $body): void {
    $st = $pdo->prepare('SELECT ' . DCC_COLS . ' FROM datacount_cuentas WHERE id = :id LIMIT 1');
    $st->execute([':id' => $id]);
    $prev = $st->fetch();
    if (!$prev) jsonError('Cuenta no encontrada', 404);

    // `empresa_id` es inmutable: la cuenta pertenece a la empresa donde fue
    // creada y las validaciones abajo asumen ese contexto.
    $empresaCuenta = (int)$prev['empresa_id'];
    $p = sanitizePayload($body, false);

    $sets   = [];
    $params = [':id' => $id];

    if ($p['codigo'] !== '')     { $sets[] = 'codigo = :codigo';           $params[':codigo']     = $p['codigo']; }
    if ($p['nombre'] !== '')     { $sets[] = 'nombre = :nombre';           $params[':nombre']     = $p['nombre']; }
    if ($p['tipo'] !== '')       { $sets[] = 'tipo = :tipo';               $params[':tipo']       = $p['tipo']; }
    if ($p['naturaleza'] !== '') { $sets[] = 'naturaleza = :naturaleza';   $params[':naturaleza'] = $p['naturaleza']; }

    if (array_key_exists('imputable', $body)) {
        $sets[] = 'imputable = :imputable';
        $params[':imputable'] = $p['imputable'];
    }
    if (array_key_exists('activa', $body)) {
        $sets[] = 'activa = :activa';
        $params[':activa'] = $p['activa'];
    }
    if (array_key_exists('descripcion', $body)) {
        $sets[] = 'descripcion = :descripcion';
        $params[':descripcion'] = $p['descripcion'];
    }

    if (array_key_exists('parent_id', $body)) {
        // Cambiar padre implica recalcular nivel. El padre debe ser
        // de la misma empresa que la cuenta editada.
        if ($p['parent_id'] !== null) {
            if ($p['parent_id'] === $id) jsonError('Una cuenta no puede ser su propio padre.', 400);
            $sp = $pdo->prepare('SELECT nivel, empresa_id FROM datacount_cuentas WHERE id = :id');
            $sp->execute([':id' => $p['parent_id']]);
            $padre = $sp->fetch();
            if (!$padre) jsonError('Cuenta padre no encontrada', 400);
            if ((int)$padre['empresa_id'] !== $empresaCuenta) {
                jsonError('La cuenta padre pertenece a otra empresa.', 400);
            }
            $sets[] = 'parent_id = :parent_id';
            $sets[] = 'nivel = :nivel';
            $params[':parent_id'] = $p['parent_id'];
            $params[':nivel']     = (int)$padre['nivel'] + 1;
        } else {
            $sets[] = 'parent_id = NULL';
            $sets[] = 'nivel = 1';
        }
    }

    if (empty($sets)) jsonError('No hay campos para actualizar.', 400);

    try {
        $sql = 'UPDATE datacount_cuentas SET ' . implode(', ', $sets) . ' WHERE id = :id';
        $st  = $pdo->prepare($sql);
        $st->execute($params);
    } catch (PDOException $e) {
        if ($e->getCode() === '23000') {
            jsonError('Ya existe una cuenta con ese codigo en esta empresa.', 409);
        }
        throw $e;
    }

    registrarSuceso($pdo, 'datacountcuentas', 'info',
        "Modificacion cuenta #{$id} — empresa {$empresaCuenta} — {$prev['codigo']} {$prev['nombre']}");

    handleGetOne($pdo, $id);
}

function handleDelete(PDO $pdo, int $id): void {
    $st = $pdo->prepare('SELECT codigo, nombre FROM datacount_cuentas WHERE id = :id LIMIT 1');
    $st->execute([':id' => $id]);
    $prev = $st->fetch();
    if (!$prev) jsonError('Cuenta no encontrada', 404);

    $sh = $pdo->prepare('SELECT COUNT(*) FROM datacount_cuentas WHERE parent_id = :id');
    $sh->execute([':id' => $id]);
    if ((int)$sh->fetchColumn() > 0) {
        jsonError('No se puede eliminar: la cuenta tiene subcuentas.', 409);
    }

    $sd = $pdo->prepare('DELETE FROM datacount_cuentas WHERE id = :id');
    $sd->execute([':id' => $id]);

    registrarSuceso($pdo, 'datacountcuentas', 'info',
        "Baja cuenta #{$id} — {$prev['codigo']} {$prev['nombre']}");

    jsonOk(['id' => $id]);
}

// ----------------------------------------------------------------------------
// Auto-seed: plan de cuentas estandar (mismo que `repo`).
// ----------------------------------------------------------------------------

function seedPlanCuentas(PDO $pdo, int $empresaId): void {
    // [codigo, nombre, tipo, naturaleza, imputable]
    // imputable=0 = cuenta de agrupacion; imputable=1 = recibe asientos.
    $plan = [
        // ===== ACTIVO =====
        ['1',         'ACTIVO',                                 'activo',     'deudora',   0],
        ['1.1',       'ACTIVO CORRIENTE',                       'activo',     'deudora',   0],
        ['1.1.01',    'Caja y Bancos',                          'activo',     'deudora',   0],
        ['1.1.01.01', 'Caja Efectivo',                          'activo',     'deudora',   1],
        ['1.1.01.02', 'Banco Cuenta Corriente 1',               'activo',     'deudora',   1],
        ['1.1.01.03', 'Banco Cuenta Corriente 2',               'activo',     'deudora',   1],
        ['1.1.02',    'Creditos por Ventas',                    'activo',     'deudora',   0],
        ['1.1.02.01', 'Deudores por Ventas',                    'activo',     'deudora',   1],
        ['1.1.02.02', 'Tarjetas de Credito a Cobrar',           'activo',     'deudora',   1],
        ['1.1.02.03', 'Mercado Pago a Cobrar',                  'activo',     'deudora',   1],
        ['1.1.03',    'Otros Creditos',                         'activo',     'deudora',   0],
        ['1.1.03.01', 'Anticipos a Proveedores',                'activo',     'deudora',   1],
        ['1.1.03.02', 'Adelantos a Repartidores',               'activo',     'deudora',   1],
        ['1.1.03.03', 'IVA Credito Fiscal',                     'activo',     'deudora',   1],
        ['1.1.04',    'Bienes de Cambio',                       'activo',     'deudora',   0],
        ['1.1.04.01', 'Mercaderias - Almacen',                  'activo',     'deudora',   1],
        ['1.1.04.02', 'Mercaderias - Bebidas',                  'activo',     'deudora',   1],
        ['1.1.04.03', 'Mercaderias - Frescos',                  'activo',     'deudora',   1],
        ['1.1.04.04', 'Mercaderias - Limpieza',                 'activo',     'deudora',   1],

        ['1.2',       'ACTIVO NO CORRIENTE',                    'activo',     'deudora',   0],
        ['1.2.01',    'Bienes de Uso',                          'activo',     'deudora',   0],
        ['1.2.01.01', 'Inmueble - Centro de Distribucion',      'activo',     'deudora',   1],
        ['1.2.01.02', 'Rodados - Vehiculo Repartidor 1',        'activo',     'deudora',   1],
        ['1.2.01.03', 'Rodados - Vehiculo Repartidor 2',        'activo',     'deudora',   1],
        ['1.2.01.04', 'Rodados - Vehiculo Repartidor 3',        'activo',     'deudora',   1],
        ['1.2.01.05', 'Muebles y Utiles',                       'activo',     'deudora',   1],
        ['1.2.01.06', 'Equipos de Computacion',                 'activo',     'deudora',   1],
        ['1.2.01.07', 'Instalaciones',                          'activo',     'deudora',   1],
        ['1.2.02',    'Amortizaciones Acumuladas',              'activo',     'acreedora', 0],
        ['1.2.02.01', 'Amort. Acum. Inmuebles',                 'activo',     'acreedora', 1],
        ['1.2.02.02', 'Amort. Acum. Rodados',                   'activo',     'acreedora', 1],
        ['1.2.02.03', 'Amort. Acum. Muebles y Utiles',          'activo',     'acreedora', 1],
        ['1.2.02.04', 'Amort. Acum. Equipos de Computacion',    'activo',     'acreedora', 1],

        // ===== PASIVO =====
        ['2',         'PASIVO',                                 'pasivo',     'acreedora', 0],
        ['2.1',       'PASIVO CORRIENTE',                       'pasivo',     'acreedora', 0],
        ['2.1.01',    'Deudas Comerciales',                     'pasivo',     'acreedora', 0],
        ['2.1.01.01', 'Proveedores',                            'pasivo',     'acreedora', 1],
        ['2.1.01.02', 'Documentos a Pagar',                     'pasivo',     'acreedora', 1],
        ['2.1.02',    'Deudas Fiscales',                        'pasivo',     'acreedora', 0],
        ['2.1.02.01', 'IVA Debito Fiscal',                      'pasivo',     'acreedora', 1],
        ['2.1.02.02', 'IVA a Pagar',                            'pasivo',     'acreedora', 1],
        ['2.1.02.03', 'Ingresos Brutos a Pagar',                'pasivo',     'acreedora', 1],
        ['2.1.02.04', 'Impuesto a las Ganancias',               'pasivo',     'acreedora', 1],
        ['2.1.03',    'Deudas Sociales',                        'pasivo',     'acreedora', 0],
        ['2.1.03.01', 'Sueldos a Pagar',                        'pasivo',     'acreedora', 1],
        ['2.1.03.02', 'Cargas Sociales a Pagar',                'pasivo',     'acreedora', 1],
        ['2.1.03.03', 'Provision Aguinaldo',                    'pasivo',     'acreedora', 1],
        ['2.1.03.04', 'Provision Vacaciones',                   'pasivo',     'acreedora', 1],
        ['2.1.04',    'Otras Deudas',                           'pasivo',     'acreedora', 0],
        ['2.1.04.01', 'Servicios a Pagar',                      'pasivo',     'acreedora', 1],
        ['2.1.04.02', 'Alquileres a Pagar',                     'pasivo',     'acreedora', 1],

        // ===== PATRIMONIO NETO =====
        ['3',         'PATRIMONIO NETO',                        'patrimonio', 'acreedora', 0],
        ['3.1',       'Capital',                                'patrimonio', 'acreedora', 0],
        ['3.1.01',    'Capital Social',                         'patrimonio', 'acreedora', 0],
        ['3.1.01.01', 'Aporte Socio 1',                         'patrimonio', 'acreedora', 1],
        ['3.1.01.02', 'Aporte Socio 2',                         'patrimonio', 'acreedora', 1],
        ['3.2',       'Resultados',                             'patrimonio', 'acreedora', 0],
        ['3.2.01',    'Resultados Acumulados',                  'patrimonio', 'acreedora', 1],
        ['3.2.02',    'Resultado del Ejercicio',                'patrimonio', 'acreedora', 1],

        // ===== INGRESOS =====
        ['4',         'INGRESOS',                               'ingreso',    'acreedora', 0],
        ['4.1',       'Ventas',                                 'ingreso',    'acreedora', 0],
        ['4.1.01',    'Ventas Almacen',                         'ingreso',    'acreedora', 1],
        ['4.1.02',    'Ventas Bebidas',                         'ingreso',    'acreedora', 1],
        ['4.1.03',    'Ventas Frescos',                         'ingreso',    'acreedora', 1],
        ['4.1.04',    'Ventas Limpieza',                        'ingreso',    'acreedora', 1],
        ['4.2',       'Otros Ingresos',                         'ingreso',    'acreedora', 0],
        ['4.2.01',    'Cargo por Envio',                        'ingreso',    'acreedora', 1],
        ['4.2.02',    'Descuentos Obtenidos',                   'ingreso',    'acreedora', 1],
        ['4.2.03',    'Intereses Ganados',                      'ingreso',    'acreedora', 1],

        // ===== EGRESOS =====
        ['5',         'EGRESOS',                                'egreso',     'deudora',   0],
        ['5.1',       'Costo de Mercaderia Vendida',            'egreso',     'deudora',   0],
        ['5.1.01',    'CMV Almacen',                            'egreso',     'deudora',   1],
        ['5.1.02',    'CMV Bebidas',                            'egreso',     'deudora',   1],
        ['5.1.03',    'CMV Frescos',                            'egreso',     'deudora',   1],
        ['5.1.04',    'CMV Limpieza',                           'egreso',     'deudora',   1],

        ['5.2',       'Gastos de Comercializacion',             'egreso',     'deudora',   0],
        ['5.2.01',    'Sueldos Repartidores',                   'egreso',     'deudora',   0],
        ['5.2.01.01', 'Sueldo Repartidor 1',                    'egreso',     'deudora',   1],
        ['5.2.01.02', 'Sueldo Repartidor 2',                    'egreso',     'deudora',   1],
        ['5.2.01.03', 'Sueldo Repartidor 3',                    'egreso',     'deudora',   1],
        ['5.2.02',    'Combustible y Mantenimiento Vehiculos',  'egreso',     'deudora',   1],
        ['5.2.03',    'Comisiones MercadoPago / Tarjetas',      'egreso',     'deudora',   1],
        ['5.2.04',    'Publicidad y Marketing',                 'egreso',     'deudora',   1],
        ['5.2.05',    'Packaging y Bolsas',                     'egreso',     'deudora',   1],

        ['5.3',       'Gastos de Administracion',               'egreso',     'deudora',   0],
        ['5.3.01',    'Sueldos Administracion',                 'egreso',     'deudora',   1],
        ['5.3.02',    'Honorarios Profesionales',               'egreso',     'deudora',   1],
        ['5.3.03',    'Gastos de Oficina',                      'egreso',     'deudora',   1],
        ['5.3.04',    'Servicios (Luz, Agua, Internet)',        'egreso',     'deudora',   1],
        ['5.3.05',    'Hosting y Software',                     'egreso',     'deudora',   1],
        ['5.3.06',    'Alquiler Centro de Distribucion',        'egreso',     'deudora',   1],

        ['5.4',       'Impuestos y Tasas',                      'egreso',     'deudora',   0],
        ['5.4.01',    'Impuesto a los Debitos y Creditos',      'egreso',     'deudora',   1],
        ['5.4.02',    'Tasa Municipal',                         'egreso',     'deudora',   1],
        ['5.4.03',    'Ingresos Brutos',                        'egreso',     'deudora',   1],

        ['5.5',       'Gastos Financieros',                     'egreso',     'deudora',   0],
        ['5.5.01',    'Intereses Bancarios',                    'egreso',     'deudora',   1],
        ['5.5.02',    'Comisiones Bancarias',                   'egreso',     'deudora',   1],
    ];

    $pdo->beginTransaction();
    try {
        $idsByCodigo = [];
        $ins = $pdo->prepare(
            'INSERT INTO datacount_cuentas
                (empresa_id, codigo, nombre, tipo, parent_id, nivel, imputable, naturaleza, activa)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, 1)'
        );
        foreach ($plan as $row) {
            [$codigo, $nombre, $tipo, $naturaleza, $imputable] = $row;
            $partes   = explode('.', $codigo);
            $nivel    = count($partes);
            $padreCod = $nivel > 1 ? implode('.', array_slice($partes, 0, $nivel - 1)) : null;
            $parentId = ($padreCod && isset($idsByCodigo[$padreCod])) ? $idsByCodigo[$padreCod] : null;
            $ins->execute([$empresaId, $codigo, $nombre, $tipo, $parentId, $nivel, $imputable, $naturaleza]);
            $idsByCodigo[$codigo] = (int)$pdo->lastInsertId();
        }
        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }
}
