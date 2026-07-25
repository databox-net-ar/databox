-- Agrega los permisos cloud del nuevo modulo `Sistemas > Datainfra`
-- (Servidores / Bases de Datos / Endpoints). Por ahora solo se seedea
-- `consultar` por cada sub-modulo — alcanza para que la landing y las
-- tarjetas sean visibles. Cuando cada ABM se implemente en serio se
-- agregaran los permisos `agregar/editar/eliminar` en migraciones
-- posteriores.
--
-- Reprograma tambien `desarrollador.permisos` con TODOS los permisos
-- cloud del env actual, igual que las migraciones previas de permisos,
-- para que los slugs nuevos queden incluidos inmediatamente en el rol.
--
-- Idempotente en los 2 pasos.

-- ============================================================================
-- Paso 1: catalogo de permisos (LEFT JOIN + IS NULL como el seed original).
-- ============================================================================

CREATE TEMPORARY TABLE tmp_permisos_datainfra (
  slug   VARCHAR(100) NOT NULL,
  nombre VARCHAR(255) NOT NULL
) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci;

INSERT INTO tmp_permisos_datainfra (slug, nombre) VALUES
('datainfra.servidores.consultar',   'Datainfra > Servidores > Consultar'),
('datainfra.bases_datos.consultar',  'Datainfra > Bases de Datos > Consultar'),
('datainfra.endpoints.consultar',    'Datainfra > Endpoints > Consultar');

INSERT INTO `permisos` (`slug`, `nombre`)
SELECT t.slug, t.nombre
FROM tmp_permisos_datainfra t
LEFT JOIN `permisos` p ON p.slug = t.slug
WHERE p.id IS NULL;

DROP TEMPORARY TABLE tmp_permisos_datainfra;

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
