-- datarocket_contactos: dropea `verificacion`, `estado`, `error`, `completado`
-- y `suscripciones`.
--
-- Las cinco columnas venian del motor de envios legacy, donde la ficha del
-- contacto llevaba adosado el resultado del ultimo procesamiento. En el CRM
-- Datarocket actual ese estado no vive mas en el contacto: la actividad esta
-- en `datarocket_interacciones`, la suscripcion a listas en la puente
-- `datarocket_contactos_listas` (20260811_1400) y el avance comercial en
-- `datarocket_prospectos`. El contacto queda siendo solo la IDENTIDAD de la
-- persona o empresa.
--
-- Que se pierde, para que quede escrito (conteos de desarrollo, 43.239 filas):
--
--   * `verificacion` varchar(1): resultado de la validacion previa al envio
--     ('1' 36.915 filas, '2' 2.809, '0' 407, vacio 2.119, NULL 989). El ABM lo
--     pintaba con un badge OK/Valido/Error/Fallado/Pendiente. No hay proceso
--     vivo que lo escriba.
--   * `estado` varchar(1): estado del contacto en el motor viejo ('1' 34.766,
--     '2' 7.485, vacio 988). Nunca se mapeo al catalogo `estados` ni tuvo
--     semantica documentada de este lado.
--   * `error` varchar(255): mensaje del ultimo fallo de ingreso/validacion.
--     0 filas con valor en desarrollo — no se pierde ningun dato cargado.
--   * `completado` datetime: fecha en que el contacto termino de completar sus
--     datos en el formulario del motor viejo (42.251 filas con valor).
--     `registrado` se queda y sigue siendo la fecha de alta.
--   * `suscripciones` int: contador denormalizado de listas suscriptas
--     (37.185 filas con valor distinto de cero). Redundante desde que la
--     relacion real vive en `datarocket_contactos_listas`; el conteo se saca
--     de ahi.
--
-- Aplicar DESPUES del deploy del codigo que dejo de leer las cinco columnas
-- (ABM cloud, endpoint v4 y front). Con el codigo viejo arriba, el INSERT y el
-- UPDATE de ambos endpoints nombran las columnas y fallan.
--
-- Ninguna de las cinco tenia indice propio ni FK, asi que no hay nada mas que
-- limpiar.
--
-- Idempotente: se guarda con information_schema (MySQL 8 no soporta
-- `DROP COLUMN IF EXISTS`, que si existe en MariaDB).

SET @sql = IF(
  (SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'datarocket_contactos'
      AND COLUMN_NAME = 'verificacion') > 0,
  'ALTER TABLE `datarocket_contactos` DROP COLUMN `verificacion`',
  'DO 0');
PREPARE st FROM @sql; EXECUTE st; DEALLOCATE PREPARE st;

SET @sql = IF(
  (SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'datarocket_contactos'
      AND COLUMN_NAME = 'estado') > 0,
  'ALTER TABLE `datarocket_contactos` DROP COLUMN `estado`',
  'DO 0');
PREPARE st FROM @sql; EXECUTE st; DEALLOCATE PREPARE st;

SET @sql = IF(
  (SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'datarocket_contactos'
      AND COLUMN_NAME = 'error') > 0,
  'ALTER TABLE `datarocket_contactos` DROP COLUMN `error`',
  'DO 0');
PREPARE st FROM @sql; EXECUTE st; DEALLOCATE PREPARE st;

SET @sql = IF(
  (SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'datarocket_contactos'
      AND COLUMN_NAME = 'completado') > 0,
  'ALTER TABLE `datarocket_contactos` DROP COLUMN `completado`',
  'DO 0');
PREPARE st FROM @sql; EXECUTE st; DEALLOCATE PREPARE st;

SET @sql = IF(
  (SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'datarocket_contactos'
      AND COLUMN_NAME = 'suscripciones') > 0,
  'ALTER TABLE `datarocket_contactos` DROP COLUMN `suscripciones`',
  'DO 0');
PREPARE st FROM @sql; EXECUTE st; DEALLOCATE PREPARE st;
