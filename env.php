<?php
/**
 * Loader compartido de variables de entorno.
 *
 * Detección automática del entorno (sin necesidad de exportar APP_ENV):
 *  - Si existe .env.production y NO existe .env.development -> production
 *  - En cualquier otro caso -> development
 *
 * Carga dinámica: todas las variables presentes en el .env quedan disponibles
 * vía getenv(), $_ENV, $_SERVER y como constantes globales (siempre que el
 * nombre sea un identificador válido en mayúsculas: [A-Z_][A-Z0-9_]*).
 *
 * Precedencia: las variables ya seteadas en el entorno (docker-compose,
 * Apache SetEnv, etc.) tienen prioridad sobre el .env file.
 *
 * Sin defaults: lo que no esté en el .env (ni en el entorno) no se define.
 * Si alguna constante crítica falta, el primer uso fallará con
 * "undefined constant", indicando claramente qué hay que agregar al .env.
 * Única excepción: APP_ENV, que se deriva de la detección por archivo.
 *
 * Este archivo lo consume cloud/api/db.php vía require_once. Si en el futuro
 * se suman más apps al repo, cada una lo carga del mismo modo.
 */

if (defined('SECRETS_LOADED')) {
    return;
}
define('SECRETS_LOADED', true);

$repoRoot = __DIR__;
$hasDev   = is_readable($repoRoot . '/.env.development');
$hasProd  = is_readable($repoRoot . '/.env.production');
$env      = ($hasProd && !$hasDev) ? 'production' : 'development';
$envFile  = $repoRoot . '/.env.' . $env;

$loadedKeys = [];
if (is_readable($envFile)) {
    foreach (file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
        $line = trim($line);
        if ($line === '' || str_starts_with($line, '#')) {
            continue;
        }
        [$k, $v] = array_pad(explode('=', $line, 2), 2, '');
        $k = trim($k);
        $v = trim($v);
        if ($k === '') {
            continue;
        }
        // Toda key listada en .env queda registrada para definirla como constante,
        // aunque su valor real venga del entorno (precedencia env > file).
        $loadedKeys[$k] = true;
        if (getenv($k) !== false) {
            continue;
        }
        if (strlen($v) >= 2 && (($v[0] === '"' && $v[-1] === '"') || ($v[0] === "'" && $v[-1] === "'"))) {
            $v = substr($v, 1, -1);
        }
        putenv("$k=$v");
        $_ENV[$k]    = $v;
        $_SERVER[$k] = $v;
    }
}

foreach (array_keys($loadedKeys) as $k) {
    if (!defined($k) && preg_match('/^[A-Z_][A-Z0-9_]*$/', $k)) {
        define($k, getenv($k));
    }
}

// APP_ENV se deriva de la detección por archivo, no se lee del .env.
defined('APP_ENV') || define('APP_ENV', $env);
