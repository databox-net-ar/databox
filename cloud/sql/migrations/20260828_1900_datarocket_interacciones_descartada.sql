-- datarocket_interacciones: agrega `descartada` para el tercer estado de una
-- consulta entrante.
--
-- POR QUE
-- -------
-- Hasta ahora una entrante solo podia estar pendiente (`respondida IS NULL`) o
-- respondida. Falta el caso real: la que NO hay que contestar — spam, un
-- formulario enviado en blanco, una prueba, un mensaje que no trae ninguna
-- pregunta. Con dos estados, esas quedaban pendientes para siempre: ensuciaban
-- la tarjeta "Sin responder" del landing y, desde que existe el aviso por
-- WhatsApp, le generaban al vendedor un recordatorio por hora, para siempre,
-- por algo que nunca va a contestar.
--
-- Marcarlas como "respondida" era el workaround disponible y es peor que el
-- problema: miente sobre el trabajo hecho y contamina `respuesta_promedio` con
-- demoras de consultas que nadie contesto.
--
-- Cambio:
--   * + `descartada` datetime NULL -> momento en que se descarto. NULL = no
--     descartada.
--
-- Los tres estados quedan asi:
--   pendiente  -> sentido = 'entrante' AND respondida IS NULL AND descartada IS NULL
--   respondida -> respondida IS NOT NULL
--   descartada -> descartada IS NOT NULL
--
-- ---------------------------------------------------------------------------
-- POR QUE UNA COLUMNA MAS Y NO UNA COLUMNA `estado`
-- ---------------------------------------------------------------------------
-- Un `estado` varchar seria mas prolijo en abstracto, pero obliga a migrar y
-- reescribir todo lo que hoy lee `respondida` (el ABM, las stats, los
-- indicadores del landing, el job de avisos, la ficha publica) y a perder la
-- FECHA de respuesta, que es la que alimenta `respuesta_minutos` y el promedio
-- de demora. La columna paralela mantiene esa metrica intacta y es simetrica
-- con `respondida`: las dos son un sello de tiempo NULL-able.
--
-- Son mutuamente excluyentes por regla de la API, no por constraint (el schema
-- no usa CHECK): el PUT de descartar rechaza una interaccion ya respondida y
-- viceversa.
--
-- ---------------------------------------------------------------------------
-- SOLO APLICA A LAS ENTRANTES
-- ---------------------------------------------------------------------------
-- Igual que `respondida` (migracion 20260817_1300): descartar una saliente o
-- una nota interna no significa nada. Lo valida la API con un 400.
--
-- El backfill deja todo en NULL: no hay forma de saber cuales de las historicas
-- eran spam, y adivinarlo borraria trabajo real de la vista de alguien.
--
-- No hace falta permiso nuevo: descartar es un PUT sobre el ABM y
-- `requirePermCrud` lo mapea a `datarocket.interacciones.editar`, que ya existe
-- desde la 20260817_1300.
--
-- Idempotente en los dos pasos.

-- ---------------------------------------------------------------------------
-- Paso 1: columna
-- ---------------------------------------------------------------------------
-- MySQL 8 (dev) no soporta `ADD COLUMN IF NOT EXISTS`, que si existe en
-- MariaDB (prod). Patron portable information_schema + PREPARE.

SET @sql = IF(
  (SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'datarocket_interacciones'
      AND COLUMN_NAME = 'descartada') = 0,
  'ALTER TABLE `datarocket_interacciones`
     ADD COLUMN `descartada` datetime NULL DEFAULT NULL AFTER `respondida`',
  'DO 0');
PREPARE st FROM @sql; EXECUTE st; DEALLOCATE PREPARE st;

-- ---------------------------------------------------------------------------
-- Paso 2: indice
-- ---------------------------------------------------------------------------
-- La consulta caliente es "entrantes pendientes", que ahora filtra por los tres
-- campos: sentido + respondida + descartada. Se agrega el compuesto en ese
-- orden — el `idx_dri_sentido_respondida` existente queda como prefijo suyo,
-- pero no se dropea: lo usan las consultas que solo miran respondida (el
-- promedio de demora, el filtro `?respondida=1`).
SET @sql = IF(
  (SELECT COUNT(*) FROM information_schema.STATISTICS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'datarocket_interacciones'
      AND INDEX_NAME = 'idx_dri_sentido_respondida_descartada') = 0,
  'ALTER TABLE `datarocket_interacciones`
     ADD INDEX `idx_dri_sentido_respondida_descartada` (`sentido`, `respondida`, `descartada`)',
  'DO 0');
PREPARE st FROM @sql; EXECUTE st; DEALLOCATE PREPARE st;
