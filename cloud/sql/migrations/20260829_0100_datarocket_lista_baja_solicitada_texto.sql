-- Acorta el texto del motivo 'solicitada' de `datarocket_lista_baja_motivo`.
--
-- POR QUE
-- -------
-- 'Baja solicitada por el destinatario' (5 palabras) se renderiza en la columna
-- MOTIVO de la pestana Bajas del ABM de listas dentro de un `<span class="badge">`.
-- La pildora no tiene ancho fijo: con ese largo envuelve en cuatro renglones y
-- deforma la fila entera. Los otros tres motivos del catalogo son de dos palabras
-- ('Rebote duro', 'Denuncia de spam', 'Baja manual'), asi que este quedaba fuera
-- de escala.
--
-- 'Baja solicitada' conserva el sentido (la pidio el destinatario, no un
-- operador) y ademas se lee igual que el `valor` de la fila. El detalle largo
-- ("Baja solicitada por el destinatario: prospecto #N") lo sigue guardando
-- www/datarocket/suscripcion/index.php en la columna `detalle`, que es donde
-- vive la explicacion completa.
--
-- Idempotente: el UPDATE apunta por (campo, valor), correrlo dos veces no cambia
-- nada. `estados` es MyISAM (no transaccional): un ROLLBACK no revierte esto.

UPDATE `estados`
   SET `texto` = 'Baja solicitada'
 WHERE `campo` = 'datarocket_lista_baja_motivo'
   AND `valor` = 'solicitada';
