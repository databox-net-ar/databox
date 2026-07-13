<?php
/**
 * API cloud — Herramientas: Editar zona horaria (informativo, read-only).
 *
 * Compila un snapshot de la zona horaria efectiva en las distintas capas
 * del stack, para confirmar que todo el monorepo esta alineado:
 *
 *   - Zona horaria de referencia = la del proceso PHP que sirve este panel
 *     (date_default_timezone_get()). Todo lo demas se contrasta contra eso.
 *   - Sistema operativo del contenedor: `/etc/timezone` + envvar `TZ`.
 *   - MySQL: @@global.time_zone, @@session.time_zone, @@system_time_zone,
 *            offset efectivo (NOW() - UTC_TIMESTAMP()) y NOW().
 *   - Otros proyectos del monorepo visibles desde el contenedor: cualquier
 *     subcarpeta bind-monteada bajo /var/www que contenga codigo PHP. Se
 *     buscan declaraciones estaticas de timezone y se listan las zonas
 *     unicas encontradas. Como la herramienta no conoce los nombres de los
 *     proyectos de antemano, no hardcodea ninguno: todo lo que aparezca en
 *     /var/www se descubre solo.
 *
 * La herramienta es de solo lectura. Cambiar la TZ del stack requiere
 * tocar codigo distribuido en varios lugares (los `new DateTimeZone(...)`
 * de los endpoints, el `SET time_zone` de db.php, el `TZ` del Dockerfile),
 * y no es algo que tenga sentido hacer desde el panel.
 *
 *   GET api/herramientas_zona_horaria.php
 *     -> {ok:true, data:{referencia, php, os, mysql, proyectos:[...], ok}}
 */
ini_set('display_errors', 0);
error_reporting(E_ALL);
header('Content-Type: application/json; charset=utf-8');

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'OPTIONS') { exit; }

require_once __DIR__ . '/lib/auth_check.php';
requireAuth();
require_once __DIR__ . '/db.php';

