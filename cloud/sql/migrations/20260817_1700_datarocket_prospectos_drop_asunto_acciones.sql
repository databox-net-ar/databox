-- datarocket_prospectos: DROP de `asunto` y `acciones`.
--
--   asunto    VARCHAR(255)  -> su contenido ya vive dentro de `comentarios`
--                              (migracion 20260817_1600, que es REQUISITO).
--   acciones  MEDIUMTEXT    -> log libre de gestion, 100% vacio (0 filas con
--                              contenido en dev, ni una en la legacy
--                              `datasaleprospectos` de la que se heredo). Su
--                              reemplazo estructurado es
--                              `datarocket_interacciones`.
--
-- Ambas columnas venian del ABM legacy de Datasale y no tenian indice ni FK.
-- Lo que el frontend mostraba de ellas:
--   - `asunto` como etiqueta corta del prospecto en el listado de Interacciones
--     y en la pestana Prospectos del modal de Contacto -> pasa a `producto`
--     (cargado en 1162 de 1336 prospectos, 87%, contra 6% de `asunto`).
--   - `acciones` como textarea "log libre" en el tab Notas y como tarjeta en el
--     modal de Consultar -> se van; queda `comentarios`.
--
-- ORDEN DE DESPLIEGUE: esta migracion se aplica DESPUES de publicar el codigo
-- que deja de leerlas (DR_PRO_COLS sin `asunto`/`acciones`, buscador sin
-- `p.asunto`, interacciones con `p.producto AS prospecto_producto`). Al reves,
-- el endpoint viejo tira 500 en cada listado hasta el deploy.
--
-- REQUISITO: la 20260817_1600 (fusion asunto -> comentarios) tiene que haber
-- corrido antes, o se pierden los 79 asuntos cargados.
--
-- Idempotente: cada DROP se guarda con information_schema.
-- Compatible MySQL 8 (dev) + MariaDB 10.11 (prod).


-- ---------------------------------------------------------------------------
-- 0) GUARD: si quedan prospectos con `asunto` cargado que NO estan reflejados
--    en `comentarios`, es que la 20260817_1600 no corrio (o corrio antes de que
--    se cargara ese asunto) y el DROP borraria el dato sin retorno. En ese caso
--    la migracion aborta con "Unknown column
--    'ABORTADO_falta_correr_20260817_1600...'" — el mensaje de error ES la
--    explicacion. Para destrabarla: correr la 20260817_1600 y reintentar.
--
--    Se usa una columna inexistente y no SIGNAL porque SIGNAL no pasa por el
--    protocolo de prepared statements ("This command is not supported in the
--    prepared statement protocol yet") y todo el archivo se ejecuta asi.
--
--    El COUNT solo tiene sentido si `asunto` todavia existe; en la segunda
--    corrida (columna ya dropeada) se saltea.
-- ---------------------------------------------------------------------------

SET @existe_asunto := (SELECT COUNT(*) FROM information_schema.COLUMNS
                        WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'datarocket_prospectos'
                          AND COLUMN_NAME = 'asunto');

SET @sql := IF(@existe_asunto = 1,
  'SELECT COUNT(*) INTO @sin_fusionar FROM datarocket_prospectos
     WHERE asunto IS NOT NULL AND TRIM(asunto) <> ''''
       AND LEFT(COALESCE(comentarios, ''''), CHAR_LENGTH(asunto)) <> asunto',
  'SET @sin_fusionar := 0'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql := IF(@sin_fusionar = 0,
  'DO 0',
  'SELECT `ABORTADO_falta_correr_20260817_1600_asunto_a_comentarios`'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;


-- ---------------------------------------------------------------------------
-- 1) asunto
-- ---------------------------------------------------------------------------
SET @existe := (SELECT COUNT(*) FROM information_schema.COLUMNS
                 WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'datarocket_prospectos'
                   AND COLUMN_NAME = 'asunto');
SET @sql := IF(@existe = 1, 'ALTER TABLE datarocket_prospectos DROP COLUMN `asunto`', 'DO 0');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- ---------------------------------------------------------------------------
-- 2) acciones
-- ---------------------------------------------------------------------------
SET @existe := (SELECT COUNT(*) FROM information_schema.COLUMNS
                 WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'datarocket_prospectos'
                   AND COLUMN_NAME = 'acciones');
SET @sql := IF(@existe = 1, 'ALTER TABLE datarocket_prospectos DROP COLUMN `acciones`', 'DO 0');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
