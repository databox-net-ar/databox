-- Agrega los 4 permisos cloud del ABM `datarocket.etapas.*`
-- (consultar / agregar / editar / eliminar). Habilitan la nueva
-- tarjeta "Etapas" dentro del panel Sistemas > Datarocket y su
-- endpoint `api/datarocketetapas.php`.
--
-- Reprograma tambien `desarrollador.permisos` con TODOS los permisos
-- cloud del env actual, igual que las migraciones previas de permisos,
-- para que los nuevos permisos queden incluidos inmediatamente en el rol.
--
-- Idempotente en los 2 pasos.

-- ============================================================================
-- Paso 1: catalogo de permisos (LEFT JOIN + IS NULL como el seed original).
-- ============================================================================

CREATE TEMPORARY TABLE tmp_permisos_datarocket_etapas (
  slug   VARCHAR(100) NOT NULL,
  nombre VARCHAR(255) NOT NULL
) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci;

INSERT INTO tmp_permisos_datarocket_etapas (slug, nombre) VALUES
('datarocket.etapas.consultar', 'Datarocket > Etapas > Consultar'),
('datarocket.etapas.agregar',   'Datarocket > Etapas > Agregar'),
('datarocket.etapas.editar',    'Datarocket > Etapas > Editar'),
('datarocket.etapas.eliminar',  'Datarocket > Etapas > Eliminar');

INSERT INTO `permisos` (`slug`, `nombre`)
SELECT t.slug, t.nombre
FROM tmp_permisos_datarocket_etapas t
LEFT JOIN `permisos` p ON p.slug = t.slug
WHERE p.id IS NULL;

DROP TEMPORARY TABLE tmp_permisos_datarocket_etapas;

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
