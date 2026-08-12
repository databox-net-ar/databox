-- datarocket_embudos: eliminar las columnas `color` y `orden`.
--
-- Correccion de scope de la migracion 20260812_0200 (crear tabla). Ambas
-- columnas se agregaron pensando en un embudo "estandalone" con estilo
-- propio, pero:
--   * `color` corresponde a las etapas — cada columna del kanban tiene su
--     color propio (`datarocket_etapas.color`), y el embudo como contenedor
--     no se pinta en ningun lado de la UI.
--   * `orden` no tiene uso: cada proyecto tiene su embudo, y el listado se
--     ordena por proyecto/nombre/id. Un `orden` a nivel embudo era heredado
--     de una idea previa de multi-embudo con tabs, que se descarto.
--
-- Se dropean tambien los indices asociados (`idx_datarocket_embudos_orden`)
-- si existen. Idempotente: cada DROP se guarda con information_schema.
-- Compatible MySQL 8 (dev) + MariaDB 10.11 (prod).

-- ============================================================================
-- Paso 1: dropear el indice sobre `orden` si existe.
-- ============================================================================

SET @exists := (
  SELECT COUNT(*) FROM information_schema.STATISTICS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME   = 'datarocket_embudos'
    AND INDEX_NAME   = 'idx_datarocket_embudos_orden'
);
SET @sql := IF(@exists > 0,
  'ALTER TABLE datarocket_embudos DROP INDEX `idx_datarocket_embudos_orden`',
  'DO 0'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- ============================================================================
-- Paso 2: dropear columna `orden` si existe.
-- ============================================================================

SET @exists := (
  SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME   = 'datarocket_embudos'
    AND COLUMN_NAME  = 'orden'
);
SET @sql := IF(@exists > 0,
  'ALTER TABLE datarocket_embudos DROP COLUMN `orden`',
  'DO 0'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- ============================================================================
-- Paso 3: dropear columna `color` si existe.
-- ============================================================================

SET @exists := (
  SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME   = 'datarocket_embudos'
    AND COLUMN_NAME  = 'color'
);
SET @sql := IF(@exists > 0,
  'ALTER TABLE datarocket_embudos DROP COLUMN `color`',
  'DO 0'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
