-- Quita la columna `color` de `datarocket_etiquetas`. La columna se introdujo
-- junto con la tabla en la migracion 20260811_1200 y se descarta porque el
-- ABM ya no la utiliza: las etiquetas se muestran como texto plano y la
-- eleccion visual paso a ser una decision de estilo del consumidor.
--
-- Patron `information_schema` + PREPARE/EXECUTE para funcionar tanto en
-- MySQL 8.0 (dev) como MariaDB 10.11 (prod). Idempotente: si la columna
-- no existe, es no-op.

SET @db := DATABASE();

SET @sql := (
  SELECT IF(COUNT(*) > 0,
    'ALTER TABLE `datarocket_etiquetas` DROP COLUMN `color`',
    'DO 0'
  )
  FROM information_schema.columns
  WHERE table_schema = @db
    AND table_name   = 'datarocket_etiquetas'
    AND column_name  = 'color'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
