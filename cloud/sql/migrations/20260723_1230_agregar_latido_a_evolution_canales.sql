-- Agrega la columna `latido` DATETIME a `evolution_canales`. La escribe el
-- job cron/evolutioncanales_actualizar_estados con NOW() SOLO cuando Evolution
-- devuelve que la instancia esta en linea (connectionStatus='open'). Si la
-- consulta da error o el canal figura offline, `latido` NO se modifica: queda
-- congelada la fecha del ultimo instante en que se supo online.
--
-- El listado del ABM la muestra como columna "Latido" entre "Online" y
-- "Actualizado", renderizada como "hace X minutos" via fmtHace().
--
-- Idempotente: chequea INFORMATION_SCHEMA antes de tocar la columna, asi corre
-- tanto sobre bases nuevas (schema.sql ya trae el esquema final) como sobre
-- bases viejas (esquema previo sin `latido`).

SET @db := DATABASE();

SET @sql := (
  SELECT IF(
    (SELECT COUNT(*) FROM information_schema.COLUMNS
      WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'evolution_canales'
        AND COLUMN_NAME = 'latido') > 0,
    'SELECT 1',
    'ALTER TABLE `evolution_canales` ADD COLUMN `latido` DATETIME NULL DEFAULT NULL AFTER `online`'
  )
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
