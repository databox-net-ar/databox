-- Seed de los 9 roles "cloud" del panel + asignacion del rol 'desarrollador'
-- al usuario leonardojavieralvarezrosas@gmail.com.
--
-- Los roles cloud son los que tienen `slug` con valor (los legacy tienen NULL
-- desde 20260711_1200_limpiar_slug_y_descripcion_legacy.sql). Esta migracion
-- lleva a produccion los registros nuevos que hoy solo estan en dev, sin
-- tocar los legacy que siguen sirviendo a la UI vieja.
--
-- Los IDs de roles y permisos son auto-asignados por MySQL/MariaDB en cada
-- env (dev y prod tienen numeraciones distintas). Por eso NO hardcodeamos IDs
-- ni copiamos las CSV de `roles.permisos` verbatim: siempre traducimos por
-- SLUG a los IDs locales del env actual.
--
-- Requisito previo: haber corrido 20260711_1300_crear_permisos_cloud.sql
-- antes (que siembra el catalogo de ~137 permisos con slug). El migrador
-- aplica los archivos en orden alfabetico, asi 1400 va despues de 1300
-- automaticamente al elegir "Aplicar todas las pendientes".
--
-- Idempotente en los 3 pasos:
--   1) INSERT roles con LEFT JOIN ... IS NULL -> no duplica.
--   2) UPDATE permisos siempre pisa con el set actual (si mas adelante se
--      suman permisos cloud, re-correr actualiza `desarrollador.permisos`).
--   3) UPDATE usuarios usa CASE que verifica presencia antes de concatenar.

SET SESSION group_concat_max_len = 65535;

-- ============================================================================
-- Paso 1: crear los 9 roles cloud si no existen (matching por slug).
-- ============================================================================

CREATE TEMPORARY TABLE tmp_roles_cloud (
  slug        VARCHAR(100) NOT NULL,
  nombre      VARCHAR(255) NOT NULL,
  descripcion VARCHAR(255)     NULL
) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci;

INSERT INTO tmp_roles_cloud (slug, nombre, descripcion) VALUES
('desarrollador',                             'Desarrollador',                                  'Acceso completo a todos los modulos del panel cloud.'),
('plataformas.movistar.operador',             'Plataformas > Movistar > Operador',              NULL),
('plataformas.claro.operador',                'Plataformas > Claro > Operador',                 NULL),
('datasale.prospectos.operador',              'Datasale > Prospectos  > Operador',              NULL),
('datacount.plan.de.cuentas.operador',        'Datacount > Plan de Cuentas > Operador',         NULL),
('datacount.movimiento.recurrentes.operador', 'Datacount > Movimiento Recurrentes  > Operador', NULL),
('datacount.empleados.operador',              'Datacount > Empleados  > Operador',              NULL),
('datacount.asientos.operador',               'Datacount > Asientos  > Operador',               NULL),
('datacount.comprobantes.operador',           'Datacount > Comprobantes  > Operador',           NULL);

INSERT INTO `roles` (slug, nombre, descripcion)
SELECT t.slug, t.nombre, t.descripcion
FROM tmp_roles_cloud t
LEFT JOIN `roles` r ON r.slug = t.slug
WHERE r.id IS NULL;

DROP TEMPORARY TABLE tmp_roles_cloud;

-- ============================================================================
-- Paso 2a: poblar `permisos` de los 8 roles "operador" traduciendo cada
-- permiso_slug al permiso_id local del env actual.
-- ============================================================================

CREATE TEMPORARY TABLE tmp_rol_permiso_slugs (
  rol_slug     VARCHAR(100) NOT NULL,
  permiso_slug VARCHAR(100) NOT NULL
) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci;

