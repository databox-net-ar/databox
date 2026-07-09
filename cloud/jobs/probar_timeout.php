<?php
// Job de prueba: duerme 60 segundos imprimiendo un tick por segundo.
// Sirve para verificar que el handler de SIGTERM del bootstrap corta la
// ejecucion en el momento exacto de `timeout_seg` en vez de esperar al
// watchdog (~1 min de latencia).
//
// Como probarlo:
//   1. Dar de alta una tarea que apunte a este script.
//   2. Setear `timeout_seg = 5` (o el valor que quieras).
//   3. Menu contextual -> Ejecutar ahora.
//   4. Observar en el terminal:
//        - Segundos 1..N: aparecen los ticks en vivo
//        - Segundo N+1 (donde N = timeout_seg): el proceso deberia
//          cortarse, el badge cambiar a "timeout" (amarillo) y la fila
//          quedar como estado='timeout' con exit_code=124.
//   5. Al cerrar el terminal, el listado ya refleja el estado final.
//
// Si el badge queda en "corriendo" > 5s despues de configurar timeout=5,
// el fix del handler SIGTERM (Dockerfile con pcntl + _bootstrap.php) no
// esta activo. Correr `deploy + rebuild` para aplicar.

require_once __DIR__ . '/_bootstrap.php';

try {
    anotarLog('Iniciando job de prueba (60 segundos).');
    for ($i = 1; $i <= 60; $i++) {
        anotarLog('Tick ' . $i . '/60');
        sleep(1);
    }
    anotarLog('Job terminado sin interrupcion.');
    marcarEjecucionOk('completo (60s)');

} catch (Throwable $e) {
    anotarLog('ERROR: ' . $e->getMessage());
    marcarEjecucionError($e);
    throw $e;
}
