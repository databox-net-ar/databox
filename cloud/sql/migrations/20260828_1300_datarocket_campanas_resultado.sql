-- Datarocket > Campanas: resultado de entrega por destinatario y baja
-- automatica de la lista ante rebote duro / denuncia de spam.
--
-- EL PROBLEMA
-- -----------
-- El padron sabia si un mensaje habia llegado a la cola del canal y si el canal
-- lo habia despachado, pero NO que habia pasado despues. `aws_mensajes` tiene
-- DOS columnas y el reconciliador solo miraba una:
--
--   estado    -> 'pendiente' | 'enviando' | 'enviado' | 'error' | 'anulado'
--                Que hizo NUESTRO motor. 'enviado' = SES acepto el mensaje.
--   resultado -> 'entregado' | 'abierto' | 'cliqueado' | 'spam' | 'rebotado'
--                | 'rechazado'. Que reporto SES DESPUES, via los eventos SNS
--                que recibe api/v4/aws/eventos.php.
--
-- Un rebote llega como estado='enviado' + resultado='rebotado': SES acepto el
-- mensaje y recien despues descubrio que la casilla no existe. Mirando solo
-- `estado`, el padron marcaba ese rebote como 'enviado' -- indistinguible de una
-- entrega exitosa. Al momento de escribir esto prod tenia 96 entregados y 15
-- rebotados que el padron no podia diferenciar.
--
-- POR QUE UNA COLUMNA NUEVA Y NO UN ESTADO MAS
-- -------------------------------------------
-- `estado` es el ciclo de vida del padron y es COMUN a los tres medios:
-- pendiente -> encolado -> enviado / fallido / omitido. `resultado` es feedback
-- de entrega, existe solo para correo (WhatsApp y Telegram no tienen SNS) y
-- sigue evolucionando despues de 'enviado': un open puede llegar horas mas
-- tarde y un complaint, dias. Meterlo en `estado` mezclaria dos ejes y romperia
-- el calculo de cierre de campana (drcaCampanaReconciliar), que cuenta
-- pendientes y en vuelo sobre `estado`.
--
-- REBOTE DURO vs BLANDO -- LA DISTINCION QUE IMPORTA
-- -------------------------------------------------
-- `resultado='rebotado'` NO alcanza para dar de baja: SES distingue
-- bounceType 'Permanent' (la casilla no existe) de 'Transient' (buzon lleno,
-- servidor caido) y los dos mapean al mismo 'rebotado'. Prod tenia 10
-- Permanent y 7 Transient: desuscribir por los 7 seria perder la suscripcion
-- por un problema temporal. El subtipo vive en `aws_eventos.subtipo` y se cruza
-- por `uuid` -- de ahi el indice nuevo sobre (uuid, tipo).
--
-- LA BAJA ES DESTRUCTIVA -- POR ESO EL RASTRO
-- ------------------------------------------
-- Dar de baja borra el renglon de `datarocket_prospectos_listas`, que no tiene
-- columnas propias: una vez borrado no queda registro de que la persona estuvo
-- suscripta. Por eso la baja se ESTAMPA en el padron (`baja_lista`), que
-- sobrevive a la desuscripcion y conserva prospecto_id, destino y resultado.
-- El padron pasa a ser la evidencia de por que alguien ya no esta en la lista.
--
-- Idempotente en los 5 pasos. Compatible MySQL 8 (dev) + MariaDB 10.11 (prod):
-- sin `ADD COLUMN IF NOT EXISTS` de MariaDB, sin funciones almacenadas (prod
-- rechaza CREATE FUNCTION con error 1419).

