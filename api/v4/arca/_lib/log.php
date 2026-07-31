<?php
// api/v4/arca/_lib/log.php
// Logueo automatico de errores del microservicio en la tabla sucesos.
// arcaInitLog(endpoint, context) registra un shutdown handler que corre
// despues del exit de jsonOk o jsonError, captura el output JSON emitido
// y persiste un INSERT en sucesos con tipo error si la respuesta fue
// ok false. Los ok true no se loguean para no ensuciar. Si no hay JSON
// parseable, se intenta con error_get_last para atrapar fatales PHP.
// Los exitos relevantes como CAE emitido en autorizar se loguean
// explicitamente desde el endpoint via arcaLogInfo.

declare(strict_types=1);

require_once dirname(__DIR__, 4) . '/cloud/api/lib/sucesos.php';

function arcaInitLog(string $endpoint, array $context = []): void {
    // Bufferizamos el output para poder inspeccionarlo antes de que salga
    // al cliente. Sin esto, el shutdown_handler no tiene forma de saber
    // que respondio el endpoint.
    ob_start();

    register_shutdown_function(function () use ($endpoint, $context) {
        // Flush primero: garantizamos que el cliente recibe la respuesta
        // aunque el log falle.
        $out = ob_get_clean();
        if ($out !== false && $out !== '') {
            echo $out;
        }

        try {
            $pdo = db();
        } catch (Throwable $_) {
            return; // sin DB no hay donde loguear
        }

        // Prioridad 1: fatal PHP no capturado (respuesta rara o vacia).
        $fatal = error_get_last();
        $esErrorFatal = $fatal !== null
            && in_array($fatal['type'] ?? 0, [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR], true);

        if ($esErrorFatal) {
            $detalle = sprintf(
                "Fatal PHP en /v4/arca/%s: %s en %s:%d",
                $endpoint,
                $fatal['message'] ?? '(sin mensaje)',
                $fatal['file']    ?? '?',
                (int)($fatal['line'] ?? 0)
            );
            if (!empty($context)) {
                $detalle .= ' | ctx=' . arcaLogCompactar($context);
            }
            registrarSuceso($pdo, "arca.{$endpoint}", 'error', substr($detalle, 0, 4000));
            return;
        }

        // Prioridad 2: respuesta JSON con ok:false.
        $decoded = json_decode((string)$out, true);
        if (is_array($decoded) && ($decoded['ok'] ?? true) === false) {
            $err     = (string)($decoded['error'] ?? '(sin mensaje)');
            $detalle = "Endpoint /v4/arca/{$endpoint} respondio error: {$err}";
            if (!empty($context)) {
                $detalle .= ' | ctx=' . arcaLogCompactar($context);
            }
            registrarSuceso($pdo, "arca.{$endpoint}", 'error', substr($detalle, 0, 4000));
        }
    });
}

/**
 * Log EXPLICITO de un evento OK (info). Se usa desde autorizar.php al
 * emitir un CAE, por ejemplo. Se llama ANTES de jsonOk() para que la
 * fila del suceso quede grabada aunque despues falle algo.
 */
function arcaLogInfo(string $endpoint, string $detalle): void {
    try {
        $pdo = db();
        registrarSuceso($pdo, "arca.{$endpoint}", 'info', substr($detalle, 0, 4000));
    } catch (Throwable $_) {
        // Si falla el log no queremos romper el flujo real.
    }
}

/** Compacta un array de contexto a JSON one-line con truncado por si viene grande. */
function arcaLogCompactar(array $ctx): string {
    // Evitar loguear secretos: si vino un Bearer en headers, no llega
    // aca porque el contexto lo arma el endpoint (no incluye headers).
    $json = json_encode($ctx, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if ($json === false) return '(ctx no serializable)';
    return strlen($json) > 800 ? substr($json, 0, 800) . '...' : $json;
}
