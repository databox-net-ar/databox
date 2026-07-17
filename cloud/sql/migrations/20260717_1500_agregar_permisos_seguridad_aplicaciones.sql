-- Agrega los 5 permisos cloud del ABM `seguridad.aplicaciones.*`
-- (consultar / agregar / editar / eliminar / regenerar). Habilitan la
-- nueva pantalla "Aplicaciones" del menu Seguridad y su endpoint
-- `aplicaciones.php`, que administra el catalogo de la tabla
-- `aplicaciones` (API keys que otros sistemas usan para consumir datos
-- de Databox).
--
-- Reprograma tambien `desarrollador.permisos` con TODOS los permisos
-- cloud del env actual, igual que las migraciones previas de permisos,
-- para que los nuevos permisos queden incluidos inmediatamente en el rol.
--
-- Idempotente en los 2 pasos.

-- ============================================================================
-- Paso 1: catalogo de permisos (LEFT JOIN + IS NULL como el seed original).
-- ============================================================================

CREATE TEMPORARY TABLE tmp_permisos_seguridad_aplicaciones (
  slug   VARCHAR(100) NOT NULL,
  nombre VARCHAR(255) NOT NULL
) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci;

INSERT INTO tmp_permisos_seguridad_aplicaciones (slug, nombre) VALUES
('seguridad.aplicaciones.consultar', 'Seguridad > Aplicaciones > Consultar'),
('seguridad.aplicaciones.agregar',   'Seguridad > Aplicaciones > Agregar'),
('seguridad.aplicaciones.editar',    'Seguridad > Aplicaciones > Editar'),
('seguridad.aplicaciones.eliminar',  'Seguridad > Aplicaciones > Eliminar'),
('seguridad.aplicaciones.regenerar', 'Seguridad > Aplicaciones > Regenerar API key');

INSERT INTO `permisos` (`slug`, `nombre`)
SELECT t.slug, t.nombre
FROM tmp_permisos_seguridad_aplicaciones t
LEFT JOIN `permisos` p ON p.slug = t.slug
WHERE p.id IS NULL;

DROP TEMPORARY TABLE tmp_permisos_seguridad_aplicaciones;

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
