<?php
/**
 * API cloud — Herramientas: Sincronizador de tablas (ejecutar).
 *
 * Copia una tabla completa desde un entorno a otro (dev <-> prod)
 * preservando los IDs de origen. Si la tabla no existe en destino,
 * se crea con SHOW CREATE TABLE del origen. Si existe, se vacia
 * (TRUNCATE) antes de insertar para poder respetar los IDs.
 *
 * Streamea el progreso en tiempo real via Server-Sent Events (SSE),
 * emitiendo un evento por linea del "log terminal" del frontend.
 *
 *   GET api/herramientas_sincronizador_run.php?origen=dev|prod&destino=dev|prod&tabla=<nombre>
 *
 * Formato de evento:
 *   data: {"type":"info|warn|error|success|done","msg":"..."}\n\n
 *
 * Requiere APP_ENV=development (403 en produccion).
 */
ini_set('display_errors', 0);
error_reporting(E_ALL);

require_once __DIR__ . '/lib/auth_check.php';
requireAuth();
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/lib/sincronizador.php';

// Chequeo de dev antes de setear headers SSE.
sincronizadorAssertDev();

// --- Setup SSE ---
// Desactivar cualquier buffer intermedio para que cada echo llegue al navegador
// en el momento (Apache + PHP-FPM tienden a bufferear salidas cortas).
@ini_set('zlib.output_compression', '0');
@ini_set('implicit_flush', '1');
while (ob_get_level() > 0) { @ob_end_clean(); }
ob_implicit_flush(true);
set_time_limit(0);
ignore_user_abort(false);

header('Content-Type: text/event-stream; charset=utf-8');
header('Cache-Control: no-cache, no-store, must-revalidate');
header('X-Accel-Buffering: no'); // hint para nginx en prod (no aplica aca pero es barato)
header('Connection: keep-alive');

function sseEvent(string $type, string $msg, array $extra = []): void {
    $payload = array_merge(['type' => $type, 'msg' => $msg], $extra);
    echo 'data: ' . json_encode($payload, JSON_UNESCAPED_UNICODE) . "\n\n";
    @flush();
}

$origen  = strtolower(trim((string)($_GET['origen']  ?? '')));
$destino = strtolower(trim((string)($_GET['destino'] ?? '')));
$tabla   = trim((string)($_GET['tabla'] ?? ''));

if (($origen !== 'dev' && $origen !== 'prod')
 || ($destino !== 'dev' && $destino !== 'prod')) {
    sseEvent('error', 'Parametros "origen" / "destino" invalidos (usar dev o prod).');
    sseEvent('done',  'Abortado.', ['ok' => false]);
    exit;
}
if ($origen === $destino) {
    sseEvent('error', 'Origen y destino no pueden ser el mismo entorno.');
    sseEvent('done',  'Abortado.', ['ok' => false]);
    exit;
}
if (!sincronizadorNombreTablaValido($tabla)) {
    sseEvent('error', 'Nombre de tabla invalido.');
    sseEvent('done',  'Abortado.', ['ok' => false]);
    exit;
}

$tOrigen  = sincronizadorEntorno($origen);
$tDestino = sincronizadorEntorno($destino);

sseEvent('info', '========================================');
sseEvent('info', 'Sincronizador de tablas');
sseEvent('info', '========================================');
sseEvent('info', "Tabla:    {$tabla}");
sseEvent('info', "Origen:   {$origen}  ({$tOrigen['host']} / {$tOrigen['database']})");
sseEvent('info', "Destino:  {$destino}  ({$tDestino['host']} / {$tDestino['database']})");
sseEvent('info', '----------------------------------------');

