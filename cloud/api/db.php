<?php
// api/db.php
// Conexion PDO unica para los endpoints. Lee credenciales desde getenv()
// (en prod las inyecta docker-compose.prod.yml via env_file). En desarrollo
// caen a los defaults del docker-compose.yml (host db / databox_dev / root / root).
// Ver STACK.md sec. 7.

function db(): PDO {
    static $pdo = null;
    if ($pdo !== null) return $pdo;

    $host = getenv('DB_HOST') ?: 'db';
    $port = getenv('DB_PORT') ?: '3306';
    $name = getenv('DB_NAME') ?: 'databox_dev';
    $user = getenv('DB_USER') ?: 'root';
    $pass = getenv('DB_PASS') ?: 'root';

    $dsn = "mysql:host={$host};port={$port};dbname={$name};charset=utf8mb4";
    $pdo = new PDO($dsn, $user, $pass, [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES   => false,
    ]);
    $pdo->exec("SET time_zone = '-03:00'");
    return $pdo;
}

function jsonOk(mixed $data, int $code = 200): void {
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['ok' => true, 'data' => $data], JSON_UNESCAPED_UNICODE);
    exit;
}

function jsonError(string $msg, int $code = 400): void {
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['ok' => false, 'error' => $msg], JSON_UNESCAPED_UNICODE);
    exit;
}

function readJsonBody(): array {
    $raw = file_get_contents('php://input') ?: '';
    if ($raw === '') return [];
    $j = json_decode($raw, true);
    if (!is_array($j)) jsonError('Cuerpo no es JSON valido', 400);
    return $j;
}

// Cifrado reversible compatible con el legado del grupo. Clave fija por defecto.
// IMPORTANTE: no es un hash criptograficamente seguro — se mantiene tal cual para
// no romper la compatibilidad con los registros ya cifrados en `usuarios.contrasena`.
const CLAVE_CIFRADO_DEFAULT = '0123456789';

function encriptar(string $cadena, string $clave = ''): string {
    if ($clave === '') $clave = CLAVE_CIFRADO_DEFAULT;
    $len  = strlen($cadena);
    $klen = strlen($clave);
    $out  = '';
    for ($i = 0; $i < $len; $i++) {
        $char    = $cadena[$i];
        $keychar = substr($clave, ($i % $klen) - 1, 1);
        $out    .= chr(ord($char) + ord($keychar));
    }
    return base64_encode($out);
}

function desencriptar(string $cadena, string $clave = ''): string {
    if ($clave === '') $clave = CLAVE_CIFRADO_DEFAULT;
    $cadena = base64_decode($cadena) ?: '';
    $len    = strlen($cadena);
    $klen   = strlen($clave);
    $out    = '';
    for ($i = 0; $i < $len; $i++) {
        $char    = $cadena[$i];
        $keychar = substr($clave, ($i % $klen) - 1, 1);
        $out    .= chr(ord($char) - ord($keychar));
    }
    return $out;
}
