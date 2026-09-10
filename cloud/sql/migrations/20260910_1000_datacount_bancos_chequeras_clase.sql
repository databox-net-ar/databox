-- Datacount > Chequeras: alta de `clase` en `datacount_bancos_chequeras`.
--
--   cuenta_id
--   + clase   (nueva, enum('electronica','papel') NOT NULL DEFAULT 'papel')
--   tipo
--
-- POR QUE
-- -------
-- `clase` y `tipo` son dos ejes distintos y ortogonales de la chequera, y hasta
-- ahora solo estaba el segundo:
--
--   - `clase` dice en que soporte viene el talonario. PAPEL es la chequera
--     fisica de toda la vida; ELECTRONICA es el ECHEQ, que se libra y se
--     endosa desde el homebanking y nunca se imprime.
--   - `tipo` dice cuando se cobra el cheque: COMUN a la vista, DIFERIDO con
--     fecha de pago futura.
--
-- Son independientes: existe la chequera electronica de cheques diferidos igual
-- que la de papel de comunes. Por eso `clase` es una columna propia y no un
-- valor mas del enum de `tipo` -- meterlos en el mismo campo obligaria a un
-- enum de cuatro valores que no podria expresar las cuatro combinaciones sin
-- repetirlas.
--
-- Va inmediatamente despues de `cuenta_id` porque las tres primeras columnas
-- son "de que cuenta salio y que talonario es": se leen juntas.
--
-- DEFAULT 'papel' y no NULL: las chequeras ya cargadas son talonarios fisicos
-- (el modulo se estreno el 2026-09-08 y no habia forma de registrar un ECHEQ),
-- asi que el backfill implicito del ALTER las deja bien. Un NULL obligaria a
-- que la UI y los reportes arrastren un tercer estado "no se" que en los
-- hechos no existe.
--
-- El catalogo se seedea ademas en `estados` (campo
-- `datacount_bancos_chequera_clase`) para alimentar el combo desde
-- Herramientas > Editor de estados -- mismo patron que
-- `datacount_bancos_chequera_tipo` y que `datacount_bancos_cuentas.tipo`.
--
-- ---------------------------------------------------------------------------
-- ORDEN DE DESPLIEGUE
-- ---------------------------------------------------------------------------
-- Esta migracion se aplica ANTES de publicar el codigo que lee la columna
-- (cloud/api/datacount_chequeras.php y cloud/assets/js/app.js). Al reves, el
-- SELECT del endpoint tira 500 por columna inexistente hasta que corra.
--
-- Idempotente en los tres pasos. Compatible MySQL 8 (dev) + MariaDB 10.11
-- (prod): sin `ADD COLUMN IF EXISTS` de MariaDB, sin funciones almacenadas.


-- ---------------------------------------------------------------------------
-- 1) Alta de la columna
-- ---------------------------------------------------------------------------
-- MySQL 8 (dev) no soporta `ADD COLUMN IF NOT EXISTS`, que si existe en
-- MariaDB (prod). Patron portable information_schema + PREPARE.
SET @existe := (SELECT COUNT(*) FROM information_schema.COLUMNS
                 WHERE TABLE_SCHEMA = DATABASE()
                   AND TABLE_NAME   = 'datacount_bancos_chequeras'
                   AND COLUMN_NAME  = 'clase');
SET @sql := IF(@existe = 0,
  'ALTER TABLE `datacount_bancos_chequeras`
     ADD COLUMN `clase` enum(''electronica'',''papel'')
       CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci
       NOT NULL DEFAULT ''papel'' AFTER `cuenta_id`',
  'DO 0');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- ---------------------------------------------------------------------------
-- 2) Indice
-- ---------------------------------------------------------------------------
-- Acompana a `idx_tipo`: los dos ejes del talonario se filtran igual de seguido
-- desde el listado del ABM.
SET @existe := (SELECT COUNT(*) FROM information_schema.STATISTICS
                 WHERE TABLE_SCHEMA = DATABASE()
                   AND TABLE_NAME   = 'datacount_bancos_chequeras'
                   AND INDEX_NAME   = 'idx_clase');
SET @sql := IF(@existe = 0,
  'ALTER TABLE `datacount_bancos_chequeras` ADD INDEX `idx_clase`(`clase`) USING BTREE',
  'DO 0');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- ---------------------------------------------------------------------------
-- 3) Catalogo `estados`
-- ---------------------------------------------------------------------------
-- `estados` no tiene UNIQUE, asi que el INSERT va con su NOT EXISTS para no
-- duplicar ni pisar textos editados a mano desde Herramientas > Editor de
-- estados. Es MyISAM: estos cambios no son transaccionales ni reversibles con
-- ROLLBACK.
INSERT INTO `estados` (`campo`, `texto`, `valor`, `orden`)
SELECT * FROM (
  SELECT 'datacount_bancos_chequera_clase' AS c, 'Electronica' AS t, 'electronica' AS v, 1 AS o UNION ALL
  SELECT 'datacount_bancos_chequera_clase',      'Papel',            'papel',            2
) src
WHERE NOT EXISTS (
  SELECT 1 FROM `estados` e
   WHERE e.`campo` = src.c AND e.`valor` = src.v
);
