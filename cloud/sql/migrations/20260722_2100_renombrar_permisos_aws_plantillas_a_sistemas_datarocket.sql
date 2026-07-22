-- Renombra los 4 permisos cloud del ABM de plantillas para reubicarlos
-- desde "Plataformas > AWS > Plantillas" hacia "Sistemas > Datarocket > Plantillas".
-- Sobre la marcha tambien se renombra el `nombre` humano.
--
--   plataformas.aws.plantillas.consultar -> sistemas.datarocket.plantillas.consultar
--   plataformas.aws.plantillas.agregar   -> sistemas.datarocket.plantillas.agregar
--   plataformas.aws.plantillas.editar    -> sistemas.datarocket.plantillas.editar
--   plataformas.aws.plantillas.eliminar  -> sistemas.datarocket.plantillas.eliminar
--
-- Los permisos siguen siendo los mismos ids en la tabla `permisos` (solo se
-- actualizan `slug` y `nombre`), por lo que los CSVs de `roles.permisos` que
-- ya los referencian quedan intactos y no hace falta reprocesarlos.
--
-- Reprograma igual `desarrollador.permisos` con TODOS los permisos cloud del
-- env actual, igual que las migraciones previas, para dejar el rol coherente.
--
-- Idempotente:
--   * Los UPDATE por slug afectan 0 filas si ya se corrio (el slug destino ya
--     ganó el lugar del origen).
--   * El seed de `desarrollador` es siempre "todos".

UPDATE `permisos`
   SET `slug`   = 'sistemas.datarocket.plantillas.consultar',
       `nombre` = 'Sistemas > Datarocket > Plantillas > Consultar'
 WHERE `slug` = 'plataformas.aws.plantillas.consultar';

UPDATE `permisos`
   SET `slug`   = 'sistemas.datarocket.plantillas.agregar',
       `nombre` = 'Sistemas > Datarocket > Plantillas > Agregar'
 WHERE `slug` = 'plataformas.aws.plantillas.agregar';

UPDATE `permisos`
   SET `slug`   = 'sistemas.datarocket.plantillas.editar',
       `nombre` = 'Sistemas > Datarocket > Plantillas > Editar'
 WHERE `slug` = 'plataformas.aws.plantillas.editar';

UPDATE `permisos`
   SET `slug`   = 'sistemas.datarocket.plantillas.eliminar',
       `nombre` = 'Sistemas > Datarocket > Plantillas > Eliminar'
 WHERE `slug` = 'plataformas.aws.plantillas.eliminar';

-- ============================================================================
-- `desarrollador` = todos los permisos cloud del env actual.
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
