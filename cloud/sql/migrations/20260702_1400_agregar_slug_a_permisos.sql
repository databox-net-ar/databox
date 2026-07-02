-- Agrega la columna `slug` a la tabla `permisos`, ubicada despues de `id`.
-- El slug es el identificador corto (ej: 'usuarios.editar', 'campanas.enviar')
-- que la aplicacion usa para chequear autorizaciones sobre areas del sistema.
-- Idempotente: chequea INFORMATION_SCHEMA antes de tocar la columna, asi corre
-- tanto sobre bases nuevas (schema.sql ya trae el esquema final) como sobre
-- bases viejas (esquema previo sin `slug`).

SET @db := DATABASE();

-- slug: agregar despues de `id` (queda inmediatamente antes de `nombre`) si no existe.
SET @sql := (
  SELECT IF(
    (SELECT COUNT(*) FROM information_schema.COLUMNS
      WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'permisos'
        AND COLUMN_NAME = 'slug') > 0,
    'SELECT 1',
    'ALTER TABLE `permisos` ADD COLUMN `slug` VARCHAR(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NULL DEFAULT NULL AFTER `id`'
  )
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
