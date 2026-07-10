<?php
// api/datacountasientos.php
// Asientos contables Datacount (CRUD). Lee/escribe sobre las tablas
// `datacount_asientos` + `datacount_asientos_detalles` — mismo esquema que
// `repo.asientos` / `repo.asiento_detalles`. Cada asiento se asocia a una
// empresa (`empresa_id`) y la numeracion `numero` es autoincrementable por
// empresa (index UNIQUE `(empresa_id, numero)`).
//
//   GET    api/datacountasientos.php[?empresa=N&q=...&desde=YYYY-MM-DD
//                                     &hasta=YYYY-MM-DD&cuenta_id=N]
//                                       -> listado (500 max) + stats + detalle por asiento
//   GET    api/datacountasientos.php?id=N
//                                       -> asiento individual con su detalle
//   POST   api/datacountasientos.php     -> alta (JSON body con `empresa` y `detalle` array)
//   PUT    api/datacountasientos.php?id=N
//                                       -> modificacion (reemplaza el detalle completo)
//   DELETE api/datacountasientos.php?id=N
//                                       -> baja (CASCADE borra el detalle)
//
// Validacion: total DEBE = total HABER (tolerancia 0.01), 2+ lineas, todas
// las cuentas deben ser imputables, activas y pertenecer a la empresa del
// asiento. Al guardar/eliminar, recalcula el `saldo` de las cuentas
// afectadas (deudora: debe-haber; acreedora: haber-debe).
//
// Respuesta siempre {ok: true, data: ...} u {ok: false, error: '...'} (STACK.md sec. 10).

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/lib/auth_check.php';
require_once __DIR__ . '/lib/sucesos.php';

requireAuth();
header('Content-Type: application/json; charset=utf-8');

const DCA_COLS = 'id, empresa_id, numero, fecha, descripcion, total, created_at';

try {
    $pdo    = db();
    $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
    $id     = isset($_GET['id']) ? (int)$_GET['id'] : 0;

    if ($method === 'GET' && $id > 0) {
        handleGetOne($pdo, $id);
    } elseif ($method === 'GET') {
        handleList($pdo, $_GET);
    } elseif ($method === 'POST') {
        handleSave($pdo, readJsonBody(), null);
    } elseif ($method === 'PUT') {
        if ($id <= 0) jsonError('Falta id', 400);
        handleSave($pdo, readJsonBody(), $id);
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
// Handlers
// ----------------------------------------------------------------------------

function handleList(PDO $pdo, array $q): void {
    $search  = trim((string)($q['q'] ?? ''));
    $empresa = isset($q['empresa']) ? (int)$q['empresa'] : 0;
    $desde   = trim((string)($q['desde'] ?? ''));
    $hasta   = trim((string)($q['hasta'] ?? ''));
    $cuenta  = isset($q['cuenta_id']) ? (int)$q['cuenta_id'] : 0;

    $where  = [];
    $params = [];

    if ($empresa > 0) {
        $where[] = 'empresa_id = :empresa';
        $params[':empresa'] = $empresa;
    }
    if ($search !== '') {
        // PDO con EMULATE_PREPARES=false no permite reusar el mismo :param — usamos dos.
        $where[] = '(descripcion LIKE :s_desc OR numero LIKE :s_num)';
        $params[':s_desc'] = "%{$search}%";
        $params[':s_num']  = "%{$search}%";
    }
    if ($desde !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $desde)) {
        $where[] = 'fecha >= :desde';
        $params[':desde'] = $desde;
    }
    if ($hasta !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $hasta)) {
        $where[] = 'fecha <= :hasta';
        $params[':hasta'] = $hasta;
    }
    if ($cuenta > 0) {
        $where[] = 'id IN (SELECT asiento_id FROM datacount_asientos_detalles WHERE cuenta_id = :cta)';
        $params[':cta'] = $cuenta;
    }

    $sqlWhere = $where ? ('WHERE ' . implode(' AND ', $where)) : '';
    $sql = 'SELECT ' . DCA_COLS . " FROM datacount_asientos {$sqlWhere}
            ORDER BY fecha DESC, numero DESC LIMIT 500";

    $st = $pdo->prepare($sql);
    $st->execute($params);
    $rows = $st->fetchAll();

    // Adjuntar detalle a cada asiento en una sola query.
    if (!empty($rows)) {
        $ids   = array_column($rows, 'id');
        $place = implode(',', array_fill(0, count($ids), '?'));
        $stD = $pdo->prepare(
            "SELECT d.asiento_id, d.id AS detalle_id, d.cuenta_id, d.debe, d.haber, d.descripcion, d.orden,
                    c.codigo AS cuenta_codigo, c.nombre AS cuenta_nombre
             FROM datacount_asientos_detalles d
             LEFT JOIN datacount_cuentas c ON c.id = d.cuenta_id
             WHERE d.asiento_id IN ($place)
             ORDER BY d.asiento_id, d.orden ASC, d.id ASC"
        );
        $stD->execute($ids);
        $porAsiento = [];
        foreach ($stD->fetchAll() as $d) {
            $porAsiento[(int)$d['asiento_id']][] = normalizarLineaDetalle($d);
        }
        foreach ($rows as &$r) {
            $r['id']         = (int)$r['id'];
            $r['empresa_id'] = (int)$r['empresa_id'];
            $r['numero']     = (int)$r['numero'];
            $r['total']      = (float)$r['total'];
            $r['detalle']    = $porAsiento[(int)$r['id']] ?? [];
        }
        unset($r);
    }

    // Stats filtradas por empresa si se envio.
    $statsWhere  = $empresa > 0 ? 'WHERE empresa_id = :empresa' : '';
    $statsParams = $empresa > 0 ? [':empresa' => $empresa] : [];

    $stT = $pdo->prepare("SELECT COUNT(*) FROM datacount_asientos {$statsWhere}");
    $stT->execute($statsParams);
    $total = (int) $stT->fetchColumn();

    $stM = $pdo->prepare("SELECT COALESCE(SUM(total),0) FROM datacount_asientos {$statsWhere}");
    $stM->execute($statsParams);
    $monto = (float) $stM->fetchColumn();

    $mesWhere  = $empresa > 0 ? 'empresa_id = :empresa AND ' : '';
    $stMes = $pdo->prepare(
        "SELECT COUNT(*) FROM datacount_asientos
         WHERE {$mesWhere} YEAR(fecha)=YEAR(CURDATE()) AND MONTH(fecha)=MONTH(CURDATE())"
    );
    $stMes->execute($statsParams);
    $delMes = (int) $stMes->fetchColumn();

    jsonOk([
        'items' => $rows,
        'stats' => [
            'total'   => $total,
            'monto'   => $monto,
            'del_mes' => $delMes,
        ],
    ]);
}

