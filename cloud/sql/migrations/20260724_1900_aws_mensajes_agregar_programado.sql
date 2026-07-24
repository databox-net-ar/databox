-- Agrega la columna `aws_mensajes.programado` (datetime NULL, default NULL)
-- para dejar rastro del momento futuro en que se debe intentar el envio (por
-- ejemplo, mensajes encolados con salida diferida). Va posicionada entre
-- `encolado` y `enviado` para mantener el orden logico del ciclo de vida:
--
--   fecha (alta) -> encolado (aceptado por la cola) ->
--   programado (proxima ventana de envio) -> enviado (efectivo)
--
-- Idempotente: chequea INFORMATION_SCHEMA antes del ADD (patron compatible
-- con MySQL 8 y MariaDB 10.11 — no podemos usar `ADD COLUMN IF NOT EXISTS`
-- porque es sintaxis MariaDB-only).

SET @db := DATABASE();

SET @sql := (
  SELECT IF(
    (SELECT COUNT(*) FROM information_schema.COLUMNS
      WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'aws_mensajes'
        AND COLUMN_NAME = 'programado') = 0,
    'ALTER TABLE `aws_mensajes`
       ADD COLUMN `programado` DATETIME(0) NULL DEFAULT NULL AFTER `encolado`',
    'SELECT 1'
  )
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
