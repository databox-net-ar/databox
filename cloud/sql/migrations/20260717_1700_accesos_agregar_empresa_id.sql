-- Agrega la columna `empresa_id` a `accesos` para vincular cada credencial
-- de sistema externo con una de las empresas del holding (tabla
-- `datacount_empresas`). Es NULL: no todas las credenciales corresponden a
-- una empresa puntual (ej. paneles internos de Databox).
--
-- No agregamos FOREIGN KEY: `datacount_empresas.id` es INT UNSIGNED y hay
-- convivencia con MyISAM en tablas legacy del grupo, asi que mantenemos la
-- integridad referencial a nivel aplicacion (misma politica que
-- `usuarios.roles` -> `roles.id`). Indexamos igual para acelerar el JOIN
-- del listado.
--
-- Patron idempotente `information_schema` + PREPARE/EXECUTE porque
-- MariaDB 10.11 (prod) no soporta `ADD COLUMN IF NOT EXISTS`.

SET @c := (
    SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME   = 'accesos'
      AND COLUMN_NAME  = 'empresa_id'
);
SET @sql := IF(@c = 0,
    'ALTER TABLE `accesos` ADD COLUMN `empresa_id` INT UNSIGNED NULL DEFAULT NULL AFTER `id`',
    'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @i := (
    SELECT COUNT(*) FROM information_schema.STATISTICS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME   = 'accesos'
      AND INDEX_NAME   = 'idx_accesos_empresa_id'
);
SET @sql := IF(@i = 0,
    'ALTER TABLE `accesos` ADD INDEX `idx_accesos_empresa_id` (`empresa_id`)',
    'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
