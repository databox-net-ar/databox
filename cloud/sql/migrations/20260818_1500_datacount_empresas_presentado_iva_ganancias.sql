-- datacount_empresas: agregar `presentado_iva` y `presentado_ganancias`
-- (DATE NULL) despues de `certificado_id`.
--
-- Motivo:
--   El panel necesita registrar, por empresa, cual fue el ultimo periodo
--   presentado ante ARCA para cada uno de los dos impuestos que se declaran
--   con periodicidad fija:
--
--     * `presentado_iva`        -> ultima presentacion de IVA.
--     * `presentado_ganancias`  -> ultima presentacion de Ganancias.
--
--   Es un dato de control operativo (contra que periodo esta al dia la
--   empresa), no un movimiento contable: por eso vive en la propia ficha de
--   la empresa y no en una tabla de presentaciones.
--
-- Tipo de dato:
--   Ambos campos son un periodo MES/AÑO, no una fecha calendaria. Se guardan
--   como `DATE` con el dia SIEMPRE fijo en `01` (p.ej. julio 2026 -> la
--   fecha `2026-07-01`). Se eligio `DATE` sobre un `VARCHAR(7)` 'AAAA-MM'
--   porque:
--     * ordena y compara con los operadores nativos de fecha (`MAX()`,
--       `BETWEEN`, `<`), sin castear;
--     * permite aritmetica de periodos directa (`DATE_ADD(..., INTERVAL 1
--       MONTH)`, `PERIOD_DIFF`, `TIMESTAMPDIFF`) para calcular atrasos;
--     * es el mismo tipo que ya usa `datacount_empresas.inicio`, asi que no
--       introduce una convencion nueva en la tabla.
--
--   La normalizacion del dia a `01` la hace la capa PHP (`dcePeriodo()` en
--   cloud/api/datacount_empresas.php), que acepta tanto 'AAAA-MM' — lo que
--   emite el `<input type="month">` del modal — como 'AAAA-MM-DD'. En la UI
--   se muestra siempre como MM/AAAA.
--
-- Ambas columnas son NULLABLE: NULL significa "nunca se presento" / "no se
-- lleva el dato", que es el estado inicial de todas las filas existentes.
-- Por eso NO hay backfill.
--
-- Idempotente. Compatible con MySQL 8 (dev) y MariaDB 10.11 (prod): se usa
-- el patron information_schema + PREPARE/EXECUTE porque MySQL 8 no soporta
-- la sintaxis MariaDB `ADD COLUMN IF NOT EXISTS`.

-- ============================================================================
-- Paso 1: `presentado_iva` (despues de `certificado_id`).
-- ============================================================================

SET @exists := (
  SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME   = 'datacount_empresas'
    AND COLUMN_NAME  = 'presentado_iva'
);
SET @sql := IF(@exists = 0,
  'ALTER TABLE datacount_empresas ADD COLUMN `presentado_iva` DATE NULL DEFAULT NULL AFTER `certificado_id`',
  'DO 0'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- ============================================================================
-- Paso 2: `presentado_ganancias` (despues de `presentado_iva`).
-- ============================================================================

SET @exists := (
  SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME   = 'datacount_empresas'
    AND COLUMN_NAME  = 'presentado_ganancias'
);
SET @sql := IF(@exists = 0,
  'ALTER TABLE datacount_empresas ADD COLUMN `presentado_ganancias` DATE NULL DEFAULT NULL AFTER `presentado_iva`',
  'DO 0'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- ============================================================================
-- Paso 3: saneamiento defensivo — forzar dia = 01.
-- ============================================================================
--
-- La normalizacion la hace PHP, pero si alguna fila entra por fuera del ABM
-- (import, SQL a mano, sincronizador de tablas) con un dia distinto de 01,
-- este UPDATE la vuelve a la convencion. Es un no-op en la corrida inicial
-- (todas las filas quedan en NULL) y en cualquier re-corrida sana.

UPDATE datacount_empresas
   SET presentado_iva = DATE_FORMAT(presentado_iva, '%Y-%m-01')
 WHERE presentado_iva IS NOT NULL
   AND DAY(presentado_iva) <> 1;

UPDATE datacount_empresas
   SET presentado_ganancias = DATE_FORMAT(presentado_ganancias, '%Y-%m-01')
 WHERE presentado_ganancias IS NOT NULL
   AND DAY(presentado_ganancias) <> 1;
