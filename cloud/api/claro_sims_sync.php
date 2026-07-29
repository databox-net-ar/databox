<?php
// api/claro_sims_sync.php
// Recibe el inventario de SIMs Claro desde el agente externo `openclaw`, que
// se encarga de loguearse en https://iotgestion.claro.com.ar/ (el portal esta
// detras de un WAF con fingerprint dinamico que corta cualquier scraper HTTP
// puro, ver notas en el commit) y exporta un CSV con las lineas. Hace UPSERT
// sobre la tabla `claro_sims` por ICCID (UNIQUE KEY uk_claro_sims_icc).
// Idempotente.
//
// Consume:
//   POST api/claro_sims_sync.php
//     Content-Type: text/csv                          (body = archivo CSV)
//     Content-Type: multipart/form-data; boundary=...  (field `csv` o `file`)
//
// Auth:
//   Header `Authorization: Bearer <apikey>` donde <apikey> es una fila de la
//   tabla `aplicaciones` con `habilitada = '1'`. Se incrementa `usos` en cada
//   corrida exitosa (para ver actividad desde el ABM de aplicaciones).
//
// CSV esperado (encabezado en la primer linea, orden libre, se matchea por
// nombre de columna). Ejemplo (openclaw v1):
//   iccid,imsi,msisdn,plan,estado,tecnologia,fechaActivacion,consumo,etiquetas,notasDeLinea
//   8954312212097818037,722310079781803,5492646176179,M2M60,ACTIVO,2G 3G 4G NB CAT-M,2021-10-29T09:42:30Z,0 MB
//
// Columnas que se leen:
//   iccid   -> icc           (clave UPSERT, obligatoria)
//   msisdn  -> msisdn
//   estado  -> estado        (normalizado a "Activada"/"Desactivada"/... para que
//                             coincida con la stats query de claro_sims.php)
//   msisdn  -> linea         (derivado: se le quita el prefijo "549" si esta, para
//                             dejar el numero corto que muestra el portal)
//   consumo* / trafico* -> consumo_datos
//       string tal cual reporta el portal (ej. "0 MB"). openclaw ha cambiado
//       el nombre exacto de la columna varias veces (consumo, consumoMB,
//       trafico, traficoMB, ...); matcheamos por prefijo para no romper
//       cuando cambia.
//
// Campos NO tocados por el sync (se preservan valores editados a mano en el
// ABM): nombre, alias, imei, limite_datos, estado_gprs, estado_lte. openclaw
// no los provee y sobreescribirlos con NULL borraria trabajo del operador.
//
// Respuesta:
//   200 {ok:true, data:{fetched, insertados, actualizados, sin_icc,
//                       filas_csv, duracion_ms, ultima_sync, aplicacion}}
//   400 CSV mal formado / sin filas
//   401 Bearer ausente / apikey desconocida / aplicacion deshabilitada
//   405 metodo != POST
//   500 error de DB u otros

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/lib/apikey_auth.php';
require_once __DIR__ . '/lib/sucesos.php';

header('Content-Type: application/json; charset=utf-8');

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
if ($method !== 'POST') jsonError('Metodo no soportado', 405);

try {
    $app = requireAppApikey();

    $t0     = microtime(true);
    $pdo    = db();
    $csv    = readCsvBody();
    $stats  = importClaroSimsCsv($pdo, $csv);
    $stats['duracion_ms'] = (int) round((microtime(true) - $t0) * 1000);
    $stats['aplicacion']  = ['id' => (int)$app['id'], 'nombre' => (string)$app['nombre']];

    // Suceso 'alerta' cuando aparecen SIMs desaparecidas del origen: preserva
    // en el Visor de sucesos el detalle (lista de ICCs) para que quede
    // auditable. El sync de Claro solo se ejecuta cuando openclaw postea el
    // CSV completo del portal, asi que un "ausente" es una linea que el
    // portal ya no lista.
    $ausentesNuevos = (int)($stats['ausentes_nuevos'] ?? 0);
    if ($ausentesNuevos > 0) {
        $iccs = array_slice((array)($stats['ausentes_iccs'] ?? []), 0, 500);
        $detalle = "Claro: {$ausentesNuevos} SIMs no aparecieron en el CSV del origen y quedaron marcadas como Ausente (no se eliminaron).\n"
                 . "ICCs:\n" . implode("\n", $iccs);
        registrarSuceso($pdo, 'api/claro_sims_sync', 'alerta', $detalle);
    }

    jsonOk($stats);
} catch (Throwable $e) {
    jsonError($e->getMessage(), 500);
}

// ----------------------------------------------------------------------------
// Lectura del CSV: raw body (text/csv) o multipart file upload (`csv`|`file`).
// ----------------------------------------------------------------------------

