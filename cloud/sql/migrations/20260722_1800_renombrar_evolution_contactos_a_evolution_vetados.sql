-- Renombra la tabla `evolution_contactos` a `evolution_vetados`. La tabla
-- registra los destinos que Evolution API marca como no entregables
-- (vetados por error de validacion o rebote), asi que el nombre nuevo
-- refleja mejor la semantica que "contactos".
--
-- El modulo "Plataformas > Evolution API > Contactos" pasa a llamarse
-- "Vetados" en la UI a partir de esta migracion.
--
-- Idempotente: solo renombra si el nombre viejo existe y el nuevo aun no.
-- Si la tabla ya fue renombrada (nombre nuevo presente), es no-op. Patron
-- INFORMATION_SCHEMA + PREPARE/EXECUTE, compatible con MySQL 8 y MariaDB
-- 10.11 (`RENAME TABLE IF EXISTS` es MariaDB-only).

SET @db := DATABASE();

SET @sql := (
  SELECT IF(
    (SELECT COUNT(*) FROM information_schema.TABLES
      WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'evolution_contactos') > 0
    AND
    (SELECT COUNT(*) FROM information_schema.TABLES
      WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'evolution_vetados') = 0,
    'RENAME TABLE `evolution_contactos` TO `evolution_vetados`',
    'SELECT 1'
  )
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
