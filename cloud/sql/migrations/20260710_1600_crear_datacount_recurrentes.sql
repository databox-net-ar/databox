-- Crea `datacount_recurrentes` — catálogo de movimientos contables
-- recurrentes (por empresa + cuenta) con montos previstos de ingreso
-- y egreso y un flag de activación. Sirve como plantilla para generar
-- asientos periódicos o para reporting de expectativas.
--
-- `empresa` referencia `datacount_empresas.id` y `cuenta` referencia
-- `datacount_cuentas.id`. No se crean FKs físicas para mantener la
-- simplicidad del ABM (validación en el endpoint PHP).
--
-- Idempotente: CREATE TABLE IF NOT EXISTS. En entornos nuevos schema.sql
-- ya la define; en entornos existentes queda creada vacía.

CREATE TABLE IF NOT EXISTS `datacount_recurrentes` (
  `id`         int(11) UNSIGNED NOT NULL AUTO_INCREMENT,
  `empresa`    int(11) UNSIGNED NOT NULL,
  `cuenta`     int(11) UNSIGNED NOT NULL,
  `ingreso`    decimal(14, 2) NOT NULL DEFAULT 0.00,
  `egreso`     decimal(14, 2) NOT NULL DEFAULT 0.00,
  `activo`     tinyint(1) NOT NULL DEFAULT 1,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`) USING BTREE,
  INDEX `idx_empresa`(`empresa`) USING BTREE,
  INDEX `idx_cuenta`(`cuenta`) USING BTREE,
  INDEX `idx_activo`(`activo`) USING BTREE
) ENGINE = InnoDB CHARACTER SET = utf8mb4 COLLATE = utf8mb4_general_ci ROW_FORMAT = Dynamic;
