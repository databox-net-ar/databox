-- Dropea la columna `variables` de `aws_mensajes`. El ABM cloud ya no la
-- expone ni la guarda: el cuerpo del mensaje viaja completo en `cuerpo` y las
-- variables (si las hubiera) van en `parametros`. Simetrico a la migracion
-- 20260724_1200 que hizo lo mismo con `evolution_mensajes`.
--
-- Idempotente: chequea INFORMATION_SCHEMA antes del DROP (patron compatible
-- con MySQL 8 y MariaDB 10.11 — no podemos usar `DROP COLUMN IF EXISTS`
-- porque es sintaxis MariaDB-only).

SET @db := DATABASE();

SET @sql := (
  SELECT IF(
    (SELECT COUNT(*) FROM information_schema.COLUMNS
      WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'aws_mensajes'
        AND COLUMN_NAME = 'variables') > 0,
    'ALTER TABLE `aws_mensajes` DROP COLUMN `variables`',
    'SELECT 1'
  )
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
