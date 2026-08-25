-- movistar_sims: agregar `proyecto` (INT(11) NULL) inmediatamente despues de
-- `id`.
--
-- Motivo:
--   Cada SIM M2M de Movistar se usa dentro de un proyecto interno del grupo
--   (Vigicom, CAS, Reactor, etc.). Hasta ahora esa asignacion vivia solo en la
--   cabeza de quien administra el inventario o, a lo sumo, colada dentro de
--   `nombre` / `tags`. Con la columna propia se puede filtrar el listado por
--   proyecto y ver de un vistazo donde esta cada linea.
--
-- Formato:
--   Guarda el `proyectos.id` del proyecto asignado. NULL = sin asignar. No se
--   declara FOREIGN KEY para mantener el mismo criterio que el resto de las
--   tablas del esquema que referencian `proyectos` (aws_mensajes,
--   datacount_*, datarocket_*, etc.), todas con `proyecto int(11) NULL` suelto.
--   El ABM solo ofrece proyectos de `tipo = 'I'` (internos) en el desplegable.
--
--   La columna NO la toca la sincronizacion con Kite Platform (el upsert de
--   api/lib/movistar_sims_kite.php enumera sus columnas explicitamente), asi
--   que la asignacion manual sobrevive a cada corrida del sync — igual que
--   `nombre`, `en_uso` y `tags`.
--
-- Compatible con MySQL 8 (dev) y MariaDB 10.11 (prod): se usa el patron
-- information_schema + PREPARE/EXECUTE porque MySQL 8 no soporta la sintaxis
-- MariaDB `ADD COLUMN IF NOT EXISTS`.

SET @exists := (
  SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME   = 'movistar_sims'
    AND COLUMN_NAME  = 'proyecto'
);
SET @sql := IF(@exists = 0,
  'ALTER TABLE movistar_sims ADD COLUMN `proyecto` INT(11) NULL DEFAULT NULL AFTER `id`',
  'DO 0'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- Indice para el filtro por proyecto del listado.
SET @exists := (
  SELECT COUNT(*) FROM information_schema.STATISTICS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME   = 'movistar_sims'
    AND INDEX_NAME   = 'idx_movistar_sims_proyecto'
);
SET @sql := IF(@exists = 0,
  'ALTER TABLE movistar_sims ADD INDEX `idx_movistar_sims_proyecto` (`proyecto`)',
  'DO 0'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
