-- Convierte `aws_mensajes.prioridad` de varchar(1) (mezcla historica de
-- letras `A`/`N`/`B` escritas por el ABM nuevo y digitos `1`..`5` heredados
-- del clone desde `awssesmensajes`) a `tinyint(3) unsigned` con semantica
-- ordenable: 5 = Muy alta (se envia primero) ... 1 = Muy baja.
--
-- Traduccion:
--   A -> 4 (Alta)
--   N -> 3 (Media)
--   B -> 2 (Baja)
--   valores 1..5 ya numericos se dejan intactos (WHERE filtra por las letras)
--   valores '' o cualquier otro no reconocido se normalizan a NULL antes del
--   ALTER (sin este paso, el MODIFY COLUMN explota con SQLSTATE HY000 /
--   error 1366 "Incorrect integer value: ''" en strict mode).
--
-- No siembra filas en el catalogo `estados`: por decision explicita, las
-- etiquetas viven hardcodeadas en el front (AWS_MSG_PRIORIDAD_MAP en
-- cloud/assets/js/app.js), asi que no reproducimos el paso 6 de la migracion
-- 20260724_1300 de evolution.
--
-- Idempotente:
--   * Los UPDATE filtran por los valores viejos — al re-correr no hay filas
--     que actualizar.
--   * El ALTER chequea DATA_TYPE = 'varchar' antes de aplicar (patron
--     compatible con MySQL 8 y MariaDB 10.11 — no podemos usar
--     `MODIFY COLUMN IF EXISTS` porque no existe).

SET @db := DATABASE();

-- --- 1) prioridad: traducir letras a digitos mientras aun es varchar --------

UPDATE `aws_mensajes` SET `prioridad` = CASE `prioridad`
    WHEN 'A' THEN '4'
    WHEN 'N' THEN '3'
    WHEN 'B' THEN '2'
    ELSE `prioridad`
  END
 WHERE `prioridad` IN ('A', 'N', 'B');

-- --- 2) prioridad: normalizar valores invalidos a NULL ----------------------
-- Cubre '' (string vacio historico) y cualquier caracter suelto que no sea
-- un digito 1..5. Se ejecuta solo mientras la columna sigue siendo varchar
-- (post-ALTER un CAST a NULL seria redundante y podria disparar warnings).

SET @sql := (
  SELECT IF(
    (SELECT DATA_TYPE FROM information_schema.COLUMNS
      WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'aws_mensajes'
        AND COLUMN_NAME = 'prioridad') = 'varchar',
    'UPDATE `aws_mensajes` SET `prioridad` = NULL
       WHERE `prioridad` IS NOT NULL AND `prioridad` NOT IN (''1'',''2'',''3'',''4'',''5'')',
    'SELECT 1'
  )
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- --- 3) prioridad: convertir varchar(1) -> tinyint(3) unsigned --------------

SET @sql := (
  SELECT IF(
    (SELECT DATA_TYPE FROM information_schema.COLUMNS
      WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'aws_mensajes'
        AND COLUMN_NAME = 'prioridad') = 'varchar',
    'ALTER TABLE `aws_mensajes`
       MODIFY COLUMN `prioridad` TINYINT(3) UNSIGNED NULL DEFAULT NULL',
    'SELECT 1'
  )
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
