-- Crea la tabla `usuarios_invitaciones` para el flujo de invitacion por
-- "magic link". Cada fila es un enlace unico generado desde el ABM de
-- usuarios: el mail (encolado en `awssesmensajes`) apunta a
-- `/api/auth.php?action=magic&token=...` y ese endpoint verifica la fila,
-- la marca como `usado` y setea la cookie de sesion.
--
-- Idempotente: `CREATE TABLE IF NOT EXISTS`.

CREATE TABLE IF NOT EXISTS `usuarios_invitaciones` (
  `id`      int(11)      NOT NULL AUTO_INCREMENT,
  `usuario` int(11)      NOT NULL,
  `token`   varchar(64)  CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL,
  `expira`  datetime(0)  NOT NULL,
  `usado`   datetime(0)  NULL DEFAULT NULL,
  `creado`  datetime(0)  NOT NULL,
  PRIMARY KEY (`id`) USING BTREE,
  KEY `idx_usrinv_token`   (`token`)   USING BTREE,
  KEY `idx_usrinv_usuario` (`usuario`) USING BTREE
) ENGINE = InnoDB CHARACTER SET = utf8mb4 COLLATE = utf8mb4_general_ci ROW_FORMAT = Dynamic;
