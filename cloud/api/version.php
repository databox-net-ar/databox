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
// Trim incluye BOM UTF-8 (\xEF\xBB\xBF): si el archivo se guarda desde un
// editor de Windows con BOM, sin esto el string no coincide con el que
// index.php inyecta en el DOM (que el JS sí normaliza con .trim()) y el
// banner "hay una nueva versión" queda permanentemente visible.
$version = file_exists($path) ? trim(file_get_contents($path), "\xEF\xBB\xBF \t\n\r\0\x0B") : '0.0.0';
echo json_encode(['ok' => true, 'version' => $version]);
