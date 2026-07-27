-- Crea la tabla `telegram_mensajes`, el historial de mensajes enviados por
-- los bots de Telegram del panel. Sigue el mismo formato que
-- `evolution_mensajes` / `aws_mensajes` para que el ABM cloud, el
-- sender/worker y el vetados/interacciones puedan compartir codigo.
--
-- Diferencia clave con Evolution: acá el "canal" es un bot. El campo se
-- mantiene como `canal_id` para respetar el shape estandar del resto de los
-- motores de mensajeria (ver aws_mensajes / evolution_mensajes), pero
-- apunta a `telegram_bots.id`. No hay FK declarada para no romper la
-- convencion del schema legacy (que evita constraints reales).
--
-- La ABM cloud del listado `/telegrammensajes` NO se implementa en esta
-- tanda; la tabla queda lista para que el motor de envio y el ABM se
-- puedan construir en una segunda etapa sin migrar despues.
--
-- Idempotente: CREATE TABLE IF NOT EXISTS. Compatible con MySQL 8 y
-- MariaDB 10.11.

CREATE TABLE IF NOT EXISTS `telegram_mensajes` (
  `id`            INT(11)              NOT NULL AUTO_INCREMENT,
  `uuid`          VARCHAR(255)         NULL DEFAULT NULL,
  `fecha`         DATETIME             NULL DEFAULT NULL,
  `proyecto_id`   INT(11)              NULL DEFAULT NULL,
  `canal_id`      INT(11)              NULL DEFAULT NULL,
  `plantilla_id`  INT(11)              NULL DEFAULT NULL,
  `contacto_id`   INT(11)              NULL DEFAULT NULL,
  `remitente`     VARCHAR(255)         NULL DEFAULT NULL,
  `remite`        VARCHAR(255)         NULL DEFAULT NULL,
  `destinatario`  VARCHAR(255)         NULL DEFAULT NULL,
  `destino`       VARCHAR(255)         NULL DEFAULT NULL,
  `prioridad`     TINYINT(3) UNSIGNED  NULL DEFAULT NULL,
  `asunto`        VARCHAR(255)         NULL DEFAULT NULL,
  `cuerpo`        MEDIUMTEXT           NULL,
  `formato`       VARCHAR(20)          NULL DEFAULT NULL,
  `adjunto`       VARCHAR(500)         NULL DEFAULT NULL,
  `tags`          VARCHAR(255)         NULL DEFAULT NULL,
  `estado`        VARCHAR(20)          NULL DEFAULT NULL,
  `error`         VARCHAR(1000)        NULL DEFAULT NULL,
  `encolado`      DATETIME             NULL DEFAULT NULL,
  `programado`   DATETIME             NULL DEFAULT NULL,
  `enviado`       DATETIME             NULL DEFAULT NULL,
  `demora`        INT(11)              NULL DEFAULT NULL,
  PRIMARY KEY (`id`) USING BTREE,
  INDEX `idx_telegram_mensajes_fecha`    (`fecha`)       USING BTREE,
  INDEX `idx_telegram_mensajes_proyecto` (`proyecto_id`) USING BTREE,
  INDEX `idx_telegram_mensajes_canal`    (`canal_id`)    USING BTREE,
  INDEX `idx_telegram_mensajes_estado`   (`estado`)      USING BTREE,
  INDEX `idx_telegram_mensajes_uuid`     (`uuid`)        USING BTREE
) ENGINE=InnoDB CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci ROW_FORMAT=Dynamic;
