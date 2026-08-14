-- Registra la tarea `dolarhoy_cotizacion_actualizar` en el Programador.
-- El worker (cloud/jobs/dolarhoy_cotizacion_actualizar.php) scrapea la
-- cotizacion del dolar OFICIAL desde dolarhoy.com y deja la fila del dia en
-- `dolarhoy_cotizaciones` (fecha / compra / venta).
--
-- Cadencia: lunes a viernes a las 07:00 (`0 7 * * 1-5`). El mercado
-- cambiario no opera fines de semana ni feriados, asi que correrlo sabado y
-- domingo solo grabaria la cotizacion del viernes duplicada bajo otra fecha.
-- Los feriados igual caen dentro del rango 1-5; el job los absorbe grabando
-- el valor que publica dolarhoy.com ese dia (el ultimo de cierre).
--
-- 07:00 es despues de la apertura del mercado mayorista pero antes del
-- horario en que arranca el resto de los jobs del panel, asi que la
-- valorizacion de pagos en dolares (cloud/api/datacount_pagos.php) ya
-- encuentra la cotizacion del dia cargada.
--
-- Reintento: si la corrida de las 07:00 falla (dolarhoy.com caido, cambio de
-- markup en el HTML), la fila del dia queda sin cargar y el job del dia
-- siguiente NO la completa retroactivamente -- hay que cargarla a mano desde
-- el ABM. Es deliberado: preferimos un hueco visible antes que inventar una
-- cotizacion con el valor de otro dia.
--
-- `script` es la ruta relativa a cloud/ que resuelve el scheduler
-- (cloud/jobs/_scheduler.php: SCHED_CLOUD_ROOT . '/' . script).
--
-- Idempotente: INSERT ... SELECT ... WHERE NOT EXISTS.

INSERT INTO `tareas` (`nombre`, `descripcion`, `tipo`, `script`, `cron_expr`,
                      `activo`, `overlap`, `timeout_seg`, `retencion_dias`)
SELECT 'dolarhoy_cotizacion_actualizar',
       'Scrapea la cotizacion del dolar oficial de dolarhoy.com y registra la fila del dia en `dolarhoy_cotizaciones`. Lunes a viernes 07:00. Ver cloud/jobs/dolarhoy_cotizacion_actualizar.php.',
       'php',
       'jobs/dolarhoy_cotizacion_actualizar.php',
       '0 7 * * 1-5',
       1,
       'skip',
       120,
       7
WHERE NOT EXISTS (SELECT 1 FROM `tareas` WHERE `nombre` = 'dolarhoy_cotizacion_actualizar');
