-- Desactiva la tarea legacy `dolarhoyActualizar` (id 36), que corre cada hora
-- sin efecto observable.
--
-- QUE ES
-- Una tarea de tipo `url` que pegaba a https://api.databox.net.ar/robot/dolarhoyActualizar
-- con cron `0 8-20 * * 1-5`. Dada de alta el 2026-07-23, antes de que existiera
-- el job propio `dolarhoy_cotizacion_actualizar` (2026-08-14).
--
-- POR QUE SE APAGA
-- Sigue devolviendo HTTP 200 (19 bytes) en cada corrida, pero la tabla que
-- alimentaba —`dolarhoycotizaciones`, la legacy sin guion bajo— no recibe una
-- fila desde el 2025-12-15. El ABM y la valorizacion de pagos leen
-- `dolarhoy_cotizaciones` (con guion bajo), que escribe el job propio. O sea:
-- 13 llamadas HTTP por dia habil al contenedor legacy que no mueven ningun dato
-- que alguien lea.
--
-- Se desactiva en vez de borrarla para conservar el historial de
-- `tareas_ejecuciones` y poder revertir con un solo UPDATE si aparece un
-- consumidor del lado legacy que no vimos.
--
-- Para revertir:  UPDATE `tareas` SET `activo` = 1 WHERE `nombre` = 'dolarhoyActualizar';
--
-- Idempotente: fija un valor absoluto, no incremental. Se puede aplicar N veces.
--
-- OJO `tareas`.`descripcion` es varchar(255) y el modo SQL de prod es estricto:
-- pasarse no trunca, tira 1406 y aborta la migracion entera. El texto de abajo
-- mide 184 caracteres; el detalle largo vive en este comentario, no en la fila.
--
-- Compatible con MySQL 8.0 (dev) y MariaDB 10.11 (prod).

UPDATE `tareas`
   SET `activo`      = 0,
       `descripcion` = 'DESACTIVADA 2026-09-21. Legacy: alimentaba `dolarhoycotizaciones` (sin filas desde 2025-12-15). La reemplaza el job `dolarhoy_cotizacion_actualizar`. Ver migracion 20260921_1200.'
 WHERE `nombre` = 'dolarhoyActualizar';
