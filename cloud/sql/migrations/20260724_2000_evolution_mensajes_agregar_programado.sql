-- Agrega la columna `programado` a `evolution_mensajes`, ubicada despues de
-- `encolado` y antes de `enviado`. Es la fecha/hora en la que el mensaje debe
-- salir hacia Evolution API — permite encolar hoy un mensaje que recien tiene
-- que enviarse manana. NULL = enviar apenas el worker lo tome.
--
-- Idempotente: chequea INFORMATION_SCHEMA antes del ADD. Ni MySQL 8 ni MariaDB
-- 10.11 soportan `BEFORE col`, asi que usamos `AFTER encolado` (equivalente).

SET @db := DATABASE();

SET @sql := (
  SELECT IF(
    (SELECT COUNT(*) FROM information_schema.COLUMNS
      WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'evolution_mensajes'
        AND COLUMN_NAME = 'programado') > 0,
    'SELECT 1',
    'ALTER TABLE `evolution_mensajes` ADD COLUMN `programado` DATETIME NULL DEFAULT NULL AFTER `encolado`'
  )
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
