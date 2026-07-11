-- Actualiza `datacount_empleados` con tres cambios de contrato:
--   1) `cuenta`      -> `cuenta_id`    (mismo tipo INT UNSIGNED NULL)
--   2) `cvu`                             -> nueva columna VARCHAR(50) despues de `sueldo`
--   3) `habilitado`  -> `activo`       (ENUM('si','no') NOT NULL DEFAULT 'si')
--
-- Los indices se re-crean sobre los nuevos nombres (`idx_cuenta_id`, `idx_activo`).
--
-- Idempotente: cada paso chequea INFORMATION_SCHEMA antes de tocar el esquema.
-- Compatible con MySQL 8 y MariaDB 10.11 (no usa `ADD/DROP COLUMN IF [NOT] EXISTS`,
-- que son MariaDB-only). Usa PREPARE/EXECUTE.

SET @db := DATABASE();

-- --------------------------------------------------------------------
-- 1) cuenta -> cuenta_id (mantiene tipo y nullability).
-- --------------------------------------------------------------------
SET @sql := (
  SELECT IF(
    (SELECT COUNT(*) FROM information_schema.COLUMNS
      WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'datacount_empleados'
        AND COLUMN_NAME = 'cuenta') > 0,
    'ALTER TABLE `datacount_empleados`
       CHANGE COLUMN `cuenta` `cuenta_id` INT(11) UNSIGNED NULL DEFAULT NULL',
    'SELECT 1'
  )
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- Si no existia ni `cuenta` ni `cuenta_id`, crear la nueva.
SET @sql := (
  SELECT IF(
    (SELECT COUNT(*) FROM information_schema.COLUMNS
      WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'datacount_empleados'
        AND COLUMN_NAME = 'cuenta_id') > 0,
    'SELECT 1',
    'ALTER TABLE `datacount_empleados`
       ADD COLUMN `cuenta_id` INT(11) UNSIGNED NULL DEFAULT NULL'
  )
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- --------------------------------------------------------------------
-- 2) Agregar columna `cvu` VARCHAR(50) NULL despues de `sueldo`.
-- --------------------------------------------------------------------
SET @sql := (
  SELECT IF(
    (SELECT COUNT(*) FROM information_schema.COLUMNS
      WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'datacount_empleados'
        AND COLUMN_NAME = 'cvu') > 0,
    'SELECT 1',
    'ALTER TABLE `datacount_empleados`
       ADD COLUMN `cvu` VARCHAR(50) NULL DEFAULT NULL AFTER `sueldo`'
  )
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- --------------------------------------------------------------------
-- 3) habilitado (tinyint) -> activo ENUM('si','no').
--    Se hace en dos pasos para preservar el valor:
--      a) crear la nueva columna `activo` con default 'si'
--      b) backfill desde `habilitado`: 1 -> 'si', 0 -> 'no'
--      c) drop de `habilitado`
-- --------------------------------------------------------------------
SET @sql := (
  SELECT IF(
    (SELECT COUNT(*) FROM information_schema.COLUMNS
      WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'datacount_empleados'
        AND COLUMN_NAME = 'activo') > 0,
    'SELECT 1',
    'ALTER TABLE `datacount_empleados`
       ADD COLUMN `activo` ENUM(''si'',''no'') NOT NULL DEFAULT ''si'''
  )
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql := (
  SELECT IF(
    (SELECT COUNT(*) FROM information_schema.COLUMNS
      WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'datacount_empleados'
        AND COLUMN_NAME = 'habilitado') > 0,
    'UPDATE `datacount_empleados`
        SET `activo` = CASE WHEN `habilitado` = 1 THEN ''si'' ELSE ''no'' END',
    'SELECT 1'
  )
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql := (
  SELECT IF(
    (SELECT COUNT(*) FROM information_schema.COLUMNS
      WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'datacount_empleados'
        AND COLUMN_NAME = 'habilitado') > 0,
    'ALTER TABLE `datacount_empleados` DROP COLUMN `habilitado`',
    'SELECT 1'
  )
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- --------------------------------------------------------------------
-- 4) Re-crear indices sobre los nombres nuevos.
--    Primero dropear los viejos (`idx_cuenta`, `idx_habilitado`) si sobreviven.
-- --------------------------------------------------------------------
SET @sql := (
  SELECT IF(
    (SELECT COUNT(*) FROM information_schema.STATISTICS
      WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'datacount_empleados'
        AND INDEX_NAME = 'idx_cuenta') > 0,
    'ALTER TABLE `datacount_empleados` DROP INDEX `idx_cuenta`',
    'SELECT 1'
  )
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql := (
  SELECT IF(
    (SELECT COUNT(*) FROM information_schema.STATISTICS
      WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'datacount_empleados'
        AND INDEX_NAME = 'idx_habilitado') > 0,
    'ALTER TABLE `datacount_empleados` DROP INDEX `idx_habilitado`',
    'SELECT 1'
  )
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql := (
  SELECT IF(
    (SELECT COUNT(*) FROM information_schema.STATISTICS
      WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'datacount_empleados'
        AND INDEX_NAME = 'idx_cuenta_id') > 0,
    'SELECT 1',
    'ALTER TABLE `datacount_empleados` ADD INDEX `idx_cuenta_id`(`cuenta_id`)'
  )
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql := (
  SELECT IF(
    (SELECT COUNT(*) FROM information_schema.STATISTICS
      WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'datacount_empleados'
        AND INDEX_NAME = 'idx_activo') > 0,
    'SELECT 1',
    'ALTER TABLE `datacount_empleados` ADD INDEX `idx_activo`(`activo`)'
  )
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
