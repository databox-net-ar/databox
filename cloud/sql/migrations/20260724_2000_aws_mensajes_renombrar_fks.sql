-- Renombra las columnas FK de `aws_mensajes` para dejarlas con sufijo `_id`
-- explicito, alineado con la convencion emergente del schema (evita
-- ambiguedad con nombres cortos que suenan a texto libre):
--
--   `proyecto`  -> `proyecto_id`
--   `canal`     -> `canal_id`
--   `plantilla` -> `plantilla_id`
--
-- Idempotente: cada RENAME chequea INFORMATION_SCHEMA por la existencia de la
-- columna original (patron compatible con MySQL 8 y MariaDB 10.11 — no
-- podemos usar `RENAME COLUMN ... IF EXISTS` porque es sintaxis MariaDB-only).

SET @db := DATABASE();

-- --- 1) proyecto -> proyecto_id ---------------------------------------------

SET @sql := (
  SELECT IF(
    (SELECT COUNT(*) FROM information_schema.COLUMNS
      WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'aws_mensajes'
        AND COLUMN_NAME = 'proyecto') > 0,
    'ALTER TABLE `aws_mensajes` RENAME COLUMN `proyecto` TO `proyecto_id`',
    'SELECT 1'
  )
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- --- 2) canal -> canal_id ---------------------------------------------------

SET @sql := (
  SELECT IF(
    (SELECT COUNT(*) FROM information_schema.COLUMNS
      WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'aws_mensajes'
        AND COLUMN_NAME = 'canal') > 0,
    'ALTER TABLE `aws_mensajes` RENAME COLUMN `canal` TO `canal_id`',
    'SELECT 1'
  )
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- --- 3) plantilla -> plantilla_id -------------------------------------------

SET @sql := (
  SELECT IF(
    (SELECT COUNT(*) FROM information_schema.COLUMNS
      WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'aws_mensajes'
        AND COLUMN_NAME = 'plantilla') > 0,
    'ALTER TABLE `aws_mensajes` RENAME COLUMN `plantilla` TO `plantilla_id`',
    'SELECT 1'
  )
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
