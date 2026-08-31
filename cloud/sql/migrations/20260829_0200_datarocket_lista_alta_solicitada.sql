-- Motivo de alta 'solicitada' para la pagina publica de suscripcion
-- (www/datarocket/suscripcion/index.php).
--
-- POR QUE
-- -------
-- Esa pagina ahora ofrece las dos direcciones: al que sigue suscripto le ofrece
-- darse de baja, y al que ya no lo esta, volver a suscribirse. La vuelta escribe
-- en `datarocket_listas_altas` y necesita su propio motivo.
--
-- El catalogo `datarocket_lista_alta_motivo` tiene hoy 'manual' (las dos puertas
-- del panel: el editor de suscriptos y el combo de la ficha del prospecto) y
-- 'preexistente' (el backfill de 20260828_2100). La resuscripcion no es ninguno
-- de los dos:
--
--   - No es 'manual': 'manual' significa "un operador lo cargo desde el ABM".
--     Meter ahi las altas que pidio el propio destinatario hace imposible
--     responder por que crece una lista — que es para lo que existe el
--     historial. Una lista que suma 200 porque la gente volvio sola y una que
--     los suma porque alguien los pego a mano son dos situaciones opuestas.
--   - No es 'preexistente': eso es una marca historica de la carga inicial, no
--     un alta real.
--
-- Ademas es el numero que dice si el enlace de baja se esta tocando por error:
-- muchas 'solicitada' de alta poco despues de una 'solicitada' de baja significa
-- que el pie del correo se toca sin querer.
--
-- Mismo `valor` que en el catalogo de bajas a proposito: son dos catalogos
-- distintos (`datarocket_lista_alta_motivo` y `datarocket_lista_baja_motivo`) y
-- la palabra significa lo mismo en los dos — la pidio el destinatario, no un
-- operador. El `texto` sigue el largo del resto del catalogo de altas ('Alta
-- manual', 'Suscripción preexistente'): en la columna MOTIVO del ABM se
-- renderiza dentro de un `<span class="badge">` sin ancho fijo, y una frase
-- larga envuelve en varios renglones y deforma la fila (por eso 20260829_0100
-- tuvo que acortar el texto de la baja).
--
-- `orden` = 2: despues de 'manual' y antes de 'preexistente' (9), que queda al
-- final por ser la marca historica.
--
-- Idempotente: INSERT ... SELECT ... WHERE NOT EXISTS, el mismo patron con el
-- que se sembro el resto del catalogo. Correrla dos veces no duplica la fila.
--
-- OJO: `estados` es MyISAM, o sea NO transaccional. Un ROLLBACK no revierte
-- esto; si hay que deshacerlo, va un DELETE explicito.
--
-- Compatible MySQL 8 (dev) + MariaDB 10.11 (prod).

INSERT INTO `estados` (`campo`, `valor`, `texto`, `orden`)
SELECT * FROM (
  SELECT 'datarocket_lista_alta_motivo' AS campo,
         'solicitada'                   AS valor,
         'Alta solicitada'              AS texto,
         2                              AS orden
) src
WHERE NOT EXISTS (
  SELECT 1 FROM `estados` e
   WHERE e.`campo` = src.campo AND e.`valor` = src.valor
);
