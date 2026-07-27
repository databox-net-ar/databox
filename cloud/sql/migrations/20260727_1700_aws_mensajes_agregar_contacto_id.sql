-- Agrega la columna `aws_mensajes.contacto_id` (int NULL) para referenciar el
-- registro de `datarocket_contactos` asociado al destino del mensaje. La
-- resolucion (buscar por correo o dar de alta si no existe) ocurre en el
-- canalizador `encolarAwsMensaje()` de cloud/api/lib/aws_mensajes.php, que es
-- el punto UNICO de entrada para insertar en la cola (lo comparten el ABM
-- cloud y el microservicio v4 api/v4/aws/mensajes.php).
--
-- Ubicacion: inmediatamente despues de `plantilla_id` para mantener juntos
-- los tres punteros de dominio del mensaje (canal / plantilla / contacto).
--
-- Idempotente: chequea INFORMATION_SCHEMA antes del ADD (patron compatible
-- con MySQL 8 y MariaDB 10.11 — no podemos usar `ADD COLUMN IF NOT EXISTS`
-- porque es sintaxis MariaDB-only).

SET @db := DATABASE();

SET @sql := (
  SELECT IF(
    (SELECT COUNT(*) FROM information_schema.COLUMNS
      WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'aws_mensajes'
        AND COLUMN_NAME = 'contacto_id') = 0,
    'ALTER TABLE `aws_mensajes`
       ADD COLUMN `contacto_id` INT(11) NULL DEFAULT NULL AFTER `plantilla_id`',
    'SELECT 1'
  )
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
