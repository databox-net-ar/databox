-- Agrega la columna `adjunto_origen` (varchar 10) a `datarocket_plantillas`
-- justo despues de `adjunto`. Discrimina entre archivos subidos al bucket
-- (`archivo`) y URLs externas escritas a mano por el usuario (`url`).
--
-- El backfill inicial usa la URL de `adjunto` como pista: si apunta al bucket
-- bajo el prefijo `datarocket/plantillas/` se marca como `archivo`; si tiene
-- cualquier otro valor no vacio se marca como `url`. Si esta vacio queda NULL.
--
-- Idempotente: chequea INFORMATION_SCHEMA antes de agregar la columna, asi la
-- migracion es segura tanto en bases donde falta como en las que ya la tienen.
-- Compatible con MySQL 8 (dev) y MariaDB 10.11 (prod) — no usa sintaxis
-- MariaDB-only (`ADD COLUMN IF NOT EXISTS`).

SET @db := DATABASE();

-- --------------------------------------------------------------------
-- 1) Agregar la columna
-- --------------------------------------------------------------------
SET @sql := (
  SELECT IF(
    (SELECT COUNT(*) FROM information_schema.COLUMNS
      WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'datarocket_plantillas'
        AND COLUMN_NAME = 'adjunto_origen') = 0,
    'ALTER TABLE `datarocket_plantillas`
       ADD COLUMN `adjunto_origen` VARCHAR(10) CHARACTER SET utf8mb4
         COLLATE utf8mb4_general_ci NULL DEFAULT NULL AFTER `adjunto`',
    'SELECT 1'
  )
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- --------------------------------------------------------------------
-- 2) Backfill inicial: inferir el origen desde la URL en `adjunto`.
--    * URL que contiene `/datarocket/plantillas/` -> archivo
--    * cualquier otro valor no vacio                -> url
--    Filtro por `adjunto_origen IS NULL` para ser idempotente.
-- --------------------------------------------------------------------
UPDATE `datarocket_plantillas`
   SET `adjunto_origen` = CASE
       WHEN `adjunto` LIKE '%/datarocket/plantillas/%' THEN 'archivo'
       ELSE 'url'
     END
 WHERE `adjunto_origen` IS NULL
   AND `adjunto` IS NOT NULL
   AND `adjunto` <> '';