function handleGetOne(PDO $pdo, int $id): void {
    $st = $pdo->prepare('SELECT ' . DCA_COLS . ' FROM datacount_asientos WHERE id = :id LIMIT 1');
    $st->execute([':id' => $id]);
    $a = $st->fetch();
    if (!$a) jsonError('Asiento no encontrado', 404);

    $stD = $pdo->prepare(
        "SELECT d.id AS detalle_id, d.cuenta_id, d.debe, d.haber, d.descripcion, d.orden,
                c.codigo AS cuenta_codigo, c.nombre AS cuenta_nombre
         FROM datacount_asientos_detalles d
         LEFT JOIN datacount_cuentas c ON c.id = d.cuenta_id
         WHERE d.asiento_id = :id
         ORDER BY d.orden ASC, d.id ASC"
    );
    $stD->execute([':id' => $id]);
    $detalle = array_map('normalizarLineaDetalle', $stD->fetchAll());

    jsonOk([
        'id'          => (int)$a['id'],
        'empresa_id'  => (int)$a['empresa_id'],
        'numero'      => (int)$a['numero'],
        'fecha'       => $a['fecha'],
        'descripcion' => $a['descripcion'],
        'total'       => (float)$a['total'],
        'created_at'  => $a['created_at'],
        'detalle'     => $detalle,
    ]);
}

