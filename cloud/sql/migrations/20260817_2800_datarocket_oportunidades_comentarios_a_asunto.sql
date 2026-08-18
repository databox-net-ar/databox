-- datarocket_oportunidades: renombra `comentarios` -> `asunto`.
--
--   comentarios (VARCHAR 1000) -> asunto (VARCHAR 1000)
--
-- Es la vuelta atras del nombre, no del contenido. Historia corta de esta
-- columna:
--   * la tabla tenia `asunto` (etiqueta corta) + `comentarios` (notas libres);
--   * la migracion 20260817_1600 volco `asunto` como PRIMERA LINEA de
--     `comentarios`, y la 20260817_1700 dropeo la columna `asunto` vacia.
-- O sea: el contenido que hoy vive en `comentarios` ya es "asunto + notas"
-- fusionados. Este rename solo le devuelve el nombre; NO parte el contenido de
-- vuelta en dos columnas ni toca un solo caracter de los datos.
--
-- Se usa RENAME COLUMN y no CHANGE COLUMN a proposito: CHANGE obliga a repetir
-- la definicion completa y si dev y prod difieren en algun DEFAULT la
-- reescribiria en silencio. RENAME preserva tipo, default, nullability y
-- posicion. Soportado por MySQL 8.0 (dev) y MariaDB 10.5.2+ (prod corre 10.11).
--
-- OJO con dos homonimos que NO se tocan y se parecen:
--   * `datarocket_interacciones.asunto` (VARCHAR 500) ya existe y es otra cosa:
--     la etiqueta corta de cada interaccion. Esta migracion no la roza.
--   * `datarocket_prospectos.comentarios` (VARCHAR 500) sigue llamandose
--     `comentarios` — el rename es solo sobre la tabla de oportunidades.
--   * `datarocket_plantillas.asunto` (VARCHAR 255) es el subject del mail de
--     una plantilla; tampoco tiene nada que ver.
--
-- La columna no tiene indice, FK ni catalogo en `estados`, asi que el rename se
-- agota en el ALTER: no hay nada mas que actualizar del lado de la base.
--
-- ORDEN DE DESPLIEGUE: esta migracion se aplica DESPUES de publicar el codigo
-- que usa el nombre nuevo (cloud/api/datarocket_oportunidades.php y
-- cloud/assets/js/app.js). Al reves, el endpoint tira 500 hasta el deploy.
--
-- Idempotente: el rename corre solo si el nombre viejo todavia existe Y el
-- nuevo todavia no. En la segunda corrida no hace nada.
-- Compatible MySQL 8 (dev) + MariaDB 10.11 (prod).


-- ---------------------------------------------------------------------------
-- 1) comentarios -> asunto
-- ---------------------------------------------------------------------------
SET @viejo := (SELECT COUNT(*) FROM information_schema.COLUMNS
                WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'datarocket_oportunidades'
                  AND COLUMN_NAME = 'comentarios');
SET @nuevo := (SELECT COUNT(*) FROM information_schema.COLUMNS
                WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'datarocket_oportunidades'
                  AND COLUMN_NAME = 'asunto');
SET @sql := IF(@viejo = 1 AND @nuevo = 0,
  'ALTER TABLE datarocket_oportunidades RENAME COLUMN `comentarios` TO `asunto`', 'DO 0');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
