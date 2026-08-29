-- Mueve la base del enlace de la ficha de interaccion a /datarocket/prospecto/.
--
--   https://cloud.databox.net.ar/datarocket/interacciones/   (valor original)
--   https://www.databox.net.ar/datarocket/interacciones/     (valor de la 2400)
--        -> https://www.databox.net.ar/datarocket/prospecto/
--
-- POR QUE EL RENOMBRE
-- -------------------
-- Es la URL que el responsable ve en la barra del navegador al abrir el aviso
-- desde WhatsApp, y lo que la pagina muestra es la ficha de una persona.
-- "Interacciones" es el nombre del modulo del panel, no de lo que esa persona
-- esta mirando en el celular.
--
-- POR QUE ACEPTA DOS VALORES VIEJOS
-- ---------------------------------
-- La 20260828_2400 ya movio esta clave de cloud a www/.../interacciones, pero
-- solo esta aplicada en desarrollo. En produccion el parametro todavia tiene el
-- valor original de cloud. Con el IN de abajo esta migracion deja el valor
-- correcto en los dos casos, se haya aplicado la 2400 antes o no — y si se
-- aplican las dos en orden, la 2400 escribe un valor intermedio que esta
-- reescribe en la misma corrida.
--
-- EL REDIRECT VIEJO SIGUE, Y APUNTA AL DESTINO FINAL
-- --------------------------------------------------
-- cloud/datarocket/interacciones/index.php redirige 301 directo a
-- /datarocket/prospecto/, sin pasar por la ruta intermedia. Cubre los enlaces
-- ya enviados por WhatsApp, que viven hasta 30 dias y no se pueden reescribir
-- (el vencimiento va firmado dentro del token).
--
-- La ruta www/datarocket/interacciones/ NO necesita redirect: se verifico
-- contra `evolution_mensajes` que no salio ni un aviso con esa base — el job
-- corre `0 9-19 * * *` y la 2400 se aplico fuera de esa ventana.
--
-- IDEMPOTENTE Y NO DESTRUCTIVA
-- ----------------------------
-- El WHERE exige uno de los dos valores conocidos: si alguien edito la clave a
-- mano desde Herramientas > Editor de parametros, no se pisa.

UPDATE `parametros`
   SET `valor` = 'https://www.databox.net.ar/datarocket/prospecto/'
 WHERE `variable` = 'datarocket.interacciones.enlace.base'
   AND `valor` IN (
        'https://cloud.databox.net.ar/datarocket/interacciones/',
        'https://www.databox.net.ar/datarocket/interacciones/'
   );
