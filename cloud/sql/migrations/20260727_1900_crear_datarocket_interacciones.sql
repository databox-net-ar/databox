-- Crea la tabla `datarocket_interacciones` que registra el historial de
-- interacciones sobre cada contacto de Datarocket. Se alimenta desde las
-- APIs de envio (aws_mensajes / evolution_mensajes) — no desde la UI del
-- panel — asi que el ABM cloud es de solo lectura (sin agregar / editar,
-- si con eliminar).
--
-- Esquema:
--   * `contacto_id`  -> `datarocket_contactos.id` (obligatorio: toda
--                        interaccion corresponde a UN contacto).
--   * `fecha`        -> cuando ocurrio la interaccion (obligatorio;
--                        suele ser NOW() al momento de encolar / enviar).
--   * `tipo`         -> categoria de la interaccion. Empezamos con
--                        'correo_enviado' pero queda VARCHAR(30) para
--                        poder sumar 'whatsapp_enviado', 'sms_enviado',
--                        'correo_abierto', 'link_clickeado', etc. sin
--                        migrar. La UI mapea codigo -> etiqueta.
--   * `mensaje_id` + `origen` -> asociacion POLIMORFICA al mensaje que
--                        origino la interaccion. `mensaje_id` guarda el
--                        ID y `origen` indica en que tabla buscarlo.
--                        Hoy los unicos dos valores validos de `origen`
--                        son 'aws_mensajes' y 'evolution_mensajes' — se
--                        deja VARCHAR(50) para poder sumar mas motores
--                        (sms, telegram, push) sin migrar. La validacion
--                        se hace en la API. Ambas columnas son NULLABLE
--                        para permitir interacciones sin mensaje asociado.
--   * `descripcion`  -> texto libre corto para mostrar en el listado
--                        (ej. asunto del correo, primer texto del
--                        whatsapp, etc.).
--
-- Idempotente: CREATE TABLE IF NOT EXISTS. Compatible con MySQL 8 y
-- MariaDB 10.11.

CREATE TABLE IF NOT EXISTS `datarocket_interacciones` (
  `id`          INT(11)      NOT NULL AUTO_INCREMENT,
  `fecha`       DATETIME     NOT NULL,
  `contacto_id` INT(11)      NOT NULL,
  `tipo`        VARCHAR(30)  NOT NULL DEFAULT 'correo_enviado',
  `origen`      VARCHAR(50)  NULL DEFAULT NULL,
  `mensaje_id`  INT(11)      NULL DEFAULT NULL,
  `descripcion` VARCHAR(500) NULL DEFAULT NULL,
  PRIMARY KEY (`id`) USING BTREE,
  INDEX `idx_dri_contacto_fecha` (`contacto_id`, `fecha`) USING BTREE,
  INDEX `idx_dri_fecha`          (`fecha`) USING BTREE,
  INDEX `idx_dri_tipo`           (`tipo`) USING BTREE
) ENGINE=InnoDB CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci ROW_FORMAT=Dynamic;
