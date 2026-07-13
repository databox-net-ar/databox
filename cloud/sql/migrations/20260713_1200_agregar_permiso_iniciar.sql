-- Agrega el permiso cloud `seguridad.usuarios.iniciar` al catalogo. Habilita la
-- opcion "Iniciar" del menu contextual del ABM de usuarios, que genera un
-- magic link ad-hoc para abrir la sesion del usuario destino en una ventana
-- de incognito (impersonacion asistida).
--
-- Reprograma tambien `desarrollador.permisos` con TODOS los permisos cloud del
-- env actual, siguiendo el mismo patron de Paso 2b de
-- 20260711_1400_crear_roles_cloud_y_asignar_leonardo.sql, asi el nuevo permiso
-- queda incluido inmediatamente (y de paso repara el olvido de 1600 con
-- `invitar`).
--
-- Idempotente en los 2 pasos.

-- ============================================================================
-- Paso 1: catalogo de permisos (LEFT JOIN + IS NULL como 1600).
-- ============================================================================

INSERT INTO `permisos` (`slug`, `nombre`)
SELECT * FROM (SELECT 'seguridad.usuarios.iniciar' AS slug, 'Seguridad > Usuarios > Iniciar' AS nombre) AS t
WHERE NOT EXISTS (SELECT 1 FROM `permisos` WHERE `slug` = 'seguridad.usuarios.iniciar');

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
