-- Crea la tabla `aws_bases` -- catalogo de bases de datos administradas
-- desde Databox en la infraestructura AWS (RDS, Aurora y motores
-- similares). Cada fila representa una base con su motor, host, puerto,
-- nombre de base, credenciales de conexion y opcionalmente la cuenta
-- AWS a la que pertenece.
--
-- La contrasena se guarda en claro, igual que el resto de las tablas
-- de credenciales del modulo AWS (aws_cuentas, aws_canales), para que
-- el operador pueda copiarla desde la UI y pegarla en el cliente SQL.
--
-- `cuenta_id` referencia `aws_cuentas.id` a nivel logico (indice, no FK
-- hard), consistente con el resto de las tablas del modulo AWS.
--
-- Idempotente: CREATE TABLE IF NOT EXISTS. Compatible con MySQL 8 y
-- MariaDB 10.11.

CREATE TABLE IF NOT EXISTS `aws_bases` (
  `id`           INT(11)      NOT NULL AUTO_INCREMENT,
  `nombre`       VARCHAR(255) NOT NULL,
  `motor`        VARCHAR(30)  NULL DEFAULT NULL,
  `host`         VARCHAR(255) NULL DEFAULT NULL,
  `puerto`       INT(11)      NULL DEFAULT NULL,
  `base`         VARCHAR(255) NULL DEFAULT NULL,
  `usuario`      VARCHAR(255) NULL DEFAULT NULL,
  `contrasena`   VARCHAR(255) NULL DEFAULT NULL,
  `cuenta_id`    INT(11)      NULL DEFAULT NULL,
  `estado`       VARCHAR(1)   NULL DEFAULT '1',
  `notas`        TEXT         NULL DEFAULT NULL,
  `actualizado`  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP
                              ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`) USING BTREE,
  INDEX `idx_aws_bases_cuenta_id` (`cuenta_id`) USING BTREE
) ENGINE=InnoDB CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci ROW_FORMAT=Dynamic;
