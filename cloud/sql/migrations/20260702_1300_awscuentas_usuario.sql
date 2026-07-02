-- Agrega la columna `usuario` a `awscuentas`, entre `numero` y `contrasena`.
-- Sirve para guardar el nombre del usuario IAM o el alias con el que se
-- inicia sesion en la consola AWS de esa cuenta.
-- Idempotente: chequea INFORMATION_SCHEMA antes de tocar la columna, asi corre
-- indistintamente en bases nuevas (schema.sql ya trae el campo) o viejas
-- (esquema previo sin `usuario`). Compatible con MySQL 8 y MariaDB.

SET @db := DATABASE();

SET @sql := (
  SELECT IF(
    (SELECT COUNT(*) FROM information_schema.COLUMNS
      WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'awscuentas'
        AND COLUMN_NAME = 'usuario') > 0,
    'SELECT 1',
    'ALTER TABLE `awscuentas` ADD COLUMN `usuario` VARCHAR(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NULL DEFAULT NULL AFTER `numero`'
  )
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
