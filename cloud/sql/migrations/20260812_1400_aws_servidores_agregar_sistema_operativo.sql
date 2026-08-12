-- aws_servidores: agregar `sistema_operativo` — texto libre editable por el
-- operador desde el ABM para anotar que SO corre el servidor (ej: "Ubuntu
-- 22.04", "Amazon Linux 2", "Windows Server 2019", "Debian 12").
--
-- Se muestra en la columna "Host / IP / Sistema" del listado (debajo de la
-- IP, en gris) y en el modal de Consultar/Editar. Por ahora es un campo
-- puramente manual: el boton "Obtener" NO lo pisa porque DescribeInstances
-- solo devuelve `platform` (util solo para distinguir Windows de "el resto")
-- y no la distro/version real. Si mas adelante queremos autopoblarlo,
-- habria que resolverlo via AMI (DescribeImages) por instancia.
--
-- Idempotente: cada ALTER se guarda con information_schema + PREPARE.
-- Compatible MySQL 8 (dev) + MariaDB 10.11 (prod).

SET @exists := (
  SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME   = 'aws_servidores'
    AND COLUMN_NAME  = 'sistema_operativo'
);
SET @sql := IF(@exists = 0,
  'ALTER TABLE aws_servidores ADD COLUMN `sistema_operativo` VARCHAR(100) NULL DEFAULT NULL AFTER `estado_ec2`',
  'DO 0'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
