-- Crea `datacount_proveedores` — catálogo transversal (multiempresa)
-- de proveedores de Datacount. Los proveedores NO están asociados a
-- una `datacount_empresas` en particular: el mismo proveedor puede
-- recibir pagos desde cualquiera de las empresas administradas por
-- Datacount.
--
-- Cada fila reúne datos identificatorios (nombre, razón social,
-- condición fiscal AFIP, CUIT), de contacto (domicilio, celular,
-- correo, web) y bancarios (CBU, para transferencias).
--
-- Idempotente: CREATE TABLE IF NOT EXISTS. En entornos nuevos schema.sql
-- ya la define; en entornos existentes queda creada vacía.

CREATE TABLE IF NOT EXISTS `datacount_proveedores` (
  `id`         int(11) UNSIGNED NOT NULL AUTO_INCREMENT,
  `nombre`     varchar(160) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL,
  `razon`      varchar(200) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NULL DEFAULT NULL,
  `condicion`  enum('responsable_inscripto','monotributista','exento','consumidor_final','no_responsable','no_categorizado') CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL DEFAULT 'responsable_inscripto',
  `cuit`       varchar(15)  CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NULL DEFAULT NULL,
  `domicilio`  varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NULL DEFAULT NULL,
  `celular`    varchar(20)  CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NULL DEFAULT NULL,
  `correo`     varchar(120) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NULL DEFAULT NULL,
  `web`        varchar(200) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NULL DEFAULT NULL,
  `cbu`        varchar(50)  CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NULL DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`) USING BTREE,
  INDEX `idx_nombre`(`nombre`) USING BTREE,
  INDEX `idx_cuit`(`cuit`) USING BTREE
) ENGINE = InnoDB CHARACTER SET = utf8mb4 COLLATE = utf8mb4_general_ci ROW_FORMAT = Dynamic;
