-- movistar_sims + claro_sims: agregar `ultimo_trafico` (DATETIME NULL) despues
-- de `actualizado`.
--
-- Motivo:
--   Se necesita saber cuanto hace que una SIM no cursa trafico ("SIMs sin
--   trafico hace N dias"). Se persiste la fecha absoluta del ultimo trafico
--   detectado (no el numero de dias); "N dias" se calcula al vuelo con
--   DATEDIFF(NOW(), ultimo_trafico) al mostrar.
--
--   * Movistar: se popula durante el sync desde Kite Platform (ver
--     cloud/api/lib/movistar_sims_kite.php -> mapKiteSim + kiteSyncSims).
--   * Claro:    queda NULL por ahora — la plataforma de autogestion no
--     expone el dato; la forma de rellenarlo se define mas adelante.
--
-- Compatible con MySQL 8 (dev) y MariaDB 10.11 (prod): se usa el patron
-- information_schema + PREPARE/EXECUTE porque MySQL 8 no soporta la sintaxis
-- MariaDB `ADD COLUMN IF NOT EXISTS`.

-- ---------------------------------------------------------------------------
-- movistar_sims.ultimo_trafico
-- ---------------------------------------------------------------------------
SET @exists := (
  SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME   = 'movistar_sims'
    AND COLUMN_NAME  = 'ultimo_trafico'
);
SET @sql := IF(@exists = 0,
  'ALTER TABLE movistar_sims ADD COLUMN `ultimo_trafico` DATETIME NULL DEFAULT NULL AFTER `actualizado`',
  'DO 0'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- ---------------------------------------------------------------------------
-- claro_sims.ultimo_trafico
-- ---------------------------------------------------------------------------
SET @exists := (
  SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME   = 'claro_sims'
    AND COLUMN_NAME  = 'ultimo_trafico'
);
SET @sql := IF(@exists = 0,
  'ALTER TABLE claro_sims ADD COLUMN `ultimo_trafico` DATETIME NULL DEFAULT NULL AFTER `actualizado`',
  'DO 0'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
