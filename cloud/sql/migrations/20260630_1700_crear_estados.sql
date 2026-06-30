-- Tabla `estados`: catalogo de valores posibles para campos varios del esquema
-- (p.ej. `usuarios.estado`, `datacountcomprobantes.estado`, etc.). Cada fila
-- mapea un `valor` crudo guardado en la columna `<campo>` con su `texto`
-- amigable para mostrar en la UI, mas un `orden` para listarlo en combos.
--
-- Esquema canonico, usado tambien por otras apps del grupo
-- (batallercontenidos, cas, vigicom). Sin UNIQUE en DB: la unicidad de
-- (campo, valor) se enforce en codigo via SELECT antes de INSERT/UPDATE,
-- igual que en `parametros`.
--
-- Idempotente: CREATE TABLE IF NOT EXISTS permite que la migracion corra
-- tanto en entornos nuevos (donde schema.sql ya la cargo) como en entornos
-- existentes (donde todavia no existe).

CREATE TABLE IF NOT EXISTS `estados` (
  `id`    int(11)      NOT NULL AUTO_INCREMENT,
  `campo` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NULL DEFAULT NULL,
  `texto` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NULL DEFAULT NULL,
  `valor` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NULL DEFAULT NULL,
  `orden` int(11)      NULL DEFAULT NULL,
  PRIMARY KEY (`id`) USING BTREE
) ENGINE = InnoDB CHARACTER SET = utf8mb4 COLLATE = utf8mb4_general_ci ROW_FORMAT = Dynamic;
