-- datarocket_interacciones: agrega `respondida` para saber que consultas
-- entrantes siguen esperando respuesta y cuanto tardo la que ya se contesto.
--
-- Hoy no hay forma de distinguir una consulta entrante pendiente de una ya
-- atendida: la tabla registra que entro y que se dijo, pero no si alguien
-- contesto.
--
-- Cambio:
--   * + `respondida` datetime NULL -> momento en que se dio por contestada la
--     interaccion. NULL = todavia pendiente.
--
-- Con eso:
--   pendientes         -> sentido = 'entrante' AND respondida IS NULL
--   tiempo de respuesta -> TIMESTAMPDIFF(MINUTE, fecha, respondida)
--
-- ---------------------------------------------------------------------------
-- POR QUE SE GUARDA Y NO SE DERIVA
-- ---------------------------------------------------------------------------
-- La alternativa era no agregar columna y deducirlo: una entrante esta
-- pendiente si no existe una saliente posterior al mismo contacto. En este
-- panel eso da mal. Es la herramienta de correo y WhatsApp MASIVO: un envio de
-- campana a miles de contactos generaria una saliente para cada uno y marcaria
-- como respondida toda consulta pendiente de esa gente, sin que nadie haya
-- contestado nada. La marca explicita es la unica que no miente.
--
-- ---------------------------------------------------------------------------
-- SOLO APLICA A LAS ENTRANTES
-- ---------------------------------------------------------------------------
-- Marcar como "respondida" una saliente o una nota interna no significa nada.
-- No se pone CHECK constraint (el schema no usa ninguna); lo valida la API:
-- el PUT rechaza con 400 si la interaccion no es `sentido = 'entrante'`.
--
-- El backfill deja TODO en NULL a proposito. Las 651 consultas entrantes
-- historicas de prod vienen del backfill de `datarocket_prospectos.comentarios`
-- (migracion 20260816_1100) y no hay dato de si fueron contestadas ni cuando;
-- inventar una fecha de respuesta arruinaria la metrica desde el dia uno.
-- Arrancan todas como pendientes y se van marcando a mano.
--
-- ---------------------------------------------------------------------------
-- PERMISO
-- ---------------------------------------------------------------------------
-- Marcar/desmarcar es un PUT sobre el ABM, y `requirePermCrud` mapea PUT a
-- `.editar`. Ese permiso no existia porque el ABM era de solo lectura + baja
-- (`datarocket.interacciones.consultar` y `.eliminar`), asi que se crea aca.
--
-- Idempotente en los 3 pasos.

-- ---------------------------------------------------------------------------
-- Paso 1: columna
-- ---------------------------------------------------------------------------
-- MySQL 8 (dev) no soporta `ADD COLUMN IF NOT EXISTS`, que si existe en
-- MariaDB (prod). Patron portable information_schema + PREPARE.

SET @sql = IF(
  (SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'datarocket_interacciones'
      AND COLUMN_NAME = 'respondida') = 0,
  'ALTER TABLE `datarocket_interacciones`
     ADD COLUMN `respondida` datetime NULL DEFAULT NULL AFTER `canal`',
  'DO 0');
PREPARE st FROM @sql; EXECUTE st; DEALLOCATE PREPARE st;

-- Indice compuesto: la consulta que importa es "entrantes sin responder",
-- que filtra por los dos campos juntos.
SET @sql = IF(
  (SELECT COUNT(*) FROM information_schema.STATISTICS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'datarocket_interacciones'
      AND INDEX_NAME = 'idx_dri_sentido_respondida') = 0,
  'ALTER TABLE `datarocket_interacciones`
     ADD INDEX `idx_dri_sentido_respondida` (`sentido`, `respondida`)',
  'DO 0');
PREPARE st FROM @sql; EXECUTE st; DEALLOCATE PREPARE st;

-- ---------------------------------------------------------------------------
-- Paso 2: permiso de edicion del ABM
-- ---------------------------------------------------------------------------

INSERT INTO `permisos` (`slug`, `nombre`)
SELECT * FROM (SELECT
  'datarocket.interacciones.editar' AS slug,
  'Datarocket > Interacciones > Editar (marcar respondida)' AS nombre
) t
WHERE NOT EXISTS (
  SELECT 1 FROM `permisos` p WHERE p.slug = t.slug
);

-- ---------------------------------------------------------------------------
-- Paso 3: `desarrollador` = todos los permisos cloud del env actual
-- ---------------------------------------------------------------------------
-- Mismo cierre que el resto de las migraciones de permisos.

SET SESSION group_concat_max_len = 65535;

UPDATE `roles` r
CROSS JOIN (
    SELECT GROUP_CONCAT(id ORDER BY id) AS ids
    FROM `permisos`
    WHERE slug IS NOT NULL AND slug <> ''
) p
SET r.permisos = p.ids
WHERE r.slug = 'desarrollador';
