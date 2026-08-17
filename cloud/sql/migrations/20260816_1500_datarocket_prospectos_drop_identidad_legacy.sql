-- datarocket_prospectos: DROP de las 12 columnas de identidad duplicada. Cierra
-- el refactor "prospecto = referencia a contacto" que arrancaron la 20260812_1000
-- (ADD contacto_id) y la 20260812_1100 (backfill). Es la "fase 3" que anuncian
-- los comentarios de cloud/api/datarocket_prospectos.php.
--
-- Columnas que se van:
--   organizacion, nombre, contacto, celular, correo, web,
--   domicilio, ciudad, localidad, provincia, pais, ubicacion
--
-- La fuente de verdad de todos esos datos es `datarocket_contactos`, alcanzable
-- por la FK `fk_dr_prospectos_contacto`. El endpoint ya los expone al frontend
-- como `contacto_*` derivados del JOIN (drProEnrichRows), y el INSERT/UPDATE ya
-- no los escribia.
--
-- ORDEN DE DESPLIEGUE: esta migracion se aplica DESPUES de publicar el codigo
-- que deja de leerlas (DR_PRO_COLS sin las 12, buscador con JOIN a contactos).
-- Al reves, el endpoint viejo tira 500 en cada listado hasta el deploy.
--
-- REQUISITO: la 20260816_1400 (backfill de huecos) tiene que haber corrido
-- antes, o se pierden los datos que todavia solo viven aca.
--
-- Idempotente: cada DROP se guarda con information_schema.
-- Compatible MySQL 8 (dev) + MariaDB 10.11 (prod).


-- ---------------------------------------------------------------------------
-- 0) GUARD: si hay prospectos sin contacto vinculado, su identidad vive SOLO en
--    estas columnas y el DROP la borraria sin retorno. En dev son 0. Si en prod
--    hay alguno, la migracion aborta con "Unknown column
--    'ABORTADO_hay_prospectos_con_contacto_id_NULL...'" — el mensaje de error ES
--    la explicacion. Para destrabarla hay que vincular esos prospectos a un
--    contacto (o borrarlos) y recien ahi volver a correrla.
--
--    Se usa una columna inexistente y no SIGNAL porque SIGNAL no pasa por el
--    protocolo de prepared statements ("This command is not supported in the
--    prepared statement protocol yet") y todo el archivo se ejecuta asi.
-- ---------------------------------------------------------------------------

SET @orfanos := (SELECT COUNT(*) FROM datarocket_prospectos WHERE contacto_id IS NULL);
SET @sql := IF(@orfanos = 0,
  'DO 0',
  'SELECT `ABORTADO_hay_prospectos_con_contacto_id_NULL_vinculalos_antes_de_dropear`'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;


-- ---------------------------------------------------------------------------
-- 1) organizacion
-- ---------------------------------------------------------------------------
SET @existe := (SELECT COUNT(*) FROM information_schema.COLUMNS
                 WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'datarocket_prospectos'
                   AND COLUMN_NAME = 'organizacion');
SET @sql := IF(@existe = 1, 'ALTER TABLE datarocket_prospectos DROP COLUMN `organizacion`', 'DO 0');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- ---------------------------------------------------------------------------
-- 2) nombre
-- ---------------------------------------------------------------------------
SET @existe := (SELECT COUNT(*) FROM information_schema.COLUMNS
                 WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'datarocket_prospectos'
                   AND COLUMN_NAME = 'nombre');
SET @sql := IF(@existe = 1, 'ALTER TABLE datarocket_prospectos DROP COLUMN `nombre`', 'DO 0');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- ---------------------------------------------------------------------------
-- 3) contacto  (ojo: NO es `contacto_id`, que se queda)
-- ---------------------------------------------------------------------------
SET @existe := (SELECT COUNT(*) FROM information_schema.COLUMNS
                 WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'datarocket_prospectos'
                   AND COLUMN_NAME = 'contacto');
