<?php
/**
 * api/lib/sincronizador.php
 * Helpers compartidos por los endpoints del Sincronizador de tablas
 * (herramientas_sincronizador_*.php).
 *
 * La herramienta copia tablas entre las bases de desarrollo y produccion
 * preservando los IDs de origen. Por seguridad, SOLO se puede invocar
 * cuando el panel corre en desarrollo — en produccion los endpoints
 * responden 403.
 *
 * Para poder abrir una conexion contra "el otro entorno" desde el mismo
 * proceso PHP, este helper parsea los dos .env de la raiz del repo
 * (.env.development y .env.production) SIN pisar el entorno actual —
 * los valores viven solo en un array en memoria.
 */

function sincronizadorAssertDev(): void {
    $env = getenv('APP_ENV') ?: (defined('APP_ENV') ? APP_ENV : 'unknown');
    if (strtolower((string)$env) !== 'development') {
        http_response_code(403);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(
            ['ok' => false, 'error' => 'El sincronizador solo funciona en el panel de desarrollo.'],
            JSON_UNESCAPED_UNICODE
        );
        exit;
    }
}

// Parsea un .env sin efectos secundarios. Devuelve array asociativo.
function sincronizadorParseEnv(string $path): array {
    if (!is_readable($path)) return [];
    $out = [];
    foreach (file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
        $line = trim($line);
        if ($line === '' || str_starts_with($line, '#')) continue;
        [$k, $v] = array_pad(explode('=', $line, 2), 2, '');
        $k = trim($k);
        $v = trim($v);
        if ($k === '') continue;
        if (strlen($v) >= 2 && (($v[0] === '"' && $v[-1] === '"') || ($v[0] === "'" && $v[-1] === "'"))) {
            $v = substr($v, 1, -1);
        }
        $out[$k] = $v;
    }
    return $out;
}

// Devuelve la ruta a un .env de la raiz del repo (dev / prod).
// __DIR__ = cloud/api/lib  ->  ../../..  = raiz del repo.
function sincronizadorEnvPath(string $ambiente): string {
    return dirname(__DIR__, 3) . '/.env.' . $ambiente;
}

// Abre una conexion PDO contra el entorno pedido ("dev" | "prod")
// leyendo los DB_* del .env correspondiente. No cachea: cada endpoint
// abre y cierra su propia conexion.
function sincronizadorPdo(string $ambiente): PDO {
    $file = ($ambiente === 'prod') ? 'production' : 'development';
    $vars = sincronizadorParseEnv(sincronizadorEnvPath($file));
    if (empty($vars['DB_HOST']) || empty($vars['DB_NAME'])) {
        throw new RuntimeException("No se encontraron credenciales de BD para el entorno '$file'.");
    }
    $host = $vars['DB_HOST'];
    $port = $vars['DB_PORT'] ?? '3306';
    $name = $vars['DB_NAME'];
    $user = $vars['DB_USER'] ?? 'root';
    $pass = $vars['DB_PASS'] ?? '';

    $dsn = "mysql:host={$host};port={$port};dbname={$name};charset=utf8mb4";
    $pdo = new PDO($dsn, $user, $pass, [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES   => false,
        PDO::ATTR_TIMEOUT            => 10,
    ]);
    $pdo->exec("SET time_zone = '-03:00'");
    return $pdo;
}

// Metadata resumida del entorno (para el badge del modal).
function sincronizadorEntorno(string $ambiente): array {
    $file = ($ambiente === 'prod') ? 'production' : 'development';
    $vars = sincronizadorParseEnv(sincronizadorEnvPath($file));
    return [
        'ambiente' => $ambiente,
        'host'     => $vars['DB_HOST'] ?? '?',
        'database' => $vars['DB_NAME'] ?? '?',
    ];
}

// Valida el nombre de una tabla (evita inyeccion al interpolarlo en DDL).
function sincronizadorNombreTablaValido(string $t): bool {
    return (bool)preg_match('/^[A-Za-z0-9_]{1,64}$/', $t);
}
