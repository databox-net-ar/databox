-- Agrega los permisos CRUD faltantes del ABM `datainfra.endpoints.*`
-- (`agregar`, `editar`, `eliminar`). El `consultar` ya fue creado en
-- `20260725_1500_agregar_permisos_datainfra.sql` cuando se seedeo la
-- landing de Datainfra con las 3 tarjetas placeholder.
--
-- Reprograma tambien `desarrollador.permisos` con TODOS los permisos
-- cloud del env actual, igual que las migraciones previas de permisos,
-- para que los slugs nuevos queden incluidos inmediatamente en el rol.
--
-- Idempotente en los 2 pasos.

-- ============================================================================
-- Paso 1: catalogo de permisos (LEFT JOIN + IS NULL como el seed original).
-- ============================================================================

CREATE TEMPORARY TABLE tmp_permisos_datainfra_endpoints (
  slug   VARCHAR(100) NOT NULL,
  nombre VARCHAR(255) NOT NULL
) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci;

INSERT INTO tmp_permisos_datainfra_endpoints (slug, nombre) VALUES
('datainfra.endpoints.agregar',  'Datainfra > Endpoints > Agregar'),
('datainfra.endpoints.editar',   'Datainfra > Endpoints > Editar'),
('datainfra.endpoints.eliminar', 'Datainfra > Endpoints > Eliminar');

INSERT INTO `permisos` (`slug`, `nombre`)
SELECT t.slug, t.nombre
FROM tmp_permisos_datainfra_endpoints t
LEFT JOIN `permisos` p ON p.slug = t.slug
WHERE p.id IS NULL;

DROP TEMPORARY TABLE tmp_permisos_datainfra_endpoints;

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
