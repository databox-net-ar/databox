-- Tabla `sucesos`: log de actividad de los distintos modulos del panel.
-- Idempotente: CREATE TABLE IF NOT EXISTS permite que la migracion
-- corra tanto en entornos nuevos (donde schema.sql ya la cargo)
-- como en entornos existentes (donde todavia no existe).

CREATE TABLE IF NOT EXISTS `sucesos` (
  `id`      int(11)      NOT NULL AUTO_INCREMENT,
  `fecha`   datetime(0)  NULL DEFAULT NULL,
  `origen`  varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NULL DEFAULT NULL,
  `detalle` text         CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NULL,
  PRIMARY KEY (`id`) USING BTREE
) ENGINE = InnoDB CHARACTER SET = utf8mb4 COLLATE = utf8mb4_general_ci ROW_FORMAT = Dynamic;
