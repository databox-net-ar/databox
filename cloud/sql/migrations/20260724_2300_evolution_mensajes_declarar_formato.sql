-- Amplia y declara los valores validos de `evolution_mensajes.formato`.
-- Historicamente era varchar(1) con letras `T`/`I` (unicas presentes en la
-- data al 2026-07-24, aunque el sender worker tiene switches para I/V/A/U/T).
-- Alineamos con el esquema string full-word que ya adoptamos para `estado`.
--
-- Traduccion (1:1 con las cases del sender):
--   T -> texto      (5865 filas)
--   I -> imagen     (1427 filas)
--   V -> video      (0 filas, pero soportado por el sender via sendMedia)
--   A -> audio      (0 filas, soportado via sendWhatsAppAudio)
--   U -> ubicacion  (0 filas, soportado via sendLocation)
--   otros -> NULL   (defensivo, evita valores raros del clone historico)
--
-- Ademas siembra el catalogo `estados` para `evolution_mensaje_formato` con
-- los 5 valores validos, para que el editor de estados los muestre.
--
-- Idempotente:
--   * ALTER chequea CHARACTER_MAXIMUM_LENGTH antes de aplicar (patron
--     compatible con MySQL 8 y MariaDB 10.11).
--   * Los UPDATE filtran por los valores viejos o por "no esta en la
--     whitelist final", al re-correr no hay filas afectadas.
--   * Los INSERT usan `WHERE NOT EXISTS`.

SET @db := DATABASE();

-- --- 1) formato: ampliar varchar(1) -> varchar(20) --------------------------

SET @sql := (
  SELECT IF(
    (SELECT CHARACTER_MAXIMUM_LENGTH FROM information_schema.COLUMNS
      WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'evolution_mensajes'
        AND COLUMN_NAME = 'formato') < 20,
    'ALTER TABLE `evolution_mensajes`
       MODIFY COLUMN `formato` VARCHAR(20) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NULL DEFAULT NULL',
    'SELECT 1'
  )
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- --- 2) formato: traducir letras legacy a strings ---------------------------

UPDATE `evolution_mensajes` SET `formato` = CASE `formato`
    WHEN 'T' THEN 'texto'
    WHEN 'I' THEN 'imagen'
    WHEN 'V' THEN 'video'
    WHEN 'A' THEN 'audio'
    WHEN 'U' THEN 'ubicacion'
    ELSE `formato`
  END
 WHERE `formato` IN ('T', 'I', 'V', 'A', 'U');

-- --- 3) formato: normalizar cualquier otro valor a NULL ---------------------

UPDATE `evolution_mensajes`
   SET `formato` = NULL
 WHERE `formato` IS NOT NULL
   AND `formato` NOT IN ('texto', 'imagen', 'video', 'audio', 'ubicacion');

-- --- 4) Seed catalogo `estados` para evolution_mensaje_formato --------------

INSERT INTO `estados` (`campo`, `texto`, `valor`, `orden`)
SELECT 'evolution_mensaje_formato', 'Texto', 'texto', 1
 WHERE NOT EXISTS (SELECT 1 FROM `estados`
   WHERE `campo` = 'evolution_mensaje_formato' AND `valor` = 'texto');

INSERT INTO `estados` (`campo`, `texto`, `valor`, `orden`)
SELECT 'evolution_mensaje_formato', 'Imagen', 'imagen', 2
 WHERE NOT EXISTS (SELECT 1 FROM `estados`
   WHERE `campo` = 'evolution_mensaje_formato' AND `valor` = 'imagen');

INSERT INTO `estados` (`campo`, `texto`, `valor`, `orden`)
SELECT 'evolution_mensaje_formato', 'Video', 'video', 3
 WHERE NOT EXISTS (SELECT 1 FROM `estados`
   WHERE `campo` = 'evolution_mensaje_formato' AND `valor` = 'video');

INSERT INTO `estados` (`campo`, `texto`, `valor`, `orden`)
SELECT 'evolution_mensaje_formato', 'Audio', 'audio', 4
 WHERE NOT EXISTS (SELECT 1 FROM `estados`
   WHERE `campo` = 'evolution_mensaje_formato' AND `valor` = 'audio');

INSERT INTO `estados` (`campo`, `texto`, `valor`, `orden`)
SELECT 'evolution_mensaje_formato', 'Ubicacion', 'ubicacion', 5
 WHERE NOT EXISTS (SELECT 1 FROM `estados`
   WHERE `campo` = 'evolution_mensaje_formato' AND `valor` = 'ubicacion');
