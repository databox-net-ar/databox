-- Agrega los 4 permisos cloud del ABM `datacount.pagos.*` (consultar, agregar,
-- editar, eliminar) al catalogo. Habilita la tarjeta "Pagos" dentro del panel
-- Sistemas > Datacount (facturas recibidas y demas documentos digitalizados,
-- tabla `datacountpagos`). No estaban en el seed original
-- (20260711_1300_crear_permisos_cloud.sql) porque el modulo se sumo despues.
--
-- Reprograma tambien `desarrollador.permisos` con TODOS los permisos cloud del
-- env actual, igual que 20260713_1400, para que los nuevos permisos queden
-- incluidos inmediatamente en el rol.
--
-- Idempotente en los 2 pasos.

-- ============================================================================
-- Paso 1: catalogo de permisos (LEFT JOIN + IS NULL como el seed original).
-- ============================================================================

CREATE TEMPORARY TABLE tmp_permisos_datacount_pagos (
  slug   VARCHAR(100) NOT NULL,
  nombre VARCHAR(255) NOT NULL
) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci;

INSERT INTO tmp_permisos_datacount_pagos (slug, nombre) VALUES
('datacount.pagos.consultar', 'Datacount > Pagos > Consultar'),
('datacount.pagos.agregar',   'Datacount > Pagos > Agregar'),
('datacount.pagos.editar',    'Datacount > Pagos > Editar'),
('datacount.pagos.eliminar',  'Datacount > Pagos > Eliminar');

INSERT INTO `permisos` (`slug`, `nombre`)
SELECT t.slug, t.nombre
FROM tmp_permisos_datacount_pagos t
LEFT JOIN `permisos` p ON p.slug = t.slug
WHERE p.id IS NULL;

DROP TEMPORARY TABLE tmp_permisos_datacount_pagos;

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
