-- Elimina la tabla `aws_contactos` (clonada desde `datarocketcontactos` en la
-- migracion 20260722_1500) y los 4 permisos cloud del ABM que la exponia
-- (`plataformas.aws.contactos.consultar` / .agregar / .editar / .eliminar).
--
-- Motivacion: el modulo "AWS > Contactos" se descarto, la tabla nunca llego
-- a alimentar un flujo propio (era una copia paralela de datarocketcontactos).
-- Los datos de contactos siguen viviendo en `datarocketcontactos` intacto.
--
-- Idempotente:
--   * DROP TABLE IF EXISTS -> se puede re-correr sin error.
--   * DELETE FROM permisos WHERE slug IN (...) -> borra solo lo que exista.
--   * Reprograma `desarrollador.permisos` con TODOS los permisos cloud del
--     env actual (mismo patron que las migraciones previas de permisos),
--     asi el rol queda con la lista limpia (sin ids de permisos borrados).
-- Compatible con MySQL 8 y MariaDB 10.11.

-- ============================================================================
-- Paso 1: drop de la tabla.
-- ============================================================================
DROP TABLE IF EXISTS `aws_contactos`;

-- ============================================================================
-- Paso 2: borrar los 4 permisos del ABM aws.contactos.
-- ============================================================================
DELETE FROM `permisos`
 WHERE slug IN (
   'plataformas.aws.contactos.consultar',
   'plataformas.aws.contactos.agregar',
   'plataformas.aws.contactos.editar',
   'plataformas.aws.contactos.eliminar'
 );

-- ============================================================================
-- Paso 3: `desarrollador` = todos los permisos cloud del env actual (limpia
-- los ids ya borrados del CSV `roles.permisos`).
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
