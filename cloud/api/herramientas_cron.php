<?php
/**
 * API cloud — Herramientas: Editor de cron.
 *
 * Lee y guarda el archivo /etc/cron.d/databox del contenedor, que esta
 * bind-mounteado desde ./robot/crontab (ver docker-compose.yml). Formato
 * /etc/cron.d/ (5 campos de tiempo + user + comando).
 *
 * Cron re-lee /etc/cron.d/* cuando cambia el mtime, asi que basta con
 * escribir el archivo para que las tareas nuevas entren en vigencia en
 * el proximo tick (dentro del minuto).
 *
 *   GET  api/herramientas_cron.php
 *     -> {ok:true, data:{contenido, ruta, tamano, modificado, lineas, activas, env}}
 *
 *   POST api/herramientas_cron.php
 *     body: {"contenido": "..."}
 *     -> {ok:true, data:{tamano, modificado, lineas, activas, warnings}}
 */
ini_set('display_errors', 0);
error_reporting(E_ALL);
header('Content-Type: application/json; charset=utf-8');

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'OPTIONS') { exit; }

require_once __DIR__ . '/lib/auth_check.php';
requireAuth();
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/lib/sucesos.php';

const CRON_ARCHIVO   = '/etc/cron.d/databox';
const CRON_TAM_MAX   = 65536; // 64 KB — el crontab del worker no deberia crecer mas.

/**
 * Cuenta lineas totales y "activas" (no vacias, no comentario, no VAR=value).
 * Devuelve tambien un array de warnings blandos: cada advertencia describe una
 * linea que parece mal formada. No bloqueamos el guardado por warnings — cron
 * ya se queja solo en syslog si algo esta roto, y el usuario ve las tareas
 * fallar. Pero si podemos hacerselo notar de una, mejor.
 */
function analizarCrontab(string $contenido): array {
    $lineas   = 0;
    $activas  = 0;
    $warnings = [];

    $lns = preg_split('/\r\n|\n|\r/', $contenido) ?: [];
    foreach ($lns as $i => $ln) {
        $lineas++;
        $trim = trim($ln);
        if ($trim === '') continue;
        if ($trim[0] === '#') continue;
        // Asignacion de entorno tipo "SHELL=/bin/sh".
        if (preg_match('/^[A-Z_][A-Z0-9_]*\s*=/', $trim)) continue;

        $activas++;

        // Chequeo blando de formato /etc/cron.d/ (min hora dom mes dow user cmd).
        $tokens = preg_split('/\s+/', $trim) ?: [];
        $usaAtajo = isset($tokens[0]) && $tokens[0][0] === '@';
        $minTokens = $usaAtajo ? 3 : 7;
        if (count($tokens) < $minTokens) {
            $warnings[] = [
                'linea'   => $i + 1,
                'mensaje' => $usaAtajo
                    ? 'La linea usa un atajo (@' . ltrim($tokens[0], '@') . ') pero le faltan campos: se esperan USUARIO COMANDO despues.'
                    : 'La linea tiene ' . count($tokens) . ' campos pero cron.d requiere al menos 7 (min hora dom mes dow USUARIO COMANDO).',
                'texto'   => $trim,
            ];
        }
    }

    return ['lineas' => $lineas, 'activas' => $activas, 'warnings' => $warnings];
}

function metadataArchivo(string $ruta): array {
    if (!is_file($ruta)) {
        return ['tamano' => 0, 'modificado' => null];
    }
    return [
        'tamano'     => (int)@filesize($ruta),
        'modificado' => @date('Y-m-d H:i:s', (int)@filemtime($ruta)),
    ];
}

