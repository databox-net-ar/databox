-- Crea la tabla `datacount_cuentas` como Plan de Cuentas jerárquico
-- (equivalente al esquema de la BD `repo`.`cuentas`): codigo único,
-- jerarquía por parent_id + nivel, imputable, naturaleza, activa y saldo.
--
-- También descarta la tabla legacy `datacountcuentas` (sin guion bajo,
-- MyISAM, esquema padre/orden/categoria/tipo/nombre/observaciones/saldo)
-- que existía en schema.sql. Esa tabla se reemplaza por `datacount_cuentas`.
--
-- Idempotente: DROP TABLE IF EXISTS + CREATE. En entornos nuevos schema.sql
-- ya define esta estructura y la migración no altera nada. En entornos
-- viejos, tira los datos previos (autorizado por el usuario en la sesión
-- que introdujo la migración) y deja la tabla lista para el auto-seed del
-- endpoint datacountcuentas.php.

DROP TABLE IF EXISTS `datacountcuentas`;
DROP TABLE IF EXISTS `datacount_cuentas`;

CREATE TABLE `datacount_cuentas` (
  `id`          int(11) UNSIGNED NOT NULL AUTO_INCREMENT,
  `codigo`      varchar(20)  CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL,
  `nombre`      varchar(160) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL,
  `tipo`        enum('activo','pasivo','patrimonio','ingreso','egreso')
                CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL,
  `parent_id`   int(11) UNSIGNED NULL DEFAULT NULL,
  `nivel`       tinyint(3) UNSIGNED NOT NULL DEFAULT 1,
  `imputable`   tinyint(1) NOT NULL DEFAULT 1,
  `naturaleza`  enum('deudora','acreedora')
                CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL,
  `descripcion` text CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NULL,
  `activa`      tinyint(1) NOT NULL DEFAULT 1,
  `saldo`       decimal(14, 2) NOT NULL DEFAULT 0.00,
  `created_at`  timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`  timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`) USING BTREE,
  UNIQUE INDEX `codigo`(`codigo`) USING BTREE,
  INDEX `idx_parent`(`parent_id`) USING BTREE,
  INDEX `idx_tipo`(`tipo`) USING BTREE
) ENGINE = InnoDB CHARACTER SET = utf8mb4 COLLATE = utf8mb4_general_ci ROW_FORMAT = Dynamic;
