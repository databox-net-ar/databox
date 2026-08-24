<?php
/**
 * api/lib/datarocket_etiquetas_uso.php
 * Helper para estampar `datarocket_etiquetas.fecha_uso` — el timestamp de la
 * ultima vez que cada etiqueta se USO, entendiendo por "uso" que se aplico a
 * un recurso Datarocket (hoy: prospectos, via la tabla puente
 * `datarocket_prospectos_etiquetas`). La columna la agrego la migracion
 * 20260824_1000_datarocket_etiquetas_agregar_fecha_uso.sql.
 *
 * Vive en un lib compartido y no dentro de cada endpoint porque hay tres
 * escritores de la puente y los tres tienen que estampar igual:
 *   * cloud/api/datarocketprospectos.php  -> syncEtiquetas()      (ABM cloud)
 *   * api/v4/datarocket/prospectos.php    -> drPrSyncEtiquetas()  (full replace)
 *   * api/v4/datarocket/prospectos.php    -> drPrAgregarEtiquetas() (alta aditiva)
 * Si aparece un cuarto escritor —otro recurso que empiece a etiquetar— tiene
 * que llamar a esta funcion o `fecha_uso` empieza a mentir en silencio.
 *
 * A diferencia de `etiquetados` (contador denormalizado que se recalcula a
 * mano desde el ABM), `fecha_uso` se escribe en el momento y NUNCA se
 * recalcula desde la puente: la puente solo guarda las asignaciones vigentes,
 * asi que un recalculo borraria los usos de etiquetas que despues se quitaron.
 * La columna solo avanza.
 */

/**
 * Marca `NOW()` como ultimo uso de las etiquetas indicadas.
 *
 * `$etiquetaIds` se castea a int y se filtran los <= 0 aca adentro, asi que el
 * llamador puede pasarle directamente lo que tenga a mano. Los ids se
 * interpolan (ya son int, no hay inyeccion posible) para no armar N
 * placeholders en un UPDATE que corre una sola vez.
 *
 * El `fecha_modificacion = fecha_modificacion` del SET no es redundante: esa
 * columna es `ON UPDATE CURRENT_TIMESTAMP`, asi que sin la asignacion explicita
 * cada uso de la etiqueta le movería también la fecha de modificacion y
 * "Modificada" pasaria a significar "usada" (las dos columnas quedarian
 * siempre iguales). MySQL y MariaDB suprimen el auto-update cuando la columna
 * se asigna a mano en el UPDATE, y asignarle su propio valor la deja quieta.
 *
 * Best effort, igual que registrarSuceso(): estampar el uso es metadata del
 * catalogo, no el trabajo que el usuario pidio. Si falla —lock, permisos, la
 * columna todavia no migrada en ese entorno— el alta / edicion del prospecto
 * tiene que terminar bien igual.
 */
function marcarUsoEtiquetas(PDO $pdo, array $etiquetaIds): void {
    $ids = [];
    foreach ($etiquetaIds as $v) {
        $n = (int)$v;
        if ($n > 0) $ids[$n] = true;
    }
    if (!$ids) return;

    $in = implode(',', array_keys($ids));
    try {
        $pdo->exec("UPDATE datarocket_etiquetas
                       SET fecha_uso = NOW(), fecha_modificacion = fecha_modificacion
                     WHERE id IN ({$in})");
    } catch (Throwable $_) {
        // Ver el docblock: no rompe el flujo principal.
    }
}
