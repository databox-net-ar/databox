-- datarocket_interacciones: dropea `usuario_id`, `mensaje_id` y `origen`.
--
-- La tabla queda reducida a lo que es el timeline propiamente dicho: cuando
-- (`fecha`), sobre quien (`contacto_id` / `prospecto_id`), en que direccion y
-- por que medio (`sentido` / `canal`, de la 20260817_1000) y que se dijo
-- (`asunto` / `mensaje`).
--
-- Que se pierde, para que quede escrito:
--
--   * `mensaje_id` + `origen` eran una asociacion polimorfica al mensaje que
--     origino la interaccion: `origen` decia en que tabla buscar
--     ('aws_mensajes', 'evolution_mensajes', 'telegram_mensajes') y
--     `mensaje_id` el id de la fila. Con eso el modal de Consultar ofrecia un
--     link "Mensaje encolado" que abria el mensaje real. Ese salto deja de
--     existir: la interaccion ya guarda `asunto` y `mensaje` completos, asi
--     que el contenido no se pierde, pero no se puede volver a la fila de la
--     cola de envio (estado del despacho, error, adjuntos, demora).
--   * `origen` marcaba ademas la procedencia de las filas historicas
--     ('datarocket_prospectos.comentarios', 871 de las 882 de prod). Despues
--     de esta migracion no queda registro de que ese lote vino del backfill de
--     la 20260816_1100.
--   * `usuario_id` iba a ser el autor de las cargas manuales. Nunca se
--     escribio: 0 filas con valor en los dos entornos. No se pierde ningun
--     dato cargado.
--
-- Aplicar DESPUES del deploy del codigo que dejo de leer las tres columnas
-- (helper de alta, ABM y front). Con el codigo viejo arriba, el INSERT del
-- helper nombra `origen` y `mensaje_id` y falla.
--
-- El indice `idx_dri_usuario` se va con `usuario_id`. `mensaje_id` y `origen`
-- no tenian indice propio.
--
-- Idempotente: se guarda con information_schema (MySQL 8 no soporta
-- `DROP COLUMN IF EXISTS`, que si existe en MariaDB).

SET @sql = IF(
  (SELECT COUNT(*) FROM information_schema.STATISTICS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'datarocket_interacciones'
      AND INDEX_NAME = 'idx_dri_usuario') > 0,
  'ALTER TABLE `datarocket_interacciones` DROP INDEX `idx_dri_usuario`',
  'DO 0');
PREPARE st FROM @sql; EXECUTE st; DEALLOCATE PREPARE st;

SET @sql = IF(
  (SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'datarocket_interacciones'
      AND COLUMN_NAME = 'usuario_id') > 0,
  'ALTER TABLE `datarocket_interacciones` DROP COLUMN `usuario_id`',
  'DO 0');
PREPARE st FROM @sql; EXECUTE st; DEALLOCATE PREPARE st;

SET @sql = IF(
  (SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'datarocket_interacciones'
      AND COLUMN_NAME = 'mensaje_id') > 0,
  'ALTER TABLE `datarocket_interacciones` DROP COLUMN `mensaje_id`',
  'DO 0');
PREPARE st FROM @sql; EXECUTE st; DEALLOCATE PREPARE st;

SET @sql = IF(
  (SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'datarocket_interacciones'
      AND COLUMN_NAME = 'origen') > 0,
  'ALTER TABLE `datarocket_interacciones` DROP COLUMN `origen`',
  'DO 0');
PREPARE st FROM @sql; EXECUTE st; DEALLOCATE PREPARE st;
