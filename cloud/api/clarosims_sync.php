<?php
// api/clarosims_sync.php
// Recibe el inventario de SIMs Claro desde el agente externo `openclaw`, que
// se encarga de loguearse en https://iotgestion.claro.com.ar/ (el portal esta
// detras de un WAF con fingerprint dinamico que corta cualquier scraper HTTP
// puro, ver notas en el commit) y exporta un CSV con las lineas. Hace UPSERT
// sobre la tabla `clarosims` por ICCID (UNIQUE KEY uk_clarosims_icc).
// Idempotente.
//
// Consume:
//   POST api/clarosims_sync.php
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
//                             coincida con la stats query de clarosims.php)
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

header('Content-Type: application/json; charset=utf-8');

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
if ($method !== 'POST') jsonError('Metodo no soportado', 405);

try {
    $app = requireAppApikey();

    $t0     = microtime(true);
    $csv    = readCsvBody();
    $stats  = importClaroSimsCsv(db(), $csv);
    $stats['duracion_ms'] = (int) round((microtime(true) - $t0) * 1000);
    $stats['aplicacion']  = ['id' => (int)$app['id'], 'nombre' => (string)$app['nombre']];

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
// Import: UPSERT en clarosims por icc.
// ----------------------------------------------------------------------------

function importClaroSimsCsv(PDO $pdo, array $csv): array {
    $idx  = $csv['idx'];
    $rows = $csv['rows'];

    $fetched      = 0;
    $insertados   = 0;
    $actualizados = 0;
    $sinIcc       = 0;

    $lookup = $pdo->prepare("SELECT id FROM clarosims WHERE icc = :icc");
    // UPSERT: solo los campos que openclaw provee. `nombre`, `alias`, `imei`,
    // `limite_datos`, `estado_gprs` y `estado_lte` quedan intactos en el
    // UPDATE para no pisar ediciones manuales del ABM.
    $upsert = $pdo->prepare("
        INSERT INTO clarosims
            (linea, icc, estado, consumo_datos, msisdn, actualizado)
        VALUES
            (:linea, :icc, :estado, :consumo_datos, :msisdn, NOW())
        ON DUPLICATE KEY UPDATE
            linea         = VALUES(linea),
            estado        = VALUES(estado),
            consumo_datos = VALUES(consumo_datos),
            msisdn        = VALUES(msisdn),
            actualizado   = VALUES(actualizado)
    ");

    foreach ($rows as $row) {
        $p = mapClaroCsvRow($row, $idx);
        if ($p[':icc'] === null) { $sinIcc++; continue; }

        $lookup->execute([':icc' => $p[':icc']]);
        $existente = (bool) $lookup->fetchColumn();

        $upsert->execute($p);
        if ($existente) $actualizados++; else $insertados++;
        $fetched++;
    }

    return [
        'fetched'      => $fetched,
        'insertados'   => $insertados,
        'actualizados' => $actualizados,
        'sin_icc'      => $sinIcc,
        'filas_csv'    => count($rows),
        'ultima_sync'  => date('Y-m-d H:i:s'),
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
