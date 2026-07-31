-- Agrega los permisos del ABM de certificados de Arca:
-- `plataformas.arca.certificados.*` (CRUD completo). Espeja el patron del
-- ABM de `telegram_canales` (migracion 20260730_2310): verbos
-- consultar / agregar / editar / eliminar, alineados con requirePermCrud()
-- (POST -> .agregar, PUT -> .editar, DELETE -> .eliminar). NO usar `crear`,
-- no matchea el helper.
--
-- Al final reprograma `desarrollador.permisos` con TODOS los permisos cloud
-- del env actual, igual que las migraciones previas de permisos, para que
-- los slugs nuevos queden incluidos inmediatamente en el rol.
--
-- Idempotente en los 2 pasos.

-- ============================================================================
-- Paso 1: catalogo de permisos (LEFT JOIN + IS NULL como el seed original).
-- ============================================================================

CREATE TEMPORARY TABLE tmp_permisos_arca_cert (
  slug   VARCHAR(100) NOT NULL,
  nombre VARCHAR(255) NOT NULL
) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci;

INSERT INTO tmp_permisos_arca_cert (slug, nombre) VALUES
('plataformas.arca.certificados.consultar', 'Plataformas > Arca > Certificados > Consultar'),
('plataformas.arca.certificados.agregar',   'Plataformas > Arca > Certificados > Agregar'),
('plataformas.arca.certificados.editar',    'Plataformas > Arca > Certificados > Editar'),
('plataformas.arca.certificados.eliminar',  'Plataformas > Arca > Certificados > Eliminar');

INSERT INTO `permisos` (`slug`, `nombre`)
SELECT t.slug, t.nombre
FROM tmp_permisos_arca_cert t
LEFT JOIN `permisos` p ON p.slug = t.slug
WHERE p.id IS NULL;

DROP TEMPORARY TABLE tmp_permisos_arca_cert;

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
