-- Agrega el valor transitorio 'enviando' al catalogo `estados` para los
-- campos `aws_mensaje_estado` y `evolution_mensaje_estado`. Lo usa el job
-- `datarocket_mensajes_enviar` como lock optimista: antes de despachar
-- un mensaje se hace UPDATE ... SET estado='enviando' WHERE estado='pendiente';
-- si rowCount() == 0 el mensaje ya lo tomo otra corrida y se saltea.
--
-- Se posiciona con `orden = 5` (despues de Error) porque es un estado
-- transitorio que no deberia mostrarse mucho en la UI del ABM.
--
-- Idempotente: INSERT ... WHERE NOT EXISTS. La tabla `estados` no tiene
-- UNIQUE por diseno (se comparte con apps del grupo). Patron compatible
-- con MySQL 8 y MariaDB 10.11.

INSERT INTO `estados` (`campo`, `texto`, `valor`, `orden`)
SELECT 'aws_mensaje_estado', 'Enviando', 'enviando', 5
 WHERE NOT EXISTS (SELECT 1 FROM `estados`
   WHERE `campo` = 'aws_mensaje_estado' AND `valor` = 'enviando');

INSERT INTO `estados` (`campo`, `texto`, `valor`, `orden`)
SELECT 'evolution_mensaje_estado', 'Enviando', 'enviando', 5
 WHERE NOT EXISTS (SELECT 1 FROM `estados`
   WHERE `campo` = 'evolution_mensaje_estado' AND `valor` = 'enviando');
