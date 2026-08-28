-- Registra la tarea `datarocket_interacciones_avisar_pendientes` en el Programador.
--
-- QUE HACE EL JOB
-- --------------
-- Le manda un WhatsApp al responsable de cada oportunidad que tiene
-- interacciones entrantes sin responder (`sentido='entrante'` y
-- `respondida IS NULL`), con la cuenta y las mas viejas de la cola.
--
-- El destinatario es el `asignado` de la OPORTUNIDAD: la interaccion no tiene
-- dueño propio. Las pendientes sin oportunidad, o con la oportunidad sin
-- asignar, no se avisan a nadie -- quedan anotadas como suceso 'alerta'.
--
-- NO envia: encola. El POST va al microservicio api/v4/evolution/mensajes
-- (proyecto `databox`, canal `databox-bot`) y el despacho sigue siendo del
-- motor evolution_mensajes_enviar.php, con su rate limit y su gate manual.
--
-- POR QUE ESTA MIGRACION EXISTE
-- ----------------------------
-- Un script en cloud/jobs/ no corre por existir ni por deployarse: el scheduler
-- solo mira la tabla `tareas`. Sin esta alta el archivo queda huerfano en disco
-- y el sintoma es "no avisa nunca", sin ningun error a la vista. Mismo caso que
-- la 20260828_1200 con el expansor de campañas.
--
-- CADENCIA
-- --------
-- `0 9-19 * * *`: una pasada por hora en horario laboral. NO manda un WhatsApp
-- por hora -- el job tiene su propia ventana anti-repeticion
-- (`datarocket.interacciones.aviso.horas`, default 20 h), asi que a cada
-- responsable le llega un solo aviso por dia, en la primera pasada en la que
-- tenga algo pendiente. La ventana horaria evita el otro extremo: un unico tick
-- diario haria que una consulta entrada a las 10 AM se avise recien al dia
-- siguiente.
--
-- El rango arranca a las 9 y corta a las 19 a proposito: es un aviso a un
-- celular, no un log. Nadie quiere el recordatorio a las 3 de la mañana.
--
-- `timeout_seg` = 300 cubre el peor caso razonable (un POST de hasta 20 s por
-- responsable). `overlap` = 'skip': si una corrida sigue viva, saltear el tick
-- es correcto -- la siguiente vuelve a mirar la misma cola.
--
-- CONFIGURACION
-- -------------
-- Los tres parametros que usa el job (`datarocket.interacciones.aviso.url`,
-- `.aplicacion` y `.horas`) NO se siembran aca: los crea el propio job con
-- parametroAsegurar() en su primera corrida, y quedan editables desde
-- Herramientas > Editor de parametros. La apikey en particular no puede vivir
-- en una migracion -- es distinta en dev y en prod, y sembrarla filtraria el
-- secreto de un entorno al otro. El job la resuelve desde `aplicaciones` por el
-- nombre que diga `.aplicacion` (default 'Kernel').
--
-- `script` es la ruta relativa a cloud/ que resuelve el scheduler
-- (cloud/jobs/_scheduler.php: SCHED_CLOUD_ROOT . '/' . script).
--
-- Idempotente: INSERT ... SELECT ... WHERE NOT EXISTS, con el guard por
-- `script` (la identidad real de la tarea; el nombre es cosmetico y editable
-- desde el panel).

INSERT INTO `tareas` (`nombre`, `descripcion`, `tipo`, `script`, `cron_expr`,
                      `activo`, `overlap`, `timeout_seg`, `retencion_dias`)
SELECT 'datarocket > interacciones > avisar pendientes',
       -- `tareas`.`descripcion` es varchar(255): no entra el detalle completo.
       -- El resto de la explicacion vive en el docblock del job.
       'Avisa por WhatsApp al responsable de cada oportunidad con interacciones entrantes sin responder. Encola en el microservicio v4 (proyecto databox, canal databox-bot); un aviso por responsable por dia. Job: datarocket_interacciones_avisar_pendientes.php.',
       'php',
       'jobs/datarocket_interacciones_avisar_pendientes.php',
       '0 9-19 * * *',
       1,
       'skip',
       300,
       7
WHERE NOT EXISTS (
    SELECT 1 FROM `tareas` WHERE `script` = 'jobs/datarocket_interacciones_avisar_pendientes.php'
);
