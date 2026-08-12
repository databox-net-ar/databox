-- aws_servidores: agregar `instance_id` — clave AWS del servidor EC2.
--
-- Se usa como clave natural para deduplicar/upsertar las instancias que trae
-- el nuevo boton "Obtener" del ABM (recorre todas las cuentas AWS y todas
-- las regiones habilitadas, y por cada EC2 encontrada hace UPSERT contra
-- aws_servidores).
--
-- Los instance_id de AWS tienen forma `i-XXXXXXXXXXXXXXXXX` (17 chars) desde
-- 2016; los legacy son `i-XXXXXXXX` (8 chars). VARCHAR(30) alcanza con
-- margen y matchea el ancho de `region` / `tipo_instancia`.
--
-- La columna es NULLABLE porque las filas creadas a mano (servidores que
-- el operador da de alta desde la UI sin correr "Obtener") no tienen
-- instance_id. El UNIQUE es NULL-friendly: MySQL 8 y MariaDB 10.11 permiten
-- multiples filas con instance_id = NULL sin violar la unicidad.
--
-- Idempotente: cada ALTER se guarda con information_schema + PREPARE.
-- Compatible MySQL 8 (dev) + MariaDB 10.11 (prod).

SET @exists := (
  SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME   = 'aws_servidores'
    AND COLUMN_NAME  = 'instance_id'
);
SET @sql := IF(@exists = 0,
  'ALTER TABLE aws_servidores ADD COLUMN `instance_id` VARCHAR(30) NULL DEFAULT NULL AFTER `id`',
  'DO 0'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @exists := (
  SELECT COUNT(*) FROM information_schema.STATISTICS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME   = 'aws_servidores'
    AND INDEX_NAME   = 'uq_aws_servidores_instance_id'
);
SET @sql := IF(@exists = 0,
  'ALTER TABLE aws_servidores ADD UNIQUE INDEX `uq_aws_servidores_instance_id` (`instance_id`)',
  'DO 0'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
