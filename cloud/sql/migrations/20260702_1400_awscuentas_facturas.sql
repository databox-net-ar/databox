-- Agrega las columnas que cachean el resultado de la ultima consulta a AWS:
--
--   facturas_cantidad     -> cuantas facturas coinciden con la deuda (match subset-sum)
--   facturas_total        -> monto pendiente total (fuente: BCM Recommended Actions)
--   facturas_moneda       -> codigo de moneda (default 'USD')
--   facturas_actualizado  -> cuando se sincronizo con AWS
--
-- Ademas dropea las columnas `saldo`, `saldo_moneda`, `saldo_actualizado` de
-- una version anterior de esta misma migracion (si existen), para que quede
-- todo bajo el prefijo `facturas_`.
--
-- Idempotente: chequea INFORMATION_SCHEMA por cada columna (patron
-- compatible con MySQL 8 y MariaDB 10.11).

SET @db := DATABASE();

-- --- DROPs de columnas viejas (saldo_*) si sobrevivieron ---

SET @sql := (
  SELECT IF(
    (SELECT COUNT(*) FROM information_schema.COLUMNS
      WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'awscuentas'
        AND COLUMN_NAME = 'saldo_actualizado') > 0,
    'ALTER TABLE `awscuentas` DROP COLUMN `saldo_actualizado`',
    'SELECT 1'
  )
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql := (
  SELECT IF(
    (SELECT COUNT(*) FROM information_schema.COLUMNS
      WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'awscuentas'
        AND COLUMN_NAME = 'saldo_moneda') > 0,
    'ALTER TABLE `awscuentas` DROP COLUMN `saldo_moneda`',
    'SELECT 1'
  )
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql := (
  SELECT IF(
    (SELECT COUNT(*) FROM information_schema.COLUMNS
      WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'awscuentas'
        AND COLUMN_NAME = 'saldo') > 0,
    'ALTER TABLE `awscuentas` DROP COLUMN `saldo`',
    'SELECT 1'
  )
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- --- ADDs de columnas nuevas (facturas_*) ---

SET @sql := (
  SELECT IF(
    (SELECT COUNT(*) FROM information_schema.COLUMNS
      WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'awscuentas'
        AND COLUMN_NAME = 'facturas_cantidad') > 0,
    'SELECT 1',
    'ALTER TABLE `awscuentas` ADD COLUMN `facturas_cantidad` INT NULL DEFAULT NULL AFTER `secreto`'
  )
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql := (
  SELECT IF(
    (SELECT COUNT(*) FROM information_schema.COLUMNS
      WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'awscuentas'
        AND COLUMN_NAME = 'facturas_total') > 0,
    'SELECT 1',
    'ALTER TABLE `awscuentas` ADD COLUMN `facturas_total` DECIMAL(11,2) NULL DEFAULT NULL AFTER `facturas_cantidad`'
  )
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql := (
  SELECT IF(
    (SELECT COUNT(*) FROM information_schema.COLUMNS
      WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'awscuentas'
        AND COLUMN_NAME = 'facturas_moneda') > 0,
    'SELECT 1',
    'ALTER TABLE `awscuentas` ADD COLUMN `facturas_moneda` VARCHAR(10) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NULL DEFAULT NULL AFTER `facturas_total`'
  )
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql := (
  SELECT IF(
    (SELECT COUNT(*) FROM information_schema.COLUMNS
      WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'awscuentas'
        AND COLUMN_NAME = 'facturas_actualizado') > 0,
    'SELECT 1',
    'ALTER TABLE `awscuentas` ADD COLUMN `facturas_actualizado` DATETIME NULL DEFAULT NULL AFTER `facturas_moneda`'
  )
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
