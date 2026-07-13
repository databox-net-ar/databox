<?php
/**
 * API cloud — Herramientas: Sistema (pestaña "Variables de entorno").
 *
 * Snapshot diagnostico de todo el estado observable en el proceso PHP que
 * sirve el request actual:
 *
 *   - env:     getenv() sin argumento (todas las envvars del proceso).
 *   - server:  $_SERVER completo (headers HTTP, config Apache, script info).
 *   - cookies: $_COOKIE tal como llegaron al servidor (incluye HttpOnly, que
 *              no son visibles desde `document.cookie`).
 *   - session: $_SESSION si hay una sesion PHP nativa activa. Este panel
 *              autentica con JWT en cookie firmada, no con sesion PHP, asi
 *              que normalmente esta seccion aparece vacia — la dejamos igual
 *              porque forma parte del snapshot solicitado.
 *
 * Todos los valores se devuelven crudos (no hay redaccion de secretos). El
 * permiso `administracion.herramientas.sistema.consultar` es la unica barrera
 * — el modal es admin-only en dev.
 *
 *   GET api/herramientas_sistema_entorno.php
 *     -> {ok:true, data:{env:[{name,value}], server:[...], cookies:[...],
 *                        session:{id, activa, vars:[...]}}}
 */
ini_set('display_errors', 0);
error_reporting(E_ALL);
header('Content-Type: application/json; charset=utf-8');

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'OPTIONS') { exit; }

require_once __DIR__ . '/lib/auth_check.php';
requireAuth();
require_once __DIR__ . '/db.php';

try {
    requirePermission('administracion.herramientas.sistema.consultar');

    // --- Envvars del proceso -------------------------------------------------
    // getenv() sin args existe desde PHP 7.1 y devuelve todas las envvars,
    // independientemente de la config `variables_order` (que puede dejar
    // $_ENV vacio).
    $envRaw = getenv();
    if (!is_array($envRaw)) $envRaw = [];
    $env = _sistemaEntornoPairs($envRaw);

    // --- $_SERVER completo ---------------------------------------------------
    $server = _sistemaEntornoPairs($_SERVER ?? []);

    // --- Cookies vistas por el server ---------------------------------------
    $cookies = _sistemaEntornoPairs($_COOKIE ?? []);

    // --- Sesion PHP nativa (si la hubiera) -----------------------------------
    // Este panel no usa sesion PHP para autenticacion — usa JWT en cookie. Aun
    // asi devolvemos si hay una sesion activa por si algun modulo del futuro
    // la abriera; en un request tipico esto queda vacio.
    $sessionActiva = (session_status() === PHP_SESSION_ACTIVE);
    $sessionId     = $sessionActiva ? session_id() : null;
    $sessionVars   = $sessionActiva
        ? _sistemaEntornoPairs($_SESSION ?? [])
        : [];

    jsonOk([
        'env'     => $env,
        'server'  => $server,
        'cookies' => $cookies,
        'session' => [
            'activa' => $sessionActiva,
            'id'     => $sessionId,
            'vars'   => $sessionVars,
        ],
    ]);
} catch (Throwable $e) {
    jsonError($e->getMessage(), 500);
}

/**
 * Normaliza un array asociativo a lista ordenada por clave con valores como
 * string. Objetos/arrays se serializan a JSON para poder mostrarlos como
 * texto sin romper el render del cliente.
 *
 * @param array<string,mixed> $arr
 * @return array<int,array{name:string,value:string}>
 */
function _sistemaEntornoPairs(array $arr): array {
    $out = [];
    foreach ($arr as $k => $v) {
        if (is_scalar($v) || $v === null) {
            $val = $v === null ? '' : (string)$v;
        } else {
            $val = json_encode($v, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            if ($val === false) $val = '<no serializable>';
        }
        $out[] = ['name' => (string)$k, 'value' => $val];
    }
    usort($out, fn($a, $b) => strcasecmp($a['name'], $b['name']));
    return $out;
}
