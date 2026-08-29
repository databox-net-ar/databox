-- Motivo de baja 'solicitada' para la pagina publica de baja de lista
-- (www/datarocket/listas/index.php).
--
-- POR QUE UN MOTIVO PROPIO Y NO 'manual'
-- --------------------------------------
-- El catalogo `datarocket_lista_baja_motivo` ya tiene 'rebotado', 'spam' y
-- 'manual'. La baja que pide el destinatario desde el pie de un correo NO es
-- ninguna de las tres:
--
--   - No es 'rebotado' ni 'spam': esas las decide el sistema leyendo eventos de
--     SES, y son sintomas de un problema (la casilla no existe, la persona nos
--     denuncio).
--   - No es 'manual': 'manual' significa "un operador la saco desde el ABM".
--     Meter ahi las bajas voluntarias hace imposible responder por que se achica
--     una lista — que es justamente la pregunta que el historial existe para
--     contestar. Una lista que pierde 200 suscriptos porque la gente se dio de
--     baja y una que los pierde porque alguien los borro a mano son dos
--     situaciones opuestas.
--
-- `orden` = 4, a continuacion de las tres existentes.
--
-- Idempotente: INSERT ... SELECT ... WHERE NOT EXISTS, el mismo patron con el
-- que se sembro el resto del catalogo. Correrla dos veces no duplica la fila.
--
-- OJO: `estados` es MyISAM, o sea NO transaccional. Un ROLLBACK no revierte
-- esto; si hay que deshacerlo, va un DELETE explicito.

INSERT INTO `estados` (`campo`, `texto`, `valor`, `orden`)
SELECT * FROM (
  SELECT 'datarocket_lista_baja_motivo' AS c,
         'Baja solicitada por el destinatario' AS t,
         'solicitada' AS v,
         4 AS o
) src
WHERE NOT EXISTS (
  SELECT 1 FROM `estados` e
   WHERE e.`campo` = src.c AND e.`valor` = src.v
);
