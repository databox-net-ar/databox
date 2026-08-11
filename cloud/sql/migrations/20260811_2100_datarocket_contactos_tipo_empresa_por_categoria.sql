-- Marca `tipo = 'empresa'` a todos los contactos que tengan al menos una de
-- las etiquetas de categoria organizacional: `inmobiliaria`, `financiera`,
-- `proveedor_internet`, `municipalidad`, `club`, `consorcio`. Son rubros
-- que corresponden a organizaciones (no personas fisicas) — la asignacion
-- al tipo empresa es semanticamente correcta y se puede automatizar.
--
-- Contexto: es la tercera migracion de la serie que completa tipos por
-- etiqueta. Ver tambien:
--   * 20260811_1900 → tipo=persona por `vigicom_usuario`/`vigia_usuario`/`reactor_usuario`
--   * 20260811_2000 → tipo=empresa por `expositor`
-- Mismo patron: UPDATE ... WHERE EXISTS contra la puente
-- `datarocket_contactos_etiquetas` (fuente de verdad desde 20260811_1600),
-- JOIN con `datarocket_etiquetas.nombre` (UNIQUE) para resolver ids sin
-- hardcodear valores del catalogo.
--
-- Filtramos por `tipo IS NULL` para no pisar asignaciones previas (manuales
-- o de las migraciones 1900/2000). Idempotente: correrla dos veces no
-- cambia nada tras la primera pasada.

UPDATE `datarocket_contactos` dc
   SET dc.`tipo` = 'empresa'
 WHERE dc.`tipo` IS NULL
   AND EXISTS (
     SELECT 1
       FROM `datarocket_contactos_etiquetas` dce
       JOIN `datarocket_etiquetas` de ON de.`id` = dce.`etiqueta_id`
      WHERE dce.`contacto_id` = dc.`id`
        AND de.`nombre` IN (
              'inmobiliaria',
              'financiera',
              'proveedor_internet',
              'municipalidad',
              'club',
              'consorcio'
            )
   );