try {
    requirePermission('administracion.herramientas.zona_horaria.consultar');

    // --- PHP del proceso actual -----------------------------------------
    $phpTz  = date_default_timezone_get();
    $phpNow = (new DateTime('now'))->format('Y-m-d H:i:s');
    $phpOff = (new DateTime('now'))->format('P');

    // --- Descubrir proyectos + inferir la zona de referencia ------------
    // El "proyecto host" es el que sirve este endpoint. Se identifica por
    // su directorio raiz relativo al script (dos niveles arriba de este
    // archivo, que vive en <proyecto>/api/). Ese proyecto define la zona
    // de referencia por consenso: la TZ que aparece mas veces declarada
    // en su codigo. Si no hay ninguna declaracion, se cae a la TZ del
    // proceso PHP.
    $rutaHost = realpath(dirname(__DIR__)) ?: dirname(__DIR__);

    $proyectos = descubrirProyectos();
    $tzReferencia = $phpTz;
    $refOrigen    = 'php_actual';
    $refDetalle   = 'No se encontraron declaraciones estaticas de zona horaria en el proyecto host: se usa la del proceso PHP (date_default_timezone_get()).';

    foreach ($proyectos as $p) {
        if ($p['ruta'] === $rutaHost) {
            $topTz = $p['zona_top'] ?? '';
            if ($topTz !== '') {
                $tzReferencia = $topTz;
                $refOrigen    = 'proyecto_host';
                $refDetalle   = 'Se toma como referencia la zona mas usada en el proyecto host (' . basename($rutaHost) . ' → ' . $topTz . ', ' . ($p['zona_top_count'] ?? 0) . ' apariciones).';
            }
            break;
        }
    }

    // Reclasificar cada proyecto contra la zona de referencia definitiva.
    foreach ($proyectos as &$p) {
        $p = clasificarProyecto($p, $tzReferencia);
    }
    unset($p);

    // Offset esperado = offset actual de la zona de referencia.
    try {
        $refOffset = (new DateTime('now', new DateTimeZone($tzReferencia)))->format('P');
    } catch (Throwable $e) {
        $refOffset = $phpOff;
    }

    $phpOk = ($phpTz === $tzReferencia);

    // --- Sistema operativo del contenedor -------------------------------
    $osTz = '';
    if (is_readable('/etc/timezone')) {
        $osTz = trim((string)@file_get_contents('/etc/timezone'));
    }
    if ($osTz === '') {
        $osTz = (string)getenv('TZ');
    }
    $osOk = ($osTz !== '' && $osTz === $tzReferencia);

    // --- MySQL ----------------------------------------------------------
    // La conexion se abre en db.php con `SET time_zone = '<offset>'`, asi que
    // @@session.time_zone deberia ser ese offset. @@global.time_zone se lee
    // tal como quedo configurado en el server. @@system_time_zone refleja la
    // TZ del OS del server MySQL (no del contenedor de PHP).
    $pdo = db();
    $mysqlRow = $pdo->query(
        "SELECT
            @@global.time_zone   AS tz_global,
            @@session.time_zone  AS tz_session,
            @@system_time_zone   AS tz_system,
            NOW()                AS now_local,
            UTC_TIMESTAMP()      AS now_utc,
            DATABASE()           AS db_actual,
            VERSION()            AS version"
    )->fetch();

    // Offset efectivo de la sesion: NOW() - UTC_TIMESTAMP() en formato +HH:MM.
    $local = new DateTime($mysqlRow['now_local'], new DateTimeZone('UTC'));
    $utc   = new DateTime($mysqlRow['now_utc'],   new DateTimeZone('UTC'));
    $diff  = $local->getTimestamp() - $utc->getTimestamp();
    $sign  = $diff < 0 ? '-' : '+';
    $abs   = abs($diff);
    $hh    = str_pad((string)intdiv($abs, 3600),      2, '0', STR_PAD_LEFT);
    $mm    = str_pad((string)intdiv($abs % 3600, 60), 2, '0', STR_PAD_LEFT);
    $mysqlOffset = $sign . $hh . ':' . $mm;

    // Comparamos el offset de MySQL contra el offset actual de la referencia.
    $mysqlOk = ($mysqlOffset === $refOffset);

    // --- Chequeo global -------------------------------------------------
    $proyectosOk = true;
    foreach ($proyectos as $p) {
        if ($p['estado'] !== 'ok' && $p['estado'] !== 'sin_codigo' && $p['estado'] !== 'sin_declaracion') {
            $proyectosOk = false;
            break;
        }
    }

    $todoOk = $phpOk && $mysqlOk && $proyectosOk && ($osTz === '' || $osOk);

    jsonOk([
        'referencia' => [
            'timezone' => $tzReferencia,
            'offset'   => $refOffset,
            'origen'   => $refOrigen,
            'detalle'  => $refDetalle,
        ],
        'php' => [
            'timezone' => $phpTz,
            'now'      => $phpNow,
            'offset'   => $phpOff,
            'ok'       => $phpOk,
        ],
        'os' => [
            'timezone' => $osTz,
            'ok'       => $osOk,
            'presente' => $osTz !== '',
        ],
        'mysql' => [
            'tz_global'  => (string)$mysqlRow['tz_global'],
            'tz_session' => (string)$mysqlRow['tz_session'],
            'tz_system'  => (string)$mysqlRow['tz_system'],
            'offset'     => $mysqlOffset,
            'now'        => (string)$mysqlRow['now_local'],
            'now_utc'    => (string)$mysqlRow['now_utc'],
            'db'         => (string)$mysqlRow['db_actual'],
            'version'    => (string)$mysqlRow['version'],
            'ok'         => $mysqlOk,
        ],
        'proyectos' => $proyectos,
        'env'       => getenv('APP_ENV') ?: 'unknown',
        'ok'        => $todoOk,
    ]);
} catch (Throwable $e) {
    jsonError($e->getMessage(), 500);
}

