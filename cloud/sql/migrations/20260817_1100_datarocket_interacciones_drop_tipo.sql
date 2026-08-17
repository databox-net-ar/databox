-- datarocket_interacciones: dropea `tipo`, ya reemplazado por `sentido` + `canal`.
--
-- Segunda mitad de la 20260817_1000, que agrego las dos columnas nuevas y
-- backfilleo desde `tipo`. Va aparte y se aplica DESPUES del deploy del codigo
-- que dejo de leer la columna: misma separacion aditivo / destructivo que ya
-- usaron 20260816_1400 + 20260816_1500 en datarocket_prospectos.
--
-- Aplicar esta migracion con el codigo viejo todavia arriba rompe el ABM de
-- interacciones (el SELECT nombra `i.tipo`) y el helper de alta
-- (cloud/api/lib/datarocket_interacciones.php, que insertaba la columna). El
-- orden correcto es: deploy primero, migraciones inmediatamente despues.
--
-- Por que se dropea y no se deja: `tipo` es NOT NULL DEFAULT 'correo_enviado'.
-- Una columna que nadie mantiene pero que tiene default silencioso es peor que
-- no tenerla — toda fila nueva diria "correo_enviado" aunque sea un WhatsApp
-- entrante, y en tres meses nadie se acuerda de cual de los dos campos manda.
--
-- El indice `idx_dri_tipo` se va con la columna; lo reemplazan
-- `idx_dri_sentido_canal` y `idx_dri_canal`, creados en la 20260817_1000.
--
-- Idempotente: se guarda con information_schema (MySQL 8 no soporta
-- `DROP COLUMN IF EXISTS`, que si existe en MariaDB).
--
-- Antes de aplicar conviene confirmar que el backfill quedo completo — no debe
-- devolver ninguna fila:
--
--   SELECT id, tipo, sentido, canal FROM datarocket_interacciones
--    WHERE sentido IS NULL;

SET @sql = IF(
  (SELECT COUNT(*) FROM information_schema.STATISTICS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'datarocket_interacciones'
      AND INDEX_NAME = 'idx_dri_tipo') > 0,
  'ALTER TABLE `datarocket_interacciones` DROP INDEX `idx_dri_tipo`',
  'DO 0');
PREPARE st FROM @sql; EXECUTE st; DEALLOCATE PREPARE st;

SET @sql = IF(
  (SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'datarocket_interacciones'
      AND COLUMN_NAME = 'tipo') > 0,
  'ALTER TABLE `datarocket_interacciones` DROP COLUMN `tipo`',
  'DO 0');
PREPARE st FROM @sql; EXECUTE st; DEALLOCATE PREPARE st;
