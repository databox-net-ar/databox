-- datarocket_interacciones: parte `tipo` en dos ejes ortogonales.
--
-- Hoy `tipo` mezcla dos cosas distintas en un solo slug: hacia donde fue la
-- comunicacion y por que medio ("correo_enviado" = saliente + correo). Eso
-- hace imposible filtrar "todo lo entrante" sin un LIKE sobre el sufijo, y
-- obliga a inventar un slug nuevo por cada combinacion de medio y direccion.
--
-- Cambios:
--   * + `sentido` varchar(10) -> `entrante` | `saliente` | `interna`
--   * + `canal`   varchar(20) -> `correo` | `whatsapp` | `telegram` | `sms` |
--                                `web` | `telefono` | `presencial`, o NULL
--                                cuando no hubo comunicacion (las notas) o no
--                                se sabe por donde entro.
--
-- `tipo` NO se dropea aca: eso va en la 20260817_1100, despues de que el codigo
-- deje de leerlo. Misma separacion aditivo / destructivo que ya usaron
-- 20260816_1400 (backfill) + 20260816_1500 (drop) en datarocket_prospectos.
--
-- ---------------------------------------------------------------------------
-- BACKFILL
-- ---------------------------------------------------------------------------
--   correo_enviado     -> saliente / correo
--   whatsapp_enviado   -> saliente / whatsapp
--   telegram_enviado   -> saliente / telegram
--   sms_enviado        -> saliente / sms
--   consulta_recibida  -> entrante / web
--   nota               -> interna  / NULL
--
-- Las `consulta_recibida` van a `web` por decision explicita: son las consultas
-- que entraron por el formulario del sitio y que la 20260816_1100 migro desde
-- `datarocket_prospectos.comentarios`.
--
-- Las `nota` no son una comunicacion sino una anotacion interna del vendedor:
-- sentido `interna` y canal NULL. Esa combinacion es la que las identifica.
--
-- Repartija al momento de escribir esto:
--            prod   dev
--   consulta_recibida  651   643   -> entrante / web
--   nota               220   220   -> interna  / NULL
--   whatsapp_enviado    10     0   -> saliente / whatsapp
--   correo_enviado       1     0   -> saliente / correo
--
-- `correo_abierto` y `link_clickeado` figuran en el catalogo del front pero
-- nunca se escribieron (0 filas en los dos entornos) y no hay de donde
-- importarlos: `aws_mensajes` no registra aperturas ni clicks. No son un
-- sentido ni un canal — son el estado de un envio — y van a vivir en un campo
-- aparte cuando existan. Por eso no aparecen en el mapeo.
--
-- ---------------------------------------------------------------------------
-- CANAL NO ES ORIGEN
-- ---------------------------------------------------------------------------
-- `origen` es procedencia tecnica: que tabla o proceso escribio la fila
-- (`aws_mensajes`, `evolution_mensajes`, `datarocket_prospectos.comentarios`).
-- `canal` es de negocio: por que medio se comunico con la persona. Coinciden en
-- las altas automaticas y divergen apenas se cargue una llamada a mano, donde
-- `origen` va a ser el ABM y `canal` `telefono`. Se quedan los dos.
--
-- ---------------------------------------------------------------------------
-- Notas
-- ---------------------------------------------------------------------------
--  - Sin CREATE FUNCTION: prod es MariaDB en RDS sin SUPER y las rechaza con el
--    error 1419 (ver 20260816_1700). Aca no hacen falta: es un CASE.
--  - Idempotente: cada paso se guarda con information_schema, y el backfill
--    solo alcanza a las filas que todavia no tienen `sentido`.
--  - Los valores nuevos se declaran sin CHECK constraint, igual que el resto
--    del schema; la validacion vive en la API contra el catalogo `estados`.

-- ---------------------------------------------------------------------------
-- 1. Columnas
-- ---------------------------------------------------------------------------
-- MySQL 8 (dev) no soporta `ADD COLUMN IF NOT EXISTS`, que si existe en
-- MariaDB (prod). Se usa el patron portable information_schema + PREPARE.

SET @sql = IF(
  (SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'datarocket_interacciones'
      AND COLUMN_NAME = 'sentido') = 0,
  'ALTER TABLE `datarocket_interacciones`
     ADD COLUMN `sentido` varchar(10) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci
     NULL DEFAULT NULL AFTER `prospecto_id`',
  'DO 0');
PREPARE st FROM @sql; EXECUTE st; DEALLOCATE PREPARE st;

SET @sql = IF(
  (SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'datarocket_interacciones'
      AND COLUMN_NAME = 'canal') = 0,
  'ALTER TABLE `datarocket_interacciones`
     ADD COLUMN `canal` varchar(20) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci
     NULL DEFAULT NULL AFTER `sentido`',
  'DO 0');
