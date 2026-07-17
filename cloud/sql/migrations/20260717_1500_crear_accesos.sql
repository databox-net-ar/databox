-- Crea la tabla `accesos` -- catalogo de credenciales para conectarse a
-- sistemas externos administrados desde Databox (Movistar Kite, Claro
-- Portal, paneles de proveedores, consolas de dominios, etc.). Cada fila
-- representa un acceso: para donde es (`nombre` + `url`), con que
-- credencial se entra (`usuario` + `contrasena`), cuando se toco por
-- ultima vez (`actualizado`, automatico) y si es "privado" para uso
-- restringido (por ahora solo un flag de futuro; la UI todavia no cambia
-- el comportamiento en base a el).
--
-- La contrasena se guarda con la cifra reversible legacy del grupo
-- (`encriptar()`/`desencriptar()` de auth.php) igual que `usuarios.contrasena`,
-- para que el operador pueda recuperarla en claro desde la UI y pegarla en
-- el sistema externo. El cifrado protege contra un dump plano de la BD, no
-- contra un atacante con acceso a la aplicacion.
--
-- Idempotente: CREATE TABLE IF NOT EXISTS. Compatible con MySQL 8 y
-- MariaDB 10.11.

CREATE TABLE IF NOT EXISTS `accesos` (
  `id`           INT(11)      NOT NULL AUTO_INCREMENT,
  `nombre`       VARCHAR(200) NOT NULL,
  `url`          VARCHAR(500) NULL DEFAULT NULL,
  `usuario`      VARCHAR(200) NULL DEFAULT NULL,
  `contrasena`   VARCHAR(500) NULL DEFAULT NULL,
  `privado`      TINYINT(1)   NOT NULL DEFAULT 0,
  `actualizado`  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP
                              ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`) USING BTREE
) ENGINE=InnoDB CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci ROW_FORMAT=Dynamic;
