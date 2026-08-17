-- Backfill: los textos que hoy viven dentro de `datarocket_prospectos` pasan a
-- ser interacciones. Es el paso previo a dropear esas columnas y dejar el
-- prospecto reducido a sus relaciones (contacto_id / embudo_id / etapa_id).
--
-- Se migran las dos columnas de texto libre de la tabla:
--
--   * `comentarios` VARCHAR(1000) — 863 de 1336 filas con contenido en dev.
--     Es heterogenea: mayormente el texto de la consulta original que dejo la
--     persona (formulario web, cuerpo de un correo entrante) y en menor medida
--     transcripciones de WhatsApp pegadas a mano por el vendedor.
--   * `acciones` MEDIUMTEXT — 0 filas con contenido en dev. Se migra igual
--     porque produccion no se relevo y es la unica pasada que se hace antes de
--     dropear la columna.
--
-- Mapeo de campos:
--
--   fecha        COALESCE(ingreso, actualizado, etapa_ingreso, NOW()). `fecha`
--                es NOT NULL; en dev no hay filas sin `ingreso`, el resto de
--                los COALESCE es red de seguridad para prod.
--   contacto_id  el del prospecto. Puede ser NULL — la migracion anterior hizo
--                nullable la columna justamente para no perder estas filas.
--   prospecto_id el id del prospecto.
--   tipo         se deriva de `sentido`, que es un campo real con catalogo en
--                `estados` (datasale_prospecto_sentido: E = Entrante,
--                S = Saliente):
--                  * sentido = 'E' -> 'consulta_recibida'. Son ~835 filas: la
--                    persona nos escribio, el texto son SUS palabras.
--                  * resto ('S', vacio, NULL) -> 'nota'. Prospeccion saliente o
--                    sentido desconocido: el texto es una anotacion nuestra.
--                No se intenta adivinar el canal (correo / whatsapp / web) a
--                partir del contenido — seria heuristica fragil sobre texto
--                libre. `origen` del prospecto queda disponible en el propio
--                prospecto para quien quiera afinar despues.
--   origen       marcador de procedencia, uno por columna de origen:
--                'datarocket_prospectos.comentarios' / '...acciones'. Cumple
--                tres funciones: documenta de donde salio la fila, hace el
--                backfill idempotente (el LEFT JOIN de mas abajo se apoya en
--                el par prospecto_id + origen) y lo hace reversible de un
--                DELETE. Es coherente con la semantica ya existente de
--                `origen` = "en que tabla esta el registro de origen"; lo que
--                cambia es que aca `mensaje_id` queda NULL porque el origen no
--                es un mensaje de una cola de envio.
--   usuario_id   NULL. El autor real no quedo registrado en el prospecto.
--   descripcion  etiqueta corta del listado: el `asunto` del prospecto si lo
--                tiene (79 filas en dev), y si no los primeros 200 caracteres
--                del cuerpo, con los saltos de linea aplanados a espacios.
--   cuerpo       el texto completo, sin recortar.
--
-- Las columnas de origen NO se vacian ni se dropean aca: esta migracion es
-- solo de copia, para poder verificar el resultado antes de destruir nada. El
-- DROP COLUMN va en una migracion aparte.
--
-- Idempotente: se insertan solo los prospectos que todavia no tienen su fila
-- de backfill para esa columna (LEFT JOIN + IS NULL). Correr dos veces no
-- duplica. Si alguien edita `comentarios` despues de la primera corrida, el
-- cambio NO se re-copia — es un backfill de una sola pasada, no una sync.
--
-- Compatible MySQL 8 (dev) + MariaDB 10.11 (prod).


-- ---------------------------------------------------------------------------
-- 1) `datarocket_prospectos.comentarios` -> interaccion.
-- ---------------------------------------------------------------------------

INSERT INTO datarocket_interacciones
            (fecha, contacto_id, prospecto_id, tipo, origen, mensaje_id,
             usuario_id, descripcion, cuerpo)
SELECT COALESCE(p.ingreso, p.actualizado, p.etapa_ingreso, NOW()),
       p.contacto_id,
       p.id,
       IF(p.sentido = 'E', 'consulta_recibida', 'nota'),
       'datarocket_prospectos.comentarios',
       NULL,
       NULL,
       LEFT(TRIM(REPLACE(REPLACE(
              COALESCE(NULLIF(TRIM(p.asunto), ''), p.comentarios),
            '\r', ' '), '\n', ' ')), 200),
       p.comentarios
  FROM datarocket_prospectos p
  LEFT JOIN datarocket_interacciones i
         ON i.prospecto_id = p.id
        AND i.origen       = 'datarocket_prospectos.comentarios'
 WHERE p.comentarios IS NOT NULL
   AND TRIM(p.comentarios) <> ''
   AND i.id IS NULL;


-- ---------------------------------------------------------------------------
-- 2) `datarocket_prospectos.acciones` -> interaccion.
--    Siempre 'nota': `acciones` es el registro de gestion propia, nunca el
--    texto que dejo la persona. `actualizado` va primero en el COALESCE de
--    fecha porque es un log que se fue reescribiendo, no el alta.
-- ---------------------------------------------------------------------------

INSERT INTO datarocket_interacciones
            (fecha, contacto_id, prospecto_id, tipo, origen, mensaje_id,
             usuario_id, descripcion, cuerpo)
SELECT COALESCE(p.actualizado, p.ingreso, p.etapa_ingreso, NOW()),
       p.contacto_id,
       p.id,
       'nota',
       'datarocket_prospectos.acciones',
       NULL,
       NULL,
       LEFT(TRIM(REPLACE(REPLACE(p.acciones, '\r', ' '), '\n', ' ')), 200),
       p.acciones
  FROM datarocket_prospectos p
  LEFT JOIN datarocket_interacciones i
         ON i.prospecto_id = p.id
        AND i.origen       = 'datarocket_prospectos.acciones'
 WHERE p.acciones IS NOT NULL
   AND TRIM(p.acciones) <> ''
   AND i.id IS NULL;
