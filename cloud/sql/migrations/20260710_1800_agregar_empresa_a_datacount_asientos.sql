-- Agrega la columna `empresa_id` a `datacount_asientos` para que cada asiento
-- quede asociado a una empresa (misma relacion que `datacount_cuentas` y
-- `datacount_recurrentes`). La numeracion `numero` pasa a ser unica DENTRO de
-- una empresa (index compuesto `(empresa_id, numero)`) — cada empresa lleva
-- su propia serie 1, 2, 3, ...
--
-- Backfill: los asientos existentes reciben la empresa_id de la primera
-- cuenta de su detalle (todas las lineas de un asiento deberian pertenecer
-- a la misma empresa). Los que no tienen detalle quedan en empresa_id = 1.
--
-- Idempotente: chequea INFORMATION_SCHEMA antes de tocar la columna y los
-- indices, asi corre tanto sobre bases nuevas como sobre bases viejas.
-- Compatible con MySQL 8 y MariaDB 10.11 (no usa `ADD COLUMN IF NOT EXISTS`,
-- que es MariaDB-only).

SET @db := DATABASE();

-- --------------------------------------------------------------------
-- 1) Agregar columna `empresa_id` (despues de `id`) si no existe.
-- --------------------------------------------------------------------
SET @sql := (
  SELECT IF(
    (SELECT COUNT(*) FROM information_schema.COLUMNS
      WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'datacount_asientos'
        AND COLUMN_NAME = 'empresa_id') > 0,
    'SELECT 1',
    'ALTER TABLE `datacount_asientos`
       ADD COLUMN `empresa_id` INT(11) UNSIGNED NOT NULL DEFAULT 1 AFTER `id`'
  )
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- --------------------------------------------------------------------
-- 2) Backfill: derivar `empresa_id` desde la primera cuenta del detalle.
--    Solo pisa filas que quedaron en 1 (default) — si ya alguien reasigno
--    manualmente, respeta ese valor.
-- --------------------------------------------------------------------
UPDATE `datacount_asientos` a
JOIN (
  SELECT d.asiento_id, MIN(c.empresa_id) AS empresa_id
  FROM `datacount_asientos_detalles` d
  JOIN `datacount_cuentas` c ON c.id = d.cuenta_id
  GROUP BY d.asiento_id
) src ON src.asiento_id = a.id
SET a.empresa_id = src.empresa_id
WHERE a.empresa_id = 1
  AND src.empresa_id <> 1;

-- --------------------------------------------------------------------
-- 3) Sacar el UNIQUE global de `numero` (si existe) para poder repetir
--    el mismo `numero` entre empresas distintas.
-- --------------------------------------------------------------------
SET @sql := (
  SELECT IF(
    (SELECT COUNT(*) FROM information_schema.STATISTICS
      WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'datacount_asientos'
        AND INDEX_NAME = 'uk_numero') > 0,
    'ALTER TABLE `datacount_asientos` DROP INDEX `uk_numero`',
    'SELECT 1'
  )
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- --------------------------------------------------------------------
-- 4) Agregar el UNIQUE compuesto `(empresa_id, numero)`.
-- --------------------------------------------------------------------
SET @sql := (
  SELECT IF(
    (SELECT COUNT(*) FROM information_schema.STATISTICS
      WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'datacount_asientos'
        AND INDEX_NAME = 'uk_empresa_numero') > 0,
    'SELECT 1',
    'ALTER TABLE `datacount_asientos` ADD UNIQUE INDEX `uk_empresa_numero`(`empresa_id`, `numero`)'
  )
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- --------------------------------------------------------------------
-- 5) Indice individual sobre `empresa_id` (los listados filtran por empresa).
-- --------------------------------------------------------------------
SET @sql := (
  SELECT IF(
    (SELECT COUNT(*) FROM information_schema.STATISTICS
      WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'datacount_asientos'
        AND INDEX_NAME = 'idx_empresa') > 0,
    'SELECT 1',
    'ALTER TABLE `datacount_asientos` ADD INDEX `idx_empresa`(`empresa_id`)'
  )
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
