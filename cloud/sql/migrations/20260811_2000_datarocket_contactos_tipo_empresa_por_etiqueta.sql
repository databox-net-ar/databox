-- Marca `tipo = 'empresa'` a todos los contactos que tengan la etiqueta
-- `expositor`. Los expositores del CRM son organizaciones que participan de
-- eventos/ferias — no personas fisicas — asi que la asignacion al tipo
-- empresa es semanticamente correcta y se puede automatizar.
--
-- Contexto: hermana de 20260811_1900, que asigno `tipo = 'persona'` a los
-- contactos con etiquetas `vigicom_usuario` / `vigia_usuario` /
-- `reactor_usuario`. Se sigue el mismo patron: `UPDATE ... WHERE EXISTS`
-- contra la tabla puente `datarocket_contactos_etiquetas` (fuente de verdad
-- desde 20260811_1600), joineada con `datarocket_etiquetas.nombre` (UNIQUE)
-- para resolver ids sin hardcodear.
--
-- Filtramos por `tipo IS NULL` para no pisar asignaciones manuales ni las
-- que hizo la 1900 (los `vigicom_usuario`, etc. son personas fisicas, no
-- deberian tener tambien `expositor`, pero por si acaso). Idempotente:
-- correrla dos veces no cambia nada tras la primera pasada.

UPDATE `datarocket_contactos` dc
   SET dc.`tipo` = 'empresa'
 WHERE dc.`tipo` IS NULL
   AND EXISTS (
     SELECT 1
       FROM `datarocket_contactos_etiquetas` dce
       JOIN `datarocket_etiquetas` de ON de.`id` = dce.`etiqueta_id`
      WHERE dce.`contacto_id` = dc.`id`
        AND de.`nombre` = 'expositor'
   );
