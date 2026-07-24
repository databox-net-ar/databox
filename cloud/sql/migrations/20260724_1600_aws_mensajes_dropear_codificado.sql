-- Elimina la columna `aws_mensajes.codificado` y desactiva el concepto de
-- transporte base64. Historicamente, cuando `codificado`='1', los campos
-- `asunto` y `cuerpo` se persistian en base64 (encoding binario-safe para HTML
-- con comillas/saltos; NO era cifrado — ver memoria
-- project_evolutionmensajes_codificado). Simetrico a la migracion
-- 20260724_1400 que hizo lo mismo con `evolution_mensajes`.
--
-- A diferencia de la variante inicial, aca NO intentamos decodificar in-place
-- las filas con codificado='1': en AWS habia registros con base64 de bytes no
-- UTF-8 (adjuntos binarios embebidos, correos con encoding raro) que hacian
-- explotar `CONVERT(FROM_BASE64(...) USING utf8mb4)` con SQLSTATE HY000 /
-- error 1300 (Invalid utf8mb4 character string), abortando la migracion.
-- Decidido con el usuario 2026-07-24: **borrar** las filas afectadas antes
-- de dropear la columna. Son datos historicos irrecuperables sin el flag
-- `codificado`, no se pueden mostrar decentemente en el ABM.
--
-- Idempotente: el DELETE y el DROP chequean INFORMATION_SCHEMA por la
-- existencia de la columna. Patron compatible con MySQL 8 y MariaDB 10.11
-- (no podemos usar `DROP COLUMN IF EXISTS` porque es sintaxis MariaDB-only).

SET @db := DATABASE();

-- --- 1) Borrar filas con codificado='1' -------------------------------------
-- Solo corre si la columna todavia existe (idempotencia).

SET @sql := (
  SELECT IF(
    (SELECT COUNT(*) FROM information_schema.COLUMNS
      WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'aws_mensajes'
        AND COLUMN_NAME = 'codificado') > 0,
    'DELETE FROM `aws_mensajes` WHERE `codificado` = ''1''',
    'SELECT 1'
  )
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- --- 2) DROP COLUMN codificado ---------------------------------------------

SET @sql := (
  SELECT IF(
    (SELECT COUNT(*) FROM information_schema.COLUMNS
      WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'aws_mensajes'
        AND COLUMN_NAME = 'codificado') > 0,
    'ALTER TABLE `aws_mensajes` DROP COLUMN `codificado`',
    'SELECT 1'
  )
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
