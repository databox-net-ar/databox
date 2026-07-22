-- Clona la estructura y los datos de `datarocketmensajes` a
-- `datarocket_mensajes` para migrar el submodulo Mensajes del modulo
-- Datarocket al naming convention snake_case (alineado con
-- `datarocket_dominios`, `datarocket_plantillas`, `datarocket_contactos`,
-- `aws_cuentas`, `aws_canales`, `aws_mensajes`, `aws_plantillas`).
--
-- La tabla vieja (`datarocketmensajes`) queda en la base hasta confirmar
-- que ningun otro proyecto del grupo la consulta. A partir de esta
-- migracion el ABM del cloud escribe y lee unicamente contra la nueva
-- tabla snake_case.
--
-- Idempotente:
--   * CREATE TABLE ... LIKE solo si la origen existe y la destino aun no.
--   * INSERT IGNORE ... SELECT solo si ambas tablas existen (asi una
--     re-corrida no duplica filas por PK ni pisa cambios manuales).
-- Patron `information_schema` + `PREPARE`/`EXECUTE`, compatible con
-- MySQL 8 y MariaDB 10.11.

SET @db := DATABASE();

-- ============================================================================
-- datarocket_mensajes <- datarocketmensajes
-- ============================================================================
SET @sql := (
  SELECT IF(
    (SELECT COUNT(*) FROM information_schema.TABLES
      WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'datarocketmensajes') > 0
    AND
    (SELECT COUNT(*) FROM information_schema.TABLES
      WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'datarocket_mensajes') = 0,
    'CREATE TABLE `datarocket_mensajes` LIKE `datarocketmensajes`',
    'SELECT 1'
  )
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql := (
  SELECT IF(
    (SELECT COUNT(*) FROM information_schema.TABLES
      WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'datarocketmensajes') > 0
    AND
    (SELECT COUNT(*) FROM information_schema.TABLES
      WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'datarocket_mensajes') > 0,
    'INSERT IGNORE INTO `datarocket_mensajes` SELECT * FROM `datarocketmensajes`',
    'SELECT 1'
  )
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
