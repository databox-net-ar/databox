-- datarocket_contactos: DROP de la columna `origen` (VARCHAR(255)).
--
-- QUE SE PIERDE (medido en dev al 2026-08-17, sobre 43.239 contactos):
--
--   CT                                26.168   codigo legacy, sin catalogo
--   (vacio)                            8.597
--   M                                  7.484   codigo legacy, sin catalogo
--   migracion_datarocket_prospectos      982   marca del backfill 20260812_1100
--   evolution_mensajes                     4   alta automatica por WhatsApp
--   aws_mensajes                           2   alta automatica por correo
--   origen                                 1   basura (el header del import)
--   NULL                                   1
--
-- Los dos valores que dominan (CT y M, 33.652 filas juntos) vienen del import
-- legacy y NO los decodifica ningun catalogo: no hay filas en `estados` con
-- campo `datarocket_contacto_origen`, asi que el panel los mostraba crudos. El
-- resto son marcas tecnicas que dejaban el backfill y las altas automaticas.
--
-- CONSECUENCIA A TENER PRESENTE: los contactos que crean solos
-- cloud/api/lib/aws_mensajes.php y cloud/api/lib/evolution_mensajes.php cuando
-- entra un mensaje de alguien desconocido se marcaban con
-- origen = 'aws_mensajes' / 'evolution_mensajes'. Sin la columna no queda rastro
-- de que nacieron automaticamente. Si mas adelante hace falta distinguirlos,
-- la via es `datarocket_interacciones` (la interaccion que los creo) o una
-- etiqueta.
--
-- No confundir con `datarocket_prospectos.origen`, que se queda: es otra tabla
-- y ahi si tiene catalogo (`datasale_prospecto_origen`).
--
-- ORDEN DE DESPLIEGUE: esta migracion se aplica DESPUES de publicar el codigo
-- que deja de leerla (los dos endpoints de contactos, las dos libs de alta
-- automatica y app.js). Al reves, el INSERT de las libs y el listado del ABM
-- tiran 500 hasta el deploy.
--
-- Idempotente: el DROP se guarda con information_schema.
-- Compatible MySQL 8 (dev) + MariaDB 10.11 (prod).


-- ---------------------------------------------------------------------------
-- 1) origen
-- ---------------------------------------------------------------------------
SET @existe := (SELECT COUNT(*) FROM information_schema.COLUMNS
                 WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'datarocket_contactos'
                   AND COLUMN_NAME = 'origen');
SET @sql := IF(@existe = 1, 'ALTER TABLE datarocket_contactos DROP COLUMN `origen`', 'DO 0');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
