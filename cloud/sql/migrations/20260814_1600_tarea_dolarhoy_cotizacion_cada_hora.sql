-- Pasa la tarea `dolarhoy_cotizacion_actualizar` de una corrida diaria a una
-- corrida horaria.
--
-- Motivo: la cotizacion del oficial se mueve durante el dia, y la fila del dia
-- en `dolarhoy_cotizaciones` tiene que reflejar el ultimo valor publicado por
-- dolarhoy.com, no el que estaba a las 07:00. Las ordenes de pago en dolares
-- se valorizan contra esa serie (ver dcpValorizar() en
-- api/datacount_pagos.php), asi que un valor congelado a primera hora arrastra
-- el error a todo comprobante emitido ese dia.
--
--   antes:  0 7 * * 1-5   (lunes a viernes 07:00)
--   ahora:  0 * * * *     (todos los dias, cada hora en punto)
--
-- No hace falta tocar el job ni el helper: `dhRegistrarCotizacionDelDia()` ya
-- hace upsert por fecha — si la fila del dia existe la actualiza en lugar de
-- insertar otra — asi que correr 24 veces por dia refresca el valor sin
-- duplicar la serie. `overlap = 'skip'` evita solapamientos si una corrida se
-- demora.
--
-- Se hace en una migracion aparte y no editando
-- 20260814_1400_tarea_dolarhoy_cotizacion_actualizar.sql porque esa ya fue
-- aplicada: cambiarla ahora le moveria el hash y su INSERT ... WHERE NOT EXISTS
-- ya no volveria a correr.
--
-- Idempotente: fija un valor absoluto, no incremental. Se puede aplicar N veces.
--
-- Compatible con MySQL 8.0 (dev) y MariaDB 10.11 (prod).

UPDATE `tareas`
   SET `cron_expr`   = '0 * * * *',
       `descripcion` = 'Scrapea la cotizacion del dolar oficial de dolarhoy.com y refresca la fila del dia en `dolarhoy_cotizaciones`. Cada hora en punto. Ver cloud/jobs/dolarhoy_cotizacion_actualizar.php.'
 WHERE `nombre` = 'dolarhoy_cotizacion_actualizar';
