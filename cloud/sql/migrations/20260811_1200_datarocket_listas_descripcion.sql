-- datarocket_listas: agregar `descripcion` VARCHAR(500) NULL DEFAULT NULL
-- despues de `nombre`. La tabla ya existia con (id, proyecto_id, nombre,
-- suscripciones); esta migracion suma el unico campo que faltaba para que
-- el ABM cloud "Datarocket > Listas" pueda anotar el proposito de cada
-- lista. Todas las listas existentes quedan con NULL.
--
-- Compatible con MySQL 8 (dev) y MariaDB 10.11 (prod): se usa el patron
-- information_schema + PREPARE/EXECUTE porque MySQL 8 no soporta la
-- sintaxis MariaDB `ADD COLUMN IF NOT EXISTS`.

SET @exists := (
  SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME   = 'datarocket_listas'
    AND COLUMN_NAME  = 'descripcion'
);
SET @sql := IF(@exists = 0,
  'ALTER TABLE datarocket_listas ADD COLUMN `descripcion` VARCHAR(500) NULL DEFAULT NULL AFTER `nombre`',
  'DO 0'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
