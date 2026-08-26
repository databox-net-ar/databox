-- Agrega los permisos `datacount.asientos.agregar_adjunto` y
-- `datacount.asientos.quitar_adjunto`.
--
-- Hasta ahora `api/datacount_asientos_adjuntos.php` pedia
-- `datacount.asientos.editar` para los dos verbos (POST de subida y DELETE de
-- borrado), reusando el permiso del recurso padre. La ventana de Consultar
-- asiento mostraba en consecuencia el boton "+ Subir archivo" y el FAB de
-- papelera a cualquiera que pudiera editar asientos.
--
-- Ahora cada accion sobre los adjuntos tiene su propio permiso, asi se puede
-- dar de alta un perfil que consulte/edite asientos pero no toque la
-- documentacion respaldatoria (y viceversa).
--
-- Nomenclatura: se sigue la de los permisos ya existentes de Asientos
-- (`datacount.asientos.<accion>`), con la accion en snake_case cuando lleva
-- mas de una palabra — mismo criterio que
-- `datacount.comprobantes.autorizar_manual` o
-- `administracion.herramientas.explorador_s3.crear_carpeta`.
--
-- Pasos:
--   1. Alta de los 2 slugs en el catalogo `permisos`.
--   2. Backfill: todo rol cloud que hoy tenga `datacount.asientos.editar`
--      hereda los 2 permisos nuevos, para que la migracion no le saque
--      capacidades a nadie (antes editar habilitaba ambos botones).
--   3. Reprograma `desarrollador.permisos` con TODOS los permisos cloud del
--      env actual, igual que las migraciones previas de permisos.
--
-- Idempotente en los 3 pasos.

-- ============================================================================
-- Paso 1: catalogo de permisos (NOT EXISTS como el resto de los seeds).
-- ============================================================================

INSERT INTO `permisos` (`slug`, `nombre`)
SELECT 'datacount.asientos.agregar_adjunto', 'Datacount > Asientos > Agregar adjunto'
  FROM DUAL
 WHERE NOT EXISTS (
   SELECT 1 FROM `permisos` WHERE `slug` = 'datacount.asientos.agregar_adjunto'
 );

INSERT INTO `permisos` (`slug`, `nombre`)
SELECT 'datacount.asientos.quitar_adjunto', 'Datacount > Asientos > Quitar adjunto'
  FROM DUAL
 WHERE NOT EXISTS (
   SELECT 1 FROM `permisos` WHERE `slug` = 'datacount.asientos.quitar_adjunto'
 );

-- ============================================================================
-- Paso 2: backfill sobre los roles que ya tenian `datacount.asientos.editar`.
--
-- `roles.permisos` es una CSV de IDs. Solo se tocan los roles cloud
-- (slug NOT NULL) — los legacy usan el formato "(111)(112)" y FIND_IN_SET no
-- les aplica. El guard `FIND_IN_SET(nuevo) = 0` hace la operacion repetible.
-- ============================================================================

UPDATE `roles` r
JOIN `permisos` pe ON pe.slug = 'datacount.asientos.editar'
JOIN `permisos` pn ON pn.slug = 'datacount.asientos.agregar_adjunto'
SET r.permisos = CONCAT(r.permisos, ',', pn.id)
WHERE r.slug IS NOT NULL AND r.slug <> ''
  AND FIND_IN_SET(pe.id, r.permisos) > 0
  AND FIND_IN_SET(pn.id, r.permisos) = 0;

UPDATE `roles` r
JOIN `permisos` pe ON pe.slug = 'datacount.asientos.editar'
JOIN `permisos` pn ON pn.slug = 'datacount.asientos.quitar_adjunto'
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