/**
 * Descubre proyectos PHP bajo /var/www escaneando cada subcarpeta que
 * contenga al menos un `.php`. No hardcodea nombres — el objetivo es
 * que la herramienta funcione en cualquier monorepo donde los nombres
 * de los proyectos son otros.
 *
 * La ruta base es `/var/www` porque es la convencion de la imagen
 * `php:apache`: el DocumentRoot vive en `/var/www/html` y por convencion
 * de este grupo el resto de los proyectos hermanos se bind-montean como
 * `/var/www/<nombre>`. Si en el futuro se cambia esa convencion, mover
 * la constante de aca.
 */
function descubrirProyectos(): array {
    $base = '/var/www';

    $descubiertos = [];   // slug → path
    if (is_dir($base)) {
        // La imagen `php:apache` deja el DocumentRoot en `/var/www/html`.
        // Lo tratamos igual que a los hermanos: si tiene un index.php es un
        // proyecto valido (que sera el panel mismo o cualquier otro que
        // reuse la ruta por convencion).
        if (is_file($base . '/html/index.php')) {
            $descubiertos['html'] = $base . '/html';
        }
        foreach ((array)@scandir($base) as $entry) {
            if ($entry === '' || $entry[0] === '.') continue;
            if ($entry === 'html') continue;
            $ruta = $base . '/' . $entry;
            if (!is_dir($ruta)) continue;
            // Saltamos carpetas evidentes de sistema / assets compartidos.
            if (in_array($entry, ['certs', 'logs', 'ejecuciones', 'tmp'], true)) continue;
            if (contieneAlgunPhp($ruta)) {
                $descubiertos[$entry] = $ruta;
            }
        }
    }

    ksort($descubiertos);

    $items = [];
    foreach ($descubiertos as $nombre => $ruta) {
        $items[] = escanearProyecto($nombre, $ruta);
    }
    return $items;
}

function contieneAlgunPhp(string $ruta): bool {
    // Chequeo rapido: existe un .php directo en la carpeta.
    foreach ((array)@scandir($ruta) as $entry) {
        if ($entry === '' || $entry[0] === '.') continue;
        if (is_file($ruta . '/' . $entry) && strtolower(pathinfo($entry, PATHINFO_EXTENSION)) === 'php') {
            return true;
        }
    }
    return false;
}

/**
 * Escanea recursivamente los .php de un proyecto buscando declaraciones
 * estaticas de timezone. Devuelve el conteo crudo — la clasificacion contra
 * la zona de referencia la hace despues `clasificarProyecto()`, cuando ya
 * se resolvio cual es la referencia definitiva.
 */
