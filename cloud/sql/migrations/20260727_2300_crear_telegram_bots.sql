-- Crea la tabla `telegram_bots`, el catalogo de bots de Telegram que la
-- plataforma usa para enviar mensajes. Cada fila representa un bot dado de
-- alta en @BotFather con su token, opcionalmente asociado a un proyecto y
-- con un chat_id destino por defecto para envios rapidos.
--
-- Esquema base (alineado con `evolution_canales`):
--   * `slug`        -> discriminador cloud (ver memoria de roles/permisos
--                       cloud vs legacy). Cualquier bot dado de alta desde
--                       este panel debe tener slug NOT NULL; se autogenera
--                       en la API si el cliente no lo manda.
--   * `proyecto`    -> FK logica a `proyectos.id` (NULLABLE, sin CONSTRAINT
--                       para respetar el estilo del resto del schema).
--   * `nombre`      -> nombre interno del bot ("Bot de soporte", etc.).
--   * `username`    -> handle publico en Telegram (`@mi_bot`) sin arroba.
--   * `token`       -> token de BotFather (`123456:AAE...`). Sensible.
--   * `chat_id`     -> chat_id destino por defecto (usuario, grupo o canal).
--                       VARCHAR porque puede ser negativo o largo (canales
--                       usan `-100...`).
--   * `habilitado`  -> flag 'S'/'N' (o '1'/'0' segun convencion legacy).
--                       El motor de envio ignora los bots con habilitado='0'.
--   * `actualizado` -> ultima modificacion, seteado por la API.
--
-- Idempotente: CREATE TABLE IF NOT EXISTS. Compatible con MySQL 8 y
-- MariaDB 10.11.

CREATE TABLE IF NOT EXISTS `telegram_bots` (
  `id`          INT(11)      NOT NULL AUTO_INCREMENT,
  `slug`        VARCHAR(50)  NULL DEFAULT NULL,
  `proyecto`    INT(11)      NULL DEFAULT NULL,
  `nombre`      VARCHAR(255) NULL DEFAULT NULL,
  `username`    VARCHAR(100) NULL DEFAULT NULL,
  `token`       VARCHAR(255) NULL DEFAULT NULL,
  `chat_id`     VARCHAR(50)  NULL DEFAULT NULL,
  `habilitado`  VARCHAR(1)   NULL DEFAULT NULL,
  `actualizado` DATETIME     NULL DEFAULT NULL,
  PRIMARY KEY (`id`) USING BTREE,
  INDEX `idx_telegram_bots_proyecto`   (`proyecto`)   USING BTREE,
  INDEX `idx_telegram_bots_habilitado` (`habilitado`) USING BTREE
) ENGINE=InnoDB CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci ROW_FORMAT=Dynamic;
