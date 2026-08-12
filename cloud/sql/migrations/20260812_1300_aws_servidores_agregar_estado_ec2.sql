-- aws_servidores: agregar `estado_ec2` — estado de ejecucion reportado por AWS.
--
-- Guarda el ultimo estado de la instancia EC2 tal como lo trae la API de AWS
-- (instanceState.name en DescribeInstances): `running`, `stopped`, `stopping`,
-- `pending`, `shutting-down`, `terminated`. Lo popula el boton "Obtener" del
-- ABM en cada corrida; las filas creadas a mano (sin instance_id) quedan
-- con NULL hasta que Obtener las alcance.
--
-- Es un campo distinto de `estado` (que sigue siendo una clasificacion manual
-- Activo/Inactivo usada como flag de visibilidad interna del catalogo).
--
-- Idempotente: cada ALTER se guarda con information_schema + PREPARE.
-- Compatible MySQL 8 (dev) + MariaDB 10.11 (prod).

SET @exists := (
  SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME   = 'aws_servidores'
    AND COLUMN_NAME  = 'estado_ec2'
);
SET @sql := IF(@exists = 0,
  'ALTER TABLE aws_servidores ADD COLUMN `estado_ec2` VARCHAR(20) NULL DEFAULT NULL AFTER `tipo_instancia`',
  'DO 0'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