SET @sql := IF(@existe = 1, 'ALTER TABLE datarocket_prospectos DROP COLUMN `contacto`', 'DO 0');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- ---------------------------------------------------------------------------
-- 4) celular
-- ---------------------------------------------------------------------------
SET @existe := (SELECT COUNT(*) FROM information_schema.COLUMNS
                 WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'datarocket_prospectos'
                   AND COLUMN_NAME = 'celular');
SET @sql := IF(@existe = 1, 'ALTER TABLE datarocket_prospectos DROP COLUMN `celular`', 'DO 0');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- ---------------------------------------------------------------------------
-- 5) correo
-- ---------------------------------------------------------------------------
SET @existe := (SELECT COUNT(*) FROM information_schema.COLUMNS
                 WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'datarocket_prospectos'
                   AND COLUMN_NAME = 'correo');
SET @sql := IF(@existe = 1, 'ALTER TABLE datarocket_prospectos DROP COLUMN `correo`', 'DO 0');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- ---------------------------------------------------------------------------
-- 6) web
-- ---------------------------------------------------------------------------
SET @existe := (SELECT COUNT(*) FROM information_schema.COLUMNS
                 WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'datarocket_prospectos'
                   AND COLUMN_NAME = 'web');
SET @sql := IF(@existe = 1, 'ALTER TABLE datarocket_prospectos DROP COLUMN `web`', 'DO 0');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- ---------------------------------------------------------------------------
-- 7) domicilio
-- ---------------------------------------------------------------------------
SET @existe := (SELECT COUNT(*) FROM information_schema.COLUMNS
                 WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'datarocket_prospectos'
                   AND COLUMN_NAME = 'domicilio');
SET @sql := IF(@existe = 1, 'ALTER TABLE datarocket_prospectos DROP COLUMN `domicilio`', 'DO 0');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- ---------------------------------------------------------------------------
-- 8) ciudad
-- ---------------------------------------------------------------------------
SET @existe := (SELECT COUNT(*) FROM information_schema.COLUMNS
                 WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'datarocket_prospectos'
                   AND COLUMN_NAME = 'ciudad');
SET @sql := IF(@existe = 1, 'ALTER TABLE datarocket_prospectos DROP COLUMN `ciudad`', 'DO 0');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- ---------------------------------------------------------------------------
-- 9) localidad
-- ---------------------------------------------------------------------------
SET @existe := (SELECT COUNT(*) FROM information_schema.COLUMNS
                 WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'datarocket_prospectos'
                   AND COLUMN_NAME = 'localidad');
SET @sql := IF(@existe = 1, 'ALTER TABLE datarocket_prospectos DROP COLUMN `localidad`', 'DO 0');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- ---------------------------------------------------------------------------
-- 10) provincia
-- ---------------------------------------------------------------------------
SET @existe := (SELECT COUNT(*) FROM information_schema.COLUMNS
                 WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'datarocket_prospectos'
                   AND COLUMN_NAME = 'provincia');
SET @sql := IF(@existe = 1, 'ALTER TABLE datarocket_prospectos DROP COLUMN `provincia`', 'DO 0');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- ---------------------------------------------------------------------------
-- 11) pais
-- ---------------------------------------------------------------------------
SET @existe := (SELECT COUNT(*) FROM information_schema.COLUMNS
                 WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'datarocket_prospectos'
                   AND COLUMN_NAME = 'pais');
SET @sql := IF(@existe = 1, 'ALTER TABLE datarocket_prospectos DROP COLUMN `pais`', 'DO 0');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- ---------------------------------------------------------------------------
-- 12) ubicacion
-- ---------------------------------------------------------------------------
SET @existe := (SELECT COUNT(*) FROM information_schema.COLUMNS
                 WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'datarocket_prospectos'
                   AND COLUMN_NAME = 'ubicacion');
SET @sql := IF(@existe = 1, 'ALTER TABLE datarocket_prospectos DROP COLUMN `ubicacion`', 'DO 0');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
