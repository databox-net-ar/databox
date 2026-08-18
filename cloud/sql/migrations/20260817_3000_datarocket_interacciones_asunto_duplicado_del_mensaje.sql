-- datarocket_interacciones: limpia el `asunto` que es una copia del `mensaje`.
--
-- Sintoma: en el ABM (listado > columna "Asunto / Mensaje" y modal de
-- Consultar) las interacciones mas nuevas muestran el mismo texto dos veces,
-- una como asunto y otra como cuerpo.
--
-- Causa: los dos backfills de ayer insertan `asunto` con un fallback al propio
-- cuerpo cuando el origen legacy no traia un asunto corto:
--
--   * 20260817_2400 (linea ~432)
--       LEFT(TRIM(REPLACE(REPLACE(
--         COALESCE(NULLIF(TRIM(f.asunto),''), o.comentarios), '\r',' '),'\n',' ')), 200)
--     ...y `mensaje` = `o.comentarios`. Si el prospecto legacy no tenia asunto,
--     las dos columnas quedan con el mismo texto.
--
--   * 20260817_2500 (linea ~339)
--       asunto  = LEFT(TRIM(flatten(cm.detalle)), 200)
--       mensaje = cm.detalle
--     Ahi la duplicacion es incondicional: son las 20 transcripciones de
--     WhatsApp rescatadas de datasale.
--
-- Es la misma decision que ya habia corregido la 20260816_1200 ("el texto vive
-- una sola vez, en `mensaje`"), reintroducida por los backfills posteriores.
-- Esta migracion la vuelve a aplicar sobre las filas nuevas.
--
-- CRITERIO (importante, porque un prefijo NO alcanza como prueba):
--   la 20260817_1600 fusiono el asunto legacy como PRIMERA LINEA de
--   `comentarios`, asi que un asunto legitimo y corto tambien es prefijo del
--   mensaje. Ejemplo real en dev: la interaccion 1042 tiene asunto
--   'Contacto Web' (12 chars) y un mensaje de 95 que arranca con esa misma
--   linea — ese asunto es real y NO se toca.
--
--   Solo se anula cuando el asunto no aporta nada por encima del mensaje:
--     a) mide exactamente 200 chars -> es el recorte que hacen los backfills, o
--     b) su largo es el del mensaje aplanado entero -> es una copia literal.
--   En ambos casos ademas tiene que ser prefijo exacto del mensaje aplanado.
--
-- El aplanado (CR/LF -> espacio + TRIM) replica el que hicieron los backfills;
-- sin el, las filas cuyo cuerpo trae saltos de linea no matchearian.
--
-- Alcance medido en dev al escribir esta migracion: 29 filas anuladas de las
-- 114 que tienen asunto; sobreviven 85 asuntos reales ('Contacto Web',
-- 'Solicita Presupuesto', etc.). No toca `mensaje` ni ninguna otra columna.
--
-- Idempotente: en la segunda corrida las filas ya tienen `asunto` NULL y el
-- WHERE no las alcanza. Solo DML, sin DDL.
-- Compatible MySQL 8 (dev) + MariaDB 10.11 (prod).
--
-- ORDEN: tiene que correr DESPUES de la 20260817_2400 y la 20260817_2500, que
-- son las que insertan las filas afectadas. El nombre (3000) ya lo garantiza.


-- ---------------------------------------------------------------------------
-- 1) Anula el asunto redundante.
-- ---------------------------------------------------------------------------
UPDATE `datarocket_interacciones`
   SET `asunto` = NULL
 WHERE `asunto`  IS NOT NULL
   AND `mensaje` IS NOT NULL
   AND `asunto` = LEFT(
         TRIM(REPLACE(REPLACE(`mensaje`, CHAR(13), ' '), CHAR(10), ' ')),
         CHAR_LENGTH(`asunto`))
   AND (
         CHAR_LENGTH(`asunto`) = 200
      OR CHAR_LENGTH(`asunto`) = CHAR_LENGTH(
           TRIM(REPLACE(REPLACE(`mensaje`, CHAR(13), ' '), CHAR(10), ' ')))
       );
