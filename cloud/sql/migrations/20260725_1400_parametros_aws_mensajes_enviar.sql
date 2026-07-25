-- Siembra el parametro `aws.mensajes.enviar` (flag wake-on-demand del job
-- cloud/jobs/aws_mensajes_enviar.php).
--
-- Semantica:
--   '1' = hay o puede haber mensajes pendientes; el job debe procesar la cola.
--   '0' = cola vacia; el job se saltea la corrida sin consultar `aws_mensajes`.
--
-- Quien lo pone en '1':
--   - cloud/api/awsmensajes.php (POST/PUT) cuando encola un mensaje pendiente.
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
SELECT 'aws.mensajes.enviar', '1',
       'Flag wake-on-demand para el job cloud/jobs/aws_mensajes_enviar.php. 1 = procesar cola / 0 = saltear corrida.'
 WHERE NOT EXISTS (SELECT 1 FROM `parametros` WHERE `variable` = 'aws.mensajes.enviar');
