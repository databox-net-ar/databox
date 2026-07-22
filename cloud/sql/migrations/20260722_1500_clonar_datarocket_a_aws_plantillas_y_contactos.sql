-- Clona la estructura y los datos de `datarocketplantillas` a `aws_plantillas`
-- y de `datarocketcontactos` a `aws_contactos` para alimentar los nuevos ABM
-- "AWS Plantillas" y "AWS Contactos" (paralelos a los ABM Datarocket ya
-- existentes).
--
-- Los modulos Datarocket quedan intactos: siguen leyendo y escribiendo en
-- las tablas viejas (`datarocketplantillas` / `datarocketcontactos`). Los
-- nuevos modulos AWS trabajan sobre las tablas nuevas. A partir de aca
-- ambos pares de tablas evolucionan de forma independiente.
--
-- Naming: snake_case, alineado con `aws_cuentas` / `aws_canales` /
-- `aws_mensajes` (ver migraciones 20260722_1200 y 20260722_1300).
--
-- Idempotente:
--   * CREATE TABLE ... LIKE solo si la origen existe y la destino aun no.
--   * INSERT IGNORE ... SELECT solo si ambas tablas existen (asi una
--     re-corrida no duplica filas por PK ni pisa cambios manuales).
-- Patron `information_schema` + `PREPARE`/`EXECUTE`, compatible con
-- MySQL 8 y MariaDB 10.11.

SET @db := DATABASE();

-- ============================================================================
-- aws_plantillas <- datarocketplantillas
-- ============================================================================
SET @sql := (
  SELECT IF(
    (SELECT COUNT(*) FROM information_schema.TABLES
      WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'datarocketplantillas') > 0
    AND
    (SELECT COUNT(*) FROM information_schema.TABLES
      WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'aws_plantillas') = 0,
    'CREATE TABLE `aws_plantillas` LIKE `datarocketplantillas`',
    'SELECT 1'
  )
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql := (
  SELECT IF(
    (SELECT COUNT(*) FROM information_schema.TABLES
      WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'datarocketplantillas') > 0
    AND
    (SELECT COUNT(*) FROM information_schema.TABLES
      WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'aws_plantillas') > 0,
    'INSERT IGNORE INTO `aws_plantillas` SELECT * FROM `datarocketplantillas`',
    'SELECT 1'
  )
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- ============================================================================
-- aws_contactos <- datarocketcontactos
-- ============================================================================
SET @sql := (
  SELECT IF(
    (SELECT COUNT(*) FROM information_schema.TABLES
      WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'datarocketcontactos') > 0
    AND
    (SELECT COUNT(*) FROM information_schema.TABLES
      WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'aws_contactos') = 0,
    'CREATE TABLE `aws_contactos` LIKE `datarocketcontactos`',
    'SELECT 1'
  )
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql := (
  SELECT IF(
    (SELECT COUNT(*) FROM information_schema.TABLES
      WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'datarocketcontactos') > 0
    AND
    (SELECT COUNT(*) FROM information_schema.TABLES
      WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'aws_contactos') > 0,
    'INSERT IGNORE INTO `aws_contactos` SELECT * FROM `datarocketcontactos`',
    'SELECT 1'
  )
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
