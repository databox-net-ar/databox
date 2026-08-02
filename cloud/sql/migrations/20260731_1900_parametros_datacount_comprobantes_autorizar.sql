-- Seed del parametro `datacount.comprobantes.autorizar` que gobierna el motor
-- de autorizacion automatica de comprobantes AFIP (job
-- cloud/jobs/datacount_comprobantes_autorizar.php).
--
-- Semantica (booleana; NO es el tri-estado del motor de mensajes Evolution):
--   '1' = HABILITADO (default). El cron procesa los pendientes normalmente.
--   cualquier otro valor = DETENIDO. El cron se saltea la corrida y no
--                          vuelve a autorizar hasta que alguien restablezca
--                          el valor a '1' desde el ABM de Parametros.
--
-- El job tambien invoca `parametroAsegurar()` como fallback, asi que si esta
-- migracion nunca corre la fila se crea igual en el primer tick del cron.
-- La migracion sirve para que el flag aparezca en el ABM antes del primer
-- disparo, y para refrescar el comentario si cambia la semantica.
--
-- Idempotente: INSERT protegido con WHERE NOT EXISTS. Compatible con
-- MySQL 8 (dev) y MariaDB 10.11 (prod).

INSERT INTO `parametros` (`variable`, `valor`, `comentario`)
SELECT 'datacount.comprobantes.autorizar', '1',
       'Motor de autorizacion automatica de comprobantes AFIP. Valor 1 = habilitado; cualquier otro valor = detenido (pausa manual). Ver cloud/jobs/datacount_comprobantes_autorizar.php.'
 WHERE NOT EXISTS (SELECT 1 FROM `parametros` WHERE `variable` = 'datacount.comprobantes.autorizar');

-- Refrescar el comentario aunque la fila ya existiera (documenta la
-- semantica actual en el ABM de Parametros).
UPDATE `parametros`
   SET `comentario` = 'Motor de autorizacion automatica de comprobantes AFIP. Valor 1 = habilitado; cualquier otro valor = detenido (pausa manual). Ver cloud/jobs/datacount_comprobantes_autorizar.php.'
 WHERE `variable` = 'datacount.comprobantes.autorizar';
