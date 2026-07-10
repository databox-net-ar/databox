-- Crea `datacount_asientos` + `datacount_asientos_detalles` — asientos
-- contables de Datacount (mismo esquema que `repo.asientos` / `repo.asiento_detalles`).
--
-- Cada asiento agrupa 2+ líneas (detalles) que apuntan a cuentas del plan
-- (`datacount_cuentas`). El total DEBE debe igualar al total HABER y todas
-- las cuentas linkeadas deben ser imputables (validado en el endpoint PHP,
-- no en DB). El detalle tiene ON DELETE CASCADE contra el asiento padre.
--
-- Idempotente: CREATE TABLE IF NOT EXISTS. En entornos nuevos schema.sql
-- ya trae estas tablas; en entornos existentes las crea vacías.

CREATE TABLE IF NOT EXISTS `datacount_asientos` (
  `id`          int(11) UNSIGNED NOT NULL AUTO_INCREMENT,
  `numero`      int(11) UNSIGNED NOT NULL,
  `fecha`       date NOT NULL,
  `descripcion` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL,
  `total`       decimal(14, 2) NOT NULL DEFAULT 0.00,
  `created_at`  timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`  timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`) USING BTREE,
  UNIQUE INDEX `uk_numero`(`numero`) USING BTREE,
  INDEX `idx_fecha`(`fecha`) USING BTREE
) ENGINE = InnoDB CHARACTER SET = utf8mb4 COLLATE = utf8mb4_general_ci ROW_FORMAT = Dynamic;

CREATE TABLE IF NOT EXISTS `datacount_asientos_detalles` (
  `id`          int(11) UNSIGNED NOT NULL AUTO_INCREMENT,
  `asiento_id`  int(11) UNSIGNED NOT NULL,
  `cuenta_id`   int(11) UNSIGNED NOT NULL,
  `debe`        decimal(14, 2) NOT NULL DEFAULT 0.00,
  `haber`       decimal(14, 2) NOT NULL DEFAULT 0.00,
  `descripcion` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NULL DEFAULT NULL,
  `orden`       tinyint(3) UNSIGNED NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`) USING BTREE,
  INDEX `idx_asiento`(`asiento_id`) USING BTREE,
  INDEX `idx_cuenta`(`cuenta_id`) USING BTREE,
  CONSTRAINT `fk_dcad_asiento` FOREIGN KEY (`asiento_id`)
      REFERENCES `datacount_asientos` (`id`) ON DELETE CASCADE ON UPDATE RESTRICT
) ENGINE = InnoDB CHARACTER SET = utf8mb4 COLLATE = utf8mb4_general_ci ROW_FORMAT = Dynamic;
