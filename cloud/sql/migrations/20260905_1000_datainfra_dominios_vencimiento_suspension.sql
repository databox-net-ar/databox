-- datainfra_dominios: reordena las fechas del ciclo de vida del dominio.
--
--   - fecha_ultima_renovacion       (se elimina)
--   fecha_siguiente_renovacion  ->  fecha_vencimiento
--   + fecha_suspension              (nueva, date NULL)
--
-- POR QUE
-- -------
-- `fecha_ultima_renovacion` nunca se completo desde ninguna fuente real: el
-- scraper WHOIS (api/lib/datainfradominios_whois.php) no la extrae ni de nic.ar
-- ni de who.is — ninguno de los dos publica "ultima renovacion" — y ninguna
-- consulta del panel la lee: no aparece en el dashboard, ni en los indicadores
-- de Datainfra, ni en las stats del ABM. Solo estaba en el modal de alta y en la
-- ficha de Consultar, siempre vacia. Se va.
--
-- `fecha_siguiente_renovacion` es engañosa: lo que guarda es la fecha de
-- vencimiento del dominio (la "Fecha de vencimiento" de nic.ar / el "Expires On"
-- de who.is), no la fecha en que se planea renovarlo. Toda la logica que la
-- consume ya la trata como vencimiento — el badge "Vencido hace N dias", las
-- stats `vencidos` / `por_vencer`, el bloque rojo del dashboard. El nombre
-- ahora dice lo que el dato es.
--
-- `fecha_suspension` es el dato que faltaba: pasado el vencimiento el registrar
-- no da de baja el dominio de inmediato, lo suspende y lo mantiene recuperable
-- por un periodo (redemption). Esa es la fecha limite real para no perderlo.
-- Queda NULL para todas las filas existentes: no hay forma de derivarla del
-- vencimiento (el periodo varia por registrar y por TLD) y estimarla seria
-- inventar una fecha limite que despues alguien usa para decidir.
--
-- ---------------------------------------------------------------------------
-- ORDEN DE DESPLIEGUE
-- ---------------------------------------------------------------------------
-- Esta migracion se aplica DESPUES de publicar el codigo que usa los nombres
-- nuevos (cloud/api/datainfradominios.php, cloud/api/dashboard.php,
-- cloud/api/datainfra_indicadores.php, cloud/api/lib/datainfradominios_whois.php
-- y cloud/assets/js/app.js). Al reves, esos endpoints tiran 500 hasta el deploy.
--
-- Se usa RENAME COLUMN y no CHANGE COLUMN a proposito: CHANGE obliga a repetir
-- la definicion completa y si dev y prod difieren en algun DEFAULT la
-- reescribiria en silencio. RENAME preserva tipo, default, nullability y
-- posicion. Soportado por MySQL 8.0 (dev) y MariaDB 10.5.2+ (prod corre 10.11).
--
-- Idempotente en los cuatro pasos. Compatible MySQL 8 (dev) + MariaDB 10.11 (prod).


-- ---------------------------------------------------------------------------
-- 1) Baja de `fecha_ultima_renovacion`
-- ---------------------------------------------------------------------------
-- MySQL 8 (dev) no soporta `DROP COLUMN IF EXISTS`, que si existe en MariaDB
-- (prod). Patron portable information_schema + PREPARE.
SET @existe := (SELECT COUNT(*) FROM information_schema.COLUMNS
                 WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'datainfra_dominios'
                   AND COLUMN_NAME = 'fecha_ultima_renovacion');
SET @sql := IF(@existe = 1,
  'ALTER TABLE `datainfra_dominios` DROP COLUMN `fecha_ultima_renovacion`', 'DO 0');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- ---------------------------------------------------------------------------
-- 2) fecha_siguiente_renovacion -> fecha_vencimiento
-- ---------------------------------------------------------------------------
-- El indice `idx_datainfra_dominios_prox_renov` sigue a la columna solo: RENAME
-- COLUMN actualiza la definicion del indice pero no su nombre. Se renombra en
-- el paso 4.
SET @viejo := (SELECT COUNT(*) FROM information_schema.COLUMNS
                WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'datainfra_dominios'
                  AND COLUMN_NAME = 'fecha_siguiente_renovacion');
SET @nuevo := (SELECT COUNT(*) FROM information_schema.COLUMNS
                WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'datainfra_dominios'
                  AND COLUMN_NAME = 'fecha_vencimiento');
SET @sql := IF(@viejo = 1 AND @nuevo = 0,
  'ALTER TABLE `datainfra_dominios` RENAME COLUMN `fecha_siguiente_renovacion` TO `fecha_vencimiento`',
  'DO 0');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- ---------------------------------------------------------------------------
-- 3) Alta de `fecha_suspension`
-- ---------------------------------------------------------------------------
-- Va inmediatamente despues del vencimiento: las dos fechas se leen juntas
-- (vence tal dia, se pierde tal otro).
SET @existe := (SELECT COUNT(*) FROM information_schema.COLUMNS
                 WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'datainfra_dominios'
                   AND COLUMN_NAME = 'fecha_suspension');
SET @sql := IF(@existe = 0,
  'ALTER TABLE `datainfra_dominios`
     ADD COLUMN `fecha_suspension` date NULL DEFAULT NULL AFTER `fecha_vencimiento`',
  'DO 0');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- ---------------------------------------------------------------------------
-- 4) idx_datainfra_dominios_prox_renov -> idx_datainfra_dominios_vencimiento
-- ---------------------------------------------------------------------------
-- DROP + ADD en vez de `RENAME INDEX`: RENAME INDEX existe en MySQL 8 y en
-- MariaDB recien desde 10.5.2, y esta tabla es chica (decenas de filas), asi
-- que rehacer el indice no cuesta nada.
SET @viejo := (SELECT COUNT(*) FROM information_schema.STATISTICS
                WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'datainfra_dominios'
                  AND INDEX_NAME = 'idx_datainfra_dominios_prox_renov');
SET @sql := IF(@viejo > 0,
  'ALTER TABLE `datainfra_dominios` DROP INDEX `idx_datainfra_dominios_prox_renov`', 'DO 0');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @nuevo := (SELECT COUNT(*) FROM information_schema.STATISTICS
                WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'datainfra_dominios'
                  AND INDEX_NAME = 'idx_datainfra_dominios_vencimiento');
SET @sql := IF(@nuevo = 0,
  'ALTER TABLE `datainfra_dominios` ADD INDEX `idx_datainfra_dominios_vencimiento` (`fecha_vencimiento`)',
  'DO 0');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
