-- Renombra el permiso cloud `administracion.herramientas.zona_horaria.consultar`
-- a `administracion.herramientas.sistema.consultar`. La tarjeta "Editar zona
-- horaria" del modulo Herramientas se reemplaza por una tarjeta "Sistema" cuyo
-- modal expone el mismo snapshot de zona horaria en la pestaña "Zona horaria"
-- y ademas suma una pestaña "General" con la version del panel, el entorno y
-- las versiones de PHP y de MySQL/MariaDB.
--
-- Se hace en 3 pasos:
--   1) UPDATE en sitio (renombrar slug + nombre). Preserva el id del permiso
--      asi cualquier fila de `roles.permisos` que lo referencie sigue siendo
--      valida sin tocarla.
--   2) INSERT de fallback para DBs frescas donde el permiso viejo no existia.
--   3) Reprograma `desarrollador.permisos` con TODOS los permisos cloud del
--      env actual, igual que en 20260713_1400.
--
-- Idempotente en los 3 pasos.

-- ============================================================================
-- Paso 1: rename en sitio (preserva id).
-- ============================================================================

UPDATE `permisos`
   SET `slug`   = 'administracion.herramientas.sistema.consultar',
       `nombre` = 'Administracion > Herramientas > Sistema > Consultar'
 WHERE `slug`  = 'administracion.herramientas.zona_horaria.consultar';

-- ============================================================================
-- Paso 2: fallback para DBs donde el permiso viejo no existia.
-- ============================================================================

INSERT INTO `permisos` (`slug`, `nombre`)
SELECT * FROM (SELECT 'administracion.herramientas.sistema.consultar' AS slug,
                       'Administracion > Herramientas > Sistema > Consultar' AS nombre) AS t
WHERE NOT EXISTS (SELECT 1 FROM `permisos`
                   WHERE `slug` = 'administracion.herramientas.sistema.consultar');

-- ============================================================================
-- Paso 3: `desarrollador` = todos los permisos cloud del env actual.
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
