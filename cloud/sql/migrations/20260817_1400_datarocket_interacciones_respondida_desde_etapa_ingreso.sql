-- Backfill de `datarocket_interacciones.respondida` desde
-- `datarocket_prospectos.etapa_ingreso`.
--
-- La columna `respondida` se agrego vacia en la 20260817_1300, con la idea de
-- ir marcando a mano. Este backfill la carga con la fecha en que el prospecto
-- entro a su etapa actual, que es el dato mas cercano que hay a "cuando se
-- atendio esta consulta".
--
-- El cruce es directo: cada interaccion entrante tiene `prospecto_id`, y cada
-- prospecto tiene `etapa_ingreso`. Medido antes de escribir esto:
--
--                            prod   dev
--   entrantes                 651   643
--   con prospecto             651   643
--   con etapa_ingreso         651   643
--   etapa_ingreso >= fecha    651   643   (ninguna daria demora negativa)
--
-- ---------------------------------------------------------------------------
-- QUE SIGNIFICA LA DEMORA QUE VA A QUEDAR
-- ---------------------------------------------------------------------------
-- Conviene tenerlo presente al leer la metrica: en 406 de las 651 entrantes de
-- prod (405 de 643 en dev) `etapa_ingreso` es EXACTAMENTE igual a la fecha de
-- la consulta, asi que van a quedar con demora 0. No es que se hayan
-- contestado al instante: es que el prospecto se creo a partir de esa consulta
-- y nunca cambio de etapa, con lo cual `etapa_ingreso` quedo en el momento del
-- alta. Las 245 restantes si tienen una demora real, la del salto de etapa.
--
-- Dicho de otro modo, esto no mide "cuanto tardamos en responder" sino "cuanto
-- tardo el prospecto en moverse de etapa", que es la mejor aproximacion
-- disponible con los datos que hay. El promedio de la tarjeta va a estar
-- inflado por las consultas viejas que se movieron de etapa meses despues.
--
-- ---------------------------------------------------------------------------
-- GUARDAS
-- ---------------------------------------------------------------------------
--  * Solo `sentido = 'entrante'`: es la unica que espera respuesta, mismo
--    criterio que valida el PUT del ABM.
--  * Solo `respondida IS NULL`: no pisa nada marcado a mano, y hace la
--    migracion idempotente.
--  * Solo `etapa_ingreso >= fecha`: una respuesta anterior a la consulta daria
--    demora negativa. Hoy no hay ninguna en ninguno de los dos entornos, pero
--    la guarda queda por si prod cambia entre esta medicion y el apply.
--
-- Para volver atras, si la metrica no sirve:
--   UPDATE datarocket_interacciones SET respondida = NULL WHERE sentido = 'entrante';
-- (no hay marcas manuales todavia — la 20260817_1300 dejo la columna en cero.)

UPDATE `datarocket_interacciones` `i`
  JOIN `datarocket_prospectos` `p` ON `p`.`id` = `i`.`prospecto_id`
   SET `i`.`respondida` = `p`.`etapa_ingreso`
 WHERE `i`.`sentido` = 'entrante'
   AND `i`.`respondida` IS NULL
   AND `p`.`etapa_ingreso` IS NOT NULL
   AND `p`.`etapa_ingreso` >= `i`.`fecha`;
