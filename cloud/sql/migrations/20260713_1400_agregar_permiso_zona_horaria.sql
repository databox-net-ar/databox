-- Agrega el permiso cloud `administracion.herramientas.zona_horaria.consultar`
-- al catalogo. Habilita la tarjeta "Editar zona horaria" del modulo Herramientas
-- (snapshot informativo de la zona horaria efectiva en PHP, en el contenedor,
-- en MySQL y en los demas proyectos PHP visibles del monorepo). Es una
-- herramienta de solo lectura, por eso hay un unico verbo (`consultar`).
--
-- Reprograma tambien `desarrollador.permisos` con TODOS los permisos cloud del
-- env actual, igual que 20260713_1200, para que el nuevo permiso quede
-- incluido inmediatamente en el rol.
--
-- Idempotente en los 2 pasos.

-- ============================================================================
-- Paso 1: catalogo de permisos (LEFT JOIN + IS NULL como 1600).
-- ============================================================================

INSERT INTO `permisos` (`slug`, `nombre`)
SELECT * FROM (SELECT 'administracion.herramientas.zona_horaria.consultar' AS slug,
                       'Administracion > Herramientas > Zona horaria > Consultar' AS nombre) AS t
WHERE NOT EXISTS (SELECT 1 FROM `permisos`
                   WHERE `slug` = 'administracion.herramientas.zona_horaria.consultar');

-- ============================================================================
-- Paso 2: `desarrollador` = todos los permisos cloud del env actual.
-- ============================================================================

SET SESSION group_concat_max_len = 65535;

UPDATE `roles` r
CROSS JOIN (
    SELECT GROUP_CONCAT(id ORDER BY id) AS ids
    FROM `permisos`
    WHERE slug IS NOT NULL AND slug <> ''
) p
SET r.permisos = p.ids
WHERE r.slug = 'desarrollador';
