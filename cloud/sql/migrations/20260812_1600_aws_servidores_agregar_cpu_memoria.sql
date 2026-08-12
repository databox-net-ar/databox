-- aws_servidores: agregar `cpu` (vCPUs) y `memoria` (MiB) — specs de hardware
-- de la instancia EC2, tal como los reporta DescribeInstanceTypes.
--
--   cpu     -> INT, cantidad de vCPUs (ej: 2 para t3.small).
--   memoria -> INT, memoria RAM en MiB (ej: 2048 para t3.small = 2 GB).
--
-- Ambos son NULL por defecto y NULL-safe: los populan el boton "Obtener"
-- cruzando el `instance_type` de cada EC2 con DescribeInstanceTypes; si el
-- lookup falla o el operador crea el servidor a mano sin correr Obtener,
-- quedan en NULL y la UI muestra "—".
--
-- En el UPSERT del Obtener se aplica el mismo criterio que
-- sistema_operativo / almacenamiento: se respeta el valor manual si AWS
-- devolvio NULL (no pisamos con NULL lo que el operador hubiera cargado).
--
-- Idempotente. Compatible MySQL 8 (dev) + MariaDB 10.11 (prod).

SET @exists := (
  SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME   = 'aws_servidores'
    AND COLUMN_NAME  = 'cpu'
);
SET @sql := IF(@exists = 0,
  'ALTER TABLE aws_servidores ADD COLUMN `cpu` INT NULL DEFAULT NULL AFTER `sistema_operativo`',
  'DO 0'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @exists := (
  SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME   = 'aws_servidores'
    AND COLUMN_NAME  = 'memoria'
);
SET @sql := IF(@exists = 0,
  'ALTER TABLE aws_servidores ADD COLUMN `memoria` INT NULL DEFAULT NULL AFTER `cpu`',
  'DO 0'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
