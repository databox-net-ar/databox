-- datacount_comprobantes: agregar `webhook_url` VARCHAR(500) y `webhook_estado`
-- VARCHAR(20) despues de `estado`. La ingesta v4 (api/v4/datacount/comprobantes.php)
-- setea `webhook_url` con la URL a la que hay que notificar cuando el comprobante
-- logre autorizarse contra AFIP; `webhook_estado` arranca en 'pendiente' cuando
-- se configura la URL y pasa a 'completado' una vez que se dispara el POST de
-- notificacion. Ambos NULL cuando el caller no pide webhook.
--
-- Compatible con MySQL 8 (dev) y MariaDB 10.11 (prod): se usa el patron
-- information_schema + PREPARE/EXECUTE porque MySQL 8 no soporta la sintaxis
-- MariaDB `ADD COLUMN IF NOT EXISTS`.

SET @exists := (
  SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME   = 'datacount_comprobantes'
    AND COLUMN_NAME  = 'webhook_url'
);
SET @sql := IF(@exists = 0,
  'ALTER TABLE datacount_comprobantes ADD COLUMN `webhook_url` VARCHAR(500) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NULL DEFAULT NULL AFTER `estado`',
  'DO 0'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @exists := (
  SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME   = 'datacount_comprobantes'
    AND COLUMN_NAME  = 'webhook_estado'
);
SET @sql := IF(@exists = 0,
  'ALTER TABLE datacount_comprobantes ADD COLUMN `webhook_estado` VARCHAR(20) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NULL DEFAULT NULL AFTER `webhook_url`',
  'DO 0'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
