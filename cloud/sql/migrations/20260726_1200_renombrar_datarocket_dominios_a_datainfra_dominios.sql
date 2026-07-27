-- Renombra la tabla `datarocket_dominios` a `datainfra_dominios`. El modulo
-- Dominios se mueve conceptualmente de "Sistemas > Datarocket" a
-- "Sistemas > Datainfra" (nombres DNS de la infra que administra Databox),
-- y arrastra su tabla + endpoints + permisos + rutas + job cron.
--
-- Ademas actualiza los `script` de la tabla `tareas` que apuntaban al job
-- viejo `datarocketdominios_actualizar_whois` -> `datainfradominios_actualizar_whois`,
-- para que el Programador de tareas siga disparando la corrida diaria despues
-- del renombrado del archivo PHP.
--
-- Idempotente en los 3 pasos: solo hace algo si el nombre viejo existe (o si
-- todavia quedan filas de tareas con el script viejo). Patron
-- INFORMATION_SCHEMA + PREPARE/EXECUTE, compatible con MySQL 8 y
-- MariaDB 10.11 (`RENAME TABLE IF EXISTS` es MariaDB-only).

-- ============================================================================
-- Paso 1: RENAME de la tabla.
-- ============================================================================

SET @db := DATABASE();

SET @sql := (
  SELECT IF(
    (SELECT COUNT(*) FROM information_schema.TABLES
      WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'datarocket_dominios') > 0
    AND
    (SELECT COUNT(*) FROM information_schema.TABLES
      WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'datainfra_dominios') = 0,
    'RENAME TABLE `datarocket_dominios` TO `datainfra_dominios`',
    'SELECT 1'
  )
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- ============================================================================
-- Paso 2: renombrar los indices para que sigan el prefijo `datainfra_dominios`.
-- Solo tienen efecto si la tabla existe con el nombre nuevo Y los indices
-- viejos todavia estan (post-RENAME de Paso 1 arrastra los indices con sus
-- nombres originales). Idempotente en los 3: si el indice viejo ya no existe
-- (por rerun de la migracion), es no-op.
-- ============================================================================

SET @tbl_ok := (SELECT COUNT(*) FROM information_schema.TABLES
                 WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'datainfra_dominios');

SET @sql := (
  SELECT IF(
    @tbl_ok > 0 AND
    (SELECT COUNT(*) FROM information_schema.STATISTICS
      WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'datainfra_dominios'
        AND INDEX_NAME = 'uq_datarocket_dominios_dominio') > 0,
    'ALTER TABLE `datainfra_dominios` RENAME INDEX `uq_datarocket_dominios_dominio` TO `uq_datainfra_dominios_dominio`',
    'SELECT 1'
  )
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql := (
  SELECT IF(
    @tbl_ok > 0 AND
    (SELECT COUNT(*) FROM information_schema.STATISTICS
      WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'datainfra_dominios'
        AND INDEX_NAME = 'idx_datarocket_dominios_prox_renov') > 0,
    'ALTER TABLE `datainfra_dominios` RENAME INDEX `idx_datarocket_dominios_prox_renov` TO `idx_datainfra_dominios_prox_renov`',
    'SELECT 1'
  )
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql := (
  SELECT IF(
    @tbl_ok > 0 AND
    (SELECT COUNT(*) FROM information_schema.STATISTICS
      WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'datainfra_dominios'
        AND INDEX_NAME = 'idx_datarocket_dominios_responsable') > 0,
    'ALTER TABLE `datainfra_dominios` RENAME INDEX `idx_datarocket_dominios_responsable` TO `idx_datainfra_dominios_responsable`',
    'SELECT 1'
  )
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- ============================================================================
-- Paso 3: reapuntar el `script` de `tareas` al nuevo nombre de archivo.
-- ============================================================================

UPDATE `tareas`
   SET `script` = 'datainfradominios_actualizar_whois'
 WHERE `script` = 'datarocketdominios_actualizar_whois';
