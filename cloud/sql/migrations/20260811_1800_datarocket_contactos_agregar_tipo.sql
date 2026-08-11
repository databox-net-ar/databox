-- datarocket_contactos: agregar columna `tipo` VARCHAR(20) NULL despues de
-- `uuid`. Discrimina la naturaleza del contacto:
--   * 'persona' — individuo fisico
--   * 'empresa' — organizacion
--   * NULL     — sin definir todavia (default para las filas historicas)
--
-- Se agrega NULL porque los ~148 mil contactos ya existentes no tienen tipo
-- asignado y no queremos bloquear la migracion. El ABM cloud (y el
-- microservicio v4) marcan `tipo` como obligatorio en alta y edicion — el
-- backend rechaza cualquier CREATE/UPDATE que llegue sin uno de los dos
-- valores validos. Los NULLs remanentes se van a completar via un proceso
-- manual (editar cada contacto y asignarle tipo antes de guardar).
--
-- No se usa CHECK ni ENUM para tolerar futuros valores (p.ej. 'ong',
-- 'estado') sin migracion — la validacion vive en la capa PHP.
--
-- Compatible MySQL 8 (dev) + MariaDB 10.11 (prod): pattern information_
-- schema + PREPARE/EXECUTE. Idempotente: correr dos veces no falla.

SET @exists := (
  SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME   = 'datarocket_contactos'
    AND COLUMN_NAME  = 'tipo'
);
SET @sql := IF(@exists = 0,
  'ALTER TABLE datarocket_contactos ADD COLUMN `tipo` VARCHAR(20) NULL DEFAULT NULL AFTER `uuid`',
  'DO 0'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