-- ---------------------------------------------------------------------------
-- 1. datarocket_campanas_mensajes.resultado
-- ---------------------------------------------------------------------------
SET @sql := (
    SELECT IF(COUNT(*) = 0,
        'ALTER TABLE `datarocket_campanas_mensajes`
            ADD COLUMN `resultado` VARCHAR(20)
                CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NULL DEFAULT NULL
                AFTER `motivo`',
        'DO 0')
      FROM information_schema.COLUMNS
     WHERE TABLE_SCHEMA = DATABASE()
       AND TABLE_NAME   = 'datarocket_campanas_mensajes'
       AND COLUMN_NAME  = 'resultado'
);
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

-- Fecha del ultimo evento de SES aplicado a este renglon. Sirve para saber si
-- los eventos estan fluyendo: un padron entero con `resultado` NULL y campanas
-- viejas significa que el webhook SNS dejo de llegar, no que nadie abrio nada.
SET @sql := (
    SELECT IF(COUNT(*) = 0,
        'ALTER TABLE `datarocket_campanas_mensajes`
            ADD COLUMN `resultado_fecha` DATETIME NULL DEFAULT NULL AFTER `resultado`',
        'DO 0')
      FROM information_schema.COLUMNS
     WHERE TABLE_SCHEMA = DATABASE()
       AND TABLE_NAME   = 'datarocket_campanas_mensajes'
       AND COLUMN_NAME  = 'resultado_fecha'
);
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

-- Cuando este renglon provoco la baja del prospecto de la lista de la campana.
-- NULL = no hubo baja. Es el unico rastro que queda: el renglon de
-- `datarocket_prospectos_listas` se borra.
SET @sql := (
    SELECT IF(COUNT(*) = 0,
        'ALTER TABLE `datarocket_campanas_mensajes`
            ADD COLUMN `baja_lista` DATETIME NULL DEFAULT NULL AFTER `resultado_fecha`',
        'DO 0')
      FROM information_schema.COLUMNS
     WHERE TABLE_SCHEMA = DATABASE()
       AND TABLE_NAME   = 'datarocket_campanas_mensajes'
       AND COLUMN_NAME  = 'baja_lista'
);
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

-- Indice para los chips de filtro del padron y para el barrido de bajas, que
-- busca (campana_id, resultado) sin tocar el resto del padron.
SET @sql := (
    SELECT IF(COUNT(*) = 0,
        'ALTER TABLE `datarocket_campanas_mensajes`
            ADD INDEX `idx_drcam_campana_resultado` (`campana_id`, `resultado`)',
        'DO 0')
      FROM information_schema.STATISTICS
     WHERE TABLE_SCHEMA = DATABASE()
       AND TABLE_NAME   = 'datarocket_campanas_mensajes'
       AND INDEX_NAME   = 'idx_drcam_campana_resultado'
);
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

-- ---------------------------------------------------------------------------
-- 2. Contadores denormalizados de la campana
-- ---------------------------------------------------------------------------
-- `rebotados` cuenta rebotado + rechazado + spam: los tres son "no le llego / no
-- lo quiere", que es lo que el operador necesita ver de un vistazo en el
-- listado. El desglose fino queda en el padron.
SET @sql := (
    SELECT IF(COUNT(*) = 0,
        'ALTER TABLE `datarocket_campanas`
            ADD COLUMN `rebotados` INT(11) NOT NULL DEFAULT 0 AFTER `fallidos`',
        'DO 0')
      FROM information_schema.COLUMNS
     WHERE TABLE_SCHEMA = DATABASE()
       AND TABLE_NAME   = 'datarocket_campanas'
       AND COLUMN_NAME  = 'rebotados'
);
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

-- Cuantos prospectos dio de baja esta campana. Sin este contador la baja
-- automatica es invisible desde el listado y el operador se entera de que la
-- lista encogio recien cuando la mira.
SET @sql := (
    SELECT IF(COUNT(*) = 0,
        'ALTER TABLE `datarocket_campanas`
            ADD COLUMN `bajas` INT(11) NOT NULL DEFAULT 0 AFTER `rebotados`',
        'DO 0')
      FROM information_schema.COLUMNS
     WHERE TABLE_SCHEMA = DATABASE()
       AND TABLE_NAME   = 'datarocket_campanas'
       AND COLUMN_NAME  = 'bajas'
);
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

