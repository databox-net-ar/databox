-- Backfill de los dias habiles que quedaron sin cargar en
-- `dolarhoy_cotizaciones` por el cron corrido de la tarea
-- `dolarhoy_cotizacion_actualizar` (ver 20260921_1000, que lo corrige).
--
-- El cron malo (`0 * 8-20 * 1,2,3,4,5`) solo disparaba los dias 8 al 20 de cada
-- mes en dia habil, asi que la serie perdio:
--
--   2026-08-21 .. 2026-09-07   -> dias 21-31 de agosto y 1-7 de septiembre
--
-- De ese rango este archivo carga los 12 DIAS HABILES. Los fines de semana
-- (08-22/23, 08-29/30, 09-05/06) NO se cargan a proposito: desde
-- 20260814_1500_dolarhoy_cotizaciones_backfill_dias_habiles.sql la serie es
-- deliberadamente solo lunes a viernes, y la cadencia que queda vigente
-- (`0 8-20 * * 1-5`) tampoco los va a escribir nunca mas. Meterlos ahora
-- dejaria un tramo con fines de semana rodeado de tramos sin ellos.
--
-- Tampoco se carga el 2026-09-21: ese dia lo escribe el job corriendo en vivo
-- contra dolarhoy.com, que es la fuente de verdad del modulo. No tiene sentido
-- inferirlo si se puede scrapear.
--
-- FUENTE DE LOS DATOS
-- api.argentinadatos.com/v1/cotizaciones/dolares/oficial -- la misma que uso el
-- backfill de 20260814_1500.
--
-- PRECISION: es el valor de cierre del dia, no necesariamente el ultimo que
-- mostraba dolarhoy.com a las 20 hs. Contrastado contra los 9 dias que el job
-- si alcanzo a grabar en vivo (2026-09-08 .. 09-18), 6 coinciden exacto y 3
-- difieren en $5 (09-09, 09-11, 09-16) — drift intradia de la fuente, no un
-- error de escala. Las filas existentes NO se tocan: las grabo el job contra
-- dolarhoy.com y esa es la fuente de verdad. Asumir +-$5 solo en las 12 filas
-- inferidas de abajo.
--
-- Esto importa porque las ordenes de pago en dolares se valorizan contra esta
-- serie (dcpCotizacionDolar en cloud/api/datacount_pagos.php filtra por
-- `venta > 0`): sin estas filas, todo comprobante emitido entre el 21/08 y el
-- 07/09 cae al valor de otro dia.
--
-- Idempotente: se materializa el lote en una tabla temporal y se insertan solo
-- las fechas que todavia no existen (`dolarhoy_cotizaciones.fecha` no tiene
-- UNIQUE, no alcanza con INSERT IGNORE).
--
-- Rango de venta en el lote: 1520 .. 1535.
--
-- Compatible con MySQL 8.0 (dev) y MariaDB 10.11 (prod).

DROP TEMPORARY TABLE IF EXISTS `tmp_dolarhoy_backfill_cron`;
CREATE TEMPORARY TABLE `tmp_dolarhoy_backfill_cron` (
  `fecha`  date           NOT NULL,
  `compra` decimal(11, 2) NOT NULL,
  `venta`  decimal(11, 2) NOT NULL,
  PRIMARY KEY (`fecha`)
) ENGINE = InnoDB;

INSERT INTO `tmp_dolarhoy_backfill_cron` (`fecha`, `compra`, `venta`) VALUES
  ('2026-08-21', 1470.00, 1520.00),  -- viernes
  ('2026-08-24', 1480.00, 1530.00),
  ('2026-08-25', 1480.00, 1530.00),
  ('2026-08-26', 1485.00, 1535.00),
  ('2026-08-27', 1485.00, 1535.00),
  ('2026-08-28', 1485.00, 1535.00),
  ('2026-08-31', 1480.00, 1530.00),
  ('2026-09-01', 1485.00, 1535.00),
  ('2026-09-02', 1485.00, 1535.00),
  ('2026-09-03', 1485.00, 1535.00),
  ('2026-09-04', 1480.00, 1530.00),
  ('2026-09-07', 1480.00, 1530.00);  -- lunes

INSERT INTO `dolarhoy_cotizaciones` (`fecha`, `compra`, `venta`)
SELECT t.`fecha`, t.`compra`, t.`venta`
  FROM `tmp_dolarhoy_backfill_cron` t
 WHERE NOT EXISTS (
       SELECT 1 FROM `dolarhoy_cotizaciones` d WHERE d.`fecha` = t.`fecha`
 )
 ORDER BY t.`fecha`;

DROP TEMPORARY TABLE `tmp_dolarhoy_backfill_cron`;
