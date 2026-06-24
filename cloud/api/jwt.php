<?php
// api/jwt.php
// JWT HS256 minimo, sin dependencias. Pensado para el flujo de login interno
// del panel (ver auth.php). No exportar tokens fuera de este dominio.
//
// Firma con APP_KEY_CLOUD, definido en .env.* y cargado por env.php (lo trae
// db.php antes que cualquier endpoint llegue aca).

function jwtBase64UrlEncode(string $bin): string {
    return rtrim(strtr(base64_encode($bin), '+/', '-_'), '=');
}

function jwtBase64UrlDecode(string $str): string {
    $pad = strlen($str) % 4;
    if ($pad) $str .= str_repeat('=', 4 - $pad);
    $bin = base64_decode(strtr($str, '-_', '+/'), true);
    return $bin === false ? '' : $bin;
}

function jwtEncode(array $payload, int $ttlSeconds = 28800): string {
    $now = time();
    $payload = array_merge(['iat' => $now, 'exp' => $now + $ttlSeconds], $payload);
    $h = jwtBase64UrlEncode(json_encode(['alg' => 'HS256', 'typ' => 'JWT'], JSON_UNESCAPED_SLASHES));
    $p = jwtBase64UrlEncode(json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    $sig = hash_hmac('sha256', "$h.$p", APP_KEY_CLOUD, true);
    return "$h.$p." . jwtBase64UrlEncode($sig);
}

function jwtDecode(string $token): ?array {
    $parts = explode('.', $token);
    if (count($parts) !== 3) return null;
    [$h, $p, $s] = $parts;
    $expected = jwtBase64UrlEncode(hash_hmac('sha256', "$h.$p", APP_KEY_CLOUD, true));
    if (!hash_equals($expected, $s)) return null;
    $payload = json_decode(jwtBase64UrlDecode($p), true);
    if (!is_array($payload)) return null;
    if (isset($payload['exp']) && time() >= (int)$payload['exp']) return null;
    return $payload;
}
