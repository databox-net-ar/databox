<?php
/**
 * api/lib/sucesos.php
 * Helper para escribir en la tabla `sucesos` (log de actividad).
 * El endpoint api/sucesos.php es read-only; el resto de modulos
 * usan esta funcion para dejar constancia de eventos.
 */

function registrarSuceso(PDO $pdo, string $origen, string $tipo, string $detalle): void {
    $tiposValidos = ['info', 'error', 'alerta'];
    if (!in_array($tipo, $tiposValidos, true)) $tipo = 'info';
    try {
        $stmt = $pdo->prepare(
            'INSERT INTO sucesos (fecha, origen, tipo, detalle) VALUES (NOW(), :o, :t, :d)'
        );
        $stmt->execute([
            ':o' => substr($origen, 0, 50),
            ':t' => $tipo,
            ':d' => $detalle,
        ]);
    } catch (Throwable $_) {
        // Si falla el log no queremos que rompa el flujo principal.
    }
}