INSERT INTO tmp_rol_permiso_slugs (rol_slug, permiso_slug) VALUES
-- Plataformas > Movistar > Operador
('plataformas.movistar.operador', 'plataformas.movistar.sims.consultar'),
('plataformas.movistar.operador', 'plataformas.movistar.sims.agregar'),
('plataformas.movistar.operador', 'plataformas.movistar.sims.editar'),
('plataformas.movistar.operador', 'plataformas.movistar.sims.sincronizar'),
-- Plataformas > Claro > Operador
('plataformas.claro.operador',    'plataformas.claro.sims.consultar'),
('plataformas.claro.operador',    'plataformas.claro.sims.agregar'),
('plataformas.claro.operador',    'plataformas.claro.sims.editar'),
-- Datasale > Prospectos > Operador
('datasale.prospectos.operador',  'datasale.prospectos.consultar'),
('datasale.prospectos.operador',  'datasale.prospectos.agregar'),
('datasale.prospectos.operador',  'datasale.prospectos.editar'),
-- Datacount > Plan de Cuentas > Operador
('datacount.plan.de.cuentas.operador', 'datacount.cuentas.consultar'),
('datacount.plan.de.cuentas.operador', 'datacount.cuentas.agregar'),
('datacount.plan.de.cuentas.operador', 'datacount.cuentas.editar'),
-- Datacount > Movimiento Recurrentes > Operador
('datacount.movimiento.recurrentes.operador', 'datacount.recurrentes.consultar'),
('datacount.movimiento.recurrentes.operador', 'datacount.recurrentes.agregar'),
('datacount.movimiento.recurrentes.operador', 'datacount.recurrentes.editar'),
-- Datacount > Empleados > Operador
('datacount.empleados.operador', 'datacount.empleados.consultar'),
('datacount.empleados.operador', 'datacount.empleados.agregar'),
('datacount.empleados.operador', 'datacount.empleados.editar'),
-- Datacount > Asientos > Operador
('datacount.asientos.operador',  'datacount.asientos.consultar'),
('datacount.asientos.operador',  'datacount.asientos.agregar'),
('datacount.asientos.operador',  'datacount.asientos.editar'),
-- Datacount > Comprobantes > Operador
('datacount.comprobantes.operador', 'datacount.comprobantes.consultar'),
('datacount.comprobantes.operador', 'datacount.comprobantes.agregar'),
('datacount.comprobantes.operador', 'datacount.comprobantes.editar');

UPDATE `roles` r
INNER JOIN (
    SELECT trps.rol_slug, GROUP_CONCAT(p.id ORDER BY p.id) AS ids
    FROM tmp_rol_permiso_slugs trps
    INNER JOIN `permisos` p
      ON p.slug = trps.permiso_slug
     AND p.slug IS NOT NULL
     AND p.slug <> ''
    GROUP BY trps.rol_slug
) agg ON agg.rol_slug = r.slug
SET r.permisos = agg.ids;

DROP TEMPORARY TABLE tmp_rol_permiso_slugs;

-- ============================================================================
-- Paso 2b: 'desarrollador' recibe TODOS los permisos cloud del env actual.
-- Se recomputa desde la tabla `permisos`, asi si se agregan mas permisos
-- cloud en el futuro basta con re-correr la migracion para actualizar.
-- ============================================================================

UPDATE `roles` r
CROSS JOIN (
    SELECT GROUP_CONCAT(id ORDER BY id) AS ids
    FROM `permisos`
    WHERE slug IS NOT NULL AND slug <> ''
) p
SET r.permisos = p.ids
WHERE r.slug = 'desarrollador';

-- ============================================================================
-- Paso 3: asignar 'desarrollador' a leonardojavieralvarezrosas@gmail.com.
-- Solo agrega el ID al CSV de `usuarios.roles` si todavia no esta ahi.
-- ============================================================================

SET @rol_id := (SELECT id FROM `roles` WHERE slug = 'desarrollador' LIMIT 1);
SET @correo := 'leonardojavieralvarezrosas@gmail.com';

UPDATE `usuarios`
SET roles = CASE
    WHEN roles IS NULL OR roles = ''
        THEN CAST(@rol_id AS CHAR)
    WHEN CONCAT(',', REPLACE(roles, ' ', ''), ',') LIKE CONCAT('%,', @rol_id, ',%')
        THEN roles
    ELSE CONCAT(roles, ',', @rol_id)
END
WHERE correo = @correo;
