-- Agrega el permiso cloud `plataformas.claro.sims.sincronizar` que protege
-- el nuevo endpoint `api/clarosims_sync.php` cuando se dispara desde una
-- sesion de panel (el path openclaw usa el bearer token del .env, no este
-- permiso). Se mantiene el mismo verbo que en Movistar para no divergir la
-- convencion de slugs.
--
-- Reprograma tambien `desarrollador.permisos` con TODOS los permisos cloud
-- del env actual, igual que las migraciones previas de permisos, para que
-- el permiso nuevo quede incluido inmediatamente en el rol.
--
-- Idempotente en los 2 pasos.

-- ============================================================================
-- Paso 1: catalogo de permisos (LEFT JOIN + IS NULL como el seed original).
-- ============================================================================

CREATE TEMPORARY TABLE tmp_permisos_claro_sims_sincronizar (
  slug   VARCHAR(100) NOT NULL,
  nombre VARCHAR(255) NOT NULL
) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci;

INSERT INTO tmp_permisos_claro_sims_sincronizar (slug, nombre) VALUES
('plataformas.claro.sims.sincronizar', 'Plataformas > Claro > SIMs > Sincronizar');

INSERT INTO `permisos` (`slug`, `nombre`)
SELECT t.slug, t.nombre
FROM tmp_permisos_claro_sims_sincronizar t
LEFT JOIN `permisos` p ON p.slug = t.slug
WHERE p.id IS NULL;

DROP TEMPORARY TABLE tmp_permisos_claro_sims_sincronizar;

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
