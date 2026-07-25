<?php
/**
 * Helpers para leer/escribir parametros de configuracion runtime desde codigo
 * (jobs, endpoints internos, etc.). Se apoya en la tabla `parametros` compartida
 * con otras apps del grupo — mismo esquema que usa el ABM `cloud/api/parametros.php`
 * (id / variable / valor / comentario; sin UNIQUE en DB, unicidad enforce por codigo).
 *
 * No hay cache: cada llamada hace un query. Los usos son puntuales (flags de
 * activacion de jobs, cotas, etc.), no algo que se lea en un loop caliente.
 */

/**
 * Devuelve el valor de un parametro por nombre, o $default si no existe.
 * Siempre devuelve string (o el default). Interpretar como bool con === '1'.
 */
function parametroLeer(PDO $pdo, string $variable, ?string $default = null): ?string {
    $st = $pdo->prepare('SELECT valor FROM parametros WHERE variable = :v LIMIT 1');
    $st->execute([':v' => $variable]);
    $row = $st->fetch();
    if (!$row) return $default;
    return $row['valor'] !== null ? (string)$row['valor'] : $default;
}

/**
 * Escribe (upsert) un parametro. Si ya existe, actualiza `valor`; si no,
 * lo crea con `variable`, `valor` y `comentario` opcional.
 *
 * Comentario solo se persiste al crear la fila para no pisar ediciones que
 * el operador haya hecho desde el ABM de parametros.
 */
function parametroEscribir(PDO $pdo, string $variable, string $valor, ?string $comentario = null): void {
    $upd = $pdo->prepare('UPDATE parametros SET valor = :v WHERE variable = :k');
    $upd->execute([':v' => $valor, ':k' => $variable]);
    if ($upd->rowCount() > 0) return;

    // Puede no haber cambiado el valor y aun asi existir la fila: chequear
    // antes de insertar (rowCount() puede ser 0 tambien porque el valor
    // ya era $valor).
    $ex = $pdo->prepare('SELECT id FROM parametros WHERE variable = :k LIMIT 1');
    $ex->execute([':k' => $variable]);
    if ($ex->fetch()) return;

    $ins = $pdo->prepare('
        INSERT INTO parametros (variable, valor, comentario)
        VALUES (:k, :v, :c)
    ');
    $ins->execute([':k' => $variable, ':v' => $valor, ':c' => $comentario]);
}

/**
 * Se asegura de que el parametro exista en la tabla, sembrandolo con
 * `$default` y `$comentario` si no. Util al inicio de un job para que
 * el flag aparezca en el ABM de Parametros aunque nadie lo haya creado
 * a mano todavia.
 *
 * NO pisa el valor si el parametro ya existe (esa es tarea de
 * parametroEscribir).
 */
function parametroAsegurar(PDO $pdo, string $variable, string $default, ?string $comentario = null): void {
    $ex = $pdo->prepare('SELECT id FROM parametros WHERE variable = :k LIMIT 1');
    $ex->execute([':k' => $variable]);
    if ($ex->fetch()) return;

    $ins = $pdo->prepare('
        INSERT INTO parametros (variable, valor, comentario)
        VALUES (:k, :v, :c)
    ');
    $ins->execute([':k' => $variable, ':v' => $default, ':c' => $comentario]);
}