PREPARE st FROM @sql; EXECUTE st; DEALLOCATE PREPARE st;

-- ---------------------------------------------------------------------------
-- 2. Backfill desde `tipo`
-- ---------------------------------------------------------------------------
-- Solo las filas sin `sentido`, para que reaplicar no pise nada cargado a mano.

UPDATE `datarocket_interacciones`
   SET `sentido` = CASE
         WHEN `tipo` IN ('correo_enviado', 'whatsapp_enviado',
                         'telegram_enviado', 'sms_enviado') THEN 'saliente'
         WHEN `tipo` = 'consulta_recibida'                  THEN 'entrante'
         WHEN `tipo` = 'nota'                               THEN 'interna'
         -- Cualquier slug que aparezca despues de escribir esto: si termina en
         -- _enviado es saliente, si termina en _recibida/_recibido es entrante.
         WHEN `tipo` LIKE '%\_enviado'                      THEN 'saliente'
         WHEN `tipo` LIKE '%\_recibid_'                     THEN 'entrante'
         ELSE 'saliente'
       END,
       `canal` = CASE
         WHEN `tipo` LIKE 'correo\_%'    THEN 'correo'
         WHEN `tipo` LIKE 'whatsapp\_%'  THEN 'whatsapp'
         WHEN `tipo` LIKE 'telegram\_%'  THEN 'telegram'
         WHEN `tipo` LIKE 'sms\_%'       THEN 'sms'
         WHEN `tipo` = 'consulta_recibida' THEN 'web'
         ELSE NULL   -- `nota` y cualquier tipo sin medio identificable
       END
 WHERE `sentido` IS NULL;

-- ---------------------------------------------------------------------------
-- 3. Indices
-- ---------------------------------------------------------------------------
-- El ABM filtra por los dos campos por separado; el indice compuesto cubre
-- ademas el caso "todo lo entrante de tal canal".

SET @sql = IF(
  (SELECT COUNT(*) FROM information_schema.STATISTICS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'datarocket_interacciones'
      AND INDEX_NAME = 'idx_dri_sentido_canal') = 0,
  'ALTER TABLE `datarocket_interacciones`
     ADD INDEX `idx_dri_sentido_canal` (`sentido`, `canal`)',
  'DO 0');
PREPARE st FROM @sql; EXECUTE st; DEALLOCATE PREPARE st;

SET @sql = IF(
  (SELECT COUNT(*) FROM information_schema.STATISTICS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'datarocket_interacciones'
      AND INDEX_NAME = 'idx_dri_canal') = 0,
  'ALTER TABLE `datarocket_interacciones` ADD INDEX `idx_dri_canal` (`canal`)',
  'DO 0');
PREPARE st FROM @sql; EXECUTE st; DEALLOCATE PREPARE st;

-- ---------------------------------------------------------------------------
-- 4. Catalogo en `estados`
-- ---------------------------------------------------------------------------
-- Convencion del proyecto: snake_case y modelo en singular
-- (`datarocket_interaccion_sentido`, no `datarocket_interacciones.sentido`).
-- La tabla `estados` no tiene UNIQUE, asi que el INSERT se guarda con un
-- NOT EXISTS para que reaplicar la migracion no duplique filas.

INSERT INTO `estados` (`campo`, `valor`, `texto`, `orden`)
SELECT * FROM (
  SELECT 'datarocket_interaccion_sentido' campo, 'entrante' valor, 'Entrante' texto, 1 orden
  UNION ALL SELECT 'datarocket_interaccion_sentido', 'saliente',   'Saliente',   2
  UNION ALL SELECT 'datarocket_interaccion_sentido', 'interna',    'Interna',    3
  UNION ALL SELECT 'datarocket_interaccion_canal',   'correo',     'Correo',     1
  UNION ALL SELECT 'datarocket_interaccion_canal',   'whatsapp',   'WhatsApp',   2
  UNION ALL SELECT 'datarocket_interaccion_canal',   'telegram',   'Telegram',   3
  UNION ALL SELECT 'datarocket_interaccion_canal',   'sms',        'SMS',        4
  UNION ALL SELECT 'datarocket_interaccion_canal',   'web',        'Web',        5
  UNION ALL SELECT 'datarocket_interaccion_canal',   'telefono',   'Teléfono',   6
  UNION ALL SELECT 'datarocket_interaccion_canal',   'presencial', 'Presencial', 7
) nuevos
WHERE NOT EXISTS (
  SELECT 1 FROM `estados` e
   WHERE e.`campo` = nuevos.`campo` AND e.`valor` = nuevos.`valor`
);