function escanearProyecto(string $nombre, string $ruta): array {
    if (!is_dir($ruta)) {
        return [
            'nombre'  => $nombre,
            'ruta'    => $ruta,
            'zonas'          => [],
            'zonas_por_conteo' => [],
            'zona_top'       => '',
            'zona_top_count' => 0,
            'archivos_escaneados' => 0,
            'archivos_con_tz'     => 0,
            'estado'  => 'no_visible',
            'detalle' => 'La ruta no existe / no esta bind-monteada en el contenedor.',
        ];
    }

    $conteo      = [];   // zona → cantidad de ocurrencias
    $totalPhp    = 0;
    $conTz       = 0;
    $maxArchivos = 5000;
    $maxBytes    = 512 * 1024;
    $rechazado   = 0;

    // Detecta:
    //   - new DateTimeZone('...')
    //   - date_default_timezone_set('...')
    $regex = '/(?:new\s+DateTimeZone\s*\(\s*[\'"]([A-Za-z_\/\-\+0-9]+)[\'"]\s*\)|date_default_timezone_set\s*\(\s*[\'"]([A-Za-z_\/\-\+0-9]+)[\'"]\s*\))/i';

    try {
        $it = new RecursiveIteratorIterator(
            new RecursiveCallbackFilterIterator(
                new RecursiveDirectoryIterator($ruta, FilesystemIterator::SKIP_DOTS),
                function ($current) {
                    if ($current->isDir()) {
                        $bn = $current->getFilename();
                        if (in_array($bn, ['.git', 'node_modules', 'vendor', 'ejecuciones', 'logs'], true)) {
                            return false;
                        }
                    }
                    return true;
                }
            )
        );
        foreach ($it as $file) {
            /** @var SplFileInfo $file */
            if (!$file->isFile()) continue;
            if (strtolower($file->getExtension()) !== 'php') continue;
            if ($totalPhp >= $maxArchivos) { $rechazado++; continue; }
            $totalPhp++;
            $contenido = (string)@file_get_contents($file->getPathname(), false, null, 0, $maxBytes);
            if ($contenido === '') continue;
            if (!preg_match_all($regex, $contenido, $m)) continue;
            $conTz++;
            $encontradas = array_merge($m[1] ?? [], $m[2] ?? []);
            foreach ($encontradas as $z) {
                if ($z === '') continue;
                $conteo[$z] = ($conteo[$z] ?? 0) + 1;
            }
        }
    } catch (Throwable $e) {
        return [
            'nombre'  => $nombre,
            'ruta'    => $ruta,
            'zonas'          => [],
            'zonas_por_conteo' => [],
            'zona_top'       => '',
            'zona_top_count' => 0,
            'archivos_escaneados' => $totalPhp,
            'archivos_con_tz'     => $conTz,
            'estado'  => 'error',
            'detalle' => 'Fallo el escaneo: ' . $e->getMessage(),
        ];
    }

    arsort($conteo);
    $zonasList = array_keys($conteo);
    $topZona   = $zonasList[0] ?? '';
    $topCount  = $topZona !== '' ? $conteo[$topZona] : 0;

    return [
        'nombre'  => $nombre,
        'ruta'    => $ruta,
        'zonas'   => $zonasList,
        'zonas_por_conteo' => $conteo,
        'zona_top'         => $topZona,
        'zona_top_count'   => $topCount,
        'archivos_escaneados' => $totalPhp + $rechazado,
        'archivos_con_tz'     => $conTz,
        // La clasificacion definitiva la hace clasificarProyecto() abajo.
        'estado'  => 'pendiente',
        'detalle' => '',
    ];
}

/**
 * Toma un item ya escaneado y le asigna `estado` + `detalle` contra la
 * zona de referencia definitiva.
 */
function clasificarProyecto(array $p, string $tzReferencia): array {
    // Estados terminales del scanner que no dependen de la referencia.
    if (in_array($p['estado'], ['no_visible', 'error'], true)) {
        return $p;
    }

    $zonasList = $p['zonas'] ?? [];
    $totalPhp  = (int)($p['archivos_escaneados'] ?? 0);

    if ($totalPhp === 0) {
        $p['estado']  = 'sin_codigo';
        $p['detalle'] = 'La carpeta no contiene archivos PHP: es un stub / placeholder, no hay nada que confirmar.';
        return $p;
    }
    if (!$zonasList) {
        $p['estado']  = 'sin_declaracion';
        $p['detalle'] = 'Hay ' . $totalPhp . ' archivos PHP pero ninguno declara una zona horaria explicita. Hereda la del interprete (date.timezone del php.ini o TZ del contenedor).';
        return $p;
    }
    if (count($zonasList) === 1 && $zonasList[0] === $tzReferencia) {
        $p['estado']  = 'ok';
        $p['detalle'] = 'Todas las declaraciones usan la zona de referencia.';
        return $p;
    }
    if (count($zonasList) === 1) {
        $p['estado']  = 'mismatch';
        $p['detalle'] = 'Todas las declaraciones usan ' . $zonasList[0] . ', que no coincide con la zona de referencia (' . $tzReferencia . ').';
        return $p;
    }
    $tieneReferencia = in_array($tzReferencia, $zonasList, true);
    $p['estado']  = 'mixto';
    $p['detalle'] = 'Se encontraron ' . count($zonasList) . ' zonas horarias distintas' .
                   ($tieneReferencia ? ' (incluye la de referencia)' : ' (ninguna es la de referencia)') .
                   '. Uniformar antes del proximo deploy.';
    return $p;
}
