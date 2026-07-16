-- Agrega los 8 permisos cloud de los ABM `datacount.clientes.*` y
-- `datacount.proveedores.*` (consultar / agregar / editar / eliminar
-- para cada uno). Habilitan las nuevas tarjetas "Clientes" y
-- "Proveedores" dentro del panel Sistemas > Datacount y sus endpoints
-- `datacountclientes.php` / `datacountproveedores.php`.
--
-- Reprograma tambien `desarrollador.permisos` con TODOS los permisos
-- cloud del env actual, igual que 20260714_1200, para que los nuevos
-- permisos queden incluidos inmediatamente en el rol.
--
-- Idempotente en los 2 pasos.

-- ============================================================================
-- Paso 1: catalogo de permisos (LEFT JOIN + IS NULL como el seed original).
-- ============================================================================

CREATE TEMPORARY TABLE tmp_permisos_datacount_cliprov (
  slug   VARCHAR(100) NOT NULL,
  nombre VARCHAR(255) NOT NULL
) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci;

INSERT INTO tmp_permisos_datacount_cliprov (slug, nombre) VALUES
('datacount.clientes.consultar',    'Datacount > Clientes > Consultar'),
('datacount.clientes.agregar',      'Datacount > Clientes > Agregar'),
('datacount.clientes.editar',       'Datacount > Clientes > Editar'),
('datacount.clientes.eliminar',     'Datacount > Clientes > Eliminar'),
('datacount.proveedores.consultar', 'Datacount > Proveedores > Consultar'),
('datacount.proveedores.agregar',   'Datacount > Proveedores > Agregar'),
('datacount.proveedores.editar',    'Datacount > Proveedores > Editar'),
('datacount.proveedores.eliminar',  'Datacount > Proveedores > Eliminar');

INSERT INTO `permisos` (`slug`, `nombre`)
SELECT t.slug, t.nombre
FROM tmp_permisos_datacount_cliprov t
LEFT JOIN `permisos` p ON p.slug = t.slug
WHERE p.id IS NULL;

DROP TEMPORARY TABLE tmp_permisos_datacount_cliprov;

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
