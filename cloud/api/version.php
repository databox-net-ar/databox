<?php
// api/version.php
// Devuelve la versión actual del cloud tal como está en el filesystem del
// servidor (leyendo cloud/version.txt). El front hace polling cada pocos
// segundos y compara con la versión que cargó al abrir la pestaña — si
// difieren, muestra el banner "hay una nueva versión disponible" para que
// el usuario recargue la página tras un deploy.
//
// No requiere autenticación (es información no sensible).

header('Content-Type: application/json; charset=utf-8');
$path    = __DIR__ . '/../version.txt';
$version = file_exists($path) ? trim(file_get_contents($path)) : '0.0.0';
echo json_encode(['ok' => true, 'version' => $version]);
