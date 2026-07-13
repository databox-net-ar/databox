-- Agrega la columna `un_solo_uso` a `usuarios_invitaciones`. Habilita el flujo
-- de "iniciar sesion como" (magic link de tiempo limitado + multi-uso) sin
-- afectar la invitacion por mail (7 dias + un solo uso), que sigue siendo el
-- comportamiento por default.
--
--   un_solo_uso = 1  -> comportamiento historico (invitacion por mail): al
--                       primer canje se marca `usado` y se bloquean canjes
--                       posteriores.
--   un_solo_uso = 0  -> link multi-uso durante toda la ventana `expira`; se
--                       actualiza `usado` con cada acceso (queda como "ultimo
--                       uso") pero no se bloquea.
--
-- Idempotente: chequea INFORMATION_SCHEMA antes de tocar la columna. Compatible
-- MySQL 8 (dev) y MariaDB 10.11 (prod) — evitamos `ADD COLUMN IF NOT EXISTS`.

SET @db := DATABASE();

SET @sql := (
  SELECT IF(
    (SELECT COUNT(*) FROM information_schema.COLUMNS
      WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'usuarios_invitaciones'
        AND COLUMN_NAME = 'un_solo_uso') > 0,
    'SELECT 1',
    'ALTER TABLE `usuarios_invitaciones` ADD COLUMN `un_solo_uso` TINYINT(1) NOT NULL DEFAULT 1 AFTER `usado`'
  )
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