-- ---------------------------------------------------------------------------
-- 3. Indice en aws_eventos para el cruce por subtipo
-- ---------------------------------------------------------------------------
-- El barrido de bajas pregunta "¿este uuid tuvo un bounce Permanent?". Sin este
-- indice es un full scan de aws_eventos por cada rebote evaluado.
SET @sql := (
    SELECT IF(COUNT(*) = 0,
        'ALTER TABLE `aws_eventos` ADD INDEX `idx_awsev_uuid_tipo` (`uuid`, `tipo`)',
        'DO 0')
      FROM information_schema.STATISTICS
     WHERE TABLE_SCHEMA = DATABASE()
       AND TABLE_NAME   = 'aws_eventos'
       AND INDEX_NAME   = 'idx_awsev_uuid_tipo'
);
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

-- ---------------------------------------------------------------------------
-- 4. Catalogo de `resultado` para los combos y chips del ABM
-- ---------------------------------------------------------------------------
-- Mismos valores y mismo orden de gravedad que
-- AWS_EVT_RESULTADO_PRECEDENCIA en api/v4/aws/eventos.php: si los dos se
-- separan, el padron muestra un valor que el catalogo no sabe traducir.
INSERT INTO `estados` (`campo`, `valor`, `texto`, `orden`)
SELECT * FROM (
    SELECT 'datarocket_campana_mensaje_resultado' AS campo, 'entregado' AS valor, 'Entregado' AS texto, 1 AS orden
    UNION ALL SELECT 'datarocket_campana_mensaje_resultado', 'abierto',   'Abierto',   2
    UNION ALL SELECT 'datarocket_campana_mensaje_resultado', 'cliqueado', 'Cliqueado', 3
    UNION ALL SELECT 'datarocket_campana_mensaje_resultado', 'spam',      'Spam',      4
    UNION ALL SELECT 'datarocket_campana_mensaje_resultado', 'rebotado',  'Rebotado',  5
    UNION ALL SELECT 'datarocket_campana_mensaje_resultado', 'rechazado', 'Rechazado', 6
) x
WHERE NOT EXISTS (
    SELECT 1 FROM `estados` e
     WHERE e.`campo` = x.campo AND e.`valor` = x.valor
);

-- ---------------------------------------------------------------------------
-- 5. Backfill del padron existente
-- ---------------------------------------------------------------------------
-- Las campanas ya corridas tienen renglones 'enviado'/'encolado' con su
-- mensaje_id apuntando a `aws_mensajes`, que YA tiene el resultado de SES. Sin
-- este backfill esas campanas quedarian con `resultado` NULL para siempre: el
-- reconciliador solo mira campanas dentro de la ventana de eventos.
--
-- No toca `baja_lista` ni desuscribe a nadie: dar de baja retroactivamente a
-- gente que reboto hace meses seria una sorpresa desagradable. El backfill solo
-- rellena informacion; las bajas empiezan a correr de aca en adelante.
UPDATE `datarocket_campanas_mensajes` m
  JOIN `datarocket_campanas` c ON c.id = m.campana_id
  JOIN `aws_mensajes`        q ON q.id = m.mensaje_id
   SET m.`resultado`       = q.`resultado`,
       m.`resultado_fecha` = COALESCE(m.`resultado_fecha`, q.`enviado`)
 WHERE c.`medio`     = 'correo'
   AND m.`resultado` IS NULL
   AND q.`resultado` IS NOT NULL;

-- Contadores al dia para las campanas que ya existian.
UPDATE `datarocket_campanas` c
   SET c.`rebotados` = (
        SELECT COUNT(*)
          FROM `datarocket_campanas_mensajes` m
         WHERE m.`campana_id` = c.id
           AND m.`resultado` IN ('rebotado', 'rechazado', 'spam')
   );
