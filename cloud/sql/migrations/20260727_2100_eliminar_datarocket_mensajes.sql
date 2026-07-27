-- Elimina el submodulo Datarocket > Mensajes del cloud:
--   * DROP TABLE `datarocket_mensajes` (la nueva, snake_case).
--   * DELETE de los 4 permisos `datarocket.mensajes.*` del catalogo `permisos`.
--   * Reprograma el rol `desarrollador` con TODOS los permisos cloud vigentes
--     (mismo patron que las migraciones previas de permisos), para que el rol
--     no quede con ids apuntando a filas eliminadas.
--
-- Se conserva a proposito la tabla legacy `datarocketmensajes` (sin guion
-- bajo): la migracion `20260722_2000_clonar_a_datarocket_mensajes.sql` la
-- dejo en la base "hasta confirmar que ningun otro proyecto del grupo la
-- consulta" — esa condicion todavia no se verifico, asi que el drop legacy
-- queda para otra iteracion.
--
-- Idempotente en los 3 pasos:
--   * DROP TABLE IF EXISTS envuelto en PREPARE/EXECUTE para no fallar si ya
--     no existe (soporta tanto MySQL 8.0 como MariaDB 10.11).
--   * DELETE FROM permisos con WHERE por slug: re-corrida = no-op.
--   * UPDATE del rol `desarrollador` con GROUP_CONCAT de los ids vigentes:
--     deterministico, re-corrida = mismo resultado.

SET @db := DATABASE();

-- ============================================================================
-- Paso 1: DROP TABLE `datarocket_mensajes` (solo si existe).
-- ============================================================================
SET @sql := (
  SELECT IF(
    (SELECT COUNT(*) FROM information_schema.TABLES
      WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'datarocket_mensajes') > 0,
    'DROP TABLE `datarocket_mensajes`',
    'SELECT 1'
  )
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- ============================================================================
-- Paso 2: eliminar los 4 permisos del submodulo.
-- ============================================================================
DELETE FROM `permisos`
 WHERE `slug` IN (
   'datarocket.mensajes.consultar',
   'datarocket.mensajes.agregar',
   'datarocket.mensajes.editar',
   'datarocket.mensajes.eliminar'
 );

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