function handleSave(PDO $pdo, array $body, ?int $id): void {
    $fecha       = trim((string)($body['fecha'] ?? ''));
    $descripcion = trim((string)($body['descripcion'] ?? ''));
    $detalle     = $body['detalle'] ?? [];
    $empresaIn   = isset($body['empresa']) ? (int)$body['empresa'] : 0;

    if ($fecha === '' || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $fecha)) {
        jsonError('Fecha invalida (YYYY-MM-DD)', 400);
    }
    if ($descripcion === '') jsonError('La descripcion es obligatoria.', 400);
    if (!is_array($detalle) || count($detalle) < 2) {
        jsonError('Se requieren al menos 2 lineas.', 400);
    }

    // Determinar empresa objetivo: en alta viene en el body (obligatoria);
    // en edicion se preserva la del asiento (no se puede reasignar aca).
    if ($id) {
        $stE = $pdo->prepare('SELECT empresa_id FROM datacount_asientos WHERE id = :id LIMIT 1');
        $stE->execute([':id' => $id]);
        $prev = $stE->fetch();
        if (!$prev) jsonError('Asiento no encontrado', 404);
        $empresaId = (int)$prev['empresa_id'];
    } else {
        if ($empresaIn <= 0) jsonError('La empresa es obligatoria.', 400);
        // Validar que exista.
        $stChk = $pdo->prepare('SELECT id FROM datacount_empresas WHERE id = :id LIMIT 1');
        $stChk->execute([':id' => $empresaIn]);
        if (!$stChk->fetch()) jsonError('Empresa no encontrada.', 400);
        $empresaId = $empresaIn;
    }

    // Normalizar / validar cada linea.
    $lineas     = [];
    $totDebe    = 0.0;
    $totHaber   = 0.0;
    $cuentasIds = [];
    foreach ($detalle as $i => $d) {
        $cuentaId = isset($d['cuenta_id']) ? (int)$d['cuenta_id'] : 0;
        $debe     = isset($d['debe'])  ? round((float)$d['debe'],  2) : 0.0;
        $haber    = isset($d['haber']) ? round((float)$d['haber'], 2) : 0.0;
        $desc     = trim((string)($d['descripcion'] ?? ''));

        if ($cuentaId <= 0) {
            jsonError('Linea ' . ($i + 1) . ': cuenta requerida.', 400);
        }
        if (($debe > 0 && $haber > 0) || ($debe == 0 && $haber == 0)) {
            jsonError('Linea ' . ($i + 1) . ': debe ingresar Debe O Haber (no ambos).', 400);
        }
        if ($debe < 0 || $haber < 0) {
            jsonError('Linea ' . ($i + 1) . ': importes no pueden ser negativos.', 400);
        }
        $cuentasIds[] = $cuentaId;
        $totDebe  += $debe;
        $totHaber += $haber;
        $lineas[] = [
            'cuenta_id'   => $cuentaId,
            'debe'        => $debe,
            'haber'       => $haber,
            'descripcion' => $desc === '' ? null : $desc,
            'orden'       => $i,
        ];
    }

    if (abs($totDebe - $totHaber) > 0.01) {
        jsonError(
            'El asiento no balancea: Debe ' . number_format($totDebe, 2, '.', '') .
            ' != Haber ' . number_format($totHaber, 2, '.', ''),
            400
        );
    }

    // Validar cuentas existentes, imputables, activas y de la empresa correcta.
    $idsUnicos = array_values(array_unique($cuentasIds));
    $place = implode(',', array_fill(0, count($idsUnicos), '?'));
    $stCu = $pdo->prepare("SELECT id, empresa_id, imputable, activa FROM datacount_cuentas WHERE id IN ($place)");
    $stCu->execute($idsUnicos);
    $cuentas = $stCu->fetchAll();
    if (count($cuentas) !== count($idsUnicos)) {
        jsonError('Una o mas cuentas no existen.', 400);
    }
    foreach ($cuentas as $c) {
        if ((int)$c['imputable'] !== 1) {
            jsonError('Hay cuentas no imputables (de agrupacion) seleccionadas.', 400);
        }
        if ((int)$c['activa'] !== 1) {
            jsonError('Hay cuentas inactivas seleccionadas.', 400);
        }
        if ((int)$c['empresa_id'] !== $empresaId) {
            jsonError('Hay cuentas que pertenecen a otra empresa.', 400);
        }
    }

    $total = $totDebe; // == totHaber

    $pdo->beginTransaction();
    $oldCuentaIds = [];
    try {
        if ($id) {
            // Guardar cuentas viejas para recalcular saldos aunque cambie el detalle.
            $stOld = $pdo->prepare('SELECT DISTINCT cuenta_id FROM datacount_asientos_detalles WHERE asiento_id = :id');
            $stOld->execute([':id' => $id]);
            $oldCuentaIds = $stOld->fetchAll(PDO::FETCH_COLUMN);

            $upd = $pdo->prepare(
                'UPDATE datacount_asientos SET fecha = :f, descripcion = :d, total = :t WHERE id = :id'
            );
            $upd->execute([':f' => $fecha, ':d' => $descripcion, ':t' => $total, ':id' => $id]);
            $pdo->prepare('DELETE FROM datacount_asientos_detalles WHERE asiento_id = :id')
                ->execute([':id' => $id]);
            $asientoId = $id;
        } else {
            // Numero autoincrementable a nivel de aplicacion, por empresa:
            // MAX(numero)+1 dentro de la empresa.
            $stMax = $pdo->prepare(
                'SELECT COALESCE(MAX(numero),0) + 1 FROM datacount_asientos WHERE empresa_id = :e'
            );
            $stMax->execute([':e' => $empresaId]);
            $next = (int)$stMax->fetchColumn();
            $ins = $pdo->prepare(
                'INSERT INTO datacount_asientos (empresa_id, numero, fecha, descripcion, total)
                 VALUES (:e, :n, :f, :d, :t)'
            );
            $ins->execute([
                ':e' => $empresaId, ':n' => $next, ':f' => $fecha,
                ':d' => $descripcion, ':t' => $total,
            ]);
            $asientoId = (int)$pdo->lastInsertId();
        }

        $insD = $pdo->prepare(
            'INSERT INTO datacount_asientos_detalles (asiento_id, cuenta_id, debe, haber, descripcion, orden)
             VALUES (:a, :c, :d, :h, :desc, :o)'
        );
        foreach ($lineas as $l) {
            $insD->execute([
                ':a'    => $asientoId,
                ':c'    => $l['cuenta_id'],
                ':d'    => $l['debe'],
                ':h'    => $l['haber'],
                ':desc' => $l['descripcion'],
                ':o'    => $l['orden'],
            ]);
        }

        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }

    // Recalcular saldo de cuentas afectadas (nuevas + viejas si hubo edicion).
    $todasIds = array_values(array_unique(array_merge($idsUnicos, array_map('intval', $oldCuentaIds))));
    recalcularSaldoCuentas($pdo, $todasIds);

    registrarSuceso($pdo, 'datacountasientos', 'info',
        ($id ? 'Modificacion' : 'Alta') .
        " asiento #{$asientoId} — empresa {$empresaId} — {$descripcion} — total {$total}");

    handleGetOne($pdo, $asientoId);
}