try {
    $metodo = $_SERVER['REQUEST_METHOD'] ?? 'GET';

    if ($metodo === 'GET') {
        if (!is_file(CRON_ARCHIVO)) {
            // El bind-mount no esta armado (contenedor viejo, o falta el archivo
            // en ./robot/crontab). Devolvemos vacio con un warning explicito para
            // que la UI muestre algo util.
            jsonOk([
                'contenido'   => '',
                'ruta'        => CRON_ARCHIVO,
                'tamano'      => 0,
                'modificado'  => null,
                'lineas'      => 0,
                'activas'     => 0,
                'warnings'    => [[
                    'linea'   => 0,
                    'mensaje' => 'El archivo ' . CRON_ARCHIVO . ' no existe. Verifica el bind-mount de docker-compose.yml.',
                    'texto'   => '',
                ]],
                'env'         => getenv('APP_ENV') ?: 'unknown',
            ]);
        }
        $contenido = (string)@file_get_contents(CRON_ARCHIVO);
        $an        = analizarCrontab($contenido);
        $meta      = metadataArchivo(CRON_ARCHIVO);
        jsonOk([
            'contenido'   => $contenido,
            'ruta'        => CRON_ARCHIVO,
            'tamano'      => $meta['tamano'],
            'modificado'  => $meta['modificado'],
            'lineas'      => $an['lineas'],
            'activas'     => $an['activas'],
            'warnings'    => $an['warnings'],
            'env'         => getenv('APP_ENV') ?: 'unknown',
        ]);
    }

    if ($metodo === 'POST') {
        $in = readJsonBody();
        if (!array_key_exists('contenido', $in)) {
            jsonError('Falta el campo "contenido".', 400);
        }
        $contenido = (string)$in['contenido'];

        // Normalizacion:
        //  - Convertimos CRLF/CR a LF (el archivo termina en un FS Linux).
        //  - Aseguramos newline final (cron ignora la ultima linea sin \n).
        $contenido = preg_replace("/\r\n|\r/", "\n", $contenido) ?? $contenido;
        if ($contenido === '' || substr($contenido, -1) !== "\n") {
            $contenido .= "\n";
        }

        if (strlen($contenido) > CRON_TAM_MAX) {
            jsonError('El crontab supera el tamano maximo (' . CRON_TAM_MAX . ' bytes).', 400);
        }

        $an = analizarCrontab($contenido);

        if (!is_file(CRON_ARCHIVO)) {
            jsonError('El archivo ' . CRON_ARCHIVO . ' no existe. Verifica el bind-mount de docker-compose.yml.', 500);
        }

        // Escritura atomica: escribimos a un tmp en /tmp y renombramos.
        // Si escribieramos directo con file_put_contents perderiamos la
        // ownership/permisos que le seteo el entrypoint (root:www-data 664),
        // porque PHP no puede volver a chown root. Con rename() a un archivo
        // bind-mounted... tampoco funcionaria (rename cruzando "filesystems"
        // en un bind-mount de un solo archivo cae en EXDEV). Asi que
        // simplemente sobreescribimos en el lugar — file_put_contents en un
        // archivo existente hace open(O_TRUNC), preserva owner/perms.
        $bytes = @file_put_contents(CRON_ARCHIVO, $contenido);
        if ($bytes === false) {
            jsonError('No se pudo escribir ' . CRON_ARCHIVO . '. Revisa permisos (deberia ser root:www-data 664).', 500);
        }

        $meta = metadataArchivo(CRON_ARCHIVO);

        try {
            registrarSuceso(db(), 'Editor de cron', 'info',
                "Crontab actualizado: {$an['activas']} tarea" . ($an['activas'] === 1 ? '' : 's') .
                " activa" . ($an['activas'] === 1 ? '' : 's') . " ({$meta['tamano']} B, " .
                count($an['warnings']) . " warning" . (count($an['warnings']) === 1 ? '' : 's') . ").");
        } catch (Throwable $_) { /* no romper el flujo */ }

        jsonOk([
            'tamano'     => $meta['tamano'],
            'modificado' => $meta['modificado'],
            'lineas'     => $an['lineas'],
            'activas'    => $an['activas'],
            'warnings'   => $an['warnings'],
        ]);
    }

    jsonError('Metodo no soportado', 405);
} catch (Throwable $e) {
    jsonError($e->getMessage(), 500);
}
