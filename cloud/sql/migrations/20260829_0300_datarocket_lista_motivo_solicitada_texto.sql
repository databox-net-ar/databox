-- Unifica en 'Solicitada' el texto del motivo 'solicitada' en los DOS catalogos
-- de listas: `datarocket_lista_alta_motivo` y `datarocket_lista_baja_motivo`.
--
-- POR QUE
-- -------
-- El texto se renderiza en la columna MOTIVO de las pestanas Altas y Bajas del
-- ABM de listas, dentro de un `<span class="badge">` sin ancho fijo. Venia de
-- dos migraciones distintas y con dos criterios distintos: 'Baja solicitada'
-- (20260829_0100, que ya habia tenido que acortar 'Baja solicitada por el
-- destinatario') y 'Alta solicitada' (20260829_0200).
--
-- Repetir la direccion adentro de la pildora es redundante: la columna vive en
-- la pestana Altas o en la Bajas, nunca en las dos, asi que "Alta" y "Baja" ya
-- estan dichos por la pestana. Sacandolos queda una palabra, que es lo que entra
-- en la columna sin envolver, y las dos pestanas se leen igual — que es lo que
-- son: la misma decision de la misma persona, en una direccion o en la otra.
--
-- Los `valor` NO se tocan: siguen siendo 'solicitada' en los dos catalogos, que
-- es lo que escriben DR_LALTA_MOTIVO / DR_LBAJA_MOTIVO
-- (api/lib/datarocket_listas_baja_enlace.php) y lo que filtran los chips. Esto
-- cambia solo la etiqueta que ve el operador.
--
-- Idempotente: los UPDATE apuntan por (campo, valor), correrlos dos veces no
-- cambia nada. Tampoco pisan nada si en dev ya se edito a mano desde el Editor
-- de estados — quedan en el mismo valor.
--
-- OJO: `estados` es MyISAM, o sea NO transaccional. Un ROLLBACK no revierte
-- esto; si hay que deshacerlo, va un UPDATE explicito.
--
-- Compatible MySQL 8 (dev) + MariaDB 10.11 (prod).

UPDATE `estados`
   SET `texto` = 'Solicitada'
 WHERE `campo` = 'datarocket_lista_alta_motivo'
   AND `valor` = 'solicitada';

UPDATE `estados`
   SET `texto` = 'Solicitada'
 WHERE `campo` = 'datarocket_lista_baja_motivo'
   AND `valor` = 'solicitada';
