-- datarocket_listas: renombrar `suscripciones` -> `suscriptos`. El nombre
-- refleja mejor la semantica: la columna guarda la cantidad de contactos
-- suscriptos a la lista (sustantivo concreto), no el evento de suscribirse
-- (sustantivo abstracto). Se alinea con el vocabulario que ya usa el ABM en
-- pantalla ("Suscriptos") y con la tabla puente `datarocket_contactos_listas`
-- que representa esas suscripciones.
--
-- Idempotente: si la columna ya se renombro (o si en este env se llama
-- `suscriptos` desde el inicio), no hace nada. Compatible con MySQL 8 (dev)
-- y MariaDB 10.11 (prod) — se usa el patron information_schema +
-- PREPARE/EXECUTE porque MySQL 8 no soporta `RENAME COLUMN IF EXISTS`.

SET @tiene_vieja := (
  SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME   = 'datarocket_listas'
    AND COLUMN_NAME  = 'suscripciones'
);
SET @tiene_nueva := (
  SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME   = 'datarocket_listas'
    AND COLUMN_NAME  = 'suscriptos'
);
SET @sql := IF(@tiene_vieja = 1 AND @tiene_nueva = 0,
  'ALTER TABLE datarocket_listas CHANGE COLUMN `suscripciones` `suscriptos` INT(11) NULL DEFAULT NULL',
  'DO 0'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
