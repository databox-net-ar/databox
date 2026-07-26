-- Renombra `aws_canales.uuid` -> `aws_canales.slug`.
--
-- Motivacion: la columna nunca guardo un UUID cannonico (v4/RFC4122); siempre
-- fue un identificador libre generado como bin2hex(random_bytes(16)) por el
-- ABM, y los registros historicos tienen valores tipo `databox`, `vigicom`,
-- `reactor` (slugs de proyecto). Alinear el nombre con la semantica real.
--
-- Idempotente: chequea INFORMATION_SCHEMA por la existencia de la columna
-- origen (patron compatible con MySQL 8 y MariaDB 10.11 — no podemos usar
-- `RENAME COLUMN ... IF EXISTS` porque es sintaxis MariaDB-only).

SET @db := DATABASE();

SET @sql := (
  SELECT IF(
    (SELECT COUNT(*) FROM information_schema.COLUMNS
      WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'aws_canales'
        AND COLUMN_NAME = 'uuid') > 0,
    'ALTER TABLE `aws_canales` RENAME COLUMN `uuid` TO `slug`',
    'SELECT 1'
  )
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
