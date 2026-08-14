-- Clona la estructura y los datos de `dolarhoycotizaciones` a
-- `dolarhoy_cotizaciones` para migrar el submodulo Cotizaciones del modulo
-- Dolarhoy al naming convention snake_case (alineado con `datacount_pagos`,
-- `datarocket_contactos`, `claro_sims`, `movistar_sims`, `aws_canales`, etc.).
--
-- La tabla vieja (`dolarhoycotizaciones`) queda en la base hasta confirmar
-- que ningun otro proyecto del grupo la consulta. A partir de esta migracion
-- el ABM del cloud (cloud/api/dolarhoy_cotizaciones.php), el microservicio
-- v4 (api/v4/dolarhoy/cotizacion.php), la valorizacion de pagos
-- (cloud/api/datacount_pagos.php) y el job diario
-- (cloud/jobs/dolarhoy_cotizacion_actualizar.php) leen y escriben unicamente
-- contra la nueva tabla snake_case.
--
-- Idempotente:
--   * CREATE TABLE ... LIKE solo si la origen existe y la destino aun no.
--   * INSERT IGNORE ... SELECT solo si ambas tablas existen (asi una
--     re-corrida no duplica filas por PK ni pisa cambios manuales).
-- Patron `information_schema` + `PREPARE`/`EXECUTE`, compatible con
-- MySQL 8 y MariaDB 10.11.

SET @db := DATABASE();

-- ============================================================================
-- dolarhoy_cotizaciones <- dolarhoycotizaciones
-- ============================================================================
SET @sql := (
  SELECT IF(
    (SELECT COUNT(*) FROM information_schema.TABLES
      WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'dolarhoycotizaciones') > 0
    AND
    (SELECT COUNT(*) FROM information_schema.TABLES
      WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'dolarhoy_cotizaciones') = 0,
    'CREATE TABLE `dolarhoy_cotizaciones` LIKE `dolarhoycotizaciones`',
    'SELECT 1'
  )
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql := (
  SELECT IF(
    (SELECT COUNT(*) FROM information_schema.TABLES
      WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'dolarhoycotizaciones') > 0
    AND
    (SELECT COUNT(*) FROM information_schema.TABLES
      WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'dolarhoy_cotizaciones') > 0,
    'INSERT IGNORE INTO `dolarhoy_cotizaciones` SELECT * FROM `dolarhoycotizaciones`',
    'SELECT 1'
  )
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- ============================================================================
-- Indice por fecha
-- ============================================================================
-- La tabla vieja no tenia mas indice que la PK, pero todas las consultas
-- reales filtran u ordenan por `fecha`: el ABM (rangos desde/hasta), el
-- microservicio v4 (`WHERE fecha = ?` y `ORDER BY fecha DESC`), la
-- valorizacion de pagos (`WHERE fecha <= emision ORDER BY fecha DESC`) y el
-- job diario (chequeo de duplicado por fecha). Con ~2.2k filas hoy no duele,
-- pero la serie crece un registro por dia habil.
SET @sql := (
  SELECT IF(
    (SELECT COUNT(*) FROM information_schema.TABLES
      WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'dolarhoy_cotizaciones') > 0
    AND
    (SELECT COUNT(*) FROM information_schema.STATISTICS
      WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'dolarhoy_cotizaciones'
        AND INDEX_NAME = 'idx_dolarhoy_cot_fecha') = 0,
    'ALTER TABLE `dolarhoy_cotizaciones` ADD INDEX `idx_dolarhoy_cot_fecha` (`fecha`)',
    'SELECT 1'
  )
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
