-- Agrega la columna `uuid` a `evolution_mensajes` — identificador propio del
-- mensaje en formato UUID estandar (RFC 4122, 36 chars con guiones). Se llena
-- en la insercion desde cloud/api/lib/evolution_mensajes.php (via UUID() del
-- motor SQL) y ademas en un backfill puntual para las filas historicas.
--
-- Ubicacion: justo despues de `id` — mismo criterio que `aws_mensajes.uuid`
-- (identificadores juntos al principio), agregada en
-- 20260725_2300_aws_mensajes_uuid_y_resultado.sql.
--
-- Idempotente: cada bloque chequea INFORMATION_SCHEMA / condicion antes de
-- actuar. Patron compatible con MySQL 8 / MariaDB 10.11 (no podemos usar
-- `ADD COLUMN IF NOT EXISTS` porque es sintaxis MariaDB-only).
--
-- UUID() se re-evalua por cada fila en un UPDATE, por lo que un unico statement
-- basta para asignar un valor distinto a cada registro (mirror del criterio
-- usado en 20260727_2000_datarocket_contactos_uuid_estandar.sql y
-- 20260727_2100_aws_mensajes_uuid_estandar.sql).

SET @db := DATABASE();

-- --- 1) Agregar columna uuid ------------------------------------------------

SET @sql := (
  SELECT IF(
    (SELECT COUNT(*) FROM information_schema.COLUMNS
      WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'evolution_mensajes'
        AND COLUMN_NAME = 'uuid') = 0,
    'ALTER TABLE `evolution_mensajes`
       ADD COLUMN `uuid` VARCHAR(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NULL DEFAULT NULL AFTER `id`',
    'SELECT 1'
  )
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- --- 2) Backfill de filas historicas ---------------------------------------
-- Rellena solo las filas todavia sin uuid — si esta migracion se reaplica,
-- no toca las filas que ya tienen el valor generado por el INSERT del
-- endpoint HTTP, evitando regenerar identificadores en vivo.

UPDATE `evolution_mensajes` SET `uuid` = UUID() WHERE `uuid` IS NULL OR `uuid` = '';
