-- Se saca la ventana anti-repeticion del aviso de consultas pendientes: el
-- recordatorio sale en CADA corrida hasta que la consulta se atienda.
--
-- POR QUE
-- -------
-- El aviso nacio con un freno por consulta —primero 20 h, despues 55 min
-- (migracion 20260828_1700)— para no repetir el mismo WhatsApp. La decision de
-- producto es la contraria: al vendedor hay que insistirle todas las veces que
-- haga falta, porque una consulta sin responder no deja de estarlo porque ya se
-- aviso una vez. El freno se saca entero, no se afloja.
--
-- QUE PASA A GOBERNAR LA CADENCIA
-- -------------------------------
-- Solamente el `cron_expr` de la tarea. Hoy es `0 9-19 * * 1,2,3,4,5,6`: una
-- pasada por hora en horario laboral, o sea un recordatorio por hora por cada
-- consulta pendiente. Es la unica perilla que queda: si esa expresion pasa a
-- `* * * * *`, salen avisos por minuto — y una cuenta de WhatsApp con ese
-- volumen se gana un bloqueo. Al tocar el cron de esta tarea, tenerlo presente.
--
-- Lo que sigue acotando el VOLUMEN (no la frecuencia) es
-- `datarocket.interacciones.aviso.max_por_responsable`: cuantos avisos recibe
-- una misma persona por corrida. Ese parametro se mantiene.
--
-- PARAMETROS QUE SE BORRAN
-- ------------------------
-- Los dos que expresaban la ventana. Ya no los lee nadie; dejarlos seria una
-- perilla muerta en Herramientas > Editor de parametros que alguien va a girar
-- esperando que module la frecuencia. Se borran sin condicion —a diferencia de
-- la 1700, que preservaba un `.horas` ajustado a mano— justamente porque ahora
-- ningun valor de esos parametros significa nada.
--
-- Esta migracion SUPERSEDE la parte de la 20260828_1700 que sembraba la ventana
-- en minutos. La 1700 se deja como esta (ya aplicada en desarrollo; el Migrador
-- guarda su hash y editarla la marcaria como cambiada).

DELETE FROM `parametros`
 WHERE `variable` IN ('datarocket.interacciones.aviso.horas',
                      'datarocket.interacciones.aviso.minutos');

UPDATE `tareas`
   SET `descripcion` = 'Manda un WhatsApp por cada interaccion entrante sin responder al responsable de su oportunidad, con los datos del prospecto y el texto de la consulta. Encola en el microservicio v4 (canal databox-bot). Repite el aviso en cada corrida hasta que se atienda.'
 WHERE `script` = 'jobs/datarocket_interacciones_avisar_pendientes.php';
