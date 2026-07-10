-- Agrega la columna `empresa_id` a `datacount_cuentas`, en segunda posicion
-- (justo despues de `id`). Todas las cuentas ya existentes se backfillean
-- con `empresa_id = 1` (empresa por defecto de Datacount).
--
-- Idempotente: chequea INFORMATION_SCHEMA antes de tocar la columna y el
-- indice, asi corre tanto sobre bases nuevas (schema.sql ya trae la columna)
-- como sobre bases viejas. Compatible con MySQL 8 y MariaDB 10.11 (no usa
-- `ADD COLUMN IF NOT EXISTS`, que es MariaDB-only).

SET @db := DATABASE();

-- empresa_id: agregar despues de `id` si no existe
SET @sql := (
  SELECT IF(
    (SELECT COUNT(*) FROM information_schema.COLUMNS
      WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'datacount_cuentas'
        AND COLUMN_NAME = 'empresa_id') > 0,
    'SELECT 1',
    'ALTER TABLE `datacount_cuentas`
       ADD COLUMN `empresa_id` INT NOT NULL DEFAULT 1 AFTER `id`'
  )
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- Backfill explicito por si alguna fila quedo con NULL/0 (defensa en profundidad).
UPDATE `datacount_cuentas` SET `empresa_id` = 1 WHERE `empresa_id` IS NULL OR `empresa_id` = 0;

-- Indice sobre empresa_id (los listados van a filtrar por empresa)
SET @sql := (
  SELECT IF(
    (SELECT COUNT(*) FROM information_schema.STATISTICS
      WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'datacount_cuentas'
        AND INDEX_NAME = 'idx_empresa') > 0,
    'SELECT 1',
    'ALTER TABLE `datacount_cuentas` ADD INDEX `idx_empresa`(`empresa_id`)'
  )
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
