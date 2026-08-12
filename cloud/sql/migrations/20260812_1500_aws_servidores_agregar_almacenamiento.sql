-- aws_servidores: agregar `almacenamiento` — string con los tamanios de los
-- volumenes EBS conectados al servidor, en formato "Ngb + Mgb + Kgb" (uno
-- por volumen, ordenados ascendente por tamanio). Ejemplos:
--   "8gb"           (una sola unidad de 8 GiB)
--   "8gb + 100gb"   (root de 8 + data de 100)
--
-- Lo popula el boton "Obtener" del ABM cruzando DescribeInstances (para
-- listar los volumenes attach a cada instancia) con DescribeVolumes (para
-- leer el `size` en GiB de cada volumen). En el upsert se respeta el
-- valor manual si AWS no devolvio nada — mismo criterio que sistema_operativo.
--
-- Idempotente. Compatible MySQL 8 (dev) + MariaDB 10.11 (prod).

SET @exists := (
  SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME   = 'aws_servidores'
    AND COLUMN_NAME  = 'almacenamiento'
);
SET @sql := IF(@exists = 0,
  'ALTER TABLE aws_servidores ADD COLUMN `almacenamiento` VARCHAR(100) NULL DEFAULT NULL AFTER `sistema_operativo`',
  'DO 0'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
