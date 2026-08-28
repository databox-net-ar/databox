-- Datarocket > Listas: backfill del historial de altas.
--
-- CORRIGE UNA AFIRMACION FALSA DE LA MIGRACION 20260828_2000
-- ---------------------------------------------------------
-- Aquella dice que no hay backfill posible porque "la puente no guarda fecha".
-- Es falso: `datarocket_prospectos_listas` tiene `fecha_creacion` datetime NOT
-- NULL DEFAULT CURRENT_TIMESTAMP. No se corrige el texto alla porque el
-- Migrador hashea los archivos aplicados y editarlos levanta `hash_drift`; la
-- correccion vive aca.
--
-- PERO LA FECHA ES CASI TODA FICTICIA, Y POR ESO EL MOTIVO ES OTRO
-- ---------------------------------------------------------------
-- En dev, 37.183 de 37.186 filas de la puente comparten el timestamp exacto
-- 2026-08-11 16:50:20. Eso no es "37.183 personas se suscribieron ese minuto":
-- es la marca de cuando se poblo la tabla (carga inicial / alta de la columna).
-- La fecha real de esas suscripciones no existe en ningun lado.
--
-- Backfillearlas como 'manual' seria peor que no backfillear: la pestaña Altas
-- mostraria un pico de 37.000 altas en un minuto y el operador leeria como dato
-- lo que es un artefacto de esquema. Por eso entran con motivo propio,
-- 'preexistente', que en la UI es su propio chip y su propio color.
--
-- Con eso el historial gana lo que importa — toda suscripcion vigente tiene su
-- renglon, asi que "¿desde cuando esta X en esta lista?" siempre tiene
-- respuesta — sin mentir sobre la precision de la fecha.
--
-- `destino` es el correo de HOY, no el del momento de la suscripcion: ese dato
-- no es reconstruible. Es la unica columna del backfill que no se puede tomar
-- literal.
--
-- Idempotente por el NOT EXISTS: correrla dos veces no duplica, y tampoco pisa
-- las altas reales ya registradas por los ABMs (una suscripcion que ya tiene su
-- renglon no recibe otro).
--
-- Compatible MySQL 8 (dev) + MariaDB 10.11 (prod).

-- ---------------------------------------------------------------------------
-- 1. El motivo nuevo
-- ---------------------------------------------------------------------------
-- Orden 9 para que quede al final del combo: no es un motivo que alguien vaya a
-- elegir, es una marca historica.
INSERT INTO `estados` (`campo`, `valor`, `texto`, `orden`)
SELECT * FROM (
    SELECT 'datarocket_lista_alta_motivo' AS campo, 'preexistente' AS valor,
           'Suscripción preexistente' AS texto, 9 AS orden
) x
WHERE NOT EXISTS (
    SELECT 1 FROM `estados` e WHERE e.`campo` = x.campo AND e.`valor` = x.valor
);

-- ---------------------------------------------------------------------------
-- 2. El backfill
-- ---------------------------------------------------------------------------
-- Un renglon por suscripcion VIGENTE. Las que ya no estan no se pueden
-- reconstruir (la puente solo tiene el presente) y no se inventan.
--
-- `usuario_id` queda NULL a proposito: nadie sabe quien las cargo.
INSERT INTO `datarocket_listas_altas`
       (`lista_id`, `prospecto_id`, `destino`, `motivo`, `detalle`, `origen`, `usuario_id`, `fecha`)
SELECT dpl.`lista_id`,
       dpl.`prospecto_id`,
       NULLIF(TRIM(p.`correo`), ''),
       'preexistente',
       'Fecha tomada de la tabla puente, no de un evento registrado.',
       'backfill/20260828_2100',
       NULL,
       dpl.`fecha_creacion`
  FROM `datarocket_prospectos_listas` dpl
  JOIN `datarocket_prospectos` p ON p.`id` = dpl.`prospecto_id`
 WHERE NOT EXISTS (
        SELECT 1 FROM `datarocket_listas_altas` a
         WHERE a.`lista_id`     = dpl.`lista_id`
           AND a.`prospecto_id` = dpl.`prospecto_id`
 );
