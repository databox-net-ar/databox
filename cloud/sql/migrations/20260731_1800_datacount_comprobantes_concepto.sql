-- datacount_comprobantes: agregar `concepto` TINYINT NULL DEFAULT NULL despues
-- de `vencimiento`. Valores admitidos por AFIP (FECAESolicitar / Concepto):
--   1 = Productos
--   2 = Servicios
--   3 = Productos + Servicios
-- Todos los comprobantes existentes quedan con NULL (sin dato historico); el
-- job de autorizacion (jobs/datacount_comprobantes_autorizar.php) usa `1` por
-- default cuando la columna viene NULL, asi que el comportamiento previo se
-- preserva.
--
-- Compatible con MySQL 8 (dev) y MariaDB 10.11 (prod): se usa el patron
-- information_schema + PREPARE/EXECUTE porque MySQL 8 no soporta la sintaxis
-- MariaDB `ADD COLUMN IF NOT EXISTS`.

SET @exists := (
  SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME   = 'datacount_comprobantes'
    AND COLUMN_NAME  = 'concepto'
);
SET @sql := IF(@exists = 0,
  'ALTER TABLE datacount_comprobantes ADD COLUMN `concepto` TINYINT(1) NULL DEFAULT NULL AFTER `vencimiento`',
  'DO 0'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
