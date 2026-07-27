-- Agrega la columna `evolution_mensajes.contacto_id` (int NULL) para
-- referenciar el registro de `datarocket_contactos` asociado al destino
-- (celular) del mensaje. La resolucion (buscar por celular o dar de alta si no
-- existe) ocurre en el canalizador `encolarEvolutionMensaje()` de
-- cloud/api/lib/evolution_mensajes.php, que es el punto UNICO de entrada para
-- insertar en la cola (lo comparten el ABM cloud y el microservicio v4
-- api/v4/evolution/mensajes.php).
--
-- Ubicacion: inmediatamente despues de `plantilla_id` para mantener juntos
-- los tres punteros de dominio del mensaje (canal / plantilla / contacto).
-- Mirror estructural de la migracion 20260727_1700_aws_mensajes_agregar_contacto_id.sql.
--
-- Idempotente: chequea INFORMATION_SCHEMA antes del ADD (patron compatible
-- con MySQL 8 y MariaDB 10.11 — no podemos usar `ADD COLUMN IF NOT EXISTS`
-- porque es sintaxis MariaDB-only).

SET @db := DATABASE();

SET @sql := (
  SELECT IF(
    (SELECT COUNT(*) FROM information_schema.COLUMNS
      WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'evolution_mensajes'
        AND COLUMN_NAME = 'contacto_id') = 0,
    'ALTER TABLE `evolution_mensajes`
       ADD COLUMN `contacto_id` INT(11) NULL DEFAULT NULL AFTER `plantilla_id`',
    'SELECT 1'
  )
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
