-- Renombra los 4 permisos del modulo Dominios de `datarocket.dominios.*`
-- a `datainfra.dominios.*` (ahora vive bajo Sistemas > Datainfra), y sus
-- nombres visibles de "Datarocket > Dominios > *" a "Datainfra > Dominios > *".
--
-- Reprograma tambien `desarrollador.permisos` con TODOS los permisos cloud
-- del env actual, igual que las migraciones previas de permisos, para que
-- los slugs actualizados queden reflejados en el rol de inmediato (la
-- reasignacion es por id, asi que en la practica no cambia el listado; el
-- UPDATE queda como garantia de consistencia).
--
-- Idempotente en los 2 pasos:
--   - Paso 1: solo hace UPDATE cuando el slug/nombre viejo todavia existe;
--             si el catalogo ya esta migrado, es no-op.
--   - Paso 2: el reagrupamiento por GROUP_CONCAT(id) siempre produce el
--             mismo resultado deterministicamente.

-- ============================================================================
-- Paso 1: renombrar los 4 slugs + nombres visibles.
-- ============================================================================

UPDATE `permisos`
   SET `slug`   = 'datainfra.dominios.consultar',
       `nombre` = 'Datainfra > Dominios > Consultar'
 WHERE `slug`   = 'datarocket.dominios.consultar';

UPDATE `permisos`
   SET `slug`   = 'datainfra.dominios.agregar',
       `nombre` = 'Datainfra > Dominios > Agregar'
 WHERE `slug`   = 'datarocket.dominios.agregar';

UPDATE `permisos`
   SET `slug`   = 'datainfra.dominios.editar',
       `nombre` = 'Datainfra > Dominios > Editar'
 WHERE `slug`   = 'datarocket.dominios.editar';

UPDATE `permisos`
   SET `slug`   = 'datainfra.dominios.eliminar',
       `nombre` = 'Datainfra > Dominios > Eliminar'
 WHERE `slug`   = 'datarocket.dominios.eliminar';

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
