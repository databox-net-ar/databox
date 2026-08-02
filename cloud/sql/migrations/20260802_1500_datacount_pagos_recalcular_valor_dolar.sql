-- Recalcula `datacount_pagos.valor` = `monto * cotizacion` para los pagos en
-- dolares (`moneda = 'D'`).
--
-- Motivo: por convencion del sistema, `valor` guarda la conversion a pesos
-- del pago (`monto * cotizacion`). El ABM la computa asi al alta, pero cuando
-- el `cotizacion` se corrige a mano (o via la migracion previa
-- 20260802_1400_..._backfill_cotizacion_dolar), `valor` puede quedar
-- desalineado. Esta migracion vuelve a poner la invariante en su lugar.
--
-- Se limita a `moneda = 'D'` a proposito: los pagos en pesos ya vienen con
-- `cotizacion = 1` y `valor = monto`, la invariante ya se cumple; no hace
-- falta tocarlos.
--
-- Se saltean pagos con `monto` o `cotizacion` nulos / cotizacion <= 0 para
-- no meter NULLs ni ceros donde antes habia un valor razonable.
--
-- Idempotente: correr N veces produce el mismo resultado (el UPDATE convierte
-- a un valor determinista a partir de columnas de la misma fila).
--
-- Compatible con MySQL 8.0 (dev) y MariaDB 10.11 (prod).

UPDATE `datacount_pagos`
   SET `valor` = ROUND(`monto` * `cotizacion`, 2)
 WHERE `moneda`     = 'D'
   AND `monto`      IS NOT NULL
   AND `cotizacion` IS NOT NULL
   AND `cotizacion` > 0;
