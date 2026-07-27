-- Agrega dos columnas a `aws_mensajes`:
--
--   `uuid`      : identificador que devuelve AWS SES al aceptar el mensaje
--                 (viene en la respuesta 250 despues del DATA en la sesion
--                 SMTP). Sirve como puente contra los reportes de bounce /
--                 complaint / open que Amazon publica via SNS, y como puente
--                 con logs externos. Se rellena en el momento del envio via
--                 cloud/api/lib/mensajes_enviar.php.
--
--   `resultado` : outcome logico posterior al envio, reportado por Amazon
--                 (webhook SNS / bounces). Valores esperados: 'entregado',
--                 'rebotado', 'spam', 'rechazado', 'abierto'. Se persiste
--                 con varchar(20) — mismo criterio que `estado` para poder
--                 crecer valores sin migrar longitud otra vez.
--
-- Ubicacion:
--   `uuid`      -> justo despues de `id` (identificadores juntos al principio)
--   `resultado` -> justo despues de `error` (agrupacion de outcome de envio)
--
-- Idempotente: cada ADD chequea INFORMATION_SCHEMA. Patron compatible con
-- MySQL 8 / MariaDB 10.11 (no podemos usar `ADD COLUMN IF NOT EXISTS` porque
-- es sintaxis MariaDB-only).

SET @db := DATABASE();

-- --- 1) uuid ---------------------------------------------------------------

SET @sql := (
  SELECT IF(
    (SELECT COUNT(*) FROM information_schema.COLUMNS
      WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'aws_mensajes'
        AND COLUMN_NAME = 'uuid') = 0,
    'ALTER TABLE `aws_mensajes`
       ADD COLUMN `uuid` VARCHAR(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NULL DEFAULT NULL AFTER `id`',
    'SELECT 1'
  )
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- --- 2) resultado ----------------------------------------------------------

SET @sql := (
  SELECT IF(
    (SELECT COUNT(*) FROM information_schema.COLUMNS
      WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'aws_mensajes'
        AND COLUMN_NAME = 'resultado') = 0,
    'ALTER TABLE `aws_mensajes`
       ADD COLUMN `resultado` VARCHAR(20) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NULL DEFAULT NULL AFTER `error`',
    'SELECT 1'
  )
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
