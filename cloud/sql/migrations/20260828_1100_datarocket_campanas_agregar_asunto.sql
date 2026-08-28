-- datarocket_campanas: agregar `asunto` — el asunto propio de la campana.
--
-- POR QUE HACE FALTA
-- ------------------
-- Las plantillas Datarocket no siempre traen el asunto resuelto. El patron
-- "transaccional" (por ejemplo `datarocket_plantillas` #101, "Transaccional |
-- Databox") guarda literalmente `{asunto}` en la columna `asunto`, esperando
-- que el CALLER se lo pase: aplicarPlantillaAws() hace
--
--     str_replace('{asunto}', $in['asunto'], $tpl['asunto'])
--
-- (cloud/api/lib/aws_mensajes.php). En el ABM de mensajes sueltos ese
-- `$in['asunto']` lo escribe el operador en el modal. Una campana no tenia
-- donde ponerlo, asi que todos sus mensajes salian con asunto vacio — detectado
-- probando el expansor contra esa misma plantilla.
--
-- Es NULLABLE porque no todas las plantillas lo necesitan: una plantilla que ya
-- trae su asunto fijo ("Newsletter de octubre") ignora este campo. La capa PHP
-- valida al lanzar que el asunto RESULTANTE no quede vacio para las campanas de
-- correo — un mail sin asunto es la via rapida a la carpeta de spam.
--
-- Se ubica despues de `descripcion` para que el orden fisico acompane al del
-- formulario, donde el asunto va con el resto del contenido del mensaje.
--
-- Idempotente. Compatible MySQL 8 (dev) + MariaDB 10.11 (prod): sin
-- `ADD COLUMN IF NOT EXISTS` de MariaDB (patron information_schema + PREPARE),
-- sin funciones almacenadas.

SET @exists := (
  SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME   = 'datarocket_campanas'
    AND COLUMN_NAME  = 'asunto'
);
SET @sql := IF(@exists = 0,
  'ALTER TABLE `datarocket_campanas` ADD COLUMN `asunto` VARCHAR(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NULL DEFAULT NULL AFTER `descripcion`',
  'DO 0'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
