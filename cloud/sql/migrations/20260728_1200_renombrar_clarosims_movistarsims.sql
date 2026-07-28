-- Renombra las tablas `clarosims` -> `claro_sims` y `movistarsims` -> `movistar_sims`
-- para alinearlas con la convencion snake_case del resto del esquema. Tambien
-- renombra sus indices UNIQUE (`uk_clarosims_icc` -> `uk_claro_sims_icc`,
-- `uk_movistarsims_icc` -> `uk_movistar_sims_icc`) y actualiza la tabla
-- `tareas` para apuntar a los scripts renombrados (jobs de cloud/jobs/).
--
-- Idempotente: cada paso chequea INFORMATION_SCHEMA antes de aplicar, asi la
-- migracion se puede correr multiples veces sin fallar. Patron PREPARE/EXECUTE
-- compatible con MySQL 8 y MariaDB 10.11 (`RENAME TABLE IF EXISTS` es MariaDB-only).

SET @db := DATABASE();

-- ---------------------------------------------------------------------------
-- 1) RENAME TABLE clarosims -> claro_sims
-- ---------------------------------------------------------------------------
SET @sql := (
  SELECT IF(
    (SELECT COUNT(*) FROM information_schema.TABLES
      WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'clarosims') > 0
    AND
    (SELECT COUNT(*) FROM information_schema.TABLES
      WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'claro_sims') = 0,
    'RENAME TABLE `clarosims` TO `claro_sims`',
    'SELECT 1'
  )
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- ---------------------------------------------------------------------------
-- 2) RENAME TABLE movistarsims -> movistar_sims
-- ---------------------------------------------------------------------------
SET @sql := (
  SELECT IF(
    (SELECT COUNT(*) FROM information_schema.TABLES
      WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'movistarsims') > 0
    AND
    (SELECT COUNT(*) FROM information_schema.TABLES
      WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'movistar_sims') = 0,
    'RENAME TABLE `movistarsims` TO `movistar_sims`',
    'SELECT 1'
  )
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- ---------------------------------------------------------------------------
-- 3) Renombrar indice UNIQUE de `claro_sims`: uk_clarosims_icc -> uk_claro_sims_icc
-- ---------------------------------------------------------------------------
SET @sql := (
  SELECT IF(
    (SELECT COUNT(*) FROM information_schema.STATISTICS
      WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'claro_sims'
        AND INDEX_NAME = 'uk_clarosims_icc') > 0
    AND
    (SELECT COUNT(*) FROM information_schema.STATISTICS
      WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'claro_sims'
        AND INDEX_NAME = 'uk_claro_sims_icc') = 0,
    'ALTER TABLE `claro_sims` RENAME INDEX `uk_clarosims_icc` TO `uk_claro_sims_icc`',
    'SELECT 1'
  )
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- ---------------------------------------------------------------------------
-- 4) Renombrar indice UNIQUE de `movistar_sims`: uk_movistarsims_icc -> uk_movistar_sims_icc
-- ---------------------------------------------------------------------------
SET @sql := (
  SELECT IF(
    (SELECT COUNT(*) FROM information_schema.STATISTICS
      WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'movistar_sims'
        AND INDEX_NAME = 'uk_movistarsims_icc') > 0
    AND
    (SELECT COUNT(*) FROM information_schema.STATISTICS
      WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'movistar_sims'
        AND INDEX_NAME = 'uk_movistar_sims_icc') = 0,
    'ALTER TABLE `movistar_sims` RENAME INDEX `uk_movistarsims_icc` TO `uk_movistar_sims_icc`',
    'SELECT 1'
  )
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- ---------------------------------------------------------------------------
-- 5) Repuntar `tareas.script` a los nombres nuevos de los jobs.
--    Los archivos fisicos se renombran en el filesystem:
--      cloud/jobs/clarosims_actualizar.php   -> claro_sims_actualizar.php
--      cloud/jobs/movistarsims_actualizar.php -> movistar_sims_actualizar.php
-- ---------------------------------------------------------------------------
UPDATE `tareas`
   SET `script` = 'claro_sims_actualizar'
 WHERE `script` = 'clarosims_actualizar';

UPDATE `tareas`
   SET `script` = 'movistar_sims_actualizar'
 WHERE `script` = 'movistarsims_actualizar';
