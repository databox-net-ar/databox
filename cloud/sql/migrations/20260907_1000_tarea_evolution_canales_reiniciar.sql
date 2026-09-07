-- Registra la tarea `evolution > canales > reiniciar` en el Programador.
--
-- QUE HACE EL JOB
-- --------------
-- Una vez por dia, canal por canal, hace lo mismo que el operador hacia a mano
-- en el dashboard del Manager de Evolution: apretar RESTART
-- (POST /instance/restart/{slug}) y despues el icono de refresco
-- (GET /instance/connectionState/{slug}) para confirmar que volvio a
-- "Connected". Refresca `online` / `latido` / `actualizado` en
-- `evolution_canales` con lo que observa.
--
-- El socket de WhatsApp se degrada con los dias: la instancia sigue diciendo
-- `state: open` pero deja de recibir o de despachar. El reinicio recrea la
-- conexion sin tocar las credenciales -- no pide QR -- asi que es barato.
--
-- POR QUE ESTA MIGRACION EXISTE
-- ----------------------------
-- Un script en cloud/jobs/ no corre por existir ni por deployarse: el scheduler
-- solo mira la tabla `tareas`. Sin esta alta el archivo queda huerfano en disco
-- y el sintoma es "nunca reinicia", sin ningun error a la vista.
--
-- CADENCIA
-- --------
-- `0 4 * * *`: una sola pasada diaria a las 04:00. La hora no es casual: cada
-- canal queda unos segundos abajo mientras reconecta, y a las 4 AM no hay
-- trafico de WhatsApp que perder. El job ademas escalona los reinicios (5 s
-- entre canal y canal) para que nunca esten todos los bots caidos a la vez.
--
-- `timeout_seg` = 900 cubre el peor caso: ~12 canales habilitados x (hasta 45 s
-- de espera a que reconecte + 5 s de pausa) ~= 600 s, con margen. El job tiene
-- ademas su propio presupuesto interno de 780 s, asi que corta solo y deja el
-- resumen escrito antes de que `timeout` lo mate.
--
-- `overlap` = 'skip': si la corrida de ayer sigue viva, saltear el tick es lo
-- correcto -- dos reinicios simultaneos de la misma instancia no arreglan nada.
--
-- CONFIGURACION
-- -------------
-- Los dos parametros del job (`evolution.canales.reiniciar.slugs` y
-- `.verificar_seg`) NO se siembran aca: los crea el propio job con
-- parametroAsegurar() en su primera corrida, y quedan editables desde
-- Herramientas > Editor de parametros. Por defecto entra todo canal con
-- `habilitado = '1'` y slug + token cargados; para acotarlo a un subconjunto
-- se carga en `.slugs` un CSV (ej: "vigicom-bot,databox-bot").
--
-- `script` es la ruta relativa a cloud/ que resuelve el scheduler
-- (cloud/jobs/_scheduler.php: SCHED_CLOUD_ROOT . '/' . script).
--
-- Idempotente: INSERT ... SELECT ... WHERE NOT EXISTS, con el guard por
-- `script` (la identidad real de la tarea; el nombre es cosmetico y editable
-- desde el panel).

INSERT INTO `tareas` (`nombre`, `descripcion`, `tipo`, `script`, `cron_expr`,
                      `activo`, `overlap`, `timeout_seg`, `retencion_dias`)
SELECT 'evolution > canales > reiniciar',
       -- `tareas`.`descripcion` es varchar(255): no entra el detalle completo.
       -- El resto de la explicacion vive en el docblock del job.
       'Reinicia cada dia las instancias de Evolution API de los canales habilitados (POST /instance/restart) y verifica que vuelvan a "open". Acotable con el parametro evolution.canales.reiniciar.slugs. Job: evolution_canales_reiniciar.php.',
       'php',
       'jobs/evolution_canales_reiniciar.php',
       '0 4 * * *',
       1,
       'skip',
       900,
       7
WHERE NOT EXISTS (
    SELECT 1 FROM `tareas` WHERE `script` = 'jobs/evolution_canales_reiniciar.php'
);
