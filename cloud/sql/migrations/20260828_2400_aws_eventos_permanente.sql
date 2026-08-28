-- AWS > Eventos: clasificacion "este rebote es definitivo".
--
-- EL PROBLEMA QUE ARREGLA
-- ----------------------
-- La baja automatica de lista por rebote duro miraba `aws_eventos.subtipo =
-- 'Permanent'`, o sea le creia a la clasificacion de SES y nada mas. SES manda
-- 'Transient' cuando no puede resolver el dominio — por las dudas de que el DNS
-- este caido un rato — asi que una direccion con el dominio mal tipeado
-- ('@live.con' en vez de '@live.com') rebotaba en TODAS las campanas sin darse
-- de baja nunca. Incidente prod 2026-08-28, lista #139, dos campanas seguidas
-- al mismo destino muerto.
--
-- El dato que faltaba mirar ya estaba en el payload: el enhanced status code
-- que devolvio el SMTP del otro lado. Por RFC 3463 la primera cifra es la clase
-- del fallo — 5 = permanente, 4 = transitorio. Ese rebote traia
-- `"status":"5.4.4"` y `550 5.4.4 Invalid domain`: definitivo, dicho por el
-- servidor de destino. Cuando SES y el codigo del protocolo se contradicen,
-- gana el codigo.
--
-- POR QUE UNA COLUMNA Y NO UN FILTRO MAS COMPLICADO
-- ------------------------------------------------
-- La pregunta "¿esto es definitivo?" se contesta UNA vez, en el ingestor
-- (aws_evt_bounce_permanente en api/v4/aws/eventos.php), y queda congelada aca.
-- Los consumidores leen la columna. La alternativa era que cada consumidor
-- reinterpretara el JSON del `raw` por su cuenta, que es exactamente como se
-- terminan tres reglas distintas para la misma pregunta.
--
--   permanente = 1     bounce definitivo -> da de baja de la lista
--   permanente = 0     bounce blando (buzon lleno, servidor caido) -> no
--   permanente = NULL  no es un bounce (delivery, open, click, complaint...)
--
-- El complaint no usa esta columna: cualquier denuncia de spam da de baja sin
-- necesidad de graduar nada.
--
-- Idempotente. Compatible MySQL 8 (dev) + MariaDB 10.11 (prod): el ADD COLUMN
-- va por `information_schema` + PREPARE/EXECUTE porque
-- `ADD COLUMN IF NOT EXISTS` es sintaxis MariaDB-only.

-- ---------------------------------------------------------------------------
-- 1. La columna
-- ---------------------------------------------------------------------------
SET @existe := (SELECT COUNT(*)
                  FROM information_schema.COLUMNS
                 WHERE TABLE_SCHEMA = DATABASE()
                   AND TABLE_NAME   = 'aws_eventos'
                   AND COLUMN_NAME  = 'permanente');

SET @sql := IF(@existe = 0,
    'ALTER TABLE `aws_eventos` ADD COLUMN `permanente` tinyint(1) NULL DEFAULT NULL AFTER `subtipo`',
    'DO 0');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- ---------------------------------------------------------------------------
-- 2. Backfill de los bounces ya recibidos
-- ---------------------------------------------------------------------------
-- Se hace con LIKE sobre el `raw` y NO con JSON_EXTRACT a proposito. Dos
-- razones: (a) MySQL 8 tira error ante un `raw` que no sea JSON valido en vez
-- de devolver NULL, y `AND` no garantiza corto-circuito para protegerlo con un
-- JSON_VALID previo; (b) el LIKE matchea CUALQUIER destinatario con 5.x.x, que
-- es la misma semantica que aws_evt_bounce_permanente() en PHP, mientras que un
-- path JSON obligaria a fijar el indice [0].
--
-- El formato guardado es JSON compacto de SES (`"status":"5.4.4"`, sin espacio
-- tras los dos puntos). Se contempla igual la variante con espacio por si algun
-- payload viejo la trae.
--
-- Al escribir esto prod tenia 19 bounces: 10 ya marcados 'Permanent' por SES y
-- 2 'Transient' con status 5.x.x (los del dominio mal tipeado). Los 7 restantes
-- son blandos de verdad y quedan en 0.
UPDATE `aws_eventos`
   SET `permanente` = 1
 WHERE `tipo` = 'bounce'
   AND `permanente` IS NULL
   AND (
        `subtipo` = 'Permanent'
     OR `raw` LIKE '%"status":"5%'
     OR `raw` LIKE '%"status": "5%'
   );

-- El resto de los bounces quedan explicitamente en 0. La diferencia entre 0 y
-- NULL importa: 0 es "lo miramos y es blando", NULL es "no es un bounce".
UPDATE `aws_eventos`
   SET `permanente` = 0
 WHERE `tipo` = 'bounce'
   AND `permanente` IS NULL;
