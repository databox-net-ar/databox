-- datarocket_prospectos: agregar los 3 campos minimos del embudo:
--   * `embudo_id`      INT NULL — FK a `datarocket_embudos.id`.
--   * `etapa_id`       INT NULL — FK a `datarocket_etapas.id`.
--   * `etapa_ingreso`  DATETIME NULL — cuando el prospecto entro en su etapa
--                                       actual. Se usa para metricas de
--                                       velocity ("tiempo promedio en etapa
--                                       Propuesta") y para reordenar tarjetas
--                                       en el kanban por estadia.
--
-- Se agregan como NULL para permitir el backfill sin bloquear. Despues del
-- backfill, todas las filas quedan con embudo_id / etapa_id / etapa_ingreso
-- populados. No se aplica NOT NULL en esta migracion — la validacion
-- "prospecto sin embudo/etapa" vive en la capa PHP del ABM.
--
-- Backfill (para las ~1349 filas legacy):
--   * `embudo_id`      = id del embudo 'Captacion general' (resuelto por
--                        lookup, tolerando que tenga otro id).
--   * `etapa_id`       = mapeo desde el legacy `estado` tinyint:
--                          estado=1   -> etapa 'Nuevo'      ("esperando")
--                          estado=2   -> etapa 'Contactado' ("atendido")
--                          estado=3   -> etapa 'Ganado'     ("despachado")
--                          otro/NULL  -> etapa 'Nuevo'
--                        Es una interpretacion best-effort del legacy
--                        (los valores 1/2/3 no estan documentados en la
--                        tabla; el mapeo salio del menu rapido de la UI
--                        legacy `dsProRapidoMenu` en cloud/assets/js/app.js).
--                        Los 1341 rows con estado=3 caen todos en 'Ganado'.
--                        Auditar y reclasificar via ABM/kanban si hace falta.
--   * `etapa_ingreso`  = COALESCE(actualizado, ingreso, NOW()).
--
-- Indices y FKs:
--   * INDEX en embudo_id (filtrar prospectos por embudo).
--   * INDEX en etapa_id  (renderizar columnas del kanban).
--   * INDEX en etapa_ingreso para ordenar tarjetas por estadia.
--   * FK embudo_id -> datarocket_embudos(id) ON DELETE RESTRICT
--     (no borrar embudo si tiene prospectos).
--   * FK etapa_id  -> datarocket_etapas(id)  ON DELETE RESTRICT
--     (no borrar etapa si tiene prospectos).
--
-- Compatible MySQL 8 (dev) + MariaDB 10.11 (prod): pattern information_
-- schema + PREPARE/EXECUTE en cada ADD COLUMN / ADD INDEX / ADD CONSTRAINT.
-- Idempotente: correr dos veces no falla ni duplica.

-- ============================================================================
-- Paso 1: agregar las 3 columnas si no existen.
-- ============================================================================

SET @exists := (
  SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME   = 'datarocket_prospectos'
    AND COLUMN_NAME  = 'embudo_id'
);
SET @sql := IF(@exists = 0,
  'ALTER TABLE datarocket_prospectos ADD COLUMN `embudo_id` INT NULL DEFAULT NULL AFTER `estado`',
  'DO 0'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @exists := (
  SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME   = 'datarocket_prospectos'
    AND COLUMN_NAME  = 'etapa_id'
);
SET @sql := IF(@exists = 0,
  'ALTER TABLE datarocket_prospectos ADD COLUMN `etapa_id` INT NULL DEFAULT NULL AFTER `embudo_id`',
  'DO 0'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @exists := (
  SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME   = 'datarocket_prospectos'
    AND COLUMN_NAME  = 'etapa_ingreso'
);
SET @sql := IF(@exists = 0,
  'ALTER TABLE datarocket_prospectos ADD COLUMN `etapa_ingreso` DATETIME NULL DEFAULT NULL AFTER `etapa_id`',
  'DO 0'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- ============================================================================
-- Paso 2: backfill de los prospectos legacy que quedaron con NULL.
-- ============================================================================

-- embudo_id = id del embudo 'Captacion general' (resuelto por lookup).
UPDATE `datarocket_prospectos` p
INNER JOIN `datarocket_embudos` e ON e.nombre = 'Captacion general'
SET p.embudo_id = e.id
WHERE p.embudo_id IS NULL;

-- etapa_id = mapeo desde el legacy `estado` tinyint.
-- Se resuelven los ids de etapa por lookup contra el embudo 'Captacion general'.
UPDATE `datarocket_prospectos` p
INNER JOIN `datarocket_embudos` e   ON e.nombre  = 'Captacion general'
INNER JOIN `datarocket_etapas`  et  ON et.embudo_id = e.id AND et.nombre = 'Nuevo'
SET p.etapa_id = et.id
WHERE p.etapa_id IS NULL AND (p.estado = 1 OR p.estado IS NULL OR p.estado NOT IN (1,2,3));

UPDATE `datarocket_prospectos` p
INNER JOIN `datarocket_embudos` e   ON e.nombre  = 'Captacion general'
INNER JOIN `datarocket_etapas`  et  ON et.embudo_id = e.id AND et.nombre = 'Contactado'
SET p.etapa_id = et.id
WHERE p.etapa_id IS NULL AND p.estado = 2;

UPDATE `datarocket_prospectos` p
INNER JOIN `datarocket_embudos` e   ON e.nombre  = 'Captacion general'
INNER JOIN `datarocket_etapas`  et  ON et.embudo_id = e.id AND et.nombre = 'Ganado'
SET p.etapa_id = et.id
WHERE p.etapa_id IS NULL AND p.estado = 3;

-- etapa_ingreso = COALESCE(actualizado, ingreso, NOW()) para las filas legacy.
UPDATE `datarocket_prospectos`
SET etapa_ingreso = COALESCE(actualizado, ingreso, NOW())
WHERE etapa_ingreso IS NULL;

-- ============================================================================
-- Paso 3: indices y FKs (guardados con information_schema).
-- ============================================================================

SET @exists := (
  SELECT COUNT(*) FROM information_schema.STATISTICS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME   = 'datarocket_prospectos'
    AND INDEX_NAME   = 'idx_dr_prospectos_embudo'
);
SET @sql := IF(@exists = 0,
  'ALTER TABLE datarocket_prospectos ADD INDEX `idx_dr_prospectos_embudo` (`embudo_id`)',
  'DO 0'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @exists := (
  SELECT COUNT(*) FROM information_schema.STATISTICS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME   = 'datarocket_prospectos'
    AND INDEX_NAME   = 'idx_dr_prospectos_etapa'
);
SET @sql := IF(@exists = 0,
  'ALTER TABLE datarocket_prospectos ADD INDEX `idx_dr_prospectos_etapa` (`etapa_id`)',
  'DO 0'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @exists := (
  SELECT COUNT(*) FROM information_schema.STATISTICS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME   = 'datarocket_prospectos'
    AND INDEX_NAME   = 'idx_dr_prospectos_etapa_ingreso'
);
SET @sql := IF(@exists = 0,
  'ALTER TABLE datarocket_prospectos ADD INDEX `idx_dr_prospectos_etapa_ingreso` (`etapa_ingreso`)',
  'DO 0'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @exists := (
  SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS
  WHERE TABLE_SCHEMA    = DATABASE()
    AND TABLE_NAME      = 'datarocket_prospectos'
    AND CONSTRAINT_NAME = 'fk_dr_prospectos_embudo'
);
SET @sql := IF(@exists = 0,
  'ALTER TABLE datarocket_prospectos
    ADD CONSTRAINT `fk_dr_prospectos_embudo` FOREIGN KEY (`embudo_id`)
        REFERENCES `datarocket_embudos` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT',
  'DO 0'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @exists := (
  SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS
  WHERE TABLE_SCHEMA    = DATABASE()
    AND TABLE_NAME      = 'datarocket_prospectos'
    AND CONSTRAINT_NAME = 'fk_dr_prospectos_etapa'
);
SET @sql := IF(@exists = 0,
  'ALTER TABLE datarocket_prospectos
    ADD CONSTRAINT `fk_dr_prospectos_etapa` FOREIGN KEY (`etapa_id`)
        REFERENCES `datarocket_etapas` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT',
  'DO 0'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
