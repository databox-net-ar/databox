-- Corrige el nombre de la tabla que quedo como `datainfradominios` (sin
-- guion bajo) por un bug de una version previa de la migracion
-- `20260726_1200_renombrar_datarocket_dominios_a_datainfra_dominios.sql`
-- que aplicaba el RENAME al nombre incorrecto. Deberia haberse llamado
-- `datainfra_dominios` con guion bajo, siguiendo la convencion del resto
-- del esquema (`aws_cuentas`, `datarocket_mensajes`, `datacount_clientes`,
-- etc.).
--
-- Renombra:
--   * la tabla:  `datainfradominios`               -> `datainfra_dominios`
--   * indices:   `uq_datainfradominios_dominio`    -> `uq_datainfra_dominios_dominio`
--                `idx_datainfradominios_prox_renov`-> `idx_datainfra_dominios_prox_renov`
--                `idx_datainfradominios_responsable`-> `idx_datainfra_dominios_responsable`
--
-- Idempotente en los 4 pasos: solo hace algo si el nombre viejo existe y el
-- nuevo aun no. Patron INFORMATION_SCHEMA + PREPARE/EXECUTE, compatible con
-- MySQL 8 y MariaDB 10.11.

SET @db := DATABASE();

-- ============================================================================
-- Paso 1: RENAME de la tabla `datainfradominios` -> `datainfra_dominios`.
-- ============================================================================

SET @sql := (
  SELECT IF(
    (SELECT COUNT(*) FROM information_schema.TABLES
      WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'datainfradominios') > 0
    AND
    (SELECT COUNT(*) FROM information_schema.TABLES
      WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'datainfra_dominios') = 0,
    'RENAME TABLE `datainfradominios` TO `datainfra_dominios`',
    'SELECT 1'
  )
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- ============================================================================
-- Paso 2..4: renombrar los 3 indices heredados que quedaron con el prefijo
-- viejo `datainfradominios_*` -> `datainfra_dominios_*`. Solo aplica si el
-- indice viejo todavia esta (post-RENAME de la tabla los arrastra con sus
-- nombres originales) y el indice nuevo aun no existe.
-- ============================================================================

SET @tbl_ok := (SELECT COUNT(*) FROM information_schema.TABLES
                 WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'datainfra_dominios');

SET @sql := (
  SELECT IF(
    @tbl_ok > 0 AND
    (SELECT COUNT(*) FROM information_schema.STATISTICS
      WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'datainfra_dominios'
        AND INDEX_NAME = 'uq_datainfradominios_dominio') > 0
    AND
    (SELECT COUNT(*) FROM information_schema.STATISTICS
      WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'datainfra_dominios'
        AND INDEX_NAME = 'uq_datainfra_dominios_dominio') = 0,
    'ALTER TABLE `datainfra_dominios` RENAME INDEX `uq_datainfradominios_dominio` TO `uq_datainfra_dominios_dominio`',
    'SELECT 1'
  )
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql := (
  SELECT IF(
    @tbl_ok > 0 AND
    (SELECT COUNT(*) FROM information_schema.STATISTICS
      WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'datainfra_dominios'
        AND INDEX_NAME = 'idx_datainfradominios_prox_renov') > 0
    AND
    (SELECT COUNT(*) FROM information_schema.STATISTICS
      WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'datainfra_dominios'
        AND INDEX_NAME = 'idx_datainfra_dominios_prox_renov') = 0,
    'ALTER TABLE `datainfra_dominios` RENAME INDEX `idx_datainfradominios_prox_renov` TO `idx_datainfra_dominios_prox_renov`',
    'SELECT 1'
  )
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql := (
  SELECT IF(
    @tbl_ok > 0 AND
    (SELECT COUNT(*) FROM information_schema.STATISTICS
      WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'datainfra_dominios'
        AND INDEX_NAME = 'idx_datainfradominios_responsable') > 0
    AND
    (SELECT COUNT(*) FROM information_schema.STATISTICS
      WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'datainfra_dominios'
        AND INDEX_NAME = 'idx_datainfra_dominios_responsable') = 0,
    'ALTER TABLE `datainfra_dominios` RENAME INDEX `idx_datainfradominios_responsable` TO `idx_datainfra_dominios_responsable`',
    'SELECT 1'
  )
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
