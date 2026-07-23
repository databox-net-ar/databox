-- datarocket_dominios: agregar `en_uso` (VARCHAR(2)) despues de `moneda`.
--
-- Motivo:
--   Marcador manual del panel para distinguir dominios efectivamente en uso
--   ('si'), dominios que sabemos que ya no se usan y podrian no renovarse
--   ('no') o dominios que todavia no se revisaron (NULL). No lo toca la
--   sincronizacion WHOIS — es 100% edicion del ABM.
--
-- Compatible con MySQL 8 (dev) y MariaDB 10.11 (prod): se usa el patron
-- information_schema + PREPARE/EXECUTE porque MySQL 8 no soporta la sintaxis
-- MariaDB `ADD COLUMN IF NOT EXISTS`.

SET @exists := (
  SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME   = 'datarocket_dominios'
    AND COLUMN_NAME  = 'en_uso'
);
SET @sql := IF(@exists = 0,
  'ALTER TABLE datarocket_dominios ADD COLUMN `en_uso` VARCHAR(2) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NULL DEFAULT NULL AFTER `moneda`',
  'DO 0'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
