-- Renombra las 3 FKs de `evolution_mensajes` para explicitar que son ids:
--
--   proyecto  -> proyecto_id  (FK a proyectos.id)
--   canal     -> canal_id     (FK a evolution_canales.id)
--   plantilla -> plantilla_id (FK a datarocket_plantillas.id)
--
-- Idempotente: cada rename chequea INFORMATION_SCHEMA por la existencia de la
-- columna vieja antes de tocarla. Si la migracion ya corrio (columna nueva ya
-- presente y vieja ausente), salta silenciosamente.
--
-- Sintaxis `RENAME COLUMN` funciona tanto en MySQL 8.0+ como en MariaDB 10.5+
-- (dev + prod actual del stack). No usamos `CHANGE COLUMN` para no tener que
-- restatear el tipo y arriesgarnos a divergir del schema.sql.

SET @db := DATABASE();

-- proyecto -> proyecto_id
SET @sql := (
  SELECT IF(
    (SELECT COUNT(*) FROM information_schema.COLUMNS
      WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'evolution_mensajes'
        AND COLUMN_NAME = 'proyecto') > 0,
    'ALTER TABLE `evolution_mensajes` RENAME COLUMN `proyecto` TO `proyecto_id`',
    'SELECT 1'
  )
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- canal -> canal_id
SET @sql := (
  SELECT IF(
    (SELECT COUNT(*) FROM information_schema.COLUMNS
      WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'evolution_mensajes'
        AND COLUMN_NAME = 'canal') > 0,
    'ALTER TABLE `evolution_mensajes` RENAME COLUMN `canal` TO `canal_id`',
    'SELECT 1'
  )
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- plantilla -> plantilla_id
SET @sql := (
  SELECT IF(
    (SELECT COUNT(*) FROM information_schema.COLUMNS
      WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'evolution_mensajes'
        AND COLUMN_NAME = 'plantilla') > 0,
    'ALTER TABLE `evolution_mensajes` RENAME COLUMN `plantilla` TO `plantilla_id`',
    'SELECT 1'
  )
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
