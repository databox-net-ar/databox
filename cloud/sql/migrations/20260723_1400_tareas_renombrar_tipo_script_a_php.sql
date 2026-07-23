-- tareas.tipo: renombrar 'script' -> 'php' (nomenclatura mas consistente con el
-- futuro soporte de python, ruby, etc: el "tipo" nombra el interprete).
--
-- Idempotente: detecta el ENUM actual y solo actua si todavia contiene 'script'.
-- Pasos: (1) ampliar el ENUM a ('script','php','url') para poder convivir con
--        el valor viejo mientras hacemos el UPDATE; (2) UPDATE tipo='php' WHERE
--        tipo='script'; (3) achicar el ENUM a ('php','url').
--
-- Compatible MySQL 8 (dev) y MariaDB 10.11 (prod).

SET @col_type := (
  SELECT COLUMN_TYPE FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME   = 'tareas'
    AND COLUMN_NAME  = 'tipo'
);
SET @needs_rename := IF(@col_type LIKE '%''script''%', 1, 0);

-- Paso 1: ampliar ENUM a ('script','php','url') si aun tenia 'script'.
SET @sql := IF(@needs_rename = 1,
  'ALTER TABLE tareas MODIFY COLUMN tipo ENUM(''script'',''php'',''url'') CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT ''php''',
  'DO 0'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- Paso 2: mover las filas viejas.
SET @sql := IF(@needs_rename = 1,
  'UPDATE tareas SET tipo = ''php'' WHERE tipo = ''script''',
  'DO 0'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- Paso 3: achicar ENUM a la forma final ('php','url').
SET @sql := IF(@needs_rename = 1,
  'ALTER TABLE tareas MODIFY COLUMN tipo ENUM(''php'',''url'') CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT ''php''',
  'DO 0'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
