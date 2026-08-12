-- datarocket_prospectos: agregar `contacto_id` — FK a `datarocket_contactos`.
--
-- Primera migracion de la fase 1 del refactor "prospectos = referencia a
-- contacto" (ver el chat de decision del 2026-08-12). El objetivo del refactor:
-- eliminar la duplicacion de datos de identidad (nombre / correo / celular /
-- organizacion / domicilio / etc.) entre las tablas `datarocket_contactos` y
-- `datarocket_prospectos`. El prospecto se queda solo con la data "de vida
-- en el embudo" (ingreso, proyecto, embudo, etapa, asignado, comentarios,
-- etc.) y toda la identidad se resuelve por JOIN a `datarocket_contactos`
-- via `contacto_id`.
--
-- Esta migracion es la primera de un par:
--   1) (esta) agrega la columna nullable + indice + FK.
--   2) (20260812_1100) hace el backfill: DELETE de prospectos sin datos de
--      contacto (13 filas sin correo ni celular), dedupe por correo/celular
--      normalizados, crea contactos faltantes con tipo='persona' + etiqueta
--      del proyecto (Vigicom/Vigia/Reactor/Causam), y puebla `contacto_id`
--      en todos los prospectos supervivientes.
--
-- El NOT NULL de `contacto_id` y el DROP de las 12 columnas duplicadas
-- (nombre, contacto, celular, correo, web, organizacion, domicilio, ciudad,
-- localidad, provincia, pais, ubicacion) viene en una fase 2 posterior, ya
-- con la API y el frontend actualizados para leer del contacto por JOIN.
-- Asi, si algo del backfill sale mal, las columnas viejas siguen ahi como
-- respaldo hasta validar visualmente.
--
-- FK ON DELETE RESTRICT: no permite borrar un contacto que tiene prospectos
-- vinculados. Alineado con la regla "prospecto necesita contacto siempre".
--
-- Idempotente: cada ALTER se guarda con information_schema.
-- Compatible MySQL 8 (dev) + MariaDB 10.11 (prod).

SET @exists := (
  SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME   = 'datarocket_prospectos'
    AND COLUMN_NAME  = 'contacto_id'
);
SET @sql := IF(@exists = 0,
  'ALTER TABLE datarocket_prospectos ADD COLUMN `contacto_id` INT NULL DEFAULT NULL AFTER `id`',
  'DO 0'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @exists := (
  SELECT COUNT(*) FROM information_schema.STATISTICS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME   = 'datarocket_prospectos'
    AND INDEX_NAME   = 'idx_dr_prospectos_contacto'
);
SET @sql := IF(@exists = 0,
  'ALTER TABLE datarocket_prospectos ADD INDEX `idx_dr_prospectos_contacto` (`contacto_id`)',
  'DO 0'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @exists := (
  SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS
  WHERE TABLE_SCHEMA    = DATABASE()
    AND TABLE_NAME      = 'datarocket_prospectos'
    AND CONSTRAINT_NAME = 'fk_dr_prospectos_contacto'
);
SET @sql := IF(@exists = 0,
  'ALTER TABLE datarocket_prospectos
    ADD CONSTRAINT `fk_dr_prospectos_contacto` FOREIGN KEY (`contacto_id`)
        REFERENCES `datarocket_contactos` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT',
  'DO 0'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
