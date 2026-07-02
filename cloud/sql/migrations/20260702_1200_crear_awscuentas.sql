-- Tabla `awscuentas`: cuentas de AWS que la API usa para consultar datos.
-- Guarda credenciales de consola (numero + contrasena) y credenciales
-- programaticas (accesskey + secreto). `nombre` es la etiqueta para
-- referirse a la cuenta desde el panel.
-- Idempotente: CREATE TABLE IF NOT EXISTS permite que la migracion
-- corra tanto en entornos nuevos (donde schema.sql ya la cargo)
-- como en entornos existentes (donde todavia no existe).

CREATE TABLE IF NOT EXISTS `awscuentas` (
  `id`         int(11)      NOT NULL AUTO_INCREMENT,
  `nombre`     varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NULL DEFAULT NULL,
  `numero`     varchar(20)  CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NULL DEFAULT NULL,
  `contrasena` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NULL DEFAULT NULL,
  `accesskey`  varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NULL DEFAULT NULL,
  `secreto`    varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NULL DEFAULT NULL,
  PRIMARY KEY (`id`) USING BTREE
) ENGINE = InnoDB CHARACTER SET = utf8mb4 COLLATE = utf8mb4_general_ci ROW_FORMAT = Dynamic;
