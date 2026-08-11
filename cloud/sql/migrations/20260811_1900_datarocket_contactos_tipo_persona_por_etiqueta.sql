-- Marca `tipo = 'persona'` a todos los contactos que tengan al menos una de
-- las etiquetas `vigicom_usuario`, `vigia_usuario` o `reactor_usuario`. Son
-- tags que provienen del uso de las apps del grupo por parte de personas
-- fisicas (no de organizaciones), asi que su asignacion al tipo persona es
-- semanticamente correcta y se puede automatizar.
--
-- Contexto: la columna `datarocket_contactos.tipo` fue agregada en
-- 20260811_1800 con valor por defecto NULL para las filas historicas. El
-- usuario planea completar el tipo de forma manual editando cada contacto,
-- pero este subset (~16k contactos con estas etiquetas) tiene una asignacion
-- obvia y conviene hacerla masivamente aca en vez de una por una.
--
-- La query usa la tabla puente `datarocket_contactos_etiquetas` (fuente de
-- verdad desde 20260811_1600) — NO el campo legacy `tags` (dropeado en
-- 20260811_1700). El JOIN contra `datarocket_etiquetas.nombre` (UNIQUE)
-- resuelve los ids sin hardcodear valores del catalogo.
--
-- Filtramos por `tipo IS NULL` para no pisar asignaciones manuales que
-- pudieran existir. Idempotente: correrla dos veces no cambia nada despues
-- de la primera pasada.

UPDATE `datarocket_contactos` dc
   SET dc.`tipo` = 'persona'
 WHERE dc.`tipo` IS NULL
   AND EXISTS (
     SELECT 1
       FROM `datarocket_contactos_etiquetas` dce
       JOIN `datarocket_etiquetas` de ON de.`id` = dce.`etiqueta_id`
      WHERE dce.`contacto_id` = dc.`id`
        AND de.`nombre` IN ('vigicom_usuario', 'vigia_usuario', 'reactor_usuario')
   );
