-- Elimina la columna `evolution_mensajes.codificado` y desactiva el concepto de
-- transporte base64. Historicamente, cuando `codificado`='1', los campos
-- `asunto` y `cuerpo` se persistian en base64 (encoding binario-safe para HTML
-- con comillas/saltos; NO era cifrado — ver memoria
-- project_evolutionmensajes_codificado).
--
-- Antes de dropear la columna decodificamos in-place los registros marcados
-- como '1', asi ningun campo queda en base64 despues de la migracion. Si
-- FROM_BASE64 devuelve NULL (contenido invalido) preservamos el original —
-- preferimos data cruda a data perdida.
--
-- Idempotente: el UPDATE de decodificacion sirve para filas donde
-- codificado='1' (se limpia solo despues del propio UPDATE, porque ponemos
-- codificado=NULL). El DROP chequea INFORMATION_SCHEMA. Patron compatible con
-- MySQL 8 y MariaDB 10.11 (ambos exponen FROM_BASE64).

SET @db := DATABASE();

-- --- 1) Decodificar in-place asunto/cuerpo donde codificado='1' -------------
-- CONVERT ... USING utf8mb4 porque FROM_BASE64 devuelve VARBINARY.
-- COALESCE mantiene el original si el decode falla (retorna NULL).

SET @sql := (
  SELECT IF(
    (SELECT COUNT(*) FROM information_schema.COLUMNS
      WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'evolution_mensajes'
        AND COLUMN_NAME = 'codificado') > 0,
    'UPDATE `evolution_mensajes`
        SET `asunto` = COALESCE(CONVERT(FROM_BASE64(`asunto`) USING utf8mb4), `asunto`),
            `cuerpo` = COALESCE(CONVERT(FROM_BASE64(`cuerpo`) USING utf8mb4), `cuerpo`),
            `codificado` = NULL
      WHERE `codificado` = ''1''',
    'SELECT 1'
  )
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- --- 2) DROP COLUMN codificado ---------------------------------------------

SET @sql := (
  SELECT IF(
    (SELECT COUNT(*) FROM information_schema.COLUMNS
      WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'evolution_mensajes'
        AND COLUMN_NAME = 'codificado') > 0,
    'ALTER TABLE `evolution_mensajes` DROP COLUMN `codificado`',
    'SELECT 1'
  )
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
