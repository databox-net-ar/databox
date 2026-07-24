-- Agrega credenciales de API IAM a `aws_canales` para que el enviador de
-- mensajes pueda hablar contra AWS SES v2 (POST /v2/email/outbound-emails
-- firmado con SigV4). Hasta ahora la tabla solo guardaba credenciales SMTP
-- (servidor/usuario/contrasena) usadas por el legacy con PHPMailer; el
-- envio nuevo lo hace por la API REST reutilizando cloud/api/lib/awssig.php.
--
-- Idempotente: cada ALTER chequea INFORMATION_SCHEMA antes de aplicar.
-- Patron compatible con MySQL 8 y MariaDB 10.11.

SET @db := DATABASE();

-- --- 1) aws_canales.accesskey (varchar(255) NULL) ---------------------------

SET @sql := (
  SELECT IF(
    (SELECT COUNT(*) FROM information_schema.COLUMNS
      WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'aws_canales'
        AND COLUMN_NAME = 'accesskey') = 0,
    'ALTER TABLE `aws_canales`
       ADD COLUMN `accesskey` VARCHAR(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NULL DEFAULT NULL AFTER `contrasena`',
    'SELECT 1'
  )
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- --- 2) aws_canales.secreto (varchar(255) NULL) -----------------------------

SET @sql := (
  SELECT IF(
    (SELECT COUNT(*) FROM information_schema.COLUMNS
      WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'aws_canales'
        AND COLUMN_NAME = 'secreto') = 0,
    'ALTER TABLE `aws_canales`
       ADD COLUMN `secreto` VARCHAR(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NULL DEFAULT NULL AFTER `accesskey`',
    'SELECT 1'
  )
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- --- 3) aws_canales.region (varchar(30) NULL, default us-east-1) ------------
-- El endpoint SES v2 varia por region (email.us-east-1.amazonaws.com, etc.).
-- Se agrega como columna dedicada para no hardcodearla en el job.

SET @sql := (
  SELECT IF(
    (SELECT COUNT(*) FROM information_schema.COLUMNS
      WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'aws_canales'
        AND COLUMN_NAME = 'region') = 0,
    'ALTER TABLE `aws_canales`
       ADD COLUMN `region` VARCHAR(30) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NULL DEFAULT NULL AFTER `secreto`',
    'SELECT 1'
  )
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
