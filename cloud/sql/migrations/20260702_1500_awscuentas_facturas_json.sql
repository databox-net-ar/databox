-- Agrega `facturas_json` al final de `awscuentas` para cachear la respuesta
-- completa de la ultima sincronizacion con AWS (BCM + Invoicing + match).
-- El modal Consultar > Facturacion lee este JSON para mostrar el listado sin
-- tener que re-consultar AWS en cada apertura; el boton "Actualizar" fuerza
-- una re-sincronizacion cuando el usuario quiere ver datos frescos.
--
-- Uso `JSON` (nativo en MySQL 8 y MariaDB 10.11) para tener validacion server-side.

SET @db := DATABASE();

SET @sql := (
  SELECT IF(
    (SELECT COUNT(*) FROM information_schema.COLUMNS
      WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'awscuentas'
        AND COLUMN_NAME = 'facturas_json') > 0,
    'SELECT 1',
    'ALTER TABLE `awscuentas` ADD COLUMN `facturas_json` JSON NULL DEFAULT NULL AFTER `facturas_actualizado`'
  )
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
