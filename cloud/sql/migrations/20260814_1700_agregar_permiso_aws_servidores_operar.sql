-- Agrega el permiso cloud `plataformas.aws.servidores.operar`, requerido por
-- el nuevo endpoint `api/awsservidores_accion.php` (opciones "Encender",
-- "Apagar" y "Reiniciar" del menu contextual de cada fila del ABM de
-- servidores AWS, que disparan StartInstances / StopInstances /
-- RebootInstances contra la instancia EC2).
--
-- Se separa del atajo `requirePermCrud` (que solo mapea consultar/agregar/
-- editar/eliminar) porque operar prende y apaga infraestructura viva, no el
-- catalogo: un usuario puede tener permiso para editar la ficha del servidor
-- (credenciales SSH, notas) sin poder apagarlo. Un unico permiso cubre las
-- tres acciones — quien puede apagar un server tambien puede encenderlo y
-- reiniciarlo, no tiene sentido separarlas.
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
  'plataformas.aws.servidores.operar' AS slug,
  'Plataformas > AWS > Servidores > Operar (encender/apagar/reiniciar EC2)' AS nombre
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
