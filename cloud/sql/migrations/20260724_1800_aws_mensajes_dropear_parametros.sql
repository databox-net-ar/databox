-- Dropea la columna `parametros` de `aws_mensajes`. El ABM cloud ya no la
-- expone ni la guarda; los reintentos/parametrizaciones del envio viven fuera
-- de la tabla (tags para categorizacion, cuerpo/asunto ya interpolados por
-- quien encola el mensaje).
--
-- Idempotente: chequea INFORMATION_SCHEMA antes del DROP (patron compatible
-- con MySQL 8 y MariaDB 10.11 — no podemos usar `DROP COLUMN IF EXISTS`
-- porque es sintaxis MariaDB-only).

SET @db := DATABASE();

SET @sql := (
  SELECT IF(
    (SELECT COUNT(*) FROM information_schema.COLUMNS
      WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'aws_mensajes'
        AND COLUMN_NAME = 'parametros') > 0,
    'ALTER TABLE `aws_mensajes` DROP COLUMN `parametros`',
    'SELECT 1'
  )
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
