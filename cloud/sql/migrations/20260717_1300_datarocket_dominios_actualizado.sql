-- Agrega la columna `actualizado` (DATETIME NULL) a `datarocket_dominios`
-- para trackear la ultima vez que los datos WHOIS del dominio fueron
-- refrescados (por click derecho -> "Actualizar WHOIS" o por el job
-- `datarocketdominios_actualizar_whois`). NULL = nunca se actualizo.
--
-- Idempotente: chequea INFORMATION_SCHEMA antes de tocar la columna.
-- Compatible con MySQL 8 y MariaDB 10.11 (no usa ADD COLUMN IF NOT EXISTS,
-- MariaDB-only).

SET @db := DATABASE();

SET @sql := (
  SELECT IF(
    (SELECT COUNT(*) FROM information_schema.COLUMNS
      WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'datarocket_dominios'
        AND COLUMN_NAME = 'actualizado') > 0,
    'SELECT 1',
    'ALTER TABLE `datarocket_dominios`
       ADD COLUMN `actualizado` DATETIME NULL DEFAULT NULL AFTER `moneda`'
  )
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
