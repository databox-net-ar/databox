-- arca_certificados: quitar `cuit` y su indice.
--
-- Motivo:
--   El CUIT del contribuyente ya vive en `datacount_empresas.cuit`. Ahora que
--   cada empresa apunta al certificado que le corresponde
--   (`datacount_empresas.certificado_id` -> `arca_certificados.id`), duplicar
--   el CUIT en la tabla de certificados es redundante y abre la puerta a
--   estados inconsistentes (el CUIT del certificado no coincide con el de la
--   empresa que lo usa). Se deja el CUIT solo en `datacount_empresas`.
--
-- Compatible con MySQL 8 (dev) y MariaDB 10.11 (prod): se usa el patron
-- information_schema + PREPARE/EXECUTE porque MySQL 8 no soporta la sintaxis
-- MariaDB `DROP COLUMN IF EXISTS` / `DROP INDEX IF EXISTS`.

-- ---------------------------------------------------------------------------
-- Indice idx_arca_certificados_cuit (dropear ANTES que la columna).
-- ---------------------------------------------------------------------------
SET @exists := (
  SELECT COUNT(*) FROM information_schema.STATISTICS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME   = 'arca_certificados'
    AND INDEX_NAME   = 'idx_arca_certificados_cuit'
);
SET @sql := IF(@exists > 0,
  'ALTER TABLE arca_certificados DROP INDEX `idx_arca_certificados_cuit`',
  'DO 0'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- ---------------------------------------------------------------------------
-- Columna arca_certificados.cuit.
-- ---------------------------------------------------------------------------
SET @exists := (
  SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME   = 'arca_certificados'
    AND COLUMN_NAME  = 'cuit'
);
SET @sql := IF(@exists > 0,
  'ALTER TABLE arca_certificados DROP COLUMN `cuit`',
  'DO 0'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
