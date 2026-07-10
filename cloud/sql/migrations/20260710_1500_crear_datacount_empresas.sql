-- Crea `datacount_empresas` — catálogo de empresas para las que
-- Datacount lleva la contabilidad. Cada empresa tiene los datos
-- identificatorios y fiscales mínimos (razón social, CUIT, condición
-- ante AFIP, IIBB, domicilio e inicio de actividades).
--
-- El identificador visible es `id` (la columna `Código` del listado).
-- La `razon` (razón social) es UNIQUE porque no puede haber dos
-- empresas registradas con la misma razón social.
--
-- Idempotente: CREATE TABLE IF NOT EXISTS. En entornos nuevos schema.sql
-- ya la define; en entornos existentes queda creada vacía.

CREATE TABLE IF NOT EXISTS `datacount_empresas` (
  `id`         int(11) UNSIGNED NOT NULL AUTO_INCREMENT,
  `nombre`     varchar(160) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL,
  `razon`      varchar(200) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL,
  `domicilio`  varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NULL DEFAULT NULL,
  `condicion`  enum('responsable_inscripto','monotributista','exento','consumidor_final','no_responsable','no_categorizado')
               CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL DEFAULT 'responsable_inscripto',
  `cuit`       varchar(15)  CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NULL DEFAULT NULL,
  `iibb`       varchar(30)  CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NULL DEFAULT NULL,
  `inicio`     date NULL DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`) USING BTREE,
  UNIQUE INDEX `uk_razon`(`razon`) USING BTREE,
  INDEX `idx_cuit`(`cuit`) USING BTREE
) ENGINE = InnoDB CHARACTER SET = utf8mb4 COLLATE = utf8mb4_general_ci ROW_FORMAT = Dynamic;
