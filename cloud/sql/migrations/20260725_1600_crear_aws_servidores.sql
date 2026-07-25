-- Crea la tabla `aws_servidores` -- catalogo de servidores administrados
-- desde Databox en la infraestructura AWS (EC2 y similares). Cada fila
-- representa un servidor con su DNS/hostname, su IP para ping, la
-- region, el tipo de instancia, las credenciales SSH y opcionalmente
-- la cuenta AWS a la que pertenece.
--
-- La contrasena SSH se guarda en claro, igual que `aws_cuentas.contrasena`
-- y `aws_canales.contrasena`, para que el operador pueda copiarla desde
-- la UI y pegarla en el cliente SSH.
--
-- `cuenta_id` referencia `aws_cuentas.id` a nivel logico (indice, no FK
-- hard), consistente con el resto de las tablas del modulo AWS que no
-- declaran FKs entre si.
--
-- Idempotente: CREATE TABLE IF NOT EXISTS. Compatible con MySQL 8 y
-- MariaDB 10.11.

CREATE TABLE IF NOT EXISTS `aws_servidores` (
  `id`              INT(11)      NOT NULL AUTO_INCREMENT,
  `nombre`          VARCHAR(255) NOT NULL,
  `host`            VARCHAR(255) NULL DEFAULT NULL,
  `ip`              VARCHAR(45)  NULL DEFAULT NULL,
  `region`          VARCHAR(30)  NULL DEFAULT NULL,
  `tipo_instancia` VARCHAR(50)   NULL DEFAULT NULL,
  `usuario_ssh`     VARCHAR(255) NULL DEFAULT NULL,
  `contrasena_ssh`  VARCHAR(255) NULL DEFAULT NULL,
  `cuenta_id`       INT(11)      NULL DEFAULT NULL,
  `estado`          VARCHAR(1)   NULL DEFAULT '1',
  `notas`           TEXT         NULL DEFAULT NULL,
  `actualizado`     DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP
                                 ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`) USING BTREE,
  INDEX `idx_aws_servidores_cuenta_id` (`cuenta_id`) USING BTREE
) ENGINE=InnoDB CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci ROW_FORMAT=Dynamic;
