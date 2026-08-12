-- Marca `tipo = 'empresa'` a todos los contactos que tengan la etiqueta
-- `visitante`. En el contexto del CRM Datarocket, "visitante" refiere a
-- las empresas que visitaron ferias/expos (registradas como representantes
-- corporativos, no como personas fisicas), asi que se asignan al tipo
-- empresa.
--
-- Contexto: quinta migracion de la serie que completa tipos por etiqueta.
-- Ver tambien:
--   * 20260811_1900 → persona (vigicom_usuario, vigia_usuario, reactor_usuario)
--   * 20260811_2000 → empresa (expositor)
--   * 20260811_2100 → empresa (inmobiliaria, financiera, proveedor_internet,
--                              municipalidad, club, consorcio)
--   * 20260811_2200 → empresa (estudio_juridico, estudio_contable,
--                              barrio_privado, diputado, seguridad_fisica,
--                              municipalidad)
-- Mismo patron: UPDATE ... WHERE EXISTS contra la puente
-- `datarocket_contactos_etiquetas` (fuente de verdad desde 20260811_1600),
-- JOIN con `datarocket_etiquetas.nombre` (UNIQUE). `WHERE tipo IS NULL`
-- protege asignaciones previas. Idempotente.

UPDATE `datarocket_contactos` dc
   SET dc.`tipo` = 'empresa'
 WHERE dc.`tipo` IS NULL
   AND EXISTS (
     SELECT 1
       FROM `datarocket_contactos_etiquetas` dce
       JOIN `datarocket_etiquetas` de ON de.`id` = dce.`etiqueta_id`
      WHERE dce.`contacto_id` = dc.`id`
        AND de.`nombre` = 'visitante'
   );
