-- Ampliacion de `roles.slug` y `permisos.slug` de VARCHAR(50) a VARCHAR(100).
--
-- El limite original de 50 caracteres alcanzaba para los slugs "planos" del
-- estilo `admin` / `editor` / `usuarios.editar`, pero no para los nuevos slugs
-- jerarquicos que replican la ubicacion completa en el menu (hasta 4 niveles),
-- como `administracion.herramientas.explorador_s3.crear_carpeta` (55). Con 100
-- queda margen para permisos futuros sin volver a tocar el schema.
--
-- Debe correr antes de 20260711_1300_crear_permisos_cloud.sql (que siembra el
-- set nuevo). El orden queda garantizado por el prefijo timestamp `1250 < 1300`.
--
-- Idempotente: chequea INFORMATION_SCHEMA y solo ejecuta ALTER si la columna
-- todavia esta en <100. Correrla dos veces no hace ruido.

SET @db := DATABASE();

-- permisos.slug
SET @sql := (
  SELECT IF(
    (SELECT CHARACTER_MAXIMUM_LENGTH
       FROM information_schema.COLUMNS
       WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'permisos' AND COLUMN_NAME = 'slug') >= 100,
    'SELECT 1',
    'ALTER TABLE `permisos` MODIFY COLUMN `slug` VARCHAR(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NULL DEFAULT NULL'
  )
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- roles.slug (por consistencia; los roles usan slugs cortos hoy pero
-- alineamos ambas tablas para que compartan el mismo tope).
SET @sql := (
  SELECT IF(
    (SELECT CHARACTER_MAXIMUM_LENGTH
       FROM information_schema.COLUMNS
       WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'roles' AND COLUMN_NAME = 'slug') >= 100,
    'SELECT 1',
    'ALTER TABLE `roles` MODIFY COLUMN `slug` VARCHAR(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NULL DEFAULT NULL'
  )
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
