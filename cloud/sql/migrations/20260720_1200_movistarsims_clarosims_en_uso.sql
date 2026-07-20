-- movistarsims + clarosims: agregar `en_uso` (VARCHAR(2)) despues de `msisdn`.
--
-- Motivo:
--   Marcador manual del panel para distinguir SIMs asignadas a un cliente o
--   equipo ('si'), SIMs que sabemos que estan libres ('no') o SIMs que todavia
--   no se revisaron (NULL). No lo toca el sync — es 100% edicion del ABM.
--
-- Compatible con MySQL 8 (dev) y MariaDB 10.11 (prod): se usa el patron
-- information_schema + PREPARE/EXECUTE porque MySQL 8 no soporta la sintaxis
-- MariaDB `ADD COLUMN IF NOT EXISTS`.

-- ---------------------------------------------------------------------------
-- clarosims.en_uso
-- ---------------------------------------------------------------------------
SET @exists := (
  SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME   = 'clarosims'
    AND COLUMN_NAME  = 'en_uso'
);
SET @sql := IF(@exists = 0,
  'ALTER TABLE clarosims ADD COLUMN `en_uso` VARCHAR(2) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NULL DEFAULT NULL AFTER `msisdn`',
  'DO 0'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- ---------------------------------------------------------------------------
-- movistarsims.en_uso
-- ---------------------------------------------------------------------------
SET @exists := (
  SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME   = 'movistarsims'
    AND COLUMN_NAME  = 'en_uso'
);
SET @sql := IF(@exists = 0,
  'ALTER TABLE movistarsims ADD COLUMN `en_uso` VARCHAR(2) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NULL DEFAULT NULL AFTER `msisdn`',
  'DO 0'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
