-- Ajusta la tabla `sucesos` a la version actual de la skill "crear_visor_de_sucesos":
--   - `origen` pasa de VARCHAR(255) a VARCHAR(50) (nombre corto del modulo emisor).
--   - Se agrega `tipo` VARCHAR(20) NOT NULL DEFAULT 'info' despues de `origen`,
--     con whitelist enforced en la aplicacion (info / alerta / error).
-- Idempotente: chequea INFORMATION_SCHEMA antes de tocar cada columna, asi corre
-- indistintamente en bases nuevas (schema.sql ya trae el esquema final) o viejas
-- (esquema previo sin `tipo` y con `origen` VARCHAR(255)).

SET @db := DATABASE();

-- origen: bajar a VARCHAR(50) si aun estaba en otro tamano.
SET @sql := (
  SELECT IF(
    (SELECT COUNT(*) FROM information_schema.COLUMNS
      WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'sucesos'
        AND COLUMN_NAME = 'origen' AND CHARACTER_MAXIMUM_LENGTH = 50) > 0,
    'SELECT 1',
    'ALTER TABLE `sucesos` MODIFY COLUMN `origen` VARCHAR(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NULL DEFAULT NULL'
  )
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- tipo: agregar despues de `origen` si no existe.
SET @sql := (
  SELECT IF(
    (SELECT COUNT(*) FROM information_schema.COLUMNS
      WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'sucesos'
        AND COLUMN_NAME = 'tipo') > 0,
    'SELECT 1',
    "ALTER TABLE `sucesos` ADD COLUMN `tipo` VARCHAR(20) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL DEFAULT 'info' AFTER `origen`"
  )
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
