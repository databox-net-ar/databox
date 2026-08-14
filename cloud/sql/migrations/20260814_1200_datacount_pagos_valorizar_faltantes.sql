-- Completa `datacount_pagos.cotizacion` y `datacount_pagos.valor` en los pagos
-- que quedaron sin valorizar.
--
-- Motivo: el alta desde adjunto (boton "+ Nuevo pago" > "Cargar adjunto") crea
-- primero la orden vacia para obtener el id y recien despues vuelca los campos
-- detectados con IA, y hasta ahora ese camino no pasaba por ningun calculo de
-- `valor`. Los pagos cargados por esa via quedaron con `cotizacion` y `valor`
-- en NULL. Desde el mismo cambio, `dcpValorizar()` en api/datacount_pagos.php
-- aplica la invariante en cada alta y en cada modificacion, para todos los
-- caminos; esta migracion es el backfill de lo que ya estaba cargado.
--
-- Invariante (la misma de 20260802_1400_..._backfill_cotizacion_dolar y
-- 20260802_1500_..._recalcular_valor_dolar, de la que depende
-- datacount_analiticas.php al sumar `valor`):
--
--     valor = ROUND(monto * cotizacion, 2)     con cotizacion = 1 en pesos
--
-- Alcance deliberadamente acotado: solo se tocan filas a las que les FALTA el
-- dato (cotizacion nula/<=0, o dolares con la cotizacion erronea 1, o `valor`
-- nulo). NO se re-alinean las filas que ya tienen los tres campos cargados
-- pero no cumplen la igualdad — si las hubiera, corregirlas es una decision
-- aparte y merece su propia migracion.
--
-- Idempotente: despues de correrla los WHERE dejan de matchear y los UPDATE
-- son no-op. Se puede aplicar N veces.
--
-- Compatible con MySQL 8.0 (dev) y MariaDB 10.11 (prod).


-- ---------------------------------------------------------------------------
-- 1. Pagos en pesos (o sin moneda cargada): la conversion es 1 a 1.
-- ---------------------------------------------------------------------------
UPDATE `datacount_pagos`
   SET `cotizacion` = 1
 WHERE (`moneda` IS NULL OR `moneda` <> 'D')
   AND (`cotizacion` IS NULL OR `cotizacion` <= 0);


-- ---------------------------------------------------------------------------
-- 2. Pagos en dolares sin cotizacion usable -> venta oficial de Dolarhoy.
--
-- "Usable" excluye el 1 exacto: es el default de los pagos en pesos y queda
-- pegado cuando la moneda pasa de pesos a dolares. El dolar no vale 1 peso,
-- asi que ese 1 es dato erroneo (mismo criterio que la migracion de backfill
-- 20260802_1400).
--
-- Se toma la ultima cotizacion con `fecha <= emision`; si no hay ninguna
-- anterior (emision previa al inicio de la serie de `dolarhoycotizaciones`)
-- la fila queda sin tocar y se puede reprocesar corriendo la migracion de
-- nuevo despues de completar los datos historicos.
-- ---------------------------------------------------------------------------
UPDATE `datacount_pagos` p
  JOIN (
        SELECT p2.id AS pago_id,
               (SELECT d.venta
                  FROM `dolarhoycotizaciones` d
                 WHERE d.fecha <= p2.emision
                   AND d.venta > 0
                 ORDER BY d.fecha DESC
                 LIMIT 1) AS cotiz_dolarhoy
          FROM `datacount_pagos` p2
         WHERE p2.moneda  = 'D'
           AND p2.emision IS NOT NULL
           AND (p2.cotizacion IS NULL OR p2.cotizacion <= 1)
       ) fix
    ON fix.pago_id         = p.id
   AND fix.cotiz_dolarhoy IS NOT NULL
   SET p.cotizacion = fix.cotiz_dolarhoy;


-- ---------------------------------------------------------------------------
-- 3. `valor` faltante, ya con la cotizacion resuelta por los pasos anteriores.
--
-- Se saltean los pagos sin `monto` o sin `cotizacion`: sin esos dos datos no
-- hay valor posible y preferimos dejar NULL antes que escribir un 0 que
-- despues suma como si fuera un pago real en las analiticas.
-- ---------------------------------------------------------------------------
UPDATE `datacount_pagos`
   SET `valor` = ROUND(`monto` * `cotizacion`, 2)
 WHERE `valor`      IS NULL
   AND `monto`      IS NOT NULL
   AND `cotizacion` IS NOT NULL
   AND `cotizacion` > 0;
