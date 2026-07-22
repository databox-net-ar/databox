-- Renombra la tabla `awscuentas` a `aws_cuentas` para alinearla con la
-- convencion snake_case del resto del esquema (ej. `datacount_clientes`,
-- `datarocket_dominios`, `movistarsims`/`clarosims` son legado que se ira
-- migrando aparte).
--
-- Idempotente: solo renombra si el nombre viejo existe y el nuevo aun no.
-- Si la tabla ya fue renombrada (nombre nuevo presente), es no-op. Patron
-- INFORMATION_SCHEMA + PREPARE/EXECUTE, compatible con MySQL 8 y MariaDB
-- 10.11 (`RENAME TABLE IF EXISTS` es MariaDB-only).

SET @db := DATABASE();

SET @sql := (
  SELECT IF(
    (SELECT COUNT(*) FROM information_schema.TABLES
      WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'awscuentas') > 0
    AND
    (SELECT COUNT(*) FROM information_schema.TABLES
      WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'aws_cuentas') = 0,
    'RENAME TABLE `awscuentas` TO `aws_cuentas`',
    'SELECT 1'
  )
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
