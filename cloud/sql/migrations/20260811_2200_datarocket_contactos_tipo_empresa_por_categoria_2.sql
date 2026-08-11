-- Marca `tipo = 'empresa'` a todos los contactos que tengan al menos una de
-- las etiquetas de categoria organizacional (segunda tanda):
-- `estudio_juridico`, `estudio_contable`, `barrio_privado`, `diputado`,
-- `seguridad_fisica`, `municipalidad`. Son rubros que corresponden a
-- organizaciones (o a estructuras institucionales tratadas como tales por
-- el CRM), asi que la asignacion al tipo empresa es semanticamente correcta
-- y se puede automatizar.
--
-- Contexto: cuarta migracion de la serie que completa tipos por etiqueta.
-- Ver tambien:
--   * 20260811_1900 → persona (vigicom_usuario, vigia_usuario, reactor_usuario)
--   * 20260811_2000 → empresa (expositor)
--   * 20260811_2100 → empresa (inmobiliaria, financiera, proveedor_internet,
--                              municipalidad, club, consorcio)
-- Nota: `municipalidad` reaparece intencionalmente en esta lista para
-- tolerar que se corra esta migracion sin la 2100; el `WHERE tipo IS NULL`
-- garantiza que las filas ya marcadas antes no se vuelvan a tocar. Mismo
-- patron de las anteriores: UPDATE ... WHERE EXISTS contra la puente,
-- JOIN por `nombre` (UNIQUE), idempotente.

UPDATE `datarocket_contactos` dc
   SET dc.`tipo` = 'empresa'
 WHERE dc.`tipo` IS NULL
   AND EXISTS (
     SELECT 1
       FROM `datarocket_contactos_etiquetas` dce
       JOIN `datarocket_etiquetas` de ON de.`id` = dce.`etiqueta_id`
      WHERE dce.`contacto_id` = dc.`id`
        AND de.`nombre` IN (
              'estudio_juridico',
              'estudio_contable',
              'barrio_privado',
              'diputado',
              'seguridad_fisica',
              'municipalidad'
            )
   );
