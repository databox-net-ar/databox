-- movistarsims + clarosims: agregar `alias` (VARCHAR(100)) despues de `nombre`
-- y `consumo_datos` (VARCHAR(40)) despues de `limite_datos`.
--
-- Motivo:
--   * Movistar: el sync desde Kite venia pisando `nombre` con el customField1,
--     lo que borraba las ediciones manuales del ABM. A partir de ahora
--     customField1 va a `alias` y `nombre` queda como campo editable del ABM.
--   * Consumo: los portales de Movistar (Kite) y Claro (openclaw) reportan el
--     consumo mensual de datos. Se agrega la columna para persistirlo.
--
-- Compatible con MySQL 8 (dev) y MariaDB 10.11 (prod): se usa el patron
-- information_schema + PREPARE/EXECUTE porque MySQL 8 no soporta la sintaxis
-- MariaDB `ADD COLUMN IF NOT EXISTS`.

-- ---------------------------------------------------------------------------
-- clarosims.alias
-- ---------------------------------------------------------------------------
SET @exists := (
  SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME   = 'clarosims'
    AND COLUMN_NAME  = 'alias'
);
SET @sql := IF(@exists = 0,
  'ALTER TABLE clarosims ADD COLUMN `alias` VARCHAR(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NULL DEFAULT NULL AFTER `nombre`',
  'DO 0'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- ---------------------------------------------------------------------------
-- clarosims.consumo_datos
-- ---------------------------------------------------------------------------
SET @exists := (
  SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME   = 'clarosims'
    AND COLUMN_NAME  = 'consumo_datos'
);
SET @sql := IF(@exists = 0,
  'ALTER TABLE clarosims ADD COLUMN `consumo_datos` VARCHAR(40) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NULL DEFAULT NULL AFTER `limite_datos`',
  'DO 0'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- ---------------------------------------------------------------------------
-- movistarsims.alias
-- ---------------------------------------------------------------------------
SET @exists := (
  SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME   = 'movistarsims'
    AND COLUMN_NAME  = 'alias'
);
SET @sql := IF(@exists = 0,
  'ALTER TABLE movistarsims ADD COLUMN `alias` VARCHAR(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NULL DEFAULT NULL AFTER `nombre`',
  'DO 0'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- ---------------------------------------------------------------------------
-- movistarsims.consumo_datos
-- ---------------------------------------------------------------------------
SET @exists := (
  SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME   = 'movistarsims'
    AND COLUMN_NAME  = 'consumo_datos'
);
SET @sql := IF(@exists = 0,
  'ALTER TABLE movistarsims ADD COLUMN `consumo_datos` VARCHAR(40) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NULL DEFAULT NULL AFTER `limite_datos`',
  'DO 0'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
