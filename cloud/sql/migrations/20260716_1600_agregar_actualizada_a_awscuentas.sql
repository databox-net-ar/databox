-- Agrega la columna `actualizada` (DATETIME NULL) a `awscuentas` para trackear
-- la ultima vez que los datos de la cuenta fueron actualizados por el job.
-- Reemplaza el uso funcional de `facturas_actualizado` (que queda como
-- artefacto historico dentro del snapshot de facturas_json).
--
-- Idempotente: chequea INFORMATION_SCHEMA antes de tocar la columna, asi
-- corre tanto sobre bases nuevas como sobre bases viejas. Compatible con
-- MySQL 8 y MariaDB 10.11 (no usa `ADD COLUMN IF NOT EXISTS`, MariaDB-only).

SET @db := DATABASE();

-- --------------------------------------------------------------------
-- Agregar columna `actualizada` si no existe.
-- --------------------------------------------------------------------
SET @sql := (
  SELECT IF(
    (SELECT COUNT(*) FROM information_schema.COLUMNS
      WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'awscuentas'
        AND COLUMN_NAME = 'actualizada') > 0,
    'SELECT 1',
    'ALTER TABLE `awscuentas`
       ADD COLUMN `actualizada` DATETIME NULL DEFAULT NULL AFTER `facturas_json`'
  )
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- --------------------------------------------------------------------
-- Backfill: para cuentas ya sincronizadas alguna vez, arranca la nueva
-- columna con el valor que traia `facturas_actualizado`. Sin esto, todas
-- las cuentas quedarian como "sin datos" hasta que el job corra por
-- primera vez despues del deploy.
-- --------------------------------------------------------------------
UPDATE `awscuentas`
   SET `actualizada` = `facturas_actualizado`
 WHERE `actualizada` IS NULL
   AND `facturas_actualizado` IS NOT NULL;