function readCsvBody(): array {
    $raw = '';
    foreach (['csv', 'file', 'archivo'] as $field) {
        if (!empty($_FILES[$field]['tmp_name']) && is_uploaded_file($_FILES[$field]['tmp_name'])) {
            $raw = (string) file_get_contents($_FILES[$field]['tmp_name']);
            break;
        }
    }
    if ($raw === '') {
        $raw = (string) file_get_contents('php://input');
    }
    if ($raw === '') jsonError('Body vacio (se esperaba CSV)', 400);

    // UTF-8 BOM (Excel/openclaw lo suelen agregar): descartar para que no
    // contamine el nombre de la primer columna del header.
    if (str_starts_with($raw, "\xEF\xBB\xBF")) $raw = substr($raw, 3);

    // Parseo con SplTempFileObject para que fgetcsv maneje newlines dentro
    // de campos encomillados (por si alguna nota trae saltos de linea).
    $tmp = new SplTempFileObject();
    $tmp->fwrite($raw);
    $tmp->rewind();
    $tmp->setFlags(SplFileObject::READ_CSV | SplFileObject::SKIP_EMPTY | SplFileObject::READ_AHEAD);

    $rows = [];
    foreach ($tmp as $row) {
        if ($row === false || $row === [null]) continue;
        $rows[] = $row;
    }
    if (count($rows) < 2) jsonError('CSV sin filas de datos', 400);

    $header = array_map(fn($c) => strtolower(trim((string)$c)), $rows[0]);
    $idx    = array_flip($header); // nombre -> indice
    if (!isset($idx['iccid'])) jsonError('CSV sin columna `iccid`', 400);

    return ['idx' => $idx, 'rows' => array_slice($rows, 1)];
}

// ----------------------------------------------------------------------------
// Import: UPSERT en claro_sims por icc.
// ----------------------------------------------------------------------------

