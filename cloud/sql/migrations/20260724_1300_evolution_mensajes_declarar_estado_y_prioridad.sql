-- Declara los valores validos de dos columnas de `evolution_mensajes`:
--
--   `estado`    (varchar(1) letras P/E/F/C/R)  ->  varchar(20) texto completo
--               (pendiente / anulado / enviado / error)
--   `prioridad` (varchar(1) letras A/N/B)      ->  tinyint(3) unsigned 1..5
--               (Muy baja=1 / Baja=2 / Media=3 / Alta=4 / Muy Alta=5)
--
-- Migra los datos historicos:
--   estado:    P->pendiente  E->enviado  F->error  C->anulado  R->error
--   prioridad: A->4          N->3        B->2
--
-- Siembra las 9 filas correspondientes en el catalogo `estados` bajo los
-- campos `evolution_mensaje_estado` y `evolution_mensaje_prioridad` (naming
-- snake_case + modelo en singular, ver otros grupos en la misma tabla).
--
-- Idempotente: cada ALTER chequea INFORMATION_SCHEMA por longitud/tipo actual
-- antes de aplicar, los UPDATE filtran por los valores viejos, y los INSERT en
-- `estados` usan `WHERE NOT EXISTS` (la tabla no tiene UNIQUE por diseno, se
-- comparte con apps del grupo). Patron compatible con MySQL 8 y MariaDB 10.11.

SET @db := DATABASE();

-- --- 1) estado: ampliar varchar(1) -> varchar(20) ---------------------------

SET @sql := (
  SELECT IF(
    (SELECT CHARACTER_MAXIMUM_LENGTH FROM information_schema.COLUMNS
      WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'evolution_mensajes'
        AND COLUMN_NAME = 'estado') < 20,
    'ALTER TABLE `evolution_mensajes`
       MODIFY COLUMN `estado` VARCHAR(20) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NULL DEFAULT NULL',
    'SELECT 1'
  )
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- --- 2) estado: migrar P/E/F/C/R -> pendiente/enviado/error/anulado/error ---

UPDATE `evolution_mensajes` SET `estado` = CASE `estado`
    WHEN 'P' THEN 'pendiente'
    WHEN 'E' THEN 'enviado'
    WHEN 'F' THEN 'error'
    WHEN 'C' THEN 'anulado'
    WHEN 'R' THEN 'error'
    ELSE `estado`
  END
 WHERE `estado` IN ('P', 'E', 'F', 'C', 'R');

-- --- 3) prioridad: migrar A/N/B -> 4/3/2 (aun varchar(1); los digitos entran) -

UPDATE `evolution_mensajes` SET `prioridad` = CASE `prioridad`
    WHEN 'A' THEN '4'
    WHEN 'N' THEN '3'
    WHEN 'B' THEN '2'
    ELSE `prioridad`
  END
 WHERE `prioridad` IN ('A', 'N', 'B');

-- --- 4) prioridad: convertir varchar(1) -> tinyint(3) unsigned --------------

SET @sql := (
  SELECT IF(
    (SELECT DATA_TYPE FROM information_schema.COLUMNS
      WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'evolution_mensajes'
        AND COLUMN_NAME = 'prioridad') = 'varchar',
    'ALTER TABLE `evolution_mensajes`
       MODIFY COLUMN `prioridad` TINYINT(3) UNSIGNED NULL DEFAULT NULL',
    'SELECT 1'
  )
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- --- 5) Seed catalogo `estados` para evolution_mensaje_estado ---------------

INSERT INTO `estados` (`campo`, `texto`, `valor`, `orden`)
SELECT 'evolution_mensaje_estado', 'Pendiente', 'pendiente', 1
 WHERE NOT EXISTS (SELECT 1 FROM `estados`
   WHERE `campo` = 'evolution_mensaje_estado' AND `valor` = 'pendiente');

INSERT INTO `estados` (`campo`, `texto`, `valor`, `orden`)
SELECT 'evolution_mensaje_estado', 'Enviado', 'enviado', 2
 WHERE NOT EXISTS (SELECT 1 FROM `estados`
   WHERE `campo` = 'evolution_mensaje_estado' AND `valor` = 'enviado');

INSERT INTO `estados` (`campo`, `texto`, `valor`, `orden`)
SELECT 'evolution_mensaje_estado', 'Anulado', 'anulado', 3
 WHERE NOT EXISTS (SELECT 1 FROM `estados`
   WHERE `campo` = 'evolution_mensaje_estado' AND `valor` = 'anulado');

INSERT INTO `estados` (`campo`, `texto`, `valor`, `orden`)
SELECT 'evolution_mensaje_estado', 'Error', 'error', 4
 WHERE NOT EXISTS (SELECT 1 FROM `estados`
   WHERE `campo` = 'evolution_mensaje_estado' AND `valor` = 'error');

-- --- 6) Seed catalogo `estados` para evolution_mensaje_prioridad ------------
-- Valor numerico: 5 = Muy Alta (se envia primero), 1 = Muy baja (ultima).

INSERT INTO `estados` (`campo`, `texto`, `valor`, `orden`)
SELECT 'evolution_mensaje_prioridad', 'Muy baja', '1', 1
 WHERE NOT EXISTS (SELECT 1 FROM `estados`
   WHERE `campo` = 'evolution_mensaje_prioridad' AND `valor` = '1');

INSERT INTO `estados` (`campo`, `texto`, `valor`, `orden`)
SELECT 'evolution_mensaje_prioridad', 'Baja', '2', 2
 WHERE NOT EXISTS (SELECT 1 FROM `estados`
   WHERE `campo` = 'evolution_mensaje_prioridad' AND `valor` = '2');

INSERT INTO `estados` (`campo`, `texto`, `valor`, `orden`)
SELECT 'evolution_mensaje_prioridad', 'Media', '3', 3
 WHERE NOT EXISTS (SELECT 1 FROM `estados`
   WHERE `campo` = 'evolution_mensaje_prioridad' AND `valor` = '3');

INSERT INTO `estados` (`campo`, `texto`, `valor`, `orden`)
SELECT 'evolution_mensaje_prioridad', 'Alta', '4', 4
 WHERE NOT EXISTS (SELECT 1 FROM `estados`
   WHERE `campo` = 'evolution_mensaje_prioridad' AND `valor` = '4');

INSERT INTO `estados` (`campo`, `texto`, `valor`, `orden`)
SELECT 'evolution_mensaje_prioridad', 'Muy Alta', '5', 5
 WHERE NOT EXISTS (SELECT 1 FROM `estados`
   WHERE `campo` = 'evolution_mensaje_prioridad' AND `valor` = '5');
