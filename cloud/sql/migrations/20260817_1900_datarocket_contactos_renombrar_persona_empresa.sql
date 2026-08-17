-- datarocket_contactos: renombra los 8 campos de identidad para que el prefijo
-- diga a que tipo de contacto pertenecen (`tipo` = persona | empresa). Es el
-- correlato en la base del split de la pestaña "Identidad" del modal Consultar
-- en dos pestañas, Persona y Empresa.
--
--   persona      -> persona_nombre       empresa    -> empresa_nombre
--   dni          -> persona_dni          rubro      -> empresa_rubro
--   genero       -> persona_genero       actividad  -> empresa_actividad
--   nacimiento   -> persona_nacimiento   cargo      -> empresa_cargo
--
-- OJO con dos nombres que NO se tocan y se parecen:
--   * `nombre`  (VARCHAR 255) sigue igual — es el nombre del contacto, valga
--     persona o empresa, y es distinto de `persona_nombre` (que en un contacto
--     empresa guarda a quien atiende).
--   * los VALORES 'persona' / 'empresa' de la columna `tipo` no cambian: el
--     rename es de columnas, no de datos.
--
-- Se usa RENAME COLUMN y no CHANGE COLUMN a proposito: CHANGE obliga a repetir
-- la definicion completa y si dev y prod difieren en algun DEFAULT (varias de
-- estas columnas son NULL en unas y '' en otras) la reescribiria en silencio.
-- RENAME preserva tipo, default, nullability y posicion. Soportado por MySQL
-- 8.0 (dev) y MariaDB 10.5.2+ (prod corre 10.11).
--
-- ORDEN DE DESPLIEGUE: esta migracion se aplica DESPUES de publicar el codigo
-- que usa los nombres nuevos (cloud/api/datarocketcontactos.php,
-- api/v4/datarocket/contactos.php, cloud/api/datarocket_prospectos.php y
-- app.js). Al reves, los endpoints tiran 500 hasta el deploy.
--
-- Idempotente: cada rename corre solo si el nombre viejo todavia existe Y el
-- nuevo todavia no. En la segunda corrida no hace nada.
-- Compatible MySQL 8 (dev) + MariaDB 10.11 (prod).


-- ---------------------------------------------------------------------------
-- 1) persona -> persona_nombre
-- ---------------------------------------------------------------------------
SET @viejo := (SELECT COUNT(*) FROM information_schema.COLUMNS
                WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'datarocket_contactos'
                  AND COLUMN_NAME = 'persona');
SET @nuevo := (SELECT COUNT(*) FROM information_schema.COLUMNS
                WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'datarocket_contactos'
                  AND COLUMN_NAME = 'persona_nombre');
SET @sql := IF(@viejo = 1 AND @nuevo = 0,
  'ALTER TABLE datarocket_contactos RENAME COLUMN `persona` TO `persona_nombre`', 'DO 0');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- ---------------------------------------------------------------------------
-- 2) dni -> persona_dni
-- ---------------------------------------------------------------------------
SET @viejo := (SELECT COUNT(*) FROM information_schema.COLUMNS
                WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'datarocket_contactos'
                  AND COLUMN_NAME = 'dni');
SET @nuevo := (SELECT COUNT(*) FROM information_schema.COLUMNS
                WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'datarocket_contactos'
                  AND COLUMN_NAME = 'persona_dni');
SET @sql := IF(@viejo = 1 AND @nuevo = 0,
  'ALTER TABLE datarocket_contactos RENAME COLUMN `dni` TO `persona_dni`', 'DO 0');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- ---------------------------------------------------------------------------
-- 3) genero -> persona_genero
-- ---------------------------------------------------------------------------
SET @viejo := (SELECT COUNT(*) FROM information_schema.COLUMNS
                WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'datarocket_contactos'
                  AND COLUMN_NAME = 'genero');
SET @nuevo := (SELECT COUNT(*) FROM information_schema.COLUMNS
                WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'datarocket_contactos'
                  AND COLUMN_NAME = 'persona_genero');
SET @sql := IF(@viejo = 1 AND @nuevo = 0,
  'ALTER TABLE datarocket_contactos RENAME COLUMN `genero` TO `persona_genero`', 'DO 0');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- ---------------------------------------------------------------------------
-- 4) nacimiento -> persona_nacimiento
-- ---------------------------------------------------------------------------
SET @viejo := (SELECT COUNT(*) FROM information_schema.COLUMNS
                WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'datarocket_contactos'
                  AND COLUMN_NAME = 'nacimiento');
SET @nuevo := (SELECT COUNT(*) FROM information_schema.COLUMNS
                WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'datarocket_contactos'
                  AND COLUMN_NAME = 'persona_nacimiento');
SET @sql := IF(@viejo = 1 AND @nuevo = 0,
  'ALTER TABLE datarocket_contactos RENAME COLUMN `nacimiento` TO `persona_nacimiento`', 'DO 0');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- ---------------------------------------------------------------------------
-- 5) empresa -> empresa_nombre
-- ---------------------------------------------------------------------------
SET @viejo := (SELECT COUNT(*) FROM information_schema.COLUMNS
                WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'datarocket_contactos'
                  AND COLUMN_NAME = 'empresa');
SET @nuevo := (SELECT COUNT(*) FROM information_schema.COLUMNS
                WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'datarocket_contactos'
                  AND COLUMN_NAME = 'empresa_nombre');
SET @sql := IF(@viejo = 1 AND @nuevo = 0,
  'ALTER TABLE datarocket_contactos RENAME COLUMN `empresa` TO `empresa_nombre`', 'DO 0');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- ---------------------------------------------------------------------------
-- 6) rubro -> empresa_rubro
-- ---------------------------------------------------------------------------
SET @viejo := (SELECT COUNT(*) FROM information_schema.COLUMNS
                WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'datarocket_contactos'
                  AND COLUMN_NAME = 'rubro');
SET @nuevo := (SELECT COUNT(*) FROM information_schema.COLUMNS
                WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'datarocket_contactos'
                  AND COLUMN_NAME = 'empresa_rubro');
SET @sql := IF(@viejo = 1 AND @nuevo = 0,
  'ALTER TABLE datarocket_contactos RENAME COLUMN `rubro` TO `empresa_rubro`', 'DO 0');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- ---------------------------------------------------------------------------
-- 7) actividad -> empresa_actividad
-- ---------------------------------------------------------------------------
SET @viejo := (SELECT COUNT(*) FROM information_schema.COLUMNS
                WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'datarocket_contactos'
                  AND COLUMN_NAME = 'actividad');
SET @nuevo := (SELECT COUNT(*) FROM information_schema.COLUMNS
                WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'datarocket_contactos'
                  AND COLUMN_NAME = 'empresa_actividad');
SET @sql := IF(@viejo = 1 AND @nuevo = 0,
  'ALTER TABLE datarocket_contactos RENAME COLUMN `actividad` TO `empresa_actividad`', 'DO 0');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- ---------------------------------------------------------------------------
-- 8) cargo -> empresa_cargo
-- ---------------------------------------------------------------------------
SET @viejo := (SELECT COUNT(*) FROM information_schema.COLUMNS
                WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'datarocket_contactos'
                  AND COLUMN_NAME = 'cargo');
SET @nuevo := (SELECT COUNT(*) FROM information_schema.COLUMNS
                WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'datarocket_contactos'
                  AND COLUMN_NAME = 'empresa_cargo');
SET @sql := IF(@viejo = 1 AND @nuevo = 0,
  'ALTER TABLE datarocket_contactos RENAME COLUMN `cargo` TO `empresa_cargo`', 'DO 0');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
