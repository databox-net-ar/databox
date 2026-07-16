-- Agrega la columna `actualizado` DATETIME a `evolutioncanales`. La escribe
-- el job cron/evolutioncanales_actualizar_estados con la fecha en que se
-- refresco el estado del canal contra Evolution API, y el listado del ABM la
-- muestra como columna "Actualizado".
--
-- Idempotente: chequea INFORMATION_SCHEMA antes de tocar la columna, asi corre
-- tanto sobre bases nuevas (schema.sql ya trae el esquema final) como sobre
-- bases viejas (esquema previo sin `actualizado`).

SET @db := DATABASE();

SET @sql := (
  SELECT IF(
    (SELECT COUNT(*) FROM information_schema.COLUMNS
      WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'evolutioncanales'
        AND COLUMN_NAME = 'actualizado') > 0,
    'SELECT 1',
    'ALTER TABLE `evolutioncanales` ADD COLUMN `actualizado` DATETIME NULL DEFAULT NULL AFTER `gruposEstado`'
  )
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
