-- arca_certificados: agregar `empresa_id` (INT UNSIGNED NULL) despues de
-- `nombre`, apuntando al catalogo `datacount_empresas.id`.
--
-- Motivo:
--   Cada certificado fiscal de ARCA/AFIP corresponde a un contribuyente
--   concreto y ese contribuyente ya vive en la tabla `datacount_empresas`
--   (razon social, CUIT, condicion fiscal, IIBB, domicilio). Vinculando el
--   certificado con la empresa evitamos duplicar datos y permitimos filtrar
--   / listar los certificados por empresa. La columna es opcional para no
--   romper certificados historicos que quedaron cargados sin empresa
--   asociada.
--
-- Compatible con MySQL 8 (dev) y MariaDB 10.11 (prod): se usa el patron
-- information_schema + PREPARE/EXECUTE porque MySQL 8 no soporta la sintaxis
-- MariaDB `ADD COLUMN IF NOT EXISTS`.

-- ---------------------------------------------------------------------------
-- arca_certificados.empresa_id
-- ---------------------------------------------------------------------------
SET @exists := (
  SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME   = 'arca_certificados'
    AND COLUMN_NAME  = 'empresa_id'
);
SET @sql := IF(@exists = 0,
  'ALTER TABLE arca_certificados ADD COLUMN `empresa_id` INT UNSIGNED NULL DEFAULT NULL AFTER `nombre`',
  'DO 0'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- Indice para lookups / joins por empresa.
SET @exists := (
  SELECT COUNT(*) FROM information_schema.STATISTICS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME   = 'arca_certificados'
    AND INDEX_NAME   = 'idx_arca_certificados_empresa_id'
);
SET @sql := IF(@exists = 0,
  'ALTER TABLE arca_certificados ADD INDEX `idx_arca_certificados_empresa_id` (`empresa_id`)',
  'DO 0'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
