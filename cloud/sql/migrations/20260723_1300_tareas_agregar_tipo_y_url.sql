-- tareas: agregar `tipo` ENUM('script','url') + `url` VARCHAR(2048) NULL, y
-- hacer `script` nullable para soportar tareas de tipo URL (curl a una URL).
--
-- Motivo:
--   Ampliar el Programador de tareas para que ademas de disparar scripts PHP
--   locales, pueda hacer una llamada GET a una URL externa (ver `_curl.php`).
--   El discriminador es `tipo`: si tipo='script' se usa `script`, si tipo='url'
--   se usa `url`. La otra columna queda NULL. La API valida el par.
--
-- Compatible con MySQL 8 (dev) y MariaDB 10.11 (prod): patron
-- information_schema + PREPARE/EXECUTE porque MySQL 8 no soporta la sintaxis
-- MariaDB `ADD COLUMN IF NOT EXISTS`.

-- ---------------------------------------------------------------------------
-- tareas.tipo
-- ---------------------------------------------------------------------------
SET @exists := (
  SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME   = 'tareas'
    AND COLUMN_NAME  = 'tipo'
);
SET @sql := IF(@exists = 0,
  'ALTER TABLE tareas ADD COLUMN `tipo` ENUM(''script'',''url'') CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT ''script'' AFTER `descripcion`',
  'DO 0'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- ---------------------------------------------------------------------------
-- tareas.url
-- ---------------------------------------------------------------------------
SET @exists := (
  SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME   = 'tareas'
    AND COLUMN_NAME  = 'url'
);
SET @sql := IF(@exists = 0,
  'ALTER TABLE tareas ADD COLUMN `url` VARCHAR(2048) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NULL DEFAULT NULL AFTER `script`',
  'DO 0'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- ---------------------------------------------------------------------------
-- tareas.script -> nullable (era NOT NULL). Idempotente: solo re-modifica si
-- todavia esta como NOT NULL.
-- ---------------------------------------------------------------------------
SET @is_nullable := (
  SELECT IS_NULLABLE FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME   = 'tareas'
    AND COLUMN_NAME  = 'script'
);
SET @sql := IF(@is_nullable = 'NO',
  'ALTER TABLE tareas MODIFY COLUMN `script` VARCHAR(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NULL DEFAULT NULL',
  'DO 0'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
