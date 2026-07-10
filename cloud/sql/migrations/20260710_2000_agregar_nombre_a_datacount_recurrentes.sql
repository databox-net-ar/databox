-- Agrega la columna `nombre` a `datacount_recurrentes` para poder titular
-- cada movimiento recurrente (aparte de la empresa + cuenta, que siguen
-- siendo las claves funcionales).
--
-- Idempotente: chequea INFORMATION_SCHEMA antes de tocar la columna, asi
-- corre tanto sobre bases nuevas como sobre bases viejas. Compatible con
-- MySQL 8 y MariaDB 10.11 (no usa `ADD COLUMN IF NOT EXISTS`, MariaDB-only).

SET @db := DATABASE();

-- --------------------------------------------------------------------
-- Agregar columna `nombre` (despues de `id`) si no existe.
-- --------------------------------------------------------------------
SET @sql := (
  SELECT IF(
    (SELECT COUNT(*) FROM information_schema.COLUMNS
      WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'datacount_recurrentes'
        AND COLUMN_NAME = 'nombre') > 0,
    'SELECT 1',
    'ALTER TABLE `datacount_recurrentes`
       ADD COLUMN `nombre` VARCHAR(150) NOT NULL DEFAULT '''' AFTER `id`'
  )
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