function handleDelete(PDO $pdo, int $id): void {
    $st = $pdo->prepare('SELECT numero, descripcion FROM datacount_asientos WHERE id = :id LIMIT 1');
    $st->execute([':id' => $id]);
    $prev = $st->fetch();
    if (!$prev) jsonError('Asiento no encontrado', 404);

    // Capturar cuentas afectadas antes del CASCADE.
    $stCta = $pdo->prepare('SELECT DISTINCT cuenta_id FROM datacount_asientos_detalles WHERE asiento_id = :id');
    $stCta->execute([':id' => $id]);
    $delCuentaIds = array_map('intval', $stCta->fetchAll(PDO::FETCH_COLUMN));

    $sd = $pdo->prepare('DELETE FROM datacount_asientos WHERE id = :id');
    $sd->execute([':id' => $id]);

    recalcularSaldoCuentas($pdo, $delCuentaIds);

    registrarSuceso($pdo, 'datacountasientos', 'info',
        "Baja asiento #{$id} — N.{$prev['numero']} {$prev['descripcion']}");

    jsonOk(['id' => $id]);
}

// ----------------------------------------------------------------------------
// Helpers
// ----------------------------------------------------------------------------

function normalizarLineaDetalle(array $d): array {
    return [
        'id'            => (int)($d['detalle_id'] ?? 0),
        'cuenta_id'     => (int)($d['cuenta_id']  ?? 0),
        'cuenta_codigo' => $d['cuenta_codigo'] ?? null,
        'cuenta_nombre' => $d['cuenta_nombre'] ?? null,
        'debe'          => (float)($d['debe']  ?? 0),
        'haber'         => (float)($d['haber'] ?? 0),
        'descripcion'   => $d['descripcion'] ?? null,
        'orden'         => (int)($d['orden'] ?? 0),
    ];
}

// Recalcula y persiste el saldo de las cuentas indicadas sumando todos
// sus movimientos en la tabla de detalles.
// Deudora:   saldo = SUM(debe) - SUM(haber)
// Acreedora: saldo = SUM(haber) - SUM(debe)
function recalcularSaldoCuentas(PDO $pdo, array $cuentaIds): void {
    if (empty($cuentaIds)) return;
    $ids   = array_values(array_unique(array_map('intval', $cuentaIds)));
    $place = implode(',', array_fill(0, count($ids), '?'));

    $stN = $pdo->prepare("SELECT id, naturaleza FROM datacount_cuentas WHERE id IN ($place)");
    $stN->execute($ids);
    $naturalezas = [];
    foreach ($stN->fetchAll() as $r) {
        $naturalezas[(int)$r['id']] = $r['naturaleza'];
    }

    $stS = $pdo->prepare(
        "SELECT cuenta_id,
                COALESCE(SUM(debe),  0) AS total_debe,
                COALESCE(SUM(haber), 0) AS total_haber
         FROM datacount_asientos_detalles
         WHERE cuenta_id IN ($place)
         GROUP BY cuenta_id"
    );
    $stS->execute($ids);
    $totales = [];
    foreach ($stS->fetchAll() as $r) {
        $totales[(int)$r['cuenta_id']] = $r;
    }

    $upd = $pdo->prepare('UPDATE datacount_cuentas SET saldo = :s WHERE id = :id');
    foreach ($ids as $cid) {
        $debe  = (float)($totales[$cid]['total_debe']  ?? 0);
        $haber = (float)($totales[$cid]['total_haber'] ?? 0);
        $saldo = (($naturalezas[$cid] ?? 'deudora') === 'deudora')
            ? $debe - $haber
            : $haber - $debe;
        $upd->execute([':s' => round($saldo, 2), ':id' => $cid]);
    }
}
