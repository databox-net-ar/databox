-- datarocket_etiquetas: agregar `slug` — identificador estable en kebab-case.
--
-- Mismo criterio que `datarocket_embudos.slug` (migracion 20260818_1400) y
-- `datarocket_listas.slug` (20260821_1000): el `nombre` de una etiqueta es
-- texto libre y editable ("expo", "mar del plata", "Vigicom"...). Cuando hay
-- que referenciar una etiqueta desde afuera del panel (endpoints v4,
-- integraciones, seeds, automatizaciones que etiquetan prospectos) el nombre
-- no sirve: cambia con cualquier retoque de redaccion y trae acentos /
-- espacios / mayusculas / guiones bajos.
--
-- Reglas del `slug`:
--   * kebab-case estricto: `^[a-z0-9]+(-[a-z0-9]+)*$`, max 40 chars.
--   * obligatorio (NOT NULL); la capa PHP lo autoderiva del nombre cuando el
--     operador no lo carga a mano (ver `dreSlugify()` en
--     cloud/api/datarocket_etiquetas.php, su espejo JS `dreSlugify()` en
--     app.js, y `etqSlugLibre()` en api/v4/datarocket/etiquetas.php).
--   * UNIQUE global, sin alcance por proyecto — a diferencia de
--     `datarocket_listas` y `datarocket_embudos`, esta tabla no tiene
--     `proyecto_id`: es un catalogo unico compartido por todo Datarocket
--     (`nombre` ya es UNIQUE global por el mismo motivo).
--
-- OJO — `nombre` UNIQUE no implica `slug` UNIQUE: dos nombres distintos pueden
-- colapsar al mismo slug ("santa fe" y "Santa-Fe" -> `santa-fe`). El paso 3
-- desambigua sufijando `-<id>`, y del lado PHP tanto el ABM (chequeo 409) como
-- el microservicio v4 (busqueda del primer slug libre) evitan generar
-- colisiones nuevas.
--
-- La columna queda DESPUES de `nombre` (AFTER `nombre`) para que el orden
-- fisico acompañe al de la UI (Codigo / Nombre / Slug / Descripcion / ...).
--
-- Backfill: se deriva de `nombre` (LOWER + plegado de acentos + todo lo que no
-- sea [a-z0-9] a guion + colapso/trim de guiones + LEFT 40). Las etiquetas que
-- queden con slug vacio (nombre sin caracteres alfanumericos) caen al fallback
-- `etiqueta-<id>`.
--
-- Idempotente en los 5 pasos. Compatible MySQL 8 (dev) + MariaDB 10.11 (prod):
-- sin `IF NOT EXISTS` de MariaDB (patron information_schema + PREPARE), sin
-- funciones almacenadas y sin tablas temporales.

-- ============================================================================
-- Paso 1: agregar la columna `slug` (nullable inicialmente, para backfillear).
-- ============================================================================

SET @exists := (
  SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME   = 'datarocket_etiquetas'
    AND COLUMN_NAME  = 'slug'
);
SET @sql := IF(@exists = 0,
  'ALTER TABLE datarocket_etiquetas ADD COLUMN `slug` VARCHAR(40) NULL DEFAULT NULL AFTER `nombre`',
  'DO 0'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- ============================================================================
-- Paso 2: backfill kebab-case desde `nombre`.
-- ============================================================================
--
-- LOWER() ya pliega las mayusculas acentuadas ('Á' -> 'á'), asi que la cadena
-- de REPLACE solo necesita las minusculas. Lo que no matchee cae igual en el
-- REGEXP_REPLACE siguiente (pasa a guion), no rompe nada. El guion bajo de los
-- nombres legacy ('proveedor_internet', 'vigicom_usuario') tambien cae ahi y
-- termina como guion medio.

UPDATE datarocket_etiquetas
   SET slug = LEFT(
        REGEXP_REPLACE(
          REGEXP_REPLACE(
            REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(
              LOWER(COALESCE(nombre, '')),
              'á', 'a'), 'é', 'e'), 'í', 'i'), 'ó', 'o'), 'ú', 'u'),
              'ñ', 'n'), 'ü', 'u'), 'ç', 'c'),
            '[^a-z0-9]+', '-'),
          '^-+|-+$', ''),
        40)
 WHERE slug IS NULL OR slug = '';

-- Fallback para nombres sin caracteres alfanumericos (el slug quedaria vacio).
UPDATE datarocket_etiquetas
   SET slug = CONCAT('etiqueta-', id)
 WHERE slug IS NULL OR slug = '';

-- ============================================================================
-- Paso 3: desambiguar colisiones de slug.
-- ============================================================================
--
-- Se conserva el slug de la etiqueta de menor id y al resto se le sufija
-- `-<id>`. El GROUP BY del derivado no agrupa nada util (a.id ya es unico):
-- esta para forzar la materializacion de la subquery y evitar el error 1093 de
-- MySQL 8 ("can't specify target table for update in FROM clause").

UPDATE datarocket_etiquetas e
  JOIN (
    SELECT a.id AS id
      FROM datarocket_etiquetas a
      JOIN datarocket_etiquetas b
        ON b.slug = a.slug
       AND b.id   < a.id
     GROUP BY a.id
  ) d ON d.id = e.id
   SET e.slug = LEFT(CONCAT(e.slug, '-', e.id), 40);

-- ============================================================================
-- Paso 4: marcar `slug` como NOT NULL.
-- ============================================================================

SET @is_nullable := (
  SELECT IS_NULLABLE FROM information_schema.COLUMNS
   WHERE TABLE_SCHEMA = DATABASE()
     AND TABLE_NAME   = 'datarocket_etiquetas'
     AND COLUMN_NAME  = 'slug'
);
SET @sql := IF(@is_nullable = 'YES',
  'ALTER TABLE datarocket_etiquetas MODIFY COLUMN `slug` VARCHAR(40) NOT NULL',
  'DO 0'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- ============================================================================
-- Paso 5: UNIQUE (slug).
-- ============================================================================

SET @exists := (
  SELECT COUNT(*) FROM information_schema.STATISTICS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME   = 'datarocket_etiquetas'
    AND INDEX_NAME   = 'uq_datarocket_etiquetas_slug'
);
SET @sql := IF(@exists = 0,
  'ALTER TABLE datarocket_etiquetas ADD UNIQUE INDEX `uq_datarocket_etiquetas_slug` (`slug`)',
  'DO 0'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
