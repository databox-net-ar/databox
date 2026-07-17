<?php
/**
 * api/lib/clarosims_sync_pedido.php
 * Nucleo compartido entre el endpoint api/clarosims_sync_pedido.php y el job
 * cloud/jobs/clarosims_actualizar.php. Coordina el pedido de sincronizacion
 * de SIMs Claro con el agente externo `openclaw` a traves de dos parametros:
 *
 *   pedido_clarosims_sincronizar         "0" | "1"
 *   pedido_clarosims_sincronizar_marcado "YYYY-MM-DD HH:MM:SS" (informativo)
 *
 * El panel (o el job) marca la bandera; openclaw pollea y la consume.
 */

const CLAROSIMS_FLAG    = 'pedido_clarosims_sincronizar';
const CLAROSIMS_FLAG_TS = 'pedido_clarosims_sincronizar_marcado';

/**
 * Marca el flag en "1" y registra el timestamp. Idempotente: si ya estaba
 * en "1", devuelve ya_pendiente=true y NO pisa el timestamp — asi
 * conservamos el momento del pedido original que openclaw todavia no
 * consumio.
 */
function marcarPedidoSyncClaro(PDO $pdo): array {
    $existente = leerParametroFlag($pdo, CLAROSIMS_FLAG);
    if ($existente === '1') {
        return [
            'pedido'       => true,
            'marcado_en'   => leerParametroFlag($pdo, CLAROSIMS_FLAG_TS),
            'ya_pendiente' => true,
        ];
    }
    $now = date('Y-m-d H:i:s');
    setParametroFlag(
        $pdo,
        CLAROSIMS_FLAG,
        '1',
        'Cuando =1, openclaw scrapea el portal de Claro y postea el CSV a api/clarosims_sync.php. '
            . 'Se resetea a 0 cuando openclaw consume el pedido. Ver api/clarosims_sync_pedido.php.'
    );
    setParametroFlag(
        $pdo,
        CLAROSIMS_FLAG_TS,
        $now,
        'Timestamp del ultimo pedido en pedido_clarosims_sincronizar. Solo informativo.'
    );
    return ['pedido' => true, 'marcado_en' => $now, 'ya_pendiente' => false];
}

/**
 * Devuelve pedido=true SOLO cuando encontramos "1" y ya lo dejamos en "0".
 * Cualquier poll posterior (mientras el panel no vuelva a apretar) devuelve
 * pedido=false. `parametros` es MyISAM (ver schema.sql), por lo que no hay
 * transacciones reales — la ventana de carrera es de 1-2ms y aceptable para
 * un pedido manual apretado por un humano.
 */
function consumirPedidoSyncClaro(PDO $pdo): array {
    $val = leerParametroFlag($pdo, CLAROSIMS_FLAG);
    if ($val !== '1') {
        return ['pedido' => false, 'marcado_en' => null];
    }
    $ts = leerParametroFlag($pdo, CLAROSIMS_FLAG_TS);
    setParametroFlag($pdo, CLAROSIMS_FLAG, '0');
    return ['pedido' => true, 'marcado_en' => $ts];
}

function leerParametroFlag(PDO $pdo, string $variable): ?string {
    $st = $pdo->prepare("SELECT valor FROM parametros WHERE variable = :v LIMIT 1");
    $st->execute([':v' => $variable]);
    $v = $st->fetchColumn();
    return $v === false ? null : (string)$v;
}

// Upsert manual: `parametros` no tiene UNIQUE en `variable` (compartida con
// otras apps que la tienen sin constraint), asi que hacemos SELECT + branch.
// El comentario solo se persiste al INSERT — updates posteriores no lo pisan.
function setParametroFlag(PDO $pdo, string $variable, string $valor, ?string $comentario = null): void {
    $existe = $pdo->prepare("SELECT id FROM parametros WHERE variable = :v LIMIT 1");
    $existe->execute([':v' => $variable]);
    $id = $existe->fetchColumn();
    if ($id) {
        $pdo->prepare("UPDATE parametros SET valor = :val WHERE id = :id")
            ->execute([':val' => $valor, ':id' => (int)$id]);
    } else {
        $pdo->prepare("INSERT INTO parametros (variable, valor, comentario) VALUES (:v, :val, :c)")
            ->execute([':v' => $variable, ':val' => $valor, ':c' => $comentario]);
    }
}
