-- Agrega los 4 permisos CRUD del nuevo ABM `datarocket.etiquetas.*`. La tabla
-- destino `datarocket_etiquetas` se crea en la migracion `20260811_1200_...`.
--
-- Los nombres de los permisos siguen el patron "Datarocket > Etiquetas > <verbo>",
-- consistente con los otros modulos Datarocket (Contactos, Plantillas,
-- Interacciones). El helper `requirePermCrud('datarocket.etiquetas')` matchea
-- con los sufijos consultar / agregar / editar / eliminar (ver memoria
-- feedback_permcrud_verbos: no usar `.crear`).
--
-- Reprograma tambien `desarrollador.permisos` con TODOS los permisos cloud
-- del env actual, igual que las migraciones previas de permisos, para que los
-- permisos nuevos queden incluidos inmediatamente en el rol.
--
-- Idempotente en los 2 pasos.

-- ============================================================================
-- Paso 1: catalogo de permisos (LEFT JOIN + IS NULL como el seed original).
-- ============================================================================

CREATE TEMPORARY TABLE tmp_permisos_dr_etiquetas (
  slug   VARCHAR(100) NOT NULL,
  nombre VARCHAR(255) NOT NULL
) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci;

INSERT INTO tmp_permisos_dr_etiquetas (slug, nombre) VALUES
('datarocket.etiquetas.consultar', 'Datarocket > Etiquetas > Consultar'),
('datarocket.etiquetas.agregar',   'Datarocket > Etiquetas > Agregar'),
('datarocket.etiquetas.editar',    'Datarocket > Etiquetas > Editar'),
('datarocket.etiquetas.eliminar',  'Datarocket > Etiquetas > Eliminar');

INSERT INTO `permisos` (`slug`, `nombre`)
SELECT t.slug, t.nombre
FROM tmp_permisos_dr_etiquetas t
LEFT JOIN `permisos` p ON p.slug = t.slug
WHERE p.id IS NULL;

DROP TEMPORARY TABLE tmp_permisos_dr_etiquetas;

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
