<?php
/**
 * API cloud — AWS > Servidores: boton "Obtener".
 *
 * Recorre TODAS las cuentas AWS (`aws_cuentas`) que tengan accesskey +
 * secreto, y por cada una:
 *   1) llama DescribeRegions para saber que regiones tiene habilitadas;
 *   2) por cada region habilitada, llama DescribeInstances y trae todas
 *      las EC2 (con paginacion via NextToken);
 *   3) por cada EC2 encontrada, hace UPSERT en `aws_servidores` usando
 *      `instance_id` como clave natural (columna UNIQUE agregada por la
 *      migracion 20260812_1200).
 *
 * Streamea el progreso en tiempo real via Server-Sent Events (SSE),
 * emitiendo un evento por linea del "log terminal" del frontend.
 *
 *   GET api/awsservidores_obtener.php
 *
 * Formato de evento:
 *   data: {"type":"info|warn|error|success|done","msg":"..."}\n\n
 *
 * Requiere permiso `plataformas.aws.servidores.obtener`.
 */
ini_set('display_errors', 0);
error_reporting(E_ALL);

require_once __DIR__ . '/lib/auth_check.php';
requireAuth();
requirePermission('plataformas.aws.servidores.obtener');

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/lib/awsec2.php';

// --- Setup SSE (mismo patron que herramientas_sincronizador_run.php) ---
@ini_set('zlib.output_compression', '0');
@ini_set('implicit_flush', '1');
while (ob_get_level() > 0) { @ob_end_clean(); }
ob_implicit_flush(true);
set_time_limit(0);
ignore_user_abort(false);

header('Content-Type: text/event-stream; charset=utf-8');
header('Cache-Control: no-cache, no-store, must-revalidate');
header('X-Accel-Buffering: no');
header('Connection: keep-alive');

function sseEvent(string $type, string $msg, array $extra = []): void {
    $payload = array_merge(['type' => $type, 'msg' => $msg], $extra);
    echo 'data: ' . json_encode($payload, JSON_UNESCAPED_UNICODE) . "\n\n";
    @flush();
}

