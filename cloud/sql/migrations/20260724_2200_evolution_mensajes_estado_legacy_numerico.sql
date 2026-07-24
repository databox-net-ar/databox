-- Segunda pasada sobre `evolution_mensajes.estado`: la migracion `20260724_1300`
-- solo mapeo las letras `P/E/F/C/R` que se asumia tenia el ABM legacy, pero al
-- inspeccionar la data (7292 filas al 2026-07-24) resulto que el legacy nunca
-- uso letras — usaba enteros:
--
--   0 -> anulado    (1604 filas; se lo trataba como "borrado logico" del mensaje)
--   1 -> pendiente  (46 filas; encolado pero aun no despachado)
--   2 -> enviado    (5337 filas; termino OK, mayoria)
--   3 -> enviando   (116 filas; lock optimista que setea el sender)
--   4 -> error      (189 filas; fallo el envio)
--
-- El UI hoy trabaja con esos 5 strings y `enviando` acaba de habilitarse en el
-- catalogo `estados` (ver migracion `20260724_2100_estados_agregar_enviando`),
-- asi que el `3` historico encuentra destino natural sin perder informacion.
--
-- Como bonus, normaliza a NULL cualquier `''` o valor no reconocido que haya
-- quedado en la columna (varchar(20) post-1300, tolera cualquier texto). Sin
-- esto, el badge y la tarjeta Estado del modal Consultar caen al fallback y
-- muestran el crudo (que fue exactamente el bug que dispara esta migracion).
--
-- Idempotente: los UPDATE filtran por valores viejos; al re-correr no hay
-- filas afectadas. No hay ALTER, la columna sigue siendo varchar(20).

-- --- 1) estado: mapear digitos legacy a strings -----------------------------

UPDATE `evolution_mensajes` SET `estado` = CASE `estado`
    WHEN '0' THEN 'anulado'
    WHEN '1' THEN 'pendiente'
    WHEN '2' THEN 'enviado'
    WHEN '3' THEN 'enviando'
    WHEN '4' THEN 'error'
    ELSE `estado`
  END
 WHERE `estado` IN ('0', '1', '2', '3', '4');

-- --- 2) estado: normalizar vacios / desconocidos a NULL ---------------------

UPDATE `evolution_mensajes`
   SET `estado` = NULL
 WHERE `estado` IS NOT NULL
   AND `estado` NOT IN ('pendiente', 'enviando', 'enviado', 'anulado', 'error');
