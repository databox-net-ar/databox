-- Agrega las columnas `online` VARCHAR(1) y `latido` DATETIME a
-- `telegram_canales`, para monitorear la salud de las sesiones MTProto
-- (una sesion caida se detecta por `AUTH_KEY_UNREGISTERED` /
-- `SESSION_REVOKED` / `USER_DEACTIVATED` al mandar; ver
-- cloud/api/lib/telegram_mensajes_enviar.php).
--
-- Semantica identica a `evolution_canales`:
--   `online`  = '1' si la ultima interaccion con el canal fue OK; '0' si
--                fallo con error de auth; NULL si nunca se toco todavia.
--   `latido`  = NOW() del ultimo instante en que se supo vivo. Se pisa desde
--                dos lugares:
--                  (a) sender pasivo (envio real OK), y
--                  (b) cron activo cloud/jobs/telegramcanales_actualizar_estados.php
--                      que llama getSelf() cuando el latido esta viejo.
--                Si el envio (o el health check) falla, `latido` NO se toca:
--                queda congelado el ultimo momento en que se supo online.
--
-- Tambien se agrega un indice sobre `latido` para acelerar la query "canales
-- con latido viejo" que ejecuta el cron activo cada tick.
--
-- Idempotente: chequea INFORMATION_SCHEMA antes de tocar la tabla (patron
-- estandar del proyecto para dev MySQL 8 + prod MariaDB 10.11, que no admite
-- `ADD COLUMN IF NOT EXISTS` uniformemente).

SET @db := DATABASE();

-- online: colocada despues de `telefono`, antes de `habilitado` (mirror del
-- orden en evolution_canales).
SET @sql := (
  SELECT IF(
    (SELECT COUNT(*) FROM information_schema.COLUMNS
      WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'telegram_canales'
        AND COLUMN_NAME = 'online') > 0,
    'SELECT 1',
    'ALTER TABLE `telegram_canales` ADD COLUMN `online` VARCHAR(1) NULL DEFAULT NULL AFTER `telefono`'
  )
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- latido: DATETIME, ubicada despues de `online`.
SET @sql := (
  SELECT IF(
    (SELECT COUNT(*) FROM information_schema.COLUMNS
      WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'telegram_canales'
        AND COLUMN_NAME = 'latido') > 0,
    'SELECT 1',
    'ALTER TABLE `telegram_canales` ADD COLUMN `latido` DATETIME NULL DEFAULT NULL AFTER `online`'
  )
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- Indice sobre latido para el cron activo (SELECT ... WHERE latido IS NULL OR
-- latido < NOW() - INTERVAL X MINUTE).
SET @sql := (
  SELECT IF(
    (SELECT COUNT(*) FROM information_schema.STATISTICS
      WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'telegram_canales'
        AND INDEX_NAME = 'idx_telegram_canales_latido') > 0,
    'SELECT 1',
    'ALTER TABLE `telegram_canales` ADD INDEX `idx_telegram_canales_latido` (`latido`) USING BTREE'
  )
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