try {
    // --- Conexiones ---
    sseEvent('info', "Conectando al origen ({$origen})...");
    $pdoSrc = sincronizadorPdo($origen);
    sseEvent('info', "Conexion con origen OK.");

    sseEvent('info', "Conectando al destino ({$destino})...");
    $pdoDst = sincronizadorPdo($destino);
    sseEvent('info', "Conexion con destino OK.");

    // --- Existencia de la tabla en origen ---
    $existSrc = $pdoSrc->prepare(
        "SELECT COUNT(*) FROM INFORMATION_SCHEMA.TABLES
          WHERE TABLE_SCHEMA = :db AND TABLE_NAME = :t AND TABLE_TYPE = 'BASE TABLE'"
    );
    $existSrc->execute([':db' => $tOrigen['database'], ':t' => $tabla]);
    if ((int)$existSrc->fetchColumn() === 0) {
        sseEvent('error', "La tabla `{$tabla}` no existe en el origen.");
        sseEvent('done',  'Abortado.', ['ok' => false]);
        exit;
    }

    // --- Contar filas de origen (para reportar progreso) ---
    sseEvent('info', "Contando filas en origen...");
    $totalRows = (int)$pdoSrc->query("SELECT COUNT(*) FROM `{$tabla}`")->fetchColumn();
    sseEvent('info', "Filas en origen: " . number_format($totalRows, 0, ',', '.'));

    // --- Asegurar tabla en destino ---
    $existDst = $pdoDst->prepare(
        "SELECT COUNT(*) FROM INFORMATION_SCHEMA.TABLES
          WHERE TABLE_SCHEMA = :db AND TABLE_NAME = :t AND TABLE_TYPE = 'BASE TABLE'"
    );
    $existDst->execute([':db' => $tDestino['database'], ':t' => $tabla]);
    $existeDestino = ((int)$existDst->fetchColumn() > 0);

    if (!$existeDestino) {
        sseEvent('info', "La tabla no existe en destino. Creandola desde el DDL de origen...");
        $ddlRow = $pdoSrc->query("SHOW CREATE TABLE `{$tabla}`")->fetch(PDO::FETCH_NUM);
        if (!$ddlRow || !isset($ddlRow[1])) {
            throw new RuntimeException("No se pudo obtener SHOW CREATE TABLE de `{$tabla}` en origen.");
        }
        $ddl = (string)$ddlRow[1];
        $pdoDst->exec($ddl);
        sseEvent('success', "Tabla `{$tabla}` creada en destino.");
    } else {
        sseEvent('info', "La tabla existe en destino. Vaciandola para preservar los IDs...");
        $pdoDst->exec('SET FOREIGN_KEY_CHECKS = 0');
        $pdoDst->exec("TRUNCATE TABLE `{$tabla}`");
        sseEvent('success', "Tabla destino vaciada (TRUNCATE).");
    }

    if ($totalRows === 0) {
        sseEvent('warn', "El origen esta vacio: no hay filas para copiar.");
        sseEvent('done', 'Sincronizacion completada (0 filas).', ['ok' => true, 'copiadas' => 0]);
        exit;
    }

    // --- Copia por lotes ---
    // Obtenemos los nombres de columnas via SHOW COLUMNS para armar el INSERT.
    $cols = [];
    $colsStmt = $pdoSrc->query("SHOW COLUMNS FROM `{$tabla}`");
    foreach ($colsStmt->fetchAll() as $r) $cols[] = $r['Field'];
    if (!$cols) {
        throw new RuntimeException("No se pudieron leer las columnas de `{$tabla}`.");
    }

    $colsSql   = implode(', ', array_map(fn($c) => "`{$c}`", $cols));
    $rowPh     = '(' . implode(',', array_fill(0, count($cols), '?')) . ')';

    // Bajamos las FK checks del destino durante la copia (algunas tablas
    // referencian a otras que aun no fueron sincronizadas).
    $pdoDst->exec('SET FOREIGN_KEY_CHECKS = 0');
    $pdoDst->exec('SET UNIQUE_CHECKS = 0');

    $batchSize   = 200;
    $copiadas    = 0;
    $errores     = 0;

    // Iteramos con cursor no-buffered para no cargar millones de filas en RAM.
    $selectSql = "SELECT {$colsSql} FROM `{$tabla}`";
    $sel = $pdoSrc->prepare($selectSql);
    $sel->execute();

    $batch = [];
    while (($row = $sel->fetch(PDO::FETCH_NUM)) !== false) {
        $batch[] = $row;
        if (count($batch) >= $batchSize) {
            [$ok, $err] = sincronizadorFlushBatch($pdoDst, $tabla, $colsSql, $rowPh, $batch);
            $copiadas += $ok;
            $errores  += $err;
            if ($err > 0) {
                sseEvent('warn', "Lote con {$err} fila(s) que fallaron individualmente.");
            }
            $batch = [];
            sseEvent('info', "Progreso: " . number_format($copiadas, 0, ',', '.')
                . " / " . number_format($totalRows, 0, ',', '.') . " filas copiadas.");
        }
    }
    if (!empty($batch)) {
        [$ok, $err] = sincronizadorFlushBatch($pdoDst, $tabla, $colsSql, $rowPh, $batch);
        $copiadas += $ok;
        $errores  += $err;
        if ($err > 0) sseEvent('warn', "Lote final con {$err} fila(s) que fallaron individualmente.");
        sseEvent('info', "Progreso: " . number_format($copiadas, 0, ',', '.')
            . " / " . number_format($totalRows, 0, ',', '.') . " filas copiadas.");
    }

    $pdoDst->exec('SET UNIQUE_CHECKS = 1');
    $pdoDst->exec('SET FOREIGN_KEY_CHECKS = 1');

    // Reajustar AUTO_INCREMENT del destino al maximo id + 1 (si aplica).
    try {
        $maxId = $pdoDst->query("SELECT MAX(id) FROM `{$tabla}`")->fetchColumn();
        if ($maxId !== false && $maxId !== null) {
            $pdoDst->exec("ALTER TABLE `{$tabla}` AUTO_INCREMENT = " . ((int)$maxId + 1));
            sseEvent('info', "AUTO_INCREMENT ajustado a " . ((int)$maxId + 1) . ".");
        }
    } catch (Throwable $e) {
        // La tabla puede no tener columna `id` — no es un error.
    }

    sseEvent('info', '----------------------------------------');
    if ($errores > 0) {
        sseEvent('warn', "Sincronizacion completa con {$errores} fila(s) fallidas de {$totalRows}.");
    } else {
        sseEvent('success', "Sincronizacion completa: {$copiadas} / {$totalRows} filas copiadas.");
    }
    sseEvent('done', 'Fin.', ['ok' => true, 'copiadas' => $copiadas, 'errores' => $errores]);

} catch (Throwable $e) {
    sseEvent('error', 'Error fatal: ' . $e->getMessage());
    sseEvent('done',  'Abortado por error.', ['ok' => false]);
}

