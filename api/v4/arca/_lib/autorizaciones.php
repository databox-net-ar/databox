<?php
// api/v4/arca/_lib/autorizaciones.php
// Helper para persistir una fila en `arca_autorizaciones` por cada intento de
// FECAESolicitar. Lo llama autorizar.php una vez al terminar, con exito o
// fallo. Es best-effort: si la insercion falla no queremos romper el flujo
// real (ya autorizamos contra AFIP, seria peor tirar 500 al cliente).
//
// La firma es un array simple para que el endpoint pueda armarlo comodo y
// no tengamos que evolucionar la firma con cada campo nuevo.

declare(strict_types=1);

/**
 * @param array{
 *   aplicacion_id:  int,
 *   empresa_id:     int,
 *   punto:          int,
 *   tipo:           int,
 *   cbte_nro:       int,
 *   resultado:      string,     // 'A' | 'R' | 'error'
 *   cae:            ?string,
 *   cae_vto:        ?string,    // YYYY-MM-DD
 *   errores:        ?string,
 *   duracion_ms:    ?int,
 * } $d
 */
function arcaAutorizacionLog(array $d): void {
    try {
        $pdo = db();
        $st  = $pdo->prepare(
            'INSERT INTO arca_autorizaciones
               (aplicacion_id, empresa_id, punto, tipo, cbte_nro,
                resultado, cae, cae_vto, errores, duracion_ms)
             VALUES
               (:aplicacion_id, :empresa_id, :punto, :tipo, :cbte_nro,
                :resultado, :cae, :cae_vto, :errores, :duracion_ms)'
        );
        $st->execute([
            ':aplicacion_id' => (int)$d['aplicacion_id'],
            ':empresa_id'    => (int)$d['empresa_id'],
            ':punto'         => (int)$d['punto'],
            ':tipo'          => (int)$d['tipo'],
            ':cbte_nro'      => (int)$d['cbte_nro'],
            ':resultado'     => (string)$d['resultado'],
            ':cae'           => $d['cae']         !== null && $d['cae']     !== '' ? (string)$d['cae']     : null,
            ':cae_vto'       => $d['cae_vto']     !== null && $d['cae_vto'] !== '' ? (string)$d['cae_vto'] : null,
            ':errores'       => $d['errores']     !== null && $d['errores'] !== '' ? (string)$d['errores'] : null,
            ':duracion_ms'   => isset($d['duracion_ms']) ? (int)$d['duracion_ms'] : null,
        ]);
    } catch (Throwable $_) {
        // Best-effort: si falla el log tecnico no rompemos el flujo del
        // endpoint. El error de AFIP (si lo hubo) igual queda en `sucesos`
        // via el shutdown handler de log.php.
    }
}