function importClaroSimsCsv(PDO $pdo, array $csv): array {
    $idx  = $csv['idx'];
    $rows = $csv['rows'];

    $fetched      = 0;
    $insertados   = 0;
    $actualizados = 0;
    $con_trafico  = 0;   // SIMs cuyo consumo cambio -> ultimo_trafico marcado
    $sinIcc       = 0;

    // Referencia para detectar SIMs "desaparecidas" del portal (ver bloque
    // post-import). Se captura ANTES del primer UPSERT porque el UPSERT
    // pisa `actualizado = NOW()`; toda fila cuya `actualizado` quede menor
    // (o NULL) al final es una linea que el CSV de openclaw no incluyo.
    $syncStartedAt = date('Y-m-d H:i:s');

    // El portal de Claro (iotgestion) no expone la fecha del ultimo trafico
    // por linea, asi que la DERIVAMOS del delta de `consumo_datos` entre esta
    // corrida y la anterior. Reglas:
    //   - SIM existente con consumo distinto al previo -> ultimo_trafico = NOW().
    //   - SIM existente sin cambio -> preservamos el valor previo.
    //   - SIM nueva -> ultimo_trafico = NULL (no hay base para comparar).
    // Caveat: el reset del contador (por ej. "500 MB" -> "0 MB" a fin de ciclo)
    // tambien cuenta como delta y marca `ultimo_trafico`. Preferimos ese falso
    // positivo mensual a perder los positivos verdaderos.
    $lookup = $pdo->prepare("SELECT consumo_datos, ultimo_trafico FROM claro_sims WHERE icc = :icc");
    // UPSERT: solo los campos que openclaw provee. `nombre`, `alias`, `imei`,
    // `limite_datos`, `estado_gprs` y `estado_lte` quedan intactos en el
    // UPDATE para no pisar ediciones manuales del ABM.
    $upsert = $pdo->prepare("
        INSERT INTO claro_sims
            (linea, icc, estado, consumo_datos, msisdn, actualizado, ultimo_trafico)
        VALUES
            (:linea, :icc, :estado, :consumo_datos, :msisdn, NOW(), :ultimo_trafico)
        ON DUPLICATE KEY UPDATE
            linea          = VALUES(linea),
            estado         = VALUES(estado),
            consumo_datos  = VALUES(consumo_datos),
            msisdn         = VALUES(msisdn),
            actualizado    = VALUES(actualizado),
            ultimo_trafico = VALUES(ultimo_trafico)
    ");

    foreach ($rows as $row) {
        $p = mapClaroCsvRow($row, $idx);
        if ($p[':icc'] === null) { $sinIcc++; continue; }

        $lookup->execute([':icc' => $p[':icc']]);
        $prev = $lookup->fetch(PDO::FETCH_ASSOC);
        $existente = $prev !== false;
        if ($existente) {
            $prevConsumo  = (string) ($prev['consumo_datos'] ?? '');
            $nuevoConsumo = (string) ($p[':consumo_datos'] ?? '');
            if ($nuevoConsumo !== $prevConsumo) {
                $p[':ultimo_trafico'] = date('Y-m-d H:i:s');
                $con_trafico++;
            } else {
                $p[':ultimo_trafico'] = $prev['ultimo_trafico'];
            }
        } else {
            $p[':ultimo_trafico'] = null;
        }

        $upsert->execute($p);
        if ($existente) $actualizados++; else $insertados++;
        $fetched++;
    }

    // -----------------------------------------------------------------------
    // Deteccion de SIMs "desaparecidas": las que estaban en la BD pero no
    // vinieron en este CSV (openclaw postea el inventario completo, asi que
    // una ausencia = linea que el portal ya no lista). Se marca
    // `estado = 'Ausente'` (title case coincide con el resto de estados
    // normalizados en normalizeClaroStatus) y nunca se elimina la fila:
    // se preservan `nombre`, `alias`, `imei`, `tags`, `en_uso`, etc. La WHERE
    // filtra por `estado <> 'Ausente'` para que la proxima corrida no vuelva
    // a "descubrirla" en el listado de ausentes nuevos.
    //
    // Solo se corre si se proceso al menos una fila valida — si el CSV vino
    // vacio o todo sin ICC, no marcamos nada para evitar falsos positivos
    // por CSV mal formado.
    $ausentes = [];
    if ($fetched > 0) {
        $sel = $pdo->prepare("
            SELECT icc, linea, estado, actualizado
              FROM claro_sims
             WHERE (actualizado IS NULL OR actualizado < :ini)
               AND (estado IS NULL OR estado <> 'Ausente')
        ");
        $sel->execute([':ini' => $syncStartedAt]);
        $ausentes = $sel->fetchAll(PDO::FETCH_ASSOC) ?: [];

        if (!empty($ausentes)) {
            $upd = $pdo->prepare("
                UPDATE claro_sims
                   SET estado = 'Ausente'
                 WHERE (actualizado IS NULL OR actualizado < :ini)
                   AND (estado IS NULL OR estado <> 'Ausente')
            ");
            $upd->execute([':ini' => $syncStartedAt]);
        }
    }

    return [
        'fetched'         => $fetched,
        'insertados'      => $insertados,
        'actualizados'    => $actualizados,
        'con_trafico'     => $con_trafico,
        'sin_icc'         => $sinIcc,
        'filas_csv'       => count($rows),
        'ausentes_nuevos' => count($ausentes),
        'ausentes_iccs'   => array_map(static fn($r) => (string)$r['icc'], $ausentes),
        'ultima_sync'     => date('Y-m-d H:i:s'),
    ];
}

function mapClaroCsvRow(array $row, array $idx): array {
    $get = static fn(string $col): string => trim((string)($row[$idx[$col] ?? -1] ?? ''));

    $icc     = $get('iccid');
    $msisdn  = $get('msisdn');
    $estado  = $get('estado');

    // Consumo mensual: openclaw viene cambiando el nombre exacto de la
    // columna en el CSV segun la version del scraping (`consumo`, `consumoMB`,
    // `trafico`, `traficoMB`, ...). Como el header se guarda en $idx ya
    // lowercased, buscamos cualquier columna cuyo nombre empiece con
    // "consumo" o "trafico" y usamos la primera con valor no vacio.
    $consumo = '';
    foreach ($idx as $col => $i) {
        if (str_starts_with($col, 'consumo') || str_starts_with($col, 'trafico')) {
            $v = trim((string)($row[$i] ?? ''));
            if ($v !== '') { $consumo = $v; break; }
        }
    }

    // linea: el portal muestra el numero "corto" (sin prefijo 549). En el CSV
    // no viene por separado, asi que lo derivamos del msisdn — a Argentina
    // todas las lineas M2M llegan como 549XXXXXXXXXX. Fallback: usar msisdn
    // tal cual si no matchea el prefijo.
    $linea = $msisdn;
    if ($msisdn !== '' && str_starts_with($msisdn, '549') && strlen($msisdn) >= 12) {
        $linea = substr($msisdn, 3);
    }

    return [
        ':icc'           => $icc     !== '' ? mb_substr($icc,     0, 25) : null,
        ':linea'         => $linea   !== '' ? mb_substr($linea,   0, 30) : null,
        ':msisdn'        => $msisdn  !== '' ? mb_substr($msisdn,  0, 30) : null,
        ':estado'        => $estado  !== '' ? mb_substr(normalizeClaroStatus($estado), 0, 40) : null,
        ':consumo_datos' => $consumo !== '' ? mb_substr($consumo, 0, 40) : null,
    ];
}

function normalizeClaroStatus(string $raw): string {
    return match (strtoupper($raw)) {
        'ACTIVO'         => 'Activada',
        'DESACTIVADO'    => 'Desactivada',
        'RETIRADO'       => 'Retirada',
        'SUSPENDIDO'     => 'Suspendida',
        'PRESUSPENDIDO'  => 'Presuspendida',
        'TEST'           => 'Test',
        'INVENTARIO'     => 'Inventario',
        'NO DISPONIBLE'  => 'No disponible',
        default          => ucfirst(strtolower($raw)),
    };
}
