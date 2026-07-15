-- `datacount_recurrentes.cuenta` pasa a ser NULLABLE.
--
-- Hasta ahora la cuenta era obligatoria en el alta del movimiento recurrente,
-- pero se decidió permitir crearlo sin cuenta asignada (queda pendiente de
-- imputación) — el select del ABM ya no marca el campo como requerido.
--
-- Idempotente: chequea INFORMATION_SCHEMA y solo ejecuta el ALTER si la
-- columna todavía es NOT NULL. Correrla dos veces no hace ruido.

SET @db := DATABASE();

SET @sql := (
  SELECT IF(
    (SELECT IS_NULLABLE
       FROM information_schema.COLUMNS
       WHERE TABLE_SCHEMA = @db
         AND TABLE_NAME   = 'datacount_recurrentes'
         AND COLUMN_NAME  = 'cuenta') = 'YES',
    'SELECT 1',
    'ALTER TABLE `datacount_recurrentes` MODIFY COLUMN `cuenta` INT(11) UNSIGNED NULL DEFAULT NULL'
  )
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
