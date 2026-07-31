-- arca_certificados: quitar la columna `token`.
--
-- Motivo:
--   El campo `token` guardaba el Bearer que emitia `app.afipsdk.com` cuando
--   la plataforma se apoyaba en su servicio proxy para hablar con AFIP. Ese
--   proxy ya no se usa (ver migraciones y microservicio `/v4/arca/*` que
--   habla directo contra WSAA/WSFEv1). La columna quedo sin lectores en
--   ningun modulo cloud ni en el nuevo microservicio.
--
--   NOTA: los `token`/`sign` que si se usan para autenticar contra AFIP
--   viven en la tabla `arca_ta_cache` (persistidos por WSAA con vencimiento
--   a 12hs). No tienen relacion con esta columna que se elimina.
--
-- Compatible con MySQL 8 (dev) y MariaDB 10.11 (prod): se usa el patron
-- information_schema + PREPARE/EXECUTE para que sea idempotente y funcione
-- en ambas variantes.

SET @exists := (
  SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME   = 'arca_certificados'
    AND COLUMN_NAME  = 'token'
);
SET @sql := IF(@exists > 0,
  'ALTER TABLE arca_certificados DROP COLUMN `token`',
  'DO 0'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
