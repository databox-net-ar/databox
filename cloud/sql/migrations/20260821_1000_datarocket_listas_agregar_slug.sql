-- datarocket_listas: agregar `slug` — identificador estable en kebab-case.
--
-- Mismo criterio que `datarocket_embudos.slug` (migracion 20260818_1400) y
-- `datacount_empresas.slug`: el `nombre` de una lista es texto libre y editable
-- ("Vigicom Prospectos Frios", "Reactor Clientes"...). Cuando hay que
-- referenciar una lista desde afuera del panel (endpoints v4, integraciones,
-- seeds, formularios web que suscriben a una lista concreta) el nombre no
-- sirve: cambia con cualquier retoque de redaccion y trae acentos / espacios /
-- mayusculas.
--
-- Reglas del `slug`:
--   * kebab-case estricto: `^[a-z0-9]+(-[a-z0-9]+)*$`, max 40 chars.
--   * obligatorio (NOT NULL); la capa PHP lo autoderiva del nombre cuando el
--     operador no lo carga a mano (ver `drliSlugify()` en
--     cloud/api/datarocketlistas.php y su espejo JS en app.js).
--   * UNIQUE por proyecto: dos proyectos distintos pueden tener su propio
--     `clientes`.
--
-- OJO — a diferencia de `datarocket_embudos`, aca `proyecto_id` es NULLABLE
-- (el ABM ofrece "— Sin proyecto —"). El UNIQUE (proyecto_id, slug) no
-- restringe a las filas con `proyecto_id IS NULL`, porque MySQL/MariaDB tratan
-- cada NULL como distinto en un indice unico. Es una degradacion aceptada: hoy
-- las 29 listas existentes tienen proyecto, y el chequeo amigable del PHP
-- tampoco puede desambiguar un alcance que la DB no modela. Si en algun momento
-- se decide que `proyecto_id` pase a NOT NULL, el UNIQUE ya queda listo.
--
-- La columna queda DESPUES de `nombre` (AFTER `nombre`) para que el orden
-- fisico acompañe al de la UI (Codigo / Proyecto / Nombre / Slug / ...).
--
-- Backfill: se deriva de `nombre` (LOWER + plegado de acentos + todo lo que no
-- sea [a-z0-9] a guion + colapso/trim de guiones + LEFT 40). Las listas que
-- queden con slug vacio (nombre NULL o sin caracteres alfanumericos) caen al
-- fallback `lista-<id>`. Si dos nombres distintos del mismo proyecto colapsan al
-- mismo slug (p.ej. "Clientes VIP" y "Clientes-VIP"), al segundo se le sufija
-- `-<id>` antes de crear el UNIQUE, asi la migracion nunca aborta por colision.
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
    AND TABLE_NAME   = 'datarocket_listas'
    AND COLUMN_NAME  = 'slug'
);
SET @sql := IF(@exists = 0,
  'ALTER TABLE datarocket_listas ADD COLUMN `slug` VARCHAR(40) NULL DEFAULT NULL AFTER `nombre`',
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

UPDATE datarocket_listas
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

-- Fallback para nombres NULL o sin caracteres alfanumericos (slug quedaria vacio).
UPDATE datarocket_listas
   SET slug = CONCAT('lista-', id)
 WHERE slug IS NULL OR slug = '';

-- ============================================================================
-- Paso 3: desambiguar colisiones dentro del mismo proyecto.
-- ============================================================================
--
-- Se conserva el slug de la lista de menor id y al resto se le sufija `-<id>`.
-- El GROUP BY del derivado no agrupa nada util (a.id ya es unico): esta para
-- forzar la materializacion de la subquery y evitar el error 1093 de MySQL 8
-- ("can't specify target table for update in FROM clause").
--
-- Las filas con `proyecto_id IS NULL` no matchean el JOIN (NULL = NULL da NULL),
-- igual que no las restringe el UNIQUE del paso 5. Consistente.

UPDATE datarocket_listas l
  JOIN (
    SELECT a.id AS id
      FROM datarocket_listas a
      JOIN datarocket_listas b
        ON b.proyecto_id = a.proyecto_id
       AND b.slug        = a.slug
       AND b.id          < a.id
     GROUP BY a.id
  ) d ON d.id = l.id
   SET l.slug = LEFT(CONCAT(l.slug, '-', l.id), 40);

-- ============================================================================
-- Paso 4: marcar `slug` como NOT NULL.
-- ============================================================================

SET @is_nullable := (
  SELECT IS_NULLABLE FROM information_schema.COLUMNS
   WHERE TABLE_SCHEMA = DATABASE()
     AND TABLE_NAME   = 'datarocket_listas'
     AND COLUMN_NAME  = 'slug'
);
SET @sql := IF(@is_nullable = 'YES',
  'ALTER TABLE datarocket_listas MODIFY COLUMN `slug` VARCHAR(40) NOT NULL',
  'DO 0'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- ============================================================================
-- Paso 5: UNIQUE (proyecto_id, slug).
-- ============================================================================

SET @exists := (
  SELECT COUNT(*) FROM information_schema.STATISTICS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME   = 'datarocket_listas'
    AND INDEX_NAME   = 'uq_datarocket_listas_proyecto_slug'
);
SET @sql := IF(@exists = 0,
  'ALTER TABLE datarocket_listas ADD UNIQUE INDEX `uq_datarocket_listas_proyecto_slug` (`proyecto_id`, `slug`)',
  'DO 0'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
