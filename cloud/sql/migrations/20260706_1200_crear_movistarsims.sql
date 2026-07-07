-- Tabla `movistarsims`: catalogo de SIMs M2M administradas via Kite Platform
-- (Movistar / Telefonica). Cada fila representa una linea con su ICCID (icc)
-- como identificador natural unico, y refleja el estado devuelto por la
-- consola de Kite (estado general, GPRS, LTE, limite de datos, IMEI, MSISDN).
-- El campo `nombre` corresponde al alias editable de Kite (field1).
-- `actualizado` guarda el timestamp del ultimo sync desde la API de Kite.
-- Idempotente: CREATE TABLE IF NOT EXISTS permite que la migracion corra
-- tanto en entornos nuevos (donde schema.sql ya la cargo) como en
-- entornos existentes (donde todavia no existe).

CREATE TABLE IF NOT EXISTS `movistarsims` (
  `id`           int(11)      NOT NULL AUTO_INCREMENT,
  `nombre`       varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NULL DEFAULT NULL,
  `linea`        varchar(30)  CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NULL DEFAULT NULL,
  `icc`          varchar(25)  CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NULL DEFAULT NULL,
  `estado`       varchar(40)  CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NULL DEFAULT NULL,
  `estado_gprs`  varchar(40)  CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NULL DEFAULT NULL,
  `estado_lte`   varchar(40)  CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NULL DEFAULT NULL,
  `limite_datos` varchar(40)  CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NULL DEFAULT NULL,
  `imei`         varchar(30)  CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NULL DEFAULT NULL,
  `msisdn`       varchar(30)  CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NULL DEFAULT NULL,
  `actualizado`  datetime(0)  NULL DEFAULT NULL,
  PRIMARY KEY (`id`) USING BTREE,
  UNIQUE INDEX `uk_movistarsims_icc`(`icc`) USING BTREE
) ENGINE = InnoDB CHARACTER SET = utf8mb4 COLLATE = utf8mb4_general_ci ROW_FORMAT = Dynamic;
