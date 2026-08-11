-- datarocket_contactos: eliminar la columna `listas` (VARCHAR(500) con formato
-- `(124)(125)(445)`). La relacion N:N contacto <-> lista ahora vive en la tabla
-- puente `datarocket_contactos_listas` (creada y poblada por la migracion
-- 20260811_1400_crear_datarocket_contactos_listas.sql), que es la nueva fuente
-- de verdad. Codigo PHP y JS del ABM ya migrados a leer/escribir contra ella.
--
-- Compatible con MySQL 8 (dev) y MariaDB 10.11 (prod): se usa el patron
-- information_schema + PREPARE/EXECUTE porque MySQL 8 no soporta la sintaxis
-- MariaDB `DROP COLUMN IF EXISTS`. Idempotente: correr dos veces no falla.

SET @exists := (
  SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME   = 'datarocket_contactos'
    AND COLUMN_NAME  = 'listas'
);
SET @sql := IF(@exists = 1,
  'ALTER TABLE datarocket_contactos DROP COLUMN `listas`',
  'DO 0'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
