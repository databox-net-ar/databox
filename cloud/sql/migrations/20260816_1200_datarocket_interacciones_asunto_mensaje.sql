-- datarocket_interacciones: `descripcion` -> `asunto` y `cuerpo` -> `mensaje`,
-- y remapeo del backfill para que ambos campos salgan 1:1 de las columnas del
-- prospecto:
--
--   datarocket_prospectos.asunto      -> datarocket_interacciones.asunto
--   datarocket_prospectos.comentarios -> datarocket_interacciones.mensaje
--
-- Corrige la decision que habia tomado la migracion 20260816_1100: ahi
-- `descripcion` se llenaba con COALESCE(asunto, LEFT(comentarios, 200)) para
-- que el listado del ABM nunca quedara vacio. El efecto colateral es que ~784
-- de las 863 filas migradas tienen en `descripcion` un recorte del mensaje en
-- vez del asunto real, o sea el mismo texto duplicado en dos columnas. Con el
-- mapeo 1:1 esas filas quedan con `asunto` NULL (el prospecto no tenia asunto:
-- solo 79 de 1336 lo tienen en dev) y el texto vive una sola vez, en `mensaje`.
--
-- Nota sobre el nombre `mensaje`: convive con `mensaje_id`, que es otra cosa
-- (la FK polimorfica a la fila de `aws_mensajes` / `evolution_mensajes` /
-- `telegram_mensajes` que origino la interaccion). `mensaje` es texto plano y
-- `mensaje_id` un puntero a otra tabla; no hay relacion entre los dos.
--
-- Se usa CHANGE COLUMN y no RENAME COLUMN: RENAME requiere MariaDB >= 10.5.2
-- (mismo criterio que 20260815_1000_datarocket_contactos_fks_geo.sql).
--
-- Idempotente: los renames se guardan con information_schema y los UPDATE de
-- remapeo son naturalmente idempotentes (asignan siempre el mismo valor).
-- Compatible MySQL 8 (dev) + MariaDB 10.11 (prod).


-- ---------------------------------------------------------------------------
-- 1) `descripcion` -> `asunto`.
-- ---------------------------------------------------------------------------

SET @viejo := (
  SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME   = 'datarocket_interacciones'
    AND COLUMN_NAME  = 'descripcion'
);
SET @nuevo := (
  SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME   = 'datarocket_interacciones'
    AND COLUMN_NAME  = 'asunto'
);
SET @sql := IF(@viejo = 1 AND @nuevo = 0,
  'ALTER TABLE datarocket_interacciones
     CHANGE COLUMN `descripcion` `asunto` VARCHAR(500)
     CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NULL DEFAULT NULL',
  'DO 0'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;


-- ---------------------------------------------------------------------------
-- 2) `cuerpo` -> `mensaje`.
-- ---------------------------------------------------------------------------

SET @viejo := (
  SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME   = 'datarocket_interacciones'
    AND COLUMN_NAME  = 'cuerpo'
);
SET @nuevo := (
  SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME   = 'datarocket_interacciones'
    AND COLUMN_NAME  = 'mensaje'
);
SET @sql := IF(@viejo = 1 AND @nuevo = 0,
  'ALTER TABLE datarocket_interacciones
     CHANGE COLUMN `cuerpo` `mensaje` MEDIUMTEXT
     CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NULL',
  'DO 0'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;


-- ---------------------------------------------------------------------------
-- 3) Remapeo 1:1 de las filas del backfill historico. Alcanza solo a las que
--    llevan marcador de procedencia; las interacciones automaticas de los
--    canalizadores no se tocan.
--
--    Se reasigna tambien `mensaje` (y no solo `asunto`) para que la migracion
--    sea auto-reparadora: deja el par asunto/mensaje consistente con el
--    prospecto sin importar con que valores lo dejo la 20260816_1100.
-- ---------------------------------------------------------------------------

UPDATE datarocket_interacciones i
  JOIN datarocket_prospectos p ON p.id = i.prospecto_id
   SET i.asunto = NULLIF(TRIM(p.asunto), '')
 WHERE i.origen IN ('datarocket_prospectos.comentarios',
                    'datarocket_prospectos.acciones');

UPDATE datarocket_interacciones i
  JOIN datarocket_prospectos p ON p.id = i.prospecto_id
   SET i.mensaje = p.comentarios
 WHERE i.origen = 'datarocket_prospectos.comentarios';

UPDATE datarocket_interacciones i
  JOIN datarocket_prospectos p ON p.id = i.prospecto_id
   SET i.mensaje = p.acciones
 WHERE i.origen = 'datarocket_prospectos.acciones';
