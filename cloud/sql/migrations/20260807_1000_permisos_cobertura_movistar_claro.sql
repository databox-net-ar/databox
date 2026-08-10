-- Agrega los permisos cloud `plataformas.movistar.cobertura.consultar` y
-- `plataformas.claro.cobertura.consultar` que protegen las nuevas vistas
-- `#/movistar_cobertura` y `#/claro_cobertura` — cada una embebe el mapa
-- de antenas de OpenCellID (opencellid.org) como iframe. No hay endpoints
-- propios; la proteccion es solo a nivel de ROUTE_PERMS en el SPA.
--
-- Reprograma tambien `desarrollador.permisos` con TODOS los permisos cloud
-- del env actual, igual que las migraciones previas de permisos, para que
-- los permisos nuevos queden incluidos inmediatamente en el rol.
--
-- Idempotente en los 2 pasos.

-- ============================================================================
-- Paso 1: catalogo de permisos (LEFT JOIN + IS NULL como el seed original).
-- ============================================================================

CREATE TEMPORARY TABLE tmp_permisos_cobertura (
  slug   VARCHAR(100) NOT NULL,
  nombre VARCHAR(255) NOT NULL
) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci;

INSERT INTO tmp_permisos_cobertura (slug, nombre) VALUES
('plataformas.movistar.cobertura.consultar', 'Plataformas > Movistar > Zona de cobertura > Consultar'),
('plataformas.claro.cobertura.consultar',    'Plataformas > Claro > Zona de cobertura > Consultar');

INSERT INTO `permisos` (`slug`, `nombre`)
SELECT t.slug, t.nombre
FROM tmp_permisos_cobertura t
LEFT JOIN `permisos` p ON p.slug = t.slug
WHERE p.id IS NULL;

DROP TEMPORARY TABLE tmp_permisos_cobertura;

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
