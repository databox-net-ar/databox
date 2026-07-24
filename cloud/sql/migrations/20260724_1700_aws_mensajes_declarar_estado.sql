-- Declara los valores validos de `aws_mensajes.estado`:
--
--   `estado` (varchar(1) letras P/E/F/C/R)  ->  varchar(20) texto completo
--            (pendiente / enviado / anulado / error)
--
-- Migra los datos historicos:
--   P -> pendiente
--   E -> enviado
--   F -> error
--   C -> anulado
--   R -> error   (los reintentos historicos se colapsan a "error")
--
-- Siembra las 4 filas correspondientes en el catalogo `estados` bajo el
-- campo `aws_mensaje_estado` (naming snake_case + modelo en singular,
-- ver otros grupos en la misma tabla — en particular
-- `evolution_mensaje_estado` sembrado por la migracion 20260724_1300).
--
-- Idempotente: el ALTER chequea INFORMATION_SCHEMA por longitud actual antes
-- de aplicar, el UPDATE filtra por los valores viejos, y los INSERT en
-- `estados` usan `WHERE NOT EXISTS` (la tabla no tiene UNIQUE por diseno, se
-- comparte con apps del grupo). Patron compatible con MySQL 8 y MariaDB 10.11.

SET @db := DATABASE();

-- --- 1) estado: ampliar varchar(1) -> varchar(20) ---------------------------

SET @sql := (
  SELECT IF(
    (SELECT CHARACTER_MAXIMUM_LENGTH FROM information_schema.COLUMNS
      WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'aws_mensajes'
        AND COLUMN_NAME = 'estado') < 20,
    'ALTER TABLE `aws_mensajes`
       MODIFY COLUMN `estado` VARCHAR(20) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NULL DEFAULT NULL',
    'SELECT 1'
  )
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- --- 2) estado: migrar P/E/F/C/R -> pendiente/enviado/error/anulado/error ---

UPDATE `aws_mensajes` SET `estado` = CASE `estado`
    WHEN 'P' THEN 'pendiente'
    WHEN 'E' THEN 'enviado'
    WHEN 'F' THEN 'error'
    WHEN 'C' THEN 'anulado'
    WHEN 'R' THEN 'error'
    ELSE `estado`
  END
 WHERE `estado` IN ('P', 'E', 'F', 'C', 'R');

-- --- 3) Seed catalogo `estados` para aws_mensaje_estado ---------------------

INSERT INTO `estados` (`campo`, `texto`, `valor`, `orden`)
SELECT 'aws_mensaje_estado', 'Pendiente', 'pendiente', 1
 WHERE NOT EXISTS (SELECT 1 FROM `estados`
   WHERE `campo` = 'aws_mensaje_estado' AND `valor` = 'pendiente');

INSERT INTO `estados` (`campo`, `texto`, `valor`, `orden`)
SELECT 'aws_mensaje_estado', 'Enviado', 'enviado', 2
 WHERE NOT EXISTS (SELECT 1 FROM `estados`
   WHERE `campo` = 'aws_mensaje_estado' AND `valor` = 'enviado');

INSERT INTO `estados` (`campo`, `texto`, `valor`, `orden`)
SELECT 'aws_mensaje_estado', 'Anulado', 'anulado', 3
 WHERE NOT EXISTS (SELECT 1 FROM `estados`
   WHERE `campo` = 'aws_mensaje_estado' AND `valor` = 'anulado');

INSERT INTO `estados` (`campo`, `texto`, `valor`, `orden`)
SELECT 'aws_mensaje_estado', 'Error', 'error', 4
 WHERE NOT EXISTS (SELECT 1 FROM `estados`
   WHERE `campo` = 'aws_mensaje_estado' AND `valor` = 'error');
