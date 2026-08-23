-- datarocket_prospectos: `origen_url` -> `extraccion_url`, + `extraccion_autor`.
--
-- Rename y ampliacion del par que registra la PROCEDENCIA del dato: de donde se
-- extrajo el prospecto y quien lo extrajo.
--
--   extraccion_url    la URL de la que se saco la ficha
--   extraccion_autor  el nombre de la persona o del bot que hizo la extraccion
--
-- POR QUE EL RENAME (a un dia de haber creado la columna, migracion
-- 20260822_1000): `origen` ya significa otra cosa en seis tablas del esquema
-- (`sucesos`, `datarocket_oportunidades`, `datarocket_actividades`,
-- `hipervisoreventos` y las legacy `datarocketcontactos` / `datamarketcontactos`)
-- y en todas quiere decir "el canal o sistema por el que entro esto" — valores
-- cortos tipo 'Web' o 'aws_mensajes', nunca una URL y nunca una persona. Sumarle
-- un septimo sentido para nombrar la extraccion volvia la palabra inservible.
-- `extraccion_*` nombra exactamente lo que es y no pisa nada ocupado. Se hace
-- ahora porque la columna tiene 0 filas cargadas y ningun integrador externo la
-- usa todavia; en una semana el rename ya no era gratis.
--
-- LAS DOS COLUMNAS SE GUARDAN TAL CUAL VIENEN, sin normalizar. En particular
-- `extraccion_url` conserva el esquema y las mayusculas del path y del query,
-- que es donde viven los ids que identifican la fuente (`/p/MLA-123`,
-- `?ref=Xk9Q`). NO pasa por prospectoNormalizarWeb(), que hace lo contrario.
-- No confundir con `web`, que es el sitio DEL prospecto.
--
-- POSICION FISICA: las dos quedan DESPUES de `comentarios` (y antes de
-- `registrado`) para acompañar a la UI — en los modales del ABM viven al final
-- de la pestaña Clasificacion, debajo de Comentarios. `origen_url` estaba
-- despues de `web` porque ahi vivia en la pestaña Contacto; se mudo.
--
-- INDICE en `extraccion_url`: lo pide el caso de uso que motiva el campo — un
-- bot pregunta por `?verificar=1&extraccion_url=...` ANTES de extraer, y si la
-- URL ya esta cargada se saltea todo el trabajo. Sin indice esa pregunta es un
-- full scan de ~148k filas en cada iteracion del scraping. Mismo criterio que
-- `idx_dr_prospectos_correo` / `_celular` (migracion 20260818_1300).
--
-- NO es UNIQUE, y es a proposito: una sola URL de listado legitimamente da de
-- alta muchos prospectos (una pagina de resultados con 20 empresas se extrae de
-- una unica URL). Por eso `extraccion_url` TAMPOCO participa del chequeo de
-- unicidad que devuelve 409 en el alta — ese sigue siendo solo `correo` /
-- `celular`. La URL se consulta aparte, en `?verificar=1`.
--
-- Cabe el indice sobre la columna entera: varchar(500) utf8mb4 = 2.000 bytes,
-- debajo del limite de 3.072 de InnoDB con ROW_FORMAT=Dynamic (que es el de esta
-- tabla) tanto en MySQL 8 como en MariaDB 10.11.
--
-- Idempotente en los 4 pasos, y sirve tanto sobre una base que ya tiene
-- `origen_url` (dev y prod al 2026-08-23) como sobre una que nunca la tuvo.
-- Compatible MySQL 8 (dev) + MariaDB 10.11 (prod): sin `IF NOT EXISTS` de
-- MariaDB (patron information_schema + PREPARE), sin `RENAME COLUMN` (que pide
-- MySQL 8 / MariaDB 10.5.2+; `CHANGE COLUMN` anda en los dos), sin funciones
-- almacenadas y sin tablas temporales.

-- ============================================================================
-- Paso 1: `origen_url` -> `extraccion_url`, reubicandola despues de `comentarios`.
-- ============================================================================
--
-- Solo corre si la columna vieja esta y la nueva no. En una base donde la
-- 20260822_1000 nunca se aplico, este paso no hace nada y la crea el paso 2.

SET @tiene_vieja := (
  SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME   = 'datarocket_prospectos'
    AND COLUMN_NAME  = 'origen_url'
);
SET @tiene_nueva := (
  SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME   = 'datarocket_prospectos'
    AND COLUMN_NAME  = 'extraccion_url'
);
SET @sql := IF(@tiene_vieja = 1 AND @tiene_nueva = 0,
  'ALTER TABLE datarocket_prospectos
     CHANGE COLUMN `origen_url` `extraccion_url` VARCHAR(500) NULL DEFAULT NULL AFTER `comentarios`',
  'DO 0'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- ============================================================================
-- Paso 2: crear `extraccion_url` si no existia ninguna de las dos.
-- ============================================================================

SET @tiene_nueva := (
  SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME   = 'datarocket_prospectos'
    AND COLUMN_NAME  = 'extraccion_url'
);
SET @sql := IF(@tiene_nueva = 0,
  'ALTER TABLE datarocket_prospectos
     ADD COLUMN `extraccion_url` VARCHAR(500) NULL DEFAULT NULL AFTER `comentarios`',
  'DO 0'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- ============================================================================
-- Paso 3: `extraccion_autor` — quien hizo la extraccion.
-- ============================================================================
--
-- Texto libre a proposito: puede ser una persona ('Javier Alvarez') o un proceso
-- ('scraper-paginas-amarillas', 'bot-mercadolibre v2'). No es FK a `usuarios`
-- porque la mayoria de las extracciones no las hace un usuario del panel.

SET @exists := (
  SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME   = 'datarocket_prospectos'
    AND COLUMN_NAME  = 'extraccion_autor'
);
SET @sql := IF(@exists = 0,
  'ALTER TABLE datarocket_prospectos
     ADD COLUMN `extraccion_autor` VARCHAR(255) NULL DEFAULT NULL AFTER `extraccion_url`',
  'DO 0'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- ============================================================================
-- Paso 4: indice de `extraccion_url` para el chequeo previo del bot.
-- ============================================================================

SET @exists := (
  SELECT COUNT(*) FROM information_schema.STATISTICS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME   = 'datarocket_prospectos'
    AND INDEX_NAME   = 'idx_dr_prospectos_extraccion_url'
);
SET @sql := IF(@exists = 0,
  'ALTER TABLE datarocket_prospectos
     ADD INDEX `idx_dr_prospectos_extraccion_url` (`extraccion_url`)',
  'DO 0'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
