-- Crea `datarocket_etiquetas` — catalogo de etiquetas reutilizables que se
-- podran aplicar a otros recursos de Datarocket (plantillas, contactos,
-- interacciones, etc.). Esta migracion SOLO crea el catalogo y su ABM; las
-- tablas de union que asocien etiquetas con cada recurso destino se agregan
-- despues, cuando se enchufe el uso en cada modulo.
--
--   * `nombre`      corto y UNIQUE — es la clave logica que ven los usuarios
--                   al elegir una etiqueta desde otros modulos.
--   * `color`       hex `#RRGGBB` — se usa para pintar el badge en las UIs
--                   consumidoras. Default gris neutro.
--   * `descripcion` opcional — nota interna para explicar cuando aplica.
--   * `activo`      permite ocultar la etiqueta como opcion nueva sin
--                   perder las asignaciones historicas (soft-disable).
--
-- Idempotente: CREATE TABLE IF NOT EXISTS. Compatible con MySQL 8.0 (dev)
-- y MariaDB 10.11 (prod).

CREATE TABLE IF NOT EXISTS `datarocket_etiquetas` (
  `id`                  int(11)      NOT NULL AUTO_INCREMENT,
  `nombre`              varchar(80)  CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL,
  `color`               varchar(7)   CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL DEFAULT '#6b7280',
  `descripcion`         varchar(500) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NULL DEFAULT NULL,
  `activo`              tinyint(1)   NOT NULL DEFAULT 1,
  `fecha_creacion`      datetime     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `fecha_modificacion`  datetime     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`) USING BTREE,
  UNIQUE INDEX `uq_datarocket_etiquetas_nombre`(`nombre`) USING BTREE,
  INDEX `idx_datarocket_etiquetas_activo`(`activo`) USING BTREE
) ENGINE = InnoDB CHARACTER SET = utf8mb4 COLLATE = utf8mb4_general_ci ROW_FORMAT = Dynamic;
