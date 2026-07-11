-- Crea `datacount_empleados` — empleados asociados a una empresa Datacount.
-- Cada fila combina datos personales (nombre, documento, nacimiento,
-- domicilio), de contacto (celular, correo) y laborales (cuenta contable
-- donde imputa el sueldo, sueldo mensual, habilitación y observaciones).
--
-- `empresa_id` referencia `datacount_empresas.id` y `cuenta` referencia
-- `datacount_cuentas.id`. No se crean FKs físicas para mantener la
-- simplicidad del ABM (validación en el endpoint PHP).
--
-- Idempotente: CREATE TABLE IF NOT EXISTS. En entornos nuevos schema.sql
-- ya la define; en entornos existentes queda creada vacía.

CREATE TABLE IF NOT EXISTS `datacount_empleados` (
  `id`            int(11) UNSIGNED NOT NULL AUTO_INCREMENT,
  `empresa_id`    int(11) UNSIGNED NOT NULL,
  `nombre`        varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL,
  `documento`     varchar(15)  CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NULL DEFAULT NULL,
  `nacimiento`    date NULL DEFAULT NULL,
  `domicilio`     varchar(200) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NULL DEFAULT NULL,
  `celular`       varchar(20)  CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NULL DEFAULT NULL,
  `correo`        varchar(120) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NULL DEFAULT NULL,
  `cuenta`        int(11) UNSIGNED NULL DEFAULT NULL,
  `sueldo`        decimal(14, 2) NOT NULL DEFAULT 0.00,
  `habilitado`    tinyint(1) NOT NULL DEFAULT 1,
  `observaciones` varchar(1000) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NULL DEFAULT NULL,
  `created_at`    timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`    timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`) USING BTREE,
  INDEX `idx_empresa`(`empresa_id`) USING BTREE,
  INDEX `idx_cuenta`(`cuenta`) USING BTREE,
  INDEX `idx_habilitado`(`habilitado`) USING BTREE,
  INDEX `idx_documento`(`documento`) USING BTREE
) ENGINE = InnoDB CHARACTER SET = utf8mb4 COLLATE = utf8mb4_general_ci ROW_FORMAT = Dynamic;
