-- datacount_empresas: agregar `certificado_id` (INT NULL) despues de `inicio`,
-- apuntando al catalogo `arca_certificados.id`.
--
-- Motivo:
--   La empresa contable puede tener asignado un certificado fiscal de ARCA/AFIP
--   (el mismo tipo de fila que gestiona el ABM `Arca > Certificados`). Antes
--   la relacion existia unicamente en el sentido inverso
--   (`arca_certificados.empresa_id` -> `datacount_empresas.id`); ahora se
--   agrega tambien la referencia desde la empresa hacia el certificado
--   "principal" que corresponde usar para esa empresa. La columna es opcional
--   para no romper empresas historicas que aun no tengan un certificado
--   cargado.
--
-- Compatible con MySQL 8 (dev) y MariaDB 10.11 (prod): se usa el patron
-- information_schema + PREPARE/EXECUTE porque MySQL 8 no soporta la sintaxis
-- MariaDB `ADD COLUMN IF NOT EXISTS`.

-- ---------------------------------------------------------------------------
-- datacount_empresas.certificado_id
-- ---------------------------------------------------------------------------
SET @exists := (
  SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME   = 'datacount_empresas'
    AND COLUMN_NAME  = 'certificado_id'
);
SET @sql := IF(@exists = 0,
  'ALTER TABLE datacount_empresas ADD COLUMN `certificado_id` INT NULL DEFAULT NULL AFTER `inicio`',
  'DO 0'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- Indice para lookups / joins por certificado.
SET @exists := (
  SELECT COUNT(*) FROM information_schema.STATISTICS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME   = 'datacount_empresas'
    AND INDEX_NAME   = 'idx_datacount_empresas_certificado_id'
);
SET @sql := IF(@exists = 0,
  'ALTER TABLE datacount_empresas ADD INDEX `idx_datacount_empresas_certificado_id` (`certificado_id`)',
  'DO 0'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
