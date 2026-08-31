-- Deja el motivo 'solicitada' de `datarocket_lista_baja_motivo` en una sola
-- palabra: 'Solicitada'.
--
-- POR QUE UNA SEGUNDA VUELTA
-- -------------------------
-- 20260829_0100 ya lo habia acortado de 'Baja solicitada por el destinatario' a
-- 'Baja solicitada'. No alcanzo: la columna MOTIVO declara
-- `<th style="width:120px">` (igual en Altas y en Bajas) y el texto va dentro de
-- un `<span class="badge">` con padding, asi que dos palabras siguen envolviendo
-- en dos renglones y la fila queda mas alta que las demas.
--
-- 'Solicitada' entra en un renglon y no pierde nada de informacion: la columna
-- ya se llama MOTIVO (el "Baja" era redundante), la pestana se llama Bajas, y
-- el prefijo solo servia para distinguirlo del catalogo de altas — que es otro
-- `campo` distinto (`datarocket_lista_alta_motivo`) y nunca se renderiza en la
-- misma tabla.
--
-- OJO: el rotulo del chip de filtro NO sale de aca. Vive hardcodeado en
-- `DRLI_BAJA_MOTIVOS` (cloud/assets/js/app.js) y sigue diciendo
-- 'Baja solicitada'. Ahi no molesta porque el chip no tiene ancho fijo.
--
-- Idempotente: el UPDATE apunta por (campo, valor), correrlo dos veces no cambia
-- nada. `estados` es MyISAM (no transaccional): un ROLLBACK no revierte esto.
--
-- Compatible MySQL 8 (dev) + MariaDB 10.11 (prod).

UPDATE `estados`
   SET `texto` = 'Solicitada'
 WHERE `campo` = 'datarocket_lista_baja_motivo'
   AND `valor` = 'solicitada';