try {
    $pdo = db();

    sseEvent('info', '========================================');
    sseEvent('info', 'Obtener servidores EC2 desde AWS');
    sseEvent('info', '========================================');

    // --- Cargar cuentas con credenciales ---
    $cuentas = $pdo->query(
        "SELECT id, nombre, accesskey, secreto
           FROM aws_cuentas
          WHERE accesskey IS NOT NULL AND accesskey <> ''
            AND secreto   IS NOT NULL AND secreto   <> ''
          ORDER BY nombre, id"
    )->fetchAll();

    if (!$cuentas) {
        sseEvent('warn', 'No hay cuentas AWS con accesskey + secreto configurados.');
        sseEvent('done', 'Fin (sin cuentas para consultar).', ['ok' => true]);
        exit;
    }

    sseEvent('info', 'Cuentas AWS a procesar: ' . count($cuentas));

    // Preparo el UPSERT una sola vez.
    // Uso INSERT ... ON DUPLICATE KEY UPDATE contra la UNIQUE `instance_id`.
    // Solo pisamos los campos que vienen de AWS (host/ip/region/tipo/estado_ec2/
    // sistema_operativo/cuenta_id y el `nombre` si no habia). Notas / SSH los
    // respetamos si el operador ya los edito a mano.
    // `sistema_operativo` se pisa solo si AWS devolvio algo (platformDetails);
    // si vino vacio, respetamos el que haya cargado el operador a mano.
    // `existe` se fuerza a '1' porque toda instancia que aparece en este
    // upsert esta viva en AWS. Los que no aparecen se marcan a '0' mas
    // abajo, por cuenta, despues de procesarla completa.
    $upsert = $pdo->prepare("
        INSERT INTO aws_servidores
            (instance_id, nombre, host, ip, region, tipo_instancia,
             estado_ec2, sistema_operativo, cpu, memoria, almacenamiento,
             cuenta_id, existe)
        VALUES
            (:instance_id, :nombre, :host, :ip, :region, :tipo_instancia,
             :estado_ec2, :sistema_operativo, :cpu, :memoria, :almacenamiento,
             :cuenta_id, '1')
        ON DUPLICATE KEY UPDATE
            host              = VALUES(host),
            ip                = VALUES(ip),
            region            = VALUES(region),
            tipo_instancia    = VALUES(tipo_instancia),
            estado_ec2        = VALUES(estado_ec2),
            sistema_operativo = COALESCE(NULLIF(VALUES(sistema_operativo), ''),
                                         aws_servidores.sistema_operativo),
            cpu               = COALESCE(VALUES(cpu),     aws_servidores.cpu),
            memoria           = COALESCE(VALUES(memoria), aws_servidores.memoria),
            almacenamiento    = COALESCE(NULLIF(VALUES(almacenamiento), ''),
                                         aws_servidores.almacenamiento),
            cuenta_id         = VALUES(cuenta_id),
            existe            = '1',
            nombre            = IF(aws_servidores.nombre IS NULL OR aws_servidores.nombre = '',
                                   VALUES(nombre), aws_servidores.nombre),
            -- Forzamos el bump manual del timestamp: ON UPDATE CURRENT_TIMESTAMP
            -- no dispara si el UPDATE resulta ser un no-op (todos los valores
            -- nuevos coinciden con los actuales), lo que dejaba a `actualizado`
            -- frozen cuando AWS no habia cambiado nada desde la sync anterior.
            actualizado       = CURRENT_TIMESTAMP
    ");

    $totalNuevas    = 0;
    $totalActualiz  = 0;
    $totalErrores   = 0;
    $totalEliminad  = 0;  // marcados como existe='0' por no aparecer mas en AWS

    foreach ($cuentas as $cuenta) {
        $cuentaId    = (int)$cuenta['id'];
        $cuentaNom   = (string)$cuenta['nombre'];
        $accessKey   = (string)$cuenta['accesskey'];
        $secretKey   = (string)$cuenta['secreto'];

        sseEvent('info', '----------------------------------------');
        sseEvent('info', "Cuenta: {$cuentaNom} (#{$cuentaId})");

        // Escaneamos unicamente us-east-1: la infraestructura del grupo esta
        // consolidada en esa region y evitamos DescribeRegions (que requiere
        // el permiso ec2:DescribeRegions, no siempre concedido a los IAM
        // users historicos que solo tenian billing-readonly).
        $regiones = ['us-east-1'];

        // Trackeo del sync por cuenta: coleccionamos los instance_ids que
        // AWS devolvio y marcamos si alguna region fallo. Si TODAS las
        // regiones respondieron OK, al final marcamos como existe='0' las
        // filas de esta cuenta que no aparecieron (soft-delete). Si alguna
        // fallo, no marcamos nada para no crear falsos positivos por un
        // hipo transitorio de la API.
        $vistosEnCuenta   = [];
        $cuentaSyncOk     = true;

        foreach ($regiones as $region) {
            $insRes = aws_ec2_describe_instances($accessKey, $secretKey, $region);
            if ($insRes['error'] !== null) {
                $totalErrores++;
                $cuentaSyncOk = false;
                sseEvent('error', "  [{$region}] DescribeInstances fallo: {$insRes['error']}");
                continue;
            }
            $items = $insRes['items'];
            if (!$items) {
                sseEvent('info', "  [{$region}] Sin instancias EC2.");
                continue;
            }

            // Junto todos los volumeIds de la region para resolver tamanios
            // en un solo batch (o pocos batches de 200).
            $allVolIds = [];
            foreach ($items as $ec2) {
                foreach ($ec2['volume_ids'] as $vid) $allVolIds[] = $vid;
            }
            $sizesMap = [];
            if ($allVolIds) {
                $volRes = aws_ec2_describe_volumes($accessKey, $secretKey, $region, $allVolIds);
                if ($volRes['error'] !== null) {
                    sseEvent('warn', "  [{$region}] DescribeVolumes fallo: {$volRes['error']} (se sigue sin almacenamiento).");
                } else {
                    $sizesMap = $volRes['sizes'];
                }
            }

            // Junto todos los instance_types unicos de la region para
            // resolver vCPUs y memoria en un unico batch.
            $allTypes = [];
            foreach ($items as $ec2) {
                if ($ec2['tipo_instancia'] !== '') $allTypes[] = $ec2['tipo_instancia'];
            }
            $typeSpecsMap = [];
            if ($allTypes) {
                $specsRes = aws_ec2_describe_instance_types($accessKey, $secretKey, $region, $allTypes);
                if ($specsRes['error'] !== null) {
                    sseEvent('warn', "  [{$region}] DescribeInstanceTypes fallo: {$specsRes['error']} (se sigue sin cpu/memoria).");
                } else {
                    $typeSpecsMap = $specsRes['specs'];
                }
            }

            // UPSERT una por una para poder loguear resultado por instancia.
            foreach ($items as $ec2) {
                $iid  = $ec2['instance_id'];
                $vistosEnCuenta[] = $iid;
                $host = $ec2['public_dns']  !== '' ? $ec2['public_dns']
                      : ($ec2['private_dns'] !== '' ? $ec2['private_dns'] : null);
                $ip   = $ec2['public_ip']   !== '' ? $ec2['public_ip']
                      : ($ec2['private_ip']  !== '' ? $ec2['private_ip']  : null);
                $nomb = $ec2['name_tag'] !== '' ? $ec2['name_tag'] : $iid;
                $etiqueta = $host ?: ($ip ?: $iid);

                // Formato "8gb + 100gb" con tamanios ordenados ascendente.
                $sizes = [];
                foreach ($ec2['volume_ids'] as $vid) {
                    if (isset($sizesMap[$vid])) $sizes[] = (int)$sizesMap[$vid];
                }
                sort($sizes);
                $almacen = $sizes
                    ? implode(' + ', array_map(fn($n) => $n . 'gb', $sizes))
                    : '';

                // Specs de hardware (vCPUs + memoria en MiB) segun el tipo.
                $spec  = $typeSpecsMap[$ec2['tipo_instancia']] ?? null;
                $cpu   = ($spec && $spec['vcpus']      > 0) ? (int)$spec['vcpus']      : null;
                $memMi = ($spec && $spec['memory_mib'] > 0) ? (int)$spec['memory_mib'] : null;

                try {
                    $upsert->execute([
                        ':instance_id'       => $iid,
                        ':nombre'            => $nomb,
                        ':host'              => $host,
                        ':ip'                => $ip,
                        ':region'            => $region,
                        ':tipo_instancia'    => $ec2['tipo_instancia'] ?: null,
                        ':estado_ec2'        => $ec2['state']          ?: null,
                        ':sistema_operativo' => $ec2['so']             ?: '',
                        ':cpu'               => $cpu,
                        ':memoria'           => $memMi,
                        ':almacenamiento'    => $almacen,
                        ':cuenta_id'         => $cuentaId,
                    ]);
                    // rowCount devuelve 1 en INSERT, 2 en UPDATE (via
                    // ON DUPLICATE KEY UPDATE) en MySQL/MariaDB.
                    if ($upsert->rowCount() === 1) {
                        $totalNuevas++;
                        sseEvent('success', "  [{$region}] {$iid}  {$etiqueta}  (nuevo)");
                    } else {
                        $totalActualiz++;
                        sseEvent('success', "  [{$region}] {$iid}  {$etiqueta}  (actualizado)");
                    }
                } catch (Throwable $e) {
                    $totalErrores++;
                    sseEvent('error', "  [{$region}] {$iid}  {$etiqueta}  BD: " . $e->getMessage());
                }
            }
        }

        // Soft-delete: marcamos existe='0' en las filas de esta cuenta (en
        // las regiones que efectivamente escaneamos) que no aparecieron
        // durante la corrida. Solo lo hacemos si el sync de la cuenta fue
        // OK — si alguna region fallo, dejar existe intacto evita marcar
        // instancias vivas como eliminadas por un error de red.
        if ($cuentaSyncOk) {
            $regionsSql   = "'" . implode("','", array_map('addslashes', $regiones)) . "'";
            $paramsUpd    = [':cid' => $cuentaId];
            $notInClause  = '';
            if (!empty($vistosEnCuenta)) {
                $ph = [];
                foreach (array_values(array_unique($vistosEnCuenta)) as $i => $vid) {
                    $key = ':vid' . $i;
                    $ph[] = $key;
                    $paramsUpd[$key] = $vid;
                }
                $notInClause = ' AND instance_id NOT IN (' . implode(',', $ph) . ')';
            }
            $sqlUpd = "UPDATE aws_servidores
                          SET existe      = '0',
                              actualizado = CURRENT_TIMESTAMP
                        WHERE cuenta_id   = :cid
                          AND region IN ({$regionsSql})
                          AND instance_id IS NOT NULL
                          AND existe <> '0'
                          {$notInClause}";
            $stmtUpd = $pdo->prepare($sqlUpd);
            $stmtUpd->execute($paramsUpd);
            $marcados = (int)$stmtUpd->rowCount();
            if ($marcados > 0) {
                $totalEliminad += $marcados;
                sseEvent('warn', "  Marcadas como eliminadas en AWS: {$marcados}");
            }
        } else {
            sseEvent('warn', "  Sync incompleto: no se marcan filas como eliminadas en AWS.");
        }
    }

    sseEvent('info', '========================================');
    sseEvent('info', "Nuevos:       {$totalNuevas}");
    sseEvent('info', "Actualizados: {$totalActualiz}");
    if ($totalEliminad > 0) {
        sseEvent('warn', "Eliminados:   {$totalEliminad} (ya no aparecen en AWS)");
    } else {
        sseEvent('info', "Eliminados:   0");
    }
    if ($totalErrores > 0) {
        sseEvent('warn', "Errores:      {$totalErrores}");
    } else {
        sseEvent('info', "Errores:      0");
    }
    sseEvent('done', 'Fin.', [
        'ok'           => true,
        'nuevos'       => $totalNuevas,
        'actualizados' => $totalActualiz,
        'eliminados'   => $totalEliminad,
        'errores'      => $totalErrores,
    ]);

} catch (Throwable $e) {
    sseEvent('error', 'Error fatal: ' . $e->getMessage());
    sseEvent('done',  'Abortado por error.', ['ok' => false]);
}
