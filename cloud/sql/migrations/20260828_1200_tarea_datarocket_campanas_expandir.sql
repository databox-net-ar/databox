-- Registra la tarea `datarocket_campanas_expandir` en el Programador.
--
-- POR QUE ESTA MIGRACION EXISTE
-- ----------------------------
-- La migracion 20260828_1000 creo el modulo de campanas y dejo escrito que el
-- job expansor quedaba "para la etapa siguiente". El job se escribio y se
-- deployo (cloud/jobs/datarocket_campanas_expandir.php) pero nadie lo dio de
-- alta en `tareas`, asi que el archivo estaba en disco y el scheduler no lo
-- llamaba nunca. Esta es esa etapa siguiente.
--
-- El sintoma de la falta fue una campana clavada en `enviando` con el correo YA
-- entregado por AWS: el motor de canal despacho el mensaje, pero como los
-- motores no conocen a las campanas, el padron se entera del envio unicamente
-- por el paso 2 de este job (drcaCampanaReconciliar). Sin el job registrado, el
-- renglon del padron se queda en `encolado` y la campana en `enviando` para
-- siempre, con `enviados = 0` aunque el mail haya llegado.
--
-- QUE HACE EL JOB
-- --------------
--   1. Levanta las campanas cuya hora llego y las que quedaron a medio encolar.
--   2. Reconcilia las que estan `enviando` contra la cola de su canal, y las
--      cierra como `completada` cuando no queda nada en vuelo.
-- NO envia: encola. El despacho sigue siendo de aws/evolution/telegram
-- _mensajes_enviar, que ya tienen su rate limit y su gate manual.
--
-- CADENCIA
-- --------
-- `* * * * *`, igual que los tres motores de canal. El paso 2 es un JOIN barato
-- contra la cola y es lo que le da al panel su latencia de actualizacion: con
-- una cadencia mas floja, una campana ya entregada seguiria mostrandose "en
-- vuelo" durante minutos.
--
-- A diferencia de los motores de canal, NO se registran variantes `sleep=N`.
-- Esas existen para subdividir el minuto y subir el throughput de despacho
-- contra una API externa; aca no hay API externa que saturar y el paso 1 toma
-- `expandiendo` como candado, asi que dos corridas simultaneas sobre el mismo
-- padron no aportan nada.
--
-- `timeout_seg` = 300 con margen sobre el peor caso del job: MAX_CAMPANAS_POR_CORRIDA
-- (3) x DEADLINE_POR_CAMPANA (60s) = 180s de fase 1+2, mas la reconciliacion.
-- `overlap` = 'skip' porque el job es reanudable: si una corrida todavia esta
-- viva, saltear el tick es correcto -- la siguiente retoma donde quedo.
--
-- `script` es la ruta relativa a cloud/ que resuelve el scheduler
-- (cloud/jobs/_scheduler.php: SCHED_CLOUD_ROOT . '/' . script).
--
-- Idempotente: INSERT ... SELECT ... WHERE NOT EXISTS. El guard va por `script`
-- y no por `nombre` -- el nombre es cosmetico y editable desde el panel, la ruta
-- del script es la identidad real de la tarea. Asi, si alguien ya la registro a
-- mano con otro titulo, esta migracion no crea un duplicado que correria el job
-- dos veces por minuto.

INSERT INTO `tareas` (`nombre`, `descripcion`, `tipo`, `script`, `cron_expr`,
                      `activo`, `overlap`, `timeout_seg`, `retencion_dias`)
SELECT 'datarocket > campanas > expandir',
       'Levanta las campanas programadas, encola su padron en la cola del canal y reconcilia las que estan enviando para poder cerrarlas. No envia: el despacho es de los motores de cada canal. Ver cloud/jobs/datarocket_campanas_expandir.php.',
       'php',
       'jobs/datarocket_campanas_expandir.php',
       '* * * * *',
       1,
       'skip',
       300,
       7
WHERE NOT EXISTS (
    SELECT 1 FROM `tareas` WHERE `script` = 'jobs/datarocket_campanas_expandir.php'
);
