-- arca_certificados: quitar `empresa_id` y su indice.
--
-- Motivo:
--   La relacion empresa <-> certificado ahora vive en el otro extremo:
--   `datacount_empresas.certificado_id` -> `arca_certificados.id` (ver
--   migracion 20260731_0700_datacount_empresas_certificado_id.sql). Mantener
--   la columna inversa duplicaba la relacion y abria la puerta a estados
--   inconsistentes (A apunta a B pero B apunta a C). Se elimina la columna
--   `empresa_id` de `arca_certificados` para dejar una unica direccion
--   autoritativa.
--
-- Compatible con MySQL 8 (dev) y MariaDB 10.11 (prod): se usa el patron
-- information_schema + PREPARE/EXECUTE porque MySQL 8 no soporta la sintaxis
-- MariaDB `DROP COLUMN IF EXISTS` / `DROP INDEX IF EXISTS`.

-- ---------------------------------------------------------------------------
-- Indice idx_arca_certificados_empresa_id (dropear ANTES que la columna).
-- ---------------------------------------------------------------------------
SET @exists := (
  SELECT COUNT(*) FROM information_schema.STATISTICS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME   = 'arca_certificados'
    AND INDEX_NAME   = 'idx_arca_certificados_empresa_id'
);
SET @sql := IF(@exists > 0,
  'ALTER TABLE arca_certificados DROP INDEX `idx_arca_certificados_empresa_id`',
  'DO 0'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- ---------------------------------------------------------------------------
-- Columna arca_certificados.empresa_id.
-- ---------------------------------------------------------------------------
SET @exists := (
  SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME   = 'arca_certificados'
    AND COLUMN_NAME  = 'empresa_id'
);
SET @sql := IF(@exists > 0,
  'ALTER TABLE arca_certificados DROP COLUMN `empresa_id`',
  'DO 0'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
