-- Corrige la descripcion de la tarea `datarocket_interacciones_avisar_pendientes`.
--
-- La 20260828_1500 la registro describiendola como "un aviso por responsable
-- por dia" -- un resumen con la cuenta de pendientes. El job cambio antes de
-- salir a produccion: ahora manda UN MENSAJE POR CONSULTA, y cada uno lleva los
-- datos del prospecto (whatsapp, correo, telefono), el negocio y el texto
-- completo de lo que pregunto, para poder contestar sin abrir el panel.
--
-- Va como migracion aparte y no editando la 1500 porque esa ya esta aplicada:
-- el Migrador guarda el hash de cada archivo y modificar uno aplicado lo deja
-- marcado como cambiado. Aca solo se toca el texto que se ve en Herramientas >
-- Programador de tareas; ni el `script`, ni el `cron_expr`, ni `activo` cambian.
--
-- La ventana anti-repeticion tambien paso a ser por consulta
-- (`evolution_mensajes.tags` = 'datarocket_pendientes:<id de la interaccion>'),
-- asi que cada pendiente genera un recordatorio por dia hasta que se conteste.
--
-- Idempotente por naturaleza (un UPDATE con el texto final) y acotado por
-- `script`, que es la identidad real de la tarea.
--
-- El texto entra justo en el varchar(255) de `tareas`.`descripcion`: el detalle
-- completo vive en el docblock del job, no aca.

UPDATE `tareas`
   SET `descripcion` = 'Manda un WhatsApp por cada interaccion entrante sin responder al responsable de su oportunidad, con los datos del prospecto y el texto de la consulta. Encola en el microservicio v4 (canal databox-bot). Un recordatorio por consulta por dia.'
 WHERE `script` = 'jobs/datarocket_interacciones_avisar_pendientes.php';
