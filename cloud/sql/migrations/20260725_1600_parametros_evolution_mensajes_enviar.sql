-- Siembra el parametro `evolution.mensajes.enviar` (flag wake-on-demand del
-- job cloud/jobs/datarocket_mensajes_enviar.php para la cola de Evolution).
--
-- Semantica:
--   '1' = hay o puede haber mensajes pendientes; el job debe procesar la cola.
--   '0' = cola vacia; el job se saltea la corrida sin consultar evolution_mensajes.
--
-- Quien lo pone en '1':
--   - cloud/api/lib/evolution_mensajes.php::encolarEvolutionMensaje() al insertar
--     un mensaje nuevo (punto unico de entrada, invocado por el ABM y por el
--     microservicio v4 de ingesta).
--   - El propio job si al terminar detecta que aun hay pendientes (self-heal).
--
-- Quien lo baja a '0':
--   - El propio job al terminar la corrida si la cola quedo vacia.
--
-- Se sembra en '1' por defecto para que la primera corrida despues de aplicar
-- la migracion drene lo que hubiera acumulado antes.
--
-- Idempotente: WHERE NOT EXISTS. Patron compatible con MySQL 8 / MariaDB 10.11.

INSERT INTO `parametros` (`variable`, `valor`, `comentario`)
SELECT 'evolution.mensajes.enviar', '1',
       'Flag wake-on-demand para el job cloud/jobs/datarocket_mensajes_enviar.php (cola Evolution). 1 = procesar cola / 0 = saltear corrida.'
 WHERE NOT EXISTS (SELECT 1 FROM `parametros` WHERE `variable` = 'evolution.mensajes.enviar');
