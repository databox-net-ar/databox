-- datarocket_prospectos: funde `asunto` dentro de `comentarios` como paso previo
-- obligatorio al DROP de `asunto` + `acciones` (migracion 20260817_1700).
--
--   comentarios = asunto + salto de linea + comentarios
--
-- Por que: `asunto` es un titulo corto heredado del ABM legacy de Datasale que
-- en la practica no se carga (medido en dev al 2026-08-17: 79 de 1336
-- prospectos, 6%, y con valores de plantilla — "Contacto Web" x64, "Solicita
-- Presupuesto" x11). El dato no se pierde: pasa a ser la primera linea del
-- bloque de notas del prospecto.
--
-- `acciones` NO se toca: es un log libre que quedo 100% vacio (0 filas con
-- contenido en dev, tanto en `datarocket_prospectos` como en la legacy
-- `datasaleprospectos`) — no hay nada que fusionar, se dropea a secas en la
-- 20260817_1700.
--
-- TRUNCADO: `comentarios` es VARCHAR(1000). El LEFT(...,1000) es defensivo —
-- en dev el maximo de asunto+salto+comentarios da 972 caracteres, asi que hoy
-- no corta nada. Si prod tuviera una fila mas larga, se recorta la cola de
-- `comentarios` (nunca el asunto, que va adelante).
--
-- Filas afectadas en dev: 79.
--
-- Idempotente: la fila se saltea si `comentarios` ya arranca con el asunto (o
-- si es exactamente el asunto, caso de los prospectos que no tenian
-- comentarios). Una segunda corrida no vuelve a prefijar.
-- Compatible MySQL 8 (dev) + MariaDB 10.11 (prod).
--
-- ORDEN DE DESPLIEGUE: esta migracion primero, la 20260817_1700 (DROP) despues.
-- El codigo que deja de leer `asunto` / `acciones` se publica entre las dos, o
-- antes de ambas: mientras las columnas existan, el endpoint viejo sigue
-- funcionando.


-- ---------------------------------------------------------------------------
-- 1) comentarios <- asunto + CHAR(10) + comentarios
--
--    CHAR(10) y no '\n' a proposito: el escape con backslash depende del
--    sql_mode (NO_BACKSLASH_ESCAPES), CHAR(10) no.
-- ---------------------------------------------------------------------------

UPDATE datarocket_prospectos
   SET comentarios = LEFT(
         CASE WHEN comentarios IS NULL OR comentarios = ''
              THEN asunto
              ELSE CONCAT(asunto, CHAR(10), comentarios)
         END, 1000)
 WHERE asunto IS NOT NULL
   AND TRIM(asunto) <> ''
   -- Guardas de idempotencia: ya fusionado con comentarios previos...
   AND LEFT(COALESCE(comentarios, ''), CHAR_LENGTH(asunto) + 1) <> CONCAT(asunto, CHAR(10))
   -- ...o ya fusionado sobre un comentario vacio (quedo igual al asunto).
   AND COALESCE(comentarios, '') <> asunto;
