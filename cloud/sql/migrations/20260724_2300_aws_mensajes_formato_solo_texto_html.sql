-- Restringe `aws_mensajes.formato` a solo dos valores validos: `texto` /
-- `html`. Historicamente la columna era varchar(1) con letras `T`/`H`/`M`
-- (M = Markdown, ya no soportado). Alinea el esquema de estado normalizado
-- que ya adoptamos para `estado` (varchar con strings full-word).
--
-- Traduccion:
--   T -> texto
--   H -> html
--   M -> NULL   (Markdown queda deprecado; el sender no sabe procesarlo)
--   ''/otros -> NULL   (defensivo, evita valores raros del clone historico)
--
-- Idempotente:
--   * ALTER chequea CHARACTER_MAXIMUM_LENGTH antes de aplicar (patron
--     compatible con MySQL 8 y MariaDB 10.11 — no podemos usar
--     `MODIFY COLUMN IF EXISTS`).
--   * Los UPDATE filtran por los valores viejos o por "no esta en la
--     whitelist final", al re-correr no hay filas afectadas.

SET @db := DATABASE();

-- --- 1) formato: ampliar varchar(1) -> varchar(10) --------------------------

SET @sql := (
  SELECT IF(
    (SELECT CHARACTER_MAXIMUM_LENGTH FROM information_schema.COLUMNS
      WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'aws_mensajes'
        AND COLUMN_NAME = 'formato') < 10,
    'ALTER TABLE `aws_mensajes`
       MODIFY COLUMN `formato` VARCHAR(10) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NULL DEFAULT NULL',
    'SELECT 1'
  )
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- --- 2) formato: traducir letras a strings ---------------------------------

UPDATE `aws_mensajes` SET `formato` = CASE `formato`
    WHEN 'T' THEN 'texto'
    WHEN 'H' THEN 'html'
    ELSE `formato`
  END
 WHERE `formato` IN ('T', 'H');

-- --- 3) formato: normalizar cualquier otro valor (incluyendo M) a NULL -----

UPDATE `aws_mensajes`
   SET `formato` = NULL
 WHERE `formato` IS NOT NULL
   AND `formato` NOT IN ('texto', 'html');
