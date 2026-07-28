-- movistar_sims: marcar en_uso='no' a todas las SIMs con estado='INACTIVE_NEW'.
--
-- Motivo:
--   Una SIM en estado INACTIVE_NEW es una SIM recien provisionada por Movistar
--   que todavia no fue activada / asignada a un equipo — por definicion no esta
--   en uso. Se normaliza el flag `en_uso` para que refleje esa realidad y no
--   quede como 'si' o NULL por arrastre de sincronizaciones previas.

UPDATE `movistar_sims`
   SET `en_uso` = 'no'
 WHERE `estado` = 'INACTIVE_NEW'
   AND (`en_uso` IS NULL OR `en_uso` <> 'no');
