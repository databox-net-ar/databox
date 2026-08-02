-- Elimina el submodulo visual Datacount > Facturacion del cloud.
--
-- Contexto:
--   La vista `#/datacount_facturacion` era solo un scaffold visual (tile en
--   Datacount con icono robot, panel prende/apaga + log estilo terminal).
--   El "motor" que gobernaba nunca se implemento — el endpoint devolvia
--   [] como log placeholder y el parametro `datacount.motor` era un simple
--   flag booleano sin nadie que lo consumiera. La autorizacion real de
--   comprobantes contra AFIP la maneja `datacount.comprobantes.autorizar`
--   (job cloud/jobs/datacount_comprobantes_autorizar.php), que sigue vivo.
--
-- Cambios:
--   Paso 1: eliminar los 2 permisos `datacount.facturacion.*` del catalogo.
--   Paso 2: eliminar la fila `datacount.motor` de `parametros` (si existe).
--           Solo la creaba lazy el endpoint eliminado, no queda quien la lea.
--   Paso 3: reprogramar el rol `desarrollador` con todos los permisos cloud
--           vigentes, para que no queden ids apuntando a filas eliminadas
--           (mismo patron que 20260727_2100_eliminar_datarocket_mensajes.sql).
--
-- Idempotente:
--   * DELETE FROM permisos con WHERE por slug: re-corrida = no-op.
--   * DELETE FROM parametros con WHERE por variable: re-corrida = no-op.
--   * UPDATE del rol `desarrollador` con GROUP_CONCAT: deterministico.

-- ============================================================================
-- Paso 1: eliminar los 2 permisos del submodulo.
-- ============================================================================
DELETE FROM `permisos`
 WHERE `slug` IN (
   'datacount.facturacion.consultar',
   'datacount.facturacion.ejecutar'
 );

-- ============================================================================
-- Paso 2: eliminar el parametro `datacount.motor` (huerfano tras el borrado).
-- ============================================================================
DELETE FROM `parametros` WHERE `variable` = 'datacount.motor';

-- ============================================================================
-- Paso 3: reprogramar `desarrollador` con todos los permisos cloud vigentes.
-- Filtra por slug NOT NULL/NOT '' porque las tablas de permisos y roles se
-- comparten con UIs legacy (donde slug es NULL); ver memoria
-- `roles_permisos_cloud_vs_legacy`.
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
