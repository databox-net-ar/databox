-- Alinea `datacount_empleados` al esquema declarado en db/schema.sql (mismo
-- que aplica la migracion 20260710_2100_crear_datacount_empleados.sql en
-- entornos donde la tabla no existia). Este archivo cubre entornos donde la
-- tabla ya existia con la forma vieja (columnas `ciudad`, `telefono`, `funcion`,
-- `cvu`, `usuario_id`, `cuenta_id`, `activo` varchar y sin `empresa_id`).
--
-- Transformaciones:
--   - AGREGA  empresa_id, habilitado, created_at, updated_at
--   - RENOMBRA telefono   -> celular  (y ensancha a varchar(20))
--   - RENOMBRA cuenta_id  -> cuenta   (int UNSIGNED)
--   - MODIFICA nombre NOT NULL, documento varchar(15), correo varchar(120),
--              sueldo decimal(14,2) NOT NULL DEFAULT 0.00
--   - BACKFILL habilitado <- activo (LOWER(activo) IN ('1','si','activo','yes','true'))
--   - ELIMINA  ciudad, funcion, cvu, usuario_id, activo
--   - AGREGA   indices idx_empresa, idx_cuenta, idx_habilitado, idx_documento
--
-- Idempotente: cada paso chequea INFORMATION_SCHEMA antes de tocar el esquema.
-- Compatible con MySQL 8 y MariaDB 10.11 (no usa `ADD/DROP COLUMN IF [NOT] EXISTS`
-- que son MariaDB-only). Usa PREPARE/EXECUTE.

SET @db := DATABASE();