/**
 * Inserta un lote en destino. Si el INSERT masivo falla, cae a INSERT
 * fila-por-fila para aislar la(s) fila(s) rota(s) y reportar el error
 * puntual sin abortar la corrida completa. Devuelve [ok_count, err_count].
 */
function sincronizadorFlushBatch(PDO $pdoDst, string $tabla, string $colsSql, string $rowPh, array $batch): array {
    $n = count($batch);
    if ($n === 0) return [0, 0];

    $sql    = "INSERT INTO `{$tabla}` ({$colsSql}) VALUES " . implode(',', array_fill(0, $n, $rowPh));
    $params = [];
    foreach ($batch as $row) foreach ($row as $v) $params[] = $v;

    try {
        $st = $pdoDst->prepare($sql);
        $st->execute($params);
        return [$n, 0];
    } catch (Throwable $eBatch) {
        // Reintentar fila por fila
        $ok = 0; $err = 0;
        $sqlOne = "INSERT INTO `{$tabla}` ({$colsSql}) VALUES {$rowPh}";
        foreach ($batch as $idx => $row) {
            try {
                $stOne = $pdoDst->prepare($sqlOne);
                $stOne->execute($row);
                $ok++;
            } catch (Throwable $eRow) {
                $err++;
                $firstCol = $row[0] ?? '?';
                sseEvent('error', "Fila (id={$firstCol}) fallo: " . $eRow->getMessage());
            }
        }
        return [$ok, $err];
    }
}
