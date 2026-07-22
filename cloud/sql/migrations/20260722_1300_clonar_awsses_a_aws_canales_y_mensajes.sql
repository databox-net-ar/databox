-- Clona la estructura y los datos de `awssescanales` a `aws_canales`
-- y de `awssesmensajes` a `aws_mensajes` para alimentar el nuevo modulo
-- "AWS" (paralelo al modulo "AWS SES" ya existente).
--
-- El modulo AWS SES queda intacto: sigue leyendo y escribiendo en las
-- tablas viejas (`awssescanales` / `awssesmensajes`). El modulo AWS
-- trabaja sobre las tablas nuevas. A partir de aca ambos pares de
-- tablas evolucionan de forma independiente.
--
-- Naming: snake_case, alineado con `aws_cuentas` (ver migracion
-- 20260722_1200_renombrar_awscuentas_a_aws_cuentas.sql).
--
-- Idempotente:
--   * CREATE TABLE ... LIKE solo si la origen existe y la destino aun no.
--   * INSERT IGNORE ... SELECT solo si ambas tablas existen (asi una
--     re-corrida no duplica filas por PK ni pisa cambios manuales).
-- Patron `information_schema` + `PREPARE`/`EXECUTE`, compatible con
-- MySQL 8 y MariaDB 10.11.

SET @db := DATABASE();

-- ============================================================================
-- aws_canales <- awssescanales
-- ============================================================================
SET @sql := (
  SELECT IF(
    (SELECT COUNT(*) FROM information_schema.TABLES
      WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'awssescanales') > 0
    AND
    (SELECT COUNT(*) FROM information_schema.TABLES
      WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'aws_canales') = 0,
    'CREATE TABLE `aws_canales` LIKE `awssescanales`',
    'SELECT 1'
  )
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql := (
  SELECT IF(
    (SELECT COUNT(*) FROM information_schema.TABLES
      WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'awssescanales') > 0
    AND
    (SELECT COUNT(*) FROM information_schema.TABLES
      WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'aws_canales') > 0,
    'INSERT IGNORE INTO `aws_canales` SELECT * FROM `awssescanales`',
    'SELECT 1'
  )
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- ============================================================================
-- aws_mensajes <- awssesmensajes
-- ============================================================================
SET @sql := (
  SELECT IF(
    (SELECT COUNT(*) FROM information_schema.TABLES
      WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'awssesmensajes') > 0
    AND
    (SELECT COUNT(*) FROM information_schema.TABLES
      WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'aws_mensajes') = 0,
    'CREATE TABLE `aws_mensajes` LIKE `awssesmensajes`',
    'SELECT 1'
  )
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql := (
  SELECT IF(
    (SELECT COUNT(*) FROM information_schema.TABLES
      WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'awssesmensajes') > 0
    AND
    (SELECT COUNT(*) FROM information_schema.TABLES
      WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'aws_mensajes') > 0,
    'INSERT IGNORE INTO `aws_mensajes` SELECT * FROM `awssesmensajes`',
    'SELECT 1'
  )
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