-- --------------------------------------------------------------------
-- 1) Agregar columna `empresa_id` (despues de `id`) si no existe.
--    Se inicializa en 1 y despues cada instalacion puede reasignar.
-- --------------------------------------------------------------------
SET @sql := (
  SELECT IF(
    (SELECT COUNT(*) FROM information_schema.COLUMNS
      WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'datacount_empleados'
        AND COLUMN_NAME = 'empresa_id') > 0,
    'SELECT 1',
    'ALTER TABLE `datacount_empleados`
       ADD COLUMN `empresa_id` INT(11) UNSIGNED NOT NULL DEFAULT 1 AFTER `id`'
  )
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- --------------------------------------------------------------------
-- 2) Renombrar `telefono` -> `celular` (ensanchando a varchar(20)).
-- --------------------------------------------------------------------
SET @sql := (
  SELECT IF(
    (SELECT COUNT(*) FROM information_schema.COLUMNS
      WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'datacount_empleados'
        AND COLUMN_NAME = 'telefono') > 0,
    'ALTER TABLE `datacount_empleados`
       CHANGE COLUMN `telefono` `celular` VARCHAR(20) NULL DEFAULT NULL',
    'SELECT 1'
  )
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- --------------------------------------------------------------------
-- 3) Si no habia `telefono` pero tampoco hay `celular`, crearla.
-- --------------------------------------------------------------------
SET @sql := (
  SELECT IF(
    (SELECT COUNT(*) FROM information_schema.COLUMNS
      WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'datacount_empleados'
        AND COLUMN_NAME = 'celular') > 0,
    'SELECT 1',
    'ALTER TABLE `datacount_empleados`
       ADD COLUMN `celular` VARCHAR(20) NULL DEFAULT NULL AFTER `domicilio`'
  )
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- --------------------------------------------------------------------
-- 4) Renombrar `cuenta_id` -> `cuenta` (int UNSIGNED).
-- --------------------------------------------------------------------
SET @sql := (
  SELECT IF(
    (SELECT COUNT(*) FROM information_schema.COLUMNS
      WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'datacount_empleados'
        AND COLUMN_NAME = 'cuenta_id') > 0,
    'ALTER TABLE `datacount_empleados`
       CHANGE COLUMN `cuenta_id` `cuenta` INT(11) UNSIGNED NULL DEFAULT NULL',
    'SELECT 1'
  )
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- --------------------------------------------------------------------
-- 5) Si no habia `cuenta_id` pero tampoco `cuenta`, crearla.
-- --------------------------------------------------------------------
SET @sql := (
  SELECT IF(
    (SELECT COUNT(*) FROM information_schema.COLUMNS
      WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'datacount_empleados'
        AND COLUMN_NAME = 'cuenta') > 0,
    'SELECT 1',
    'ALTER TABLE `datacount_empleados`
       ADD COLUMN `cuenta` INT(11) UNSIGNED NULL DEFAULT NULL'
  )
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- --------------------------------------------------------------------
-- 6) Agregar columna `habilitado` (default 1). Si existia `activo` (varchar),
--    backfill a partir de sus valores comunes (`1`, `si`, `activo`, `yes`, `true`).
-- --------------------------------------------------------------------
SET @sql := (
  SELECT IF(
    (SELECT COUNT(*) FROM information_schema.COLUMNS
      WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'datacount_empleados'
        AND COLUMN_NAME = 'habilitado') > 0,
    'SELECT 1',
    'ALTER TABLE `datacount_empleados`
       ADD COLUMN `habilitado` TINYINT(1) NOT NULL DEFAULT 1'
  )
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql := (
  SELECT IF(
    (SELECT COUNT(*) FROM information_schema.COLUMNS
      WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'datacount_empleados'
        AND COLUMN_NAME = 'activo') > 0,
    'UPDATE `datacount_empleados`
        SET `habilitado` = CASE
          WHEN LOWER(TRIM(COALESCE(`activo`,''''))) IN (''1'',''si'',''sí'',''activo'',''yes'',''true'') THEN 1
          WHEN LOWER(TRIM(COALESCE(`activo`,''''))) IN (''0'',''no'',''inactivo'',''false'') THEN 0
          ELSE 1
        END',
    'SELECT 1'
  )
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- --------------------------------------------------------------------
-- 7) Modificar tipos y nullability para que coincidan con schema.sql.
--    Se hace incondicional porque MODIFY es idempotente.
-- --------------------------------------------------------------------
ALTER TABLE `datacount_empleados`
  MODIFY COLUMN `nombre`        VARCHAR(100) NOT NULL,
  MODIFY COLUMN `documento`     VARCHAR(15)  NULL DEFAULT NULL,
  MODIFY COLUMN `correo`        VARCHAR(120) NULL DEFAULT NULL,
  MODIFY COLUMN `sueldo`        DECIMAL(14,2) NOT NULL DEFAULT 0.00,
  MODIFY COLUMN `observaciones` VARCHAR(1000) NULL DEFAULT NULL;

-- --------------------------------------------------------------------
-- 8) Timestamps.
-- --------------------------------------------------------------------
SET @sql := (
  SELECT IF(
    (SELECT COUNT(*) FROM information_schema.COLUMNS
      WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'datacount_empleados'
        AND COLUMN_NAME = 'created_at') > 0,
    'SELECT 1',
    'ALTER TABLE `datacount_empleados`
       ADD COLUMN `created_at` TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP'
  )
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql := (
  SELECT IF(
    (SELECT COUNT(*) FROM information_schema.COLUMNS
      WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'datacount_empleados'
        AND COLUMN_NAME = 'updated_at') > 0,
    'SELECT 1',
    'ALTER TABLE `datacount_empleados`
       ADD COLUMN `updated_at` TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP'
  )
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- --------------------------------------------------------------------
-- 9) Eliminar columnas que ya no forman parte del esquema.
-- --------------------------------------------------------------------
SET @sql := (
  SELECT IF(
    (SELECT COUNT(*) FROM information_schema.COLUMNS
      WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'datacount_empleados'
        AND COLUMN_NAME = 'ciudad') > 0,
    'ALTER TABLE `datacount_empleados` DROP COLUMN `ciudad`',
    'SELECT 1'
  )
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql := (
  SELECT IF(
    (SELECT COUNT(*) FROM information_schema.COLUMNS
      WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'datacount_empleados'
        AND COLUMN_NAME = 'funcion') > 0,
    'ALTER TABLE `datacount_empleados` DROP COLUMN `funcion`',
    'SELECT 1'
  )
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql := (
  SELECT IF(
    (SELECT COUNT(*) FROM information_schema.COLUMNS
      WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'datacount_empleados'
        AND COLUMN_NAME = 'cvu') > 0,
    'ALTER TABLE `datacount_empleados` DROP COLUMN `cvu`',
    'SELECT 1'
  )
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql := (
  SELECT IF(
    (SELECT COUNT(*) FROM information_schema.COLUMNS
      WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'datacount_empleados'
        AND COLUMN_NAME = 'usuario_id') > 0,
    'ALTER TABLE `datacount_empleados` DROP COLUMN `usuario_id`',
    'SELECT 1'
  )
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql := (
  SELECT IF(
    (SELECT COUNT(*) FROM information_schema.COLUMNS
      WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'datacount_empleados'
        AND COLUMN_NAME = 'activo') > 0,
    'ALTER TABLE `datacount_empleados` DROP COLUMN `activo`',
    'SELECT 1'
  )
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- --------------------------------------------------------------------
-- 10) Indices.
-- --------------------------------------------------------------------
SET @sql := (
  SELECT IF(
    (SELECT COUNT(*) FROM information_schema.STATISTICS
      WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'datacount_empleados'
        AND INDEX_NAME = 'idx_empresa') > 0,
    'SELECT 1',
    'ALTER TABLE `datacount_empleados` ADD INDEX `idx_empresa`(`empresa_id`)'
  )
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql := (
  SELECT IF(
    (SELECT COUNT(*) FROM information_schema.STATISTICS
      WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'datacount_empleados'
        AND INDEX_NAME = 'idx_cuenta') > 0,
    'SELECT 1',
    'ALTER TABLE `datacount_empleados` ADD INDEX `idx_cuenta`(`cuenta`)'
  )
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql := (
  SELECT IF(
    (SELECT COUNT(*) FROM information_schema.STATISTICS
      WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'datacount_empleados'
        AND INDEX_NAME = 'idx_habilitado') > 0,
    'SELECT 1',
    'ALTER TABLE `datacount_empleados` ADD INDEX `idx_habilitado`(`habilitado`)'
  )
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql := (
  SELECT IF(
    (SELECT COUNT(*) FROM information_schema.STATISTICS
      WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'datacount_empleados'
        AND INDEX_NAME = 'idx_documento') > 0,
    'SELECT 1',
    'ALTER TABLE `datacount_empleados` ADD INDEX `idx_documento`(`documento`)'
  )
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
