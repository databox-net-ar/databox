-- Crea la tabla `telegram_canales`, el catalogo de CUENTAS DE USUARIO de
-- Telegram (MTProto via MadelineProto) que la plataforma usa para enviar
-- mensajes "como una persona real" desde /v4/telegram/mensajes.
--
-- Distinta de `telegram_bots`:
--   * `telegram_bots`     -> cuentas BOT (Bot API HTTP, token de BotFather).
--                             Usadas por el ABM cloud (cloud/api/telegrammensajes.php)
--                             y por cualquier caller que quiera mandar via bot.
--   * `telegram_canales`  -> cuentas USUARIO (MTProto). Usadas por el
--                             microservicio v4 (api/v4/telegram/mensajes.php).
--                             El "token" es un directorio de sesion
--                             (api/v4/telegram/session_<telefono>/) que se
--                             genera una unica vez en DESARROLLO via CLI
--                             interactivo (login del numero + codigo por
--                             Telegram) y que se sube a produccion via
--                             deploy.sh. Prod jamas inicia sesion; solo
--                             consume las que se crearon en dev.
--
-- Esquema (alineado con `telegram_bots` para consistencia visual, pero sin
-- las columnas propias del Bot API -- `token`, `username`, `chat_id`):
--   * `slug`        -> discriminador cloud (ver memoria de roles/permisos
--                       cloud vs legacy). NOT NULL en la practica; el endpoint
--                       lo usa para resolver el subdirectorio de la sesion.
--   * `proyecto`    -> FK logica a `proyectos.id` (NULLABLE, sin CONSTRAINT).
--   * `nombre`      -> nombre humano del canal ("Javier Alvarez (personal)").
--   * `telefono`    -> numero E.164 SIN '+' (ej. '541163219578'). Determina
--                       el nombre del subdirectorio de sesion en disco
--                       (session_<telefono>/), asi el filesystem refleja
--                       QUE numero esta logueado y renombrar el slug no
--                       muda la sesion.
--   * `habilitado`  -> '1'/'0'. El endpoint rechaza envios de canales
--                       deshabilitados.
--   * `actualizado` -> ultima modificacion, seteado por la API.
--
-- Ademas se seedea el canal 'javier' (que corresponde al +541163219578,
-- unica cuenta ya logueada al 2026-07-30 en
-- api/v4/telegram/session_541163219578/). El rename fisico del directorio
-- de sesion se hace fuera del SQL, en el mismo commit que introduce esta
-- migracion.
--
-- Idempotente: CREATE TABLE IF NOT EXISTS + INSERT ... SELECT ... WHERE
-- NOT EXISTS. Compatible con MySQL 8 y MariaDB 10.11 (ver memoria dev vs prod).

CREATE TABLE IF NOT EXISTS `telegram_canales` (
  `id`          INT(11)      NOT NULL AUTO_INCREMENT,
  `slug`        VARCHAR(50)  NULL DEFAULT NULL,
  `proyecto`    INT(11)      NULL DEFAULT NULL,
  `nombre`      VARCHAR(255) NULL DEFAULT NULL,
  `telefono`    VARCHAR(20)  NULL DEFAULT NULL,
  `habilitado`  VARCHAR(1)   NULL DEFAULT NULL,
  `actualizado` DATETIME     NULL DEFAULT NULL,
  PRIMARY KEY (`id`) USING BTREE,
  INDEX `idx_telegram_canales_slug`       (`slug`)       USING BTREE,
  INDEX `idx_telegram_canales_proyecto`   (`proyecto`)   USING BTREE,
  INDEX `idx_telegram_canales_habilitado` (`habilitado`) USING BTREE
) ENGINE=InnoDB CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci ROW_FORMAT=Dynamic;

-- Seed del canal ya existente (+541163219578, sesion en session_javier/).
-- Se hace via INSERT ... SELECT ... WHERE NOT EXISTS para mantener la
-- migracion idempotente ante re-corridas.
INSERT INTO `telegram_canales` (`slug`, `nombre`, `telefono`, `habilitado`, `actualizado`)
SELECT 'javier', 'Javier Alvarez (personal)', '541163219578', '1', NOW()
WHERE NOT EXISTS (SELECT 1 FROM `telegram_canales` WHERE `slug` = 'javier');
