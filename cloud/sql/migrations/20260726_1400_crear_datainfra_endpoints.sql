-- Crea las tablas `datainfra_endpoints` + `datainfra_endpoints_ejecuciones`
-- que soportan el monitoreo periodico de salud (health-check) de todos los
-- endpoints HTTP/HTTPS con los que Databox integra: propios (API, cloud,
-- robot) y de terceros (plataformas, proveedores). Un job cron recorre las
-- filas `activo = 1`, hace la request configurada y compara con el codigo
-- esperado (+ opcionalmente un substring del body). El resultado se
-- registra en dos lugares:
--
--   * `datainfra_endpoints.ultimo_*`   -> snapshot rapido para el ABM,
--                                         dashboard y semaforo verde/rojo.
--   * `datainfra_endpoints_ejecuciones` -> historial completo para armar
--                                         graficos de uptime/latencia y
--                                         auditar caidas viejas.
--
-- Notas de diseno:
--   * `metodo` VARCHAR(10) en vez de ENUM para no romper si aparecen
--     verbos nuevos (PATCH/OPTIONS) -- se valida desde el endpoint PHP.
--   * `headers` es TEXT con JSON opcional (ej. { "Authorization":
--     "Bearer ..." }) -- lo parsea el job antes de armar la request.
--   * `patron_respuesta` es un substring case-sensitive que se busca en
--     el body de la respuesta para validar "el endpoint no solo devolvio
--     200, ademas contesto lo que esperabamos" (ej. buscar `"ok":true`).
--   * `ultimo_estado` incluye `nunca` para filas recien dadas de alta
--     que aun no fueron chequeadas por el job.
--   * `datainfra_endpoints_ejecuciones` tiene FK a `datainfra_endpoints`
--     con ON DELETE CASCADE: si el operador borra el endpoint, se van
--     todas sus corridas historicas.
--
-- Idempotente: CREATE TABLE IF NOT EXISTS. Compatible con MySQL 8 y
-- MariaDB 10.11.

CREATE TABLE IF NOT EXISTS `datainfra_endpoints` (
  `id`                  INT(11)              NOT NULL AUTO_INCREMENT,
  `nombre`              VARCHAR(120)         NOT NULL,
  `descripcion`         VARCHAR(500)         NULL DEFAULT NULL,
  `url`                 VARCHAR(500)         NOT NULL,
  `metodo`              VARCHAR(10)          NOT NULL DEFAULT 'GET',
  `headers`             TEXT                 NULL DEFAULT NULL,
  `body`                MEDIUMTEXT           NULL DEFAULT NULL,
  `codigo_esperado`     SMALLINT(5) UNSIGNED NOT NULL DEFAULT 200,
  `patron_respuesta`    VARCHAR(255)         NULL DEFAULT NULL,
  `timeout_seg`         SMALLINT(5) UNSIGNED NOT NULL DEFAULT 15,
  `activo`              TINYINT(1)           NOT NULL DEFAULT 1,
  `ultimo_check`        DATETIME             NULL DEFAULT NULL,
  `ultimo_estado`       ENUM('ok','error','timeout','nunca') NOT NULL DEFAULT 'nunca',
  `ultimo_codigo`       SMALLINT(5) UNSIGNED NULL DEFAULT NULL,
  `ultimo_tiempo_ms`    INT(10) UNSIGNED     NULL DEFAULT NULL,
  `ultimo_error`        VARCHAR(500)         NULL DEFAULT NULL,
  `fecha_creacion`      DATETIME             NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `fecha_modificacion`  DATETIME             NOT NULL DEFAULT CURRENT_TIMESTAMP
                                             ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`) USING BTREE,
  INDEX `idx_datainfra_endpoints_activo`        (`activo`) USING BTREE,
  INDEX `idx_datainfra_endpoints_ultimo_estado` (`ultimo_estado`) USING BTREE
) ENGINE=InnoDB CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci ROW_FORMAT=Dynamic;

CREATE TABLE IF NOT EXISTS `datainfra_endpoints_ejecuciones` (
  `id`             INT(10) UNSIGNED NOT NULL AUTO_INCREMENT,
  `endpoint_id`    INT(11)          NOT NULL,
  `inicio`         DATETIME         NOT NULL,
  `fin`            DATETIME         NULL DEFAULT NULL,
  `estado`         ENUM('ok','error','timeout') NOT NULL,
  `codigo`         SMALLINT(5) UNSIGNED NULL DEFAULT NULL,
  `tiempo_ms`      INT(10) UNSIGNED NULL DEFAULT NULL,
  `error`          VARCHAR(500)     NULL DEFAULT NULL,
  PRIMARY KEY (`id`) USING BTREE,
  INDEX `idx_de_ejec_endpoint_inicio` (`endpoint_id`, `inicio`) USING BTREE,
  INDEX `idx_de_ejec_estado`          (`estado`) USING BTREE,
  CONSTRAINT `fk_de_ejec_endpoint`
    FOREIGN KEY (`endpoint_id`) REFERENCES `datainfra_endpoints` (`id`)
    ON DELETE CASCADE ON UPDATE RESTRICT
) ENGINE=InnoDB CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci ROW_FORMAT=Dynamic;
