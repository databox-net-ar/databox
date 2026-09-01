-- Agrega el permiso `administracion.documentacion.consultar`.
--
-- Da de alta el modulo Administracion > Documentacion: el navegador de
-- servicios y documentacion del panel (`cloud/api/documentacion.php` +
-- la ruta `#/documentacion` de app.js). Es la segunda entrada del grupo
-- Administracion del sidebar, debajo de Herramientas.
--
-- Un solo permiso y no cuatro: el modulo es de SOLO LECTURA. Los `.md` se
-- editan en el repo y llegan por el deploy; no hay alta, edicion ni baja por
-- ninguna via del panel, asi que `agregar` / `editar` / `eliminar` serian
-- slugs que nada consulta — y un permiso que no se chequea es peor que no
-- tenerlo: aparenta un control que no existe.
--
-- Nomenclatura: `administracion.<modulo>.<accion>`, igual que
-- `administracion.herramientas.consultar`. Notar que Documentacion cuelga
-- directo de `administracion.` y no de `administracion.herramientas.`: no es
-- una herramienta dentro de la grilla de Herramientas sino un modulo hermano
-- con su propia entrada de sidebar, y el guard de la ruta lo pide como slug
-- exacto (ROUTE_PERMS en app.js).
--
-- Pasos:
--   1. Alta del slug en el catalogo `permisos`.
--   2. Backfill: todo rol cloud que hoy tenga `administracion.herramientas.consultar`
--      hereda el permiso nuevo. Quien ya entraba a Administracion es a quien
--      le sirve la documentacion de los servicios; hacerlo al reves —dejar el
--      modulo invisible hasta que alguien reparta el permiso a mano— lo
--      convierte en una pantalla que nadie descubre.
--   3. Reprograma `desarrollador.permisos` con TODOS los permisos cloud del
--      env actual, igual que las migraciones previas de permisos.
--
-- Idempotente en los 3 pasos.

-- ============================================================================
-- Paso 1: catalogo de permisos (NOT EXISTS como el resto de los seeds).
-- ============================================================================

INSERT INTO `permisos` (`slug`, `nombre`)
SELECT 'administracion.documentacion.consultar', 'Administracion > Documentacion > Consultar'
  FROM DUAL
 WHERE NOT EXISTS (
   SELECT 1 FROM `permisos` WHERE `slug` = 'administracion.documentacion.consultar'
 );

-- ============================================================================
-- Paso 2: backfill sobre los roles que ya entran a Administracion.
--
-- `roles.permisos` es una CSV de IDs. Solo se tocan los roles cloud
-- (slug NOT NULL) — los legacy usan el formato "(111)(112)" y FIND_IN_SET no
-- les aplica. El guard `FIND_IN_SET(nuevo) = 0` hace la operacion repetible.
-- ============================================================================

UPDATE `roles` r
JOIN `permisos` pe ON pe.slug = 'administracion.herramientas.consultar'
JOIN `permisos` pn ON pn.slug = 'administracion.documentacion.consultar'
SET r.permisos = CONCAT(r.permisos, ',', pn.id)
WHERE r.slug IS NOT NULL AND r.slug <> ''
  AND FIND_IN_SET(pe.id, r.permisos) > 0
  AND FIND_IN_SET(pn.id, r.permisos) = 0;

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
