-- Seedea `datarocket_etiquetas` con los slugs unicos encontrados en el campo
-- legacy `datarocket_contactos.tags`, cuyo formato es `(slug1)(slug2)(...)`.
-- Es el primer paso de la normalizacion del sistema de etiquetas: crear el
-- catalogo con TODAS las etiquetas que hoy estan asignadas como texto libre
-- para que despues (paso 2) se pueda armar la tabla intermedia
-- `datarocket_contactos_etiquetas` y matchear las asignaciones existentes
-- contra IDs reales del catalogo.
--
-- Los slugs se insertan tal cual aparecen en `tags` (preservando case y
-- espacios; ej. "mar del plata", "vigicom_usuario"). Esto es intencional:
-- el paso 2 va a matchear por `datarocket_etiquetas.nombre = <slug>`, asi
-- que cualquier normalizacion posterior (renombrar, unificar) va a
-- propagarse via la tabla intermedia sin tocar el string legacy.
--
-- El color arranca en gris (`#6b7280`, el default del ABM) — despues cada
-- etiqueta se puede editar desde el panel y elegir un color de la paleta.
--
-- Parsing con CTE recursivo compatible con MySQL 8.0.19+ (dev) y MariaDB
-- 10.4+ (prod: 10.11). Se corta cada string por `)(` y se quitan los `(`
-- y `)` de los bordes con TRIM(BOTH ...).
--
-- Idempotente: LEFT JOIN + IS NULL evita duplicar slugs ya presentes en
-- el catalogo. Si se corre dos veces, el segundo pase es no-op. Tambien
-- respeta las etiquetas creadas manualmente desde el ABM entre corridas.

CREATE TEMPORARY TABLE tmp_dre_slugs_desde_contactos (
  slug VARCHAR(80) NOT NULL
) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci;

INSERT INTO tmp_dre_slugs_desde_contactos (slug)
WITH RECURSIVE split AS (
  -- Semilla: la 1ra etiqueta de cada `tags` (todo lo que va antes del 1er `)(`).
  SELECT
    id,
    TRIM(BOTH ')' FROM TRIM(BOTH '(' FROM SUBSTRING_INDEX(tags, ')(', 1))) AS slug,
    IF(LOCATE(')(', tags) > 0, SUBSTRING(tags, LOCATE(')(', tags) + 2), NULL) AS resto
  FROM `datarocket_contactos`
  WHERE tags IS NOT NULL AND tags <> ''
  UNION ALL
  -- Recursion: la 1ra etiqueta del resto, hasta que no queden mas `)(`.
  SELECT
    id,
    TRIM(BOTH ')' FROM TRIM(BOTH '(' FROM SUBSTRING_INDEX(resto, ')(', 1))),
    IF(LOCATE(')(', resto) > 0, SUBSTRING(resto, LOCATE(')(', resto) + 2), NULL)
  FROM split
  WHERE resto IS NOT NULL
)
SELECT DISTINCT slug
FROM split
WHERE slug IS NOT NULL AND slug <> '';

INSERT INTO `datarocket_etiquetas` (`nombre`, `color`)
SELECT t.slug, '#6b7280'
FROM tmp_dre_slugs_desde_contactos t
LEFT JOIN `datarocket_etiquetas` e ON e.nombre = t.slug
WHERE e.id IS NULL;

DROP TEMPORARY TABLE tmp_dre_slugs_desde_contactos;
