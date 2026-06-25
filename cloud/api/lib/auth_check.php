<?php
// api/lib/auth_check.php
// Middleware mínimo de autenticación reutilizable por endpoints distintos
// de auth.php (que ya tiene su propio dispatcher). Lee la cookie databox_token
// (o el header Authorization: Bearer) y valida el JWT con jwtDecode().
//
// Uso típico:
//   require_once __DIR__ . '/lib/auth_check.php';
//   requireAuth();   // 401 + exit si no hay sesión válida

require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../jwt.php';

const AUTH_COOKIE_NAME = 'databox_token';

function currentAuth(): ?array {
    $token = $_COOKIE[AUTH_COOKIE_NAME] ?? '';
    if ($token === '') {
        $auth = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
        if (stripos($auth, 'Bearer ') === 0) $token = substr($auth, 7);
    }
    if ($token === '') return null;
    return jwtDecode($token);
}

function requireAuth(): array {
    $p = currentAuth();
    if (!$p) {
        http_response_code(401);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['ok' => false, 'error' => 'No autenticado'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    return $p;
}
