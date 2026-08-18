-- datarocket_embudos: agregar `slug` — identificador estable en kebab-case.
--
-- El `nombre` de un embudo es texto libre y editable (hoy suele ser el nombre
-- del proyecto: "Vigicom", "Reactor"...). Cuando hay que referenciar un embudo
-- desde afuera del panel (endpoints v4, integraciones, seeds, formularios web
-- que caen en un pipeline concreto) el nombre no sirve: cambia con cualquier
-- retoque de redaccion y trae acentos / espacios / mayusculas.
--
-- El `slug` cumple ese rol — mismo criterio que `datacount_empresas.slug`:
--   * kebab-case estricto: `^[a-z0-9]+(-[a-z0-9]+)*$`, max 40 chars.
--   * obligatorio (NOT NULL); la capa PHP lo autoderiva del nombre cuando el
--     operador no lo carga a mano (ver `dremSlugify()` en
--     cloud/api/datarocket_embudos.php y su espejo JS en app.js).
--   * UNIQUE por proyecto — mismo alcance que el UNIQUE de `nombre`
--     (`uq_datarocket_embudos_proyecto_nombre`, migracion 20260812_0600):
--     dos proyectos distintos pueden tener su propio `captacion-general`.
--
-- La columna queda ANTES de `nombre` (AFTER `proyecto_id`) para que el orden
-- fisico acompañe al de la UI (Codigo / Proyecto / Slug / Nombre / ...).
--
-- Backfill: se deriva de `nombre` (LOWER + plegado de acentos + todo lo que no
-- sea [a-z0-9] a guion + colapso/trim de guiones + LEFT 40). Los embudos que
-- queden con slug vacio (nombre sin caracteres alfanumericos) caen al fallback
-- `embudo-<id>`. Si dos nombres distintos del mismo proyecto colapsan al mismo
-- slug (p.ej. "Ventas B2B" y "Ventas-B2B"), al segundo se le sufija `-<id>`
-- antes de crear el UNIQUE, asi la migracion nunca aborta por colision.
--
-- Idempotente en los 6 pasos. Compatible MySQL 8 (dev) + MariaDB 10.11 (prod):
-- sin `IF NOT EXISTS` de MariaDB (patron information_schema + PREPARE), sin
-- funciones almacenadas y sin tablas temporales.

-- ============================================================================
-- Paso 1: agregar la columna `slug` (nullable inicialmente, para backfillear).
-- ============================================================================

SET @exists := (
  SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME   = 'datarocket_embudos'
    AND COLUMN_NAME  = 'slug'
);
SET @sql := IF(@exists = 0,
  'ALTER TABLE datarocket_embudos ADD COLUMN `slug` VARCHAR(40) NULL DEFAULT NULL AFTER `proyecto_id`',
  'DO 0'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- ============================================================================
-- Paso 2: backfill kebab-case desde `nombre`.
-- ============================================================================
--
-- LOWER() ya pliega las mayusculas acentuadas ('Á' -> 'á'), asi que la cadena
-- de REPLACE solo necesita las minusculas. Lo que no matchee cae igual en el
-- REGEXP_REPLACE siguiente (pasa a guion), no rompe nada.

UPDATE datarocket_embudos
   SET slug = LEFT(
        REGEXP_REPLACE(
          REGEXP_REPLACE(
            REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(
              LOWER(nombre),
              'á', 'a'), 'é', 'e'), 'í', 'i'), 'ó', 'o'), 'ú', 'u'),
              'ñ', 'n'), 'ü', 'u'), 'ç', 'c'),
            '[^a-z0-9]+', '-'),
          '^-+|-+$', ''),
        40)
 WHERE slug IS NULL OR slug = '';

-- Fallback para nombres sin caracteres alfanumericos (slug quedaria vacio).
UPDATE datarocket_embudos
   SET slug = CONCAT('embudo-', id)
 WHERE slug IS NULL OR slug = '';

-- ============================================================================
-- Paso 3: desambiguar colisiones dentro del mismo proyecto.
-- ============================================================================
--
-- Se conserva el slug del embudo de menor id y al resto se le sufija `-<id>`.
-- El GROUP BY del derivado no agrupa nada util (a.id ya es unico): esta para
-- forzar la materializacion de la subquery y evitar el error 1093 de MySQL 8
-- ("can't specify target table for update in FROM clause").

UPDATE datarocket_embudos e
  JOIN (
    SELECT a.id AS id
      FROM datarocket_embudos a
      JOIN datarocket_embudos b
        ON b.proyecto_id = a.proyecto_id
       AND b.slug        = a.slug
       AND b.id          < a.id
     GROUP BY a.id
  ) d ON d.id = e.id
   SET e.slug = LEFT(CONCAT(e.slug, '-', e.id), 40);

-- ============================================================================
-- Paso 4: marcar `slug` como NOT NULL.
-- ============================================================================

SET @is_nullable := (
  SELECT IS_NULLABLE FROM information_schema.COLUMNS
   WHERE TABLE_SCHEMA = DATABASE()
     AND TABLE_NAME   = 'datarocket_embudos'
     AND COLUMN_NAME  = 'slug'
);
SET @sql := IF(@is_nullable = 'YES',
  'ALTER TABLE datarocket_embudos MODIFY COLUMN `slug` VARCHAR(40) NOT NULL',
  'DO 0'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- ============================================================================
-- Paso 5: UNIQUE (proyecto_id, slug) — mismo alcance que el UNIQUE de nombre.
-- ============================================================================

SET @exists := (
  SELECT COUNT(*) FROM information_schema.STATISTICS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME   = 'datarocket_embudos'
    AND INDEX_NAME   = 'uq_datarocket_embudos_proyecto_slug'
);
SET @sql := IF(@exists = 0,
  'ALTER TABLE datarocket_embudos ADD UNIQUE INDEX `uq_datarocket_embudos_proyecto_slug` (`proyecto_id`, `slug`)',
  'DO 0'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
