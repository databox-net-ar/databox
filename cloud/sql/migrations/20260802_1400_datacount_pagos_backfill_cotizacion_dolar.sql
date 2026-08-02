-- Backfill de `datacount_pagos.cotizacion` para pagos en dolares que fueron
-- cargados con `cotizacion = 1` (dato erroneo: el dolar no vale 1 peso).
--
-- Estrategia: tomar la cotizacion venta oficial de `dolarhoycotizaciones` para
-- la fecha de `emision` del pago. Si no hay cotizacion exacta ese dia (fin de
-- semana / feriado / falta el cron ese dia), se usa la mas cercana anterior
-- con `fecha <= emision`. Esto cubre el 100% de los casos siempre que
-- `dolarhoycotizaciones` este poblada hasta antes de la primera emision.
--
-- Pagos cuya emision es anterior a la primera fila de `dolarhoycotizaciones`
-- (caso raro; datos historicos incompletos) quedan sin tocar. Se pueden
-- reprocesar corriendo la migracion de nuevo despues de completar los datos
-- historicos de dolarhoy.
--
-- Solo se toca `cotizacion`. No se recalcula `valor` ni ningun otro campo.
--
-- Idempotente: despues de correrla, `cotizacion` deja de ser 1 para esos
-- pagos, asi que el filtro del WHERE deja de matcharlos y el UPDATE es no-op.
-- Se puede aplicar N veces sin efectos colaterales.
--
-- Sintaxis: UPDATE ... JOIN con subquery correlacionada. Compatible con MySQL
-- 8.0 (dev) y MariaDB 10.11 (prod).

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
         WHERE p2.moneda     = 'D'
           AND p2.cotizacion = 1
           AND p2.emision    IS NOT NULL
       ) fix
    ON fix.pago_id      = p.id
   AND fix.cotiz_dolarhoy IS NOT NULL
   SET p.cotizacion = fix.cotiz_dolarhoy;
