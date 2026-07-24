-- Segunda pasada sobre `aws_mensajes.estado`: la migracion `20260724_1700`
-- solo mapeo las letras `P/E/F/C/R` que escribia el ABM legacy tardio,
-- pero el clone inicial desde `awssesmensajes` (migracion `20260722_1300`)
-- trajo miles de filas historicas con estado numerico segun el esquema del
-- modulo legacy `databox_legacy/databox-api/modulos/awsses.php`:
--
--   1 -> pendiente
--   2 -> enviado
--   3 -> enviando   (usado como lock optimista antes del envio)
--   4 -> error
--
-- El UI hoy trabaja con esos 5 strings (`pendiente`/`enviando`/`enviado`/
-- `anulado`/`error`) y `enviando` acaba de habilitarse — asi que el `3`
-- historico encuentra destino natural sin perder informacion.
--
-- Como bonus, tambien normaliza a NULL cualquier `''` o valor no reconocido
-- que haya quedado en la columna (`varchar(20)` post-1700, tolera cualquier
-- texto). Sin esto, el badge y las tarjetas del modal Consultar caen al
-- fallback y muestran el crudo.
--
-- Idempotente: los UPDATE filtran por valores viejos, al re-correr no hay
-- filas afectadas. No hay ALTER, la columna sigue siendo varchar(20).

-- --- 1) estado: mapear dígitos legacy a strings ------------------------------

UPDATE `aws_mensajes` SET `estado` = CASE `estado`
    WHEN '1' THEN 'pendiente'
    WHEN '2' THEN 'enviado'
    WHEN '3' THEN 'enviando'
    WHEN '4' THEN 'error'
    ELSE `estado`
  END
 WHERE `estado` IN ('1', '2', '3', '4');

-- --- 2) estado: normalizar vacios / desconocidos a NULL ---------------------

UPDATE `aws_mensajes`
   SET `estado` = NULL
 WHERE `estado` IS NOT NULL
   AND `estado` NOT IN ('pendiente', 'enviando', 'enviado', 'anulado', 'error');
