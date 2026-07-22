-- Clona la estructura y los datos de `evolutionmensajes`, `evolutioncanales`
-- y `evolutioncontactos` a `evolution_mensajes`, `evolution_canales` y
-- `evolution_contactos` respectivamente.
--
-- A partir de esta migracion los modulos "Plataformas > Evolution API"
-- (Mensajes / Canales / Contactos) pasan a leer y escribir contra las
-- nuevas tablas snake_case. Las tablas legacy quedan intactas para que
-- otras aplicaciones del grupo que aun apunten a ellas sigan funcionando;
-- a partir de aca ambos pares evolucionan por separado.
--
-- Naming: snake_case + modelo en singular, alineado con el resto del
-- refactor `aws_cuentas` / `aws_canales` / `aws_mensajes`.
--
-- Idempotente:
--   * CREATE TABLE ... LIKE solo si la origen existe y la destino aun no.
--   * INSERT IGNORE ... SELECT solo si ambas tablas existen (asi una
--     re-corrida no duplica filas por PK ni pisa cambios manuales hechos
--     en la nueva tabla despues de la primera copia).
-- Patron `information_schema` + `PREPARE`/`EXECUTE`, compatible con
-- MySQL 8 y MariaDB 10.11.

SET @db := DATABASE();

-- ============================================================================
-- evolution_mensajes <- evolutionmensajes
-- ============================================================================
SET @sql := (
  SELECT IF(
    (SELECT COUNT(*) FROM information_schema.TABLES
      WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'evolutionmensajes') > 0
    AND
    (SELECT COUNT(*) FROM information_schema.TABLES
      WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'evolution_mensajes') = 0,
    'CREATE TABLE `evolution_mensajes` LIKE `evolutionmensajes`',
    'SELECT 1'
  )
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql := (
  SELECT IF(
    (SELECT COUNT(*) FROM information_schema.TABLES
      WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'evolutionmensajes') > 0
    AND
    (SELECT COUNT(*) FROM information_schema.TABLES
      WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'evolution_mensajes') > 0,
    'INSERT IGNORE INTO `evolution_mensajes` SELECT * FROM `evolutionmensajes`',
    'SELECT 1'
  )
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- ============================================================================
-- evolution_canales <- evolutioncanales
-- ============================================================================
SET @sql := (
  SELECT IF(
    (SELECT COUNT(*) FROM information_schema.TABLES
      WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'evolutioncanales') > 0
    AND
    (SELECT COUNT(*) FROM information_schema.TABLES
      WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'evolution_canales') = 0,
    'CREATE TABLE `evolution_canales` LIKE `evolutioncanales`',
    'SELECT 1'
  )
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql := (
  SELECT IF(
    (SELECT COUNT(*) FROM information_schema.TABLES
      WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'evolutioncanales') > 0
    AND
    (SELECT COUNT(*) FROM information_schema.TABLES
      WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'evolution_canales') > 0,
    'INSERT IGNORE INTO `evolution_canales` SELECT * FROM `evolutioncanales`',
    'SELECT 1'
  )
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- ============================================================================
-- evolution_contactos <- evolutioncontactos
-- ============================================================================
SET @sql := (
  SELECT IF(
    (SELECT COUNT(*) FROM information_schema.TABLES
      WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'evolutioncontactos') > 0
    AND
    (SELECT COUNT(*) FROM information_schema.TABLES
      WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'evolution_contactos') = 0,
    'CREATE TABLE `evolution_contactos` LIKE `evolutioncontactos`',
    'SELECT 1'
  )
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql := (
  SELECT IF(
    (SELECT COUNT(*) FROM information_schema.TABLES
      WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'evolutioncontactos') > 0
    AND
    (SELECT COUNT(*) FROM information_schema.TABLES
      WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'evolution_contactos') > 0,
    'INSERT IGNORE INTO `evolution_contactos` SELECT * FROM `evolutioncontactos`',
    'SELECT 1'
  )
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
