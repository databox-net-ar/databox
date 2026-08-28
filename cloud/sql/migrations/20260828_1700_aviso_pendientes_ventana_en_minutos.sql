-- El aviso de consultas pendientes pasa a repetirse CADA HORA hasta que se
-- atienda, y su ventana anti-repeticion pasa de horas a minutos.
--
-- QUE CAMBIO
-- ----------
-- El job nacio mandando UN recordatorio por dia por consulta: ventana de 20 h
-- (`datarocket.interacciones.aviso.horas`). El pedido es otro — una consulta sin
-- responder no deja de estarlo porque ya se aviso una vez, asi que el aviso
-- insiste cada hora hasta que alguien la atienda.
--
-- El ritmo lo define el `cron_expr` de la tarea (`0 9-19 ...`: una pasada por
-- hora en horario laboral). La ventana quedo reducida a lo que siempre fue en el
-- fondo: el piso que evita el doble aviso dentro de la misma corrida. Por eso
-- ahora se expresa en minutos, con default 55.
--
-- POR QUE 55 Y NO 60
-- ------------------
-- El cron dispara a las :00 pero el mensaje se encola unos segundos despues, y
-- la corrida siguiente compara contra NOW(). Con 60 exactos, el aviso de las
-- 14:00:15 sigue cayendo dentro de la ventana a las 15:00:12 y la consulta se
-- saltea: el recordatorio saldria hora por medio. Los 5 minutos de margen
-- absorben ese desfasaje y no habilitan un segundo aviso dentro de la misma hora
-- (el cron no vuelve a correr hasta la siguiente).
--
-- SE BORRA EL PARAMETRO VIEJO
-- ---------------------------
-- `datarocket.interacciones.aviso.horas` ya no lo lee nadie: dejarlo seria una
-- perilla muerta en Herramientas > Editor de parametros que alguien va a girar
-- esperando que haga algo. Se borra solo si sigue en su valor sembrado ('20'):
-- si alguien lo habia ajustado a mano, la fila queda y se ve en el editor, que
-- es la unica forma de que ese ajuste no desaparezca en silencio.
--
-- El parametro nuevo (`...aviso.minutos`) NO se siembra aca: lo crea el propio
-- job con parametroAsegurar() en su primera corrida, igual que el resto de su
-- configuracion.
--
-- El `cron_expr` de la tarea tampoco se toca: en produccion ya esta editado a
-- mano (`0 9-19 * * 1,2,3,4,5,6`, sin domingos) y esa decision es del operador.

DELETE FROM `parametros`
 WHERE `variable` = 'datarocket.interacciones.aviso.horas'
   AND `valor`    = '20';

UPDATE `tareas`
   SET `descripcion` = 'Manda un WhatsApp por cada interaccion entrante sin responder al responsable de su oportunidad, con los datos del prospecto y el texto de la consulta. Encola en el microservicio v4 (canal databox-bot). Se repite cada hora hasta que se atienda.'
 WHERE `script` = 'jobs/datarocket_interacciones_avisar_pendientes.php';
