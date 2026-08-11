-- datarocket_contactos: eliminar la columna `tags` (VARCHAR(500) con formato
-- `(nombre)(nombre)`). La relacion N:N contacto <-> etiqueta ahora vive en la
-- tabla puente `datarocket_contactos_etiquetas` (creada y poblada por la
-- migracion 20260811_1600_crear_datarocket_contactos_etiquetas.sql), que es
-- la nueva fuente de verdad. El codigo PHP y JS del ABM ya migrados a leer y
-- escribir contra ella.
--
-- Este es el paso 2 del mismo patron de normalizacion que aplicamos a
-- `listas` en las migraciones 1400 (crear puente + backfill) y 1500 (drop de
-- la columna). El backfill se hizo por nombre contra `datarocket_etiquetas`
-- (UNIQUE); antes de correr esta migracion la tabla `datarocket_contactos_
-- etiquetas` debe estar poblada (correr 1600 primero).
--
-- Compatible con MySQL 8 (dev) y MariaDB 10.11 (prod): se usa el patron
-- information_schema + PREPARE/EXECUTE porque MySQL 8 no soporta la sintaxis
-- MariaDB `DROP COLUMN IF EXISTS`. Idempotente: correr dos veces no falla.

SET @exists := (
  SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME   = 'datarocket_contactos'
    AND COLUMN_NAME  = 'tags'
);
SET @sql := IF(@exists = 1,
  'ALTER TABLE datarocket_contactos DROP COLUMN `tags`',
  'DO 0'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
