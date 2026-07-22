-- Clona la estructura y los datos de `datarocketplantillas` a
-- `datarocket_plantillas` y de `datarocketcontactos` a `datarocket_contactos`
-- para migrar los submodulos Mensajes y Contactos del modulo Datarocket al
-- naming convention snake_case (alineado con `datarocket_dominios`,
-- `aws_cuentas`, `aws_canales`, `aws_mensajes`, `aws_plantillas`,
-- `aws_contactos`).
--
-- Las tablas viejas (`datarocketplantillas` / `datarocketcontactos`) quedan
-- en la base hasta confirmar que ningun otro proyecto del grupo las
-- consulta. A partir de esta migracion los ABM del cloud escriben y leen
-- unicamente contra las nuevas tablas snake_case.
--
-- Idempotente:
--   * CREATE TABLE ... LIKE solo si la origen existe y la destino aun no.
--   * INSERT IGNORE ... SELECT solo si ambas tablas existen (asi una
--     re-corrida no duplica filas por PK ni pisa cambios manuales).
-- Patron `information_schema` + `PREPARE`/`EXECUTE`, compatible con
-- MySQL 8 y MariaDB 10.11.

SET @db := DATABASE();

-- ============================================================================
-- datarocket_plantillas <- datarocketplantillas
-- ============================================================================
SET @sql := (
  SELECT IF(
    (SELECT COUNT(*) FROM information_schema.TABLES
      WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'datarocketplantillas') > 0
    AND
    (SELECT COUNT(*) FROM information_schema.TABLES
      WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'datarocket_plantillas') = 0,
    'CREATE TABLE `datarocket_plantillas` LIKE `datarocketplantillas`',
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
      WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'datarocket_plantillas') > 0,
    'INSERT IGNORE INTO `datarocket_plantillas` SELECT * FROM `datarocketplantillas`',
    'SELECT 1'
  )
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- ============================================================================
-- datarocket_contactos <- datarocketcontactos
-- ============================================================================
SET @sql := (
  SELECT IF(
    (SELECT COUNT(*) FROM information_schema.TABLES
      WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'datarocketcontactos') > 0
    AND
    (SELECT COUNT(*) FROM information_schema.TABLES
      WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'datarocket_contactos') = 0,
    'CREATE TABLE `datarocket_contactos` LIKE `datarocketcontactos`',
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
      WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'datarocket_contactos') > 0,
    'INSERT IGNORE INTO `datarocket_contactos` SELECT * FROM `datarocketcontactos`',
    'SELECT 1'
  )
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
