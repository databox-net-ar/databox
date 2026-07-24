-- Renombra columnas en `datarocket_plantillas`:
--   * `proyecto` (int) -> `proyecto_id` (int)  para alinear con la convencion
--     snake_case + sufijo `_id` que ya usan las demas tablas del cloud.
--   * `uuid`     (varchar) -> `slug`   (varchar) para alinear con la convencion
--     de slug publica adoptada en `roles.slug`, `permisos.slug`, etc.
--
-- Idempotente: chequea INFORMATION_SCHEMA antes de tocar cada columna, asi la
-- migracion es segura tanto en bases donde todavia tienen los nombres viejos
-- como en bases donde ya estan renombrados.
-- Compatible con MySQL 8 (dev) y MariaDB 10.11 (prod). No usa sintaxis
-- MariaDB-only (`RENAME COLUMN IF EXISTS`).

SET @db := DATABASE();

-- --------------------------------------------------------------------
-- 1) `proyecto` -> `proyecto_id`
-- --------------------------------------------------------------------
SET @sql := (
  SELECT IF(
    (SELECT COUNT(*) FROM information_schema.COLUMNS
      WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'datarocket_plantillas'
        AND COLUMN_NAME = 'proyecto') > 0
    AND
    (SELECT COUNT(*) FROM information_schema.COLUMNS
      WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'datarocket_plantillas'
        AND COLUMN_NAME = 'proyecto_id') = 0,
    'ALTER TABLE `datarocket_plantillas`
       CHANGE COLUMN `proyecto` `proyecto_id` INT(11) NULL DEFAULT NULL',
    'SELECT 1'
  )
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- --------------------------------------------------------------------
-- 2) `uuid` -> `slug`
-- --------------------------------------------------------------------
SET @sql := (
  SELECT IF(
    (SELECT COUNT(*) FROM information_schema.COLUMNS
      WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'datarocket_plantillas'
        AND COLUMN_NAME = 'uuid') > 0
    AND
    (SELECT COUNT(*) FROM information_schema.COLUMNS
      WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'datarocket_plantillas'
        AND COLUMN_NAME = 'slug') = 0,
    'ALTER TABLE `datarocket_plantillas`
       CHANGE COLUMN `uuid` `slug` VARCHAR(10) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NULL DEFAULT NULL',
    'SELECT 1'
  )
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
