-- Cambia `datarocket_plantillas.formato` de varchar(1) a varchar(20) y
-- traduce los codigos de un caracter a etiquetas legibles, alineadas con las
-- que ya usa `evolution_mensajes.formato` (texto/imagen/video/audio/ubicacion)
-- + agrega 'html' para las plantillas de correo.
--
-- Motivo: el ABM y el microservicio v4/evolution/mensajes usan strings
-- ('texto', 'imagen', etc.); mantener la plantilla con codigos de 1 caracter
-- obligaba a un mapeo intermedio y era fuente de confusion cada vez que se
-- copiaba un formato de la plantilla al mensaje.
--
-- Mapping aplicado (mismo que el frontend del ABM ya usaba para displays):
--   T -> texto
--   H -> html
--   I -> imagen
--   V -> video
--   A -> audio
--   U -> ubicacion
--
-- Cualquier otro valor pre-existente queda intacto (no se toca), asi que
-- filas con datos raros aguantan la migracion sin sorpresas.
--
-- Idempotente:
--   - El ALTER sobre una columna ya varchar(20) es no-op (MySQL/MariaDB lo
--     ejecutan sin efecto observable).
--   - El backfill filtra por `formato IN ('T','H','I','V','A','U')`, asi que
--     una segunda corrida no altera filas ya traducidas.

SET @db := DATABASE();

-- 1) Expandir la columna a varchar(20). Idempotente por diseno: si ya es
-- varchar(20) el ALTER simplemente reejecuta la misma definicion.
ALTER TABLE `datarocket_plantillas` MODIFY COLUMN `formato` VARCHAR(20) NULL DEFAULT NULL;

-- 2) Backfill de valores char(1) -> string. Filtra por los codigos conocidos
-- para no pisar filas custom ni las que ya fueron traducidas.
UPDATE `datarocket_plantillas`
   SET `formato` = CASE `formato`
        WHEN 'T' THEN 'texto'
        WHEN 'H' THEN 'html'
        WHEN 'I' THEN 'imagen'
        WHEN 'V' THEN 'video'
        WHEN 'A' THEN 'audio'
        WHEN 'U' THEN 'ubicacion'
        ELSE `formato`
      END
 WHERE `formato` IN ('T','H','I','V','A','U');
