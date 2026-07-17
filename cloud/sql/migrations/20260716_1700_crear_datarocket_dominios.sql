-- Crea la tabla `datarocket_dominios` -- catalogo de dominios (DNS) que
-- Databox administra. Cada fila representa un dominio con su entidad
-- registrante, su titular WHOIS, quien lo renueva operativamente
-- (responsable), las fechas del ciclo de vida (registro, ultima
-- renovacion, proxima renovacion) y el costo de la renovacion con su
-- moneda ISO 4217.
--
-- Portada desde el modulo `dominios` del monorepo `dex` conservando
-- todos los campos, excepto `cuenta_id` (aqui no aplica: los dominios
-- de Databox no estan asociados a una cuenta).
--
-- `dominio` va con UNIQUE: no tiene sentido tener el mismo dominio
-- cargado dos veces. Se indexa `fecha_siguiente_renovacion` para
-- listar rapido los dominios por vencer, y `responsable` para
-- filtrar "que dominios renovamos nosotros" vs los del cliente.
--
-- Idempotente: CREATE TABLE IF NOT EXISTS. Compatible con MySQL 8 y
-- MariaDB 10.11.

CREATE TABLE IF NOT EXISTS `datarocket_dominios` (
  `id`                          INT(11)        NOT NULL AUTO_INCREMENT,
  `dominio`                     VARCHAR(255)   NOT NULL,
  `titular_dominio`             VARCHAR(200)   NULL DEFAULT NULL,
  `entidad_registrante`         VARCHAR(200)   NULL DEFAULT NULL,
  `responsable`                 VARCHAR(20)    NOT NULL DEFAULT 'Databox',
  `fecha_registro`              DATE           NULL DEFAULT NULL,
  `fecha_ultima_renovacion`     DATE           NULL DEFAULT NULL,
  `fecha_siguiente_renovacion`  DATE           NULL DEFAULT NULL,
  `costo_renovacion`            DECIMAL(12, 2) NULL DEFAULT NULL,
  `moneda`                      VARCHAR(3)     NOT NULL DEFAULT 'ARS',
  `fecha_creacion`              DATETIME       NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`) USING BTREE,
  UNIQUE KEY `uq_datarocket_dominios_dominio` (`dominio`) USING BTREE,
  KEY `idx_datarocket_dominios_prox_renov` (`fecha_siguiente_renovacion`) USING BTREE,
  KEY `idx_datarocket_dominios_responsable` (`responsable`) USING BTREE
) ENGINE=InnoDB CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci ROW_FORMAT=Dynamic;
