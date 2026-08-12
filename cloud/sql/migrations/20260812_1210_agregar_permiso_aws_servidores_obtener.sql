-- Agrega el permiso cloud `plataformas.aws.servidores.obtener`, requerido por
-- el nuevo endpoint `api/awsservidores_obtener.php` (boton "Obtener" del ABM
-- de servidores AWS, que recorre todas las cuentas y regiones y upserta cada
-- EC2 en la tabla `aws_servidores`).
--
-- Se separa del atajo `requirePermCrud` (que solo mapea consultar/agregar/
-- editar/eliminar) porque "obtener" es una accion de sincronizacion masiva
-- distinta al alta manual — un usuario puede tener permiso para editar el
-- catalogo pero no para dispararsync que traiga cientos de instancias.
--
-- Reprograma tambien `desarrollador.permisos` con TODOS los permisos cloud
-- del env actual, igual que las migraciones previas de permisos.
--
-- Idempotente en los 2 pasos.

-- ============================================================================
-- Paso 1: catalogo de permisos.
-- ============================================================================

INSERT INTO `permisos` (`slug`, `nombre`)
SELECT * FROM (SELECT
  'plataformas.aws.servidores.obtener' AS slug,
  'Plataformas > AWS > Servidores > Obtener (traer EC2 de AWS)' AS nombre
) t
WHERE NOT EXISTS (
  SELECT 1 FROM `permisos` p WHERE p.slug = t.slug
);

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
