-- movistar_sims + claro_sims: agregar `tags` (VARCHAR(500) NULL) despues de
-- `ultimo_trafico`.
--
-- Motivo:
--   Se necesita etiquetar cada SIM con "tags" libres (ej: "logistica",
--   "urgencia", "cliente-X") para poder identificarlas de un vistazo en el
--   listado. Se editan desde el modal "Editar SIM" y se muestran como pills
--   pequenas al lado del nombre.
--
-- Formato:
--   Se guarda como CSV plano de strings (ej: `logistica,cliente-x`) dentro
--   de un VARCHAR(500), sin comillas ni corchetes, para que la columna sea
--   legible / editable desde cualquier cliente SQL. La coma es reservada
--   como separador: el editor de tags del ABM (frontend) usa Enter/coma
--   para confirmar el tag actual y el normalizador backend splitea por coma
--   como red de seguridad. La API HTTP sigue exponiendo/consumiendo el
--   array (`tags: ["logistica","cliente-x"]`) — la traduccion CSV<->array
--   la hacen los helpers *DecodeTags/*EncodeTags de cada endpoint.
--
--   NULL o "" = sin tags. 500 chars alcanzan para ~15 tags cortos y es un
--   tope duro; el backend valida ademas la cantidad (max 20) y el largo
--   por tag (max 50 chars).
--
-- Compatible con MySQL 8 (dev) y MariaDB 10.11 (prod): se usa el patron
-- information_schema + PREPARE/EXECUTE porque MySQL 8 no soporta la sintaxis
-- MariaDB `ADD COLUMN IF NOT EXISTS`.

-- ---------------------------------------------------------------------------
-- movistar_sims.tags
-- ---------------------------------------------------------------------------
SET @exists := (
  SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME   = 'movistar_sims'
    AND COLUMN_NAME  = 'tags'
);
SET @sql := IF(@exists = 0,
  'ALTER TABLE movistar_sims ADD COLUMN `tags` VARCHAR(500) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NULL DEFAULT NULL AFTER `ultimo_trafico`',
  'DO 0'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- ---------------------------------------------------------------------------
-- claro_sims.tags
-- ---------------------------------------------------------------------------
SET @exists := (
  SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME   = 'claro_sims'
    AND COLUMN_NAME  = 'tags'
);
SET @sql := IF(@exists = 0,
  'ALTER TABLE claro_sims ADD COLUMN `tags` VARCHAR(500) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NULL DEFAULT NULL AFTER `ultimo_trafico`',
  'DO 0'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
