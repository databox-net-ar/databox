<?php
// api/v4/_lib/telegram.php
// Preflight de permisos del directorio de un canal de Telegram
// (api/v4/telegram/canales/<telefono>/), compartido por los endpoints HTTP
// que levantan MadelineProto: mensajes.php y health.php.
//
// ---------------------------------------------------------------------------
// USO
// ---------------------------------------------------------------------------
// Una linea, SIEMPRE inmediatamente antes del `chdir($canalDir)` + el
// `require_once` del bootstrap `madeline.php`:
//
//     require_once dirname(__DIR__) . '/_lib/telegram.php';
//     ...
//     telegramCanalPreflight($canalDir, (string)$canal['slug']);
//     chdir($canalDir);
//     require_once $bootstrap;
//
// ---------------------------------------------------------------------------
// POR QUE EXISTE
// ---------------------------------------------------------------------------
// MadelineProto ESCRIBE dentro del directorio del canal: el lock del phar, el
// MadelineProto.log y todo session.madeline/. El bootstrap toma el lock asi
// (madeline.php, Installer::load()):
//
//     $phar = "madeline-$release.phar";
//     self::$lock = fopen("$phar.lock", 'c');   // ruta RELATIVA al cwd
//     flock(self::$lock, LOCK_SH);
//
// No chequea el retorno de fopen(). Si el proceso no puede escribir ahi, PHP
// emite un Warning (que ademas ensucia el body JSON con HTML) y flock()
// revienta con
//
//     flock(): Argument #1 ($stream) must be of type resource, bool given
//
// que no dice una palabra del problema real. Incidente prod 2026-08-23: todo
// canales/ tenia owner ec2-user:ec2-user 644 y Apache corre como www-data
// (uid 33), asi que ningun envio de Telegram salia. El chown correctivo vive
// en docker/entrypoint.sh, pero solo corre al ARRANCAR el contenedor, y el
// deploy normal no lo recrea (el codigo PHP entra por bind mount) — por eso
// scripts/deploy.sh tambien lo re-aplica en cada subida.
//
// Este preflight no puede arreglar el permiso (www-data no puede chown ni
// chmod archivos de otro owner): lo que hace es convertir el TypeError opaco
// en un 500 que nombra el usuario del proceso, los archivos culpables y el
// comando exacto que lo corrige.

declare(strict_types=1);

/**
 * Aborta con un 500 accionable si el proceso actual no puede escribir lo que
 * MadelineProto necesita escribir dentro del directorio del canal.
 *
 * Se chequea SOLO lo que se escribe de verdad — el directorio en si, el/los
 * `madeline-*.phar.lock`, el `MadelineProto.log` y el arbol de
 * `session.madeline/`. El `madeline-*.phar` queda afuera a proposito: es de
 * solo lectura, y marcarlo romperia instalaciones sanas donde el phar quedo
 * con otro owner.
 */
function telegramCanalPreflight(string $canalDir, string $slug): void {
    $requeridos = [$canalDir];

    foreach ((array)@glob($canalDir . '/madeline-*.phar.lock') as $lock) {
        $requeridos[] = $lock;
    }
    if (file_exists($canalDir . '/MadelineProto.log')) {
        $requeridos[] = $canalDir . '/MadelineProto.log';
    }

    $sessDir = $canalDir . '/session.madeline';
    if (is_dir($sessDir)) {
        $requeridos[] = $sessDir;
        foreach ((array)@glob($sessDir . '/*') as $f) {
            $requeridos[] = $f;
        }
    }

    $noEscribibles = [];
    foreach ($requeridos as $path) {
        if (!is_writable($path)) $noEscribibles[] = $path;
    }
    if (!$noEscribibles) return;

    // El nombre del usuario ayuda a entender el 500 sin entrar al server:
    // "corre como www-data" + "los archivos son de otro" es todo el bug.
    $usuario = 'el usuario del proceso';
    if (function_exists('posix_geteuid')) {
        $uid = posix_geteuid();
        $pw  = function_exists('posix_getpwuid') ? @posix_getpwuid($uid) : null;
        $usuario = !empty($pw['name']) ? "{$pw['name']} (uid {$uid})" : "uid {$uid}";
    }

    jsonError(
        "El proceso corre como {$usuario} y no puede escribir el directorio de sesion "
        . "del canal '{$slug}' ({$canalDir}). MadelineProto necesita escribir ahi el lock "
        . "del phar, el log y la sesion. Corregir el owner en el server con: "
        . "docker exec databox-apache chown -R www-data:www-data /var/www/api/v4/telegram/canales",
        500,
        ['no_escribibles' => array_slice($noEscribibles, 0, 10)]
    );
}
