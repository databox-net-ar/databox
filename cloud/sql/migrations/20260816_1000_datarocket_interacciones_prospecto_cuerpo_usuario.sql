-- datarocket_interacciones: pasa de ser el log de envios automaticos sobre un
-- contacto a ser el UNICO historial de actividad del CRM — el timeline que en
-- Salesforce es Activity, en HubSpot Engagement y en Pipedrive Activity.
--
-- Motivo: `datarocket_prospectos` va a quedar reducido a las relaciones
-- (contacto_id / embudo_id / etapa_id) y va a perder los campos de texto libre
-- (`comentarios`, `acciones`). Todo lo que hoy vive ahi como "lo que se
-- converso" tiene que poder vivir aca. Hoy la tabla no alcanza por tres
-- razones:
--
--   1) No se puede colgar una interaccion de un prospecto. Solo existe
--      `contacto_id`, asi que el timeline de una oportunidad concreta no se
--      puede reconstruir ("cuantos toques hicieron falta para cerrar este
--      deal"). El estandar CRM asocia la actividad a la persona Y opcionalmente
--      a la oportunidad (Salesforce: WhoId + WhatId).
--   2) `descripcion` es VARCHAR(500) y esta pensado como etiqueta corta para el
--      listado. Los textos que hay que absorber son cuerpos largos: el maximo
--      de `datarocket_prospectos.comentarios` en dev es 973 caracteres y
--      `datasaleprospectoscomunicaciones.detalle` (MEDIUMTEXT) llega a
--      transcripciones de WhatsApp de varios miles.
--   3) No se registra quien cargo la interaccion. Para las altas automaticas
--      da igual (las escribe el canalizador), pero para las notas manuales que
--      va a cargar un vendedor hace falta el autor.
--
-- Cambios:
--
--   * + `prospecto_id` INT NULL  -> FK a `datarocket_prospectos`.
--   * + `usuario_id`   INT NULL  -> autor de la carga manual (NULL = sistema).
--   * + `cuerpo`       MEDIUMTEXT NULL -> contenido completo. `descripcion`
--     queda como el asunto / etiqueta corta del listado, mismo par que ya usa
--     `datarocket_mensajes` (asunto + cuerpo).
--   * `contacto_id` NOT NULL -> NULL.
--
-- Sobre `contacto_id` nullable: `datarocket_prospectos.contacto_id` ya es
-- nullable y la API deja crear prospectos sin contacto
-- (cloud/api/datarocket_prospectos.php, drProNullableInt). Con `contacto_id`
-- NOT NULL no se podria registrar una nota sobre uno de esos prospectos. La
-- invariante pasa a ser "al menos uno de contacto_id / prospecto_id" — se
-- documenta aca y se valida en la API; no se agrega CHECK constraint porque no
-- hay ninguna en el resto del schema.
--
-- Sobre `usuario_id` sin FK: ninguna tabla del schema referencia `usuarios`
-- (`datarocket_prospectos.asignado` / `.atendido` tampoco). Se respeta esa
-- convencion en vez de estrenarla aca.
--
-- FK del prospecto ON DELETE SET NULL, y no RESTRICT como el resto de las FKs
-- del proyecto: una interaccion es un hecho sobre el CONTACTO — paso, se dijo,
-- existe. El prospecto es la oportunidad comercial, un registro transaccional
-- que se puede descartar. Borrar la oportunidad no puede borrar ni bloquear el
-- historial de la persona; la interaccion sobrevive, solo pierde el vinculo al
-- deal. RESTRICT ademas romperia el DELETE que la ABM de prospectos ya expone.
-- El criterio "RESTRICT para que falle ruidosamente" aplica a los catalogos
-- (paises, provincias, embudos), no a esto.
--
-- NO se agrega la FK `contacto_id` -> `datarocket_contactos` en esta
-- migracion: exigiria anular antes los huerfanos que pueda haber en
-- produccion, y ese es un cambio destructivo sobre datos que no se relevaron.
-- Queda pendiente como migracion aparte.
--
-- Idempotente: cada paso se guarda con information_schema.
-- Compatible MySQL 8 (dev) + MariaDB 10.11 (prod).


-- ---------------------------------------------------------------------------
-- 1) Columnas nuevas.
-- ---------------------------------------------------------------------------

SET @exists := (
  SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME   = 'datarocket_interacciones'
    AND COLUMN_NAME  = 'prospecto_id'
);
SET @sql := IF(@exists = 0,
  'ALTER TABLE datarocket_interacciones
     ADD COLUMN `prospecto_id` INT NULL DEFAULT NULL AFTER `contacto_id`',
  'DO 0'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @exists := (
  SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME   = 'datarocket_interacciones'
    AND COLUMN_NAME  = 'usuario_id'
);
SET @sql := IF(@exists = 0,
  'ALTER TABLE datarocket_interacciones
     ADD COLUMN `usuario_id` INT NULL DEFAULT NULL AFTER `mensaje_id`',
  'DO 0'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @exists := (
  SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME   = 'datarocket_interacciones'
    AND COLUMN_NAME  = 'cuerpo'
);
SET @sql := IF(@exists = 0,
  'ALTER TABLE datarocket_interacciones
     ADD COLUMN `cuerpo` MEDIUMTEXT CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NULL AFTER `descripcion`',
  'DO 0'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;


-- ---------------------------------------------------------------------------
-- 2) `contacto_id` NOT NULL -> NULL.
--    Guardado por IS_NULLABLE: MODIFY reescribe la tabla entera aunque el
--    tipo destino ya sea el actual, asi que el guard evita ese costo en un
--    re-run.
-- ---------------------------------------------------------------------------

SET @no_nullable := (
  SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME   = 'datarocket_interacciones'
    AND COLUMN_NAME  = 'contacto_id'
    AND IS_NULLABLE  = 'NO'
);
SET @sql := IF(@no_nullable = 1,
  'ALTER TABLE datarocket_interacciones
     MODIFY COLUMN `contacto_id` INT NULL DEFAULT NULL',
  'DO 0'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;


-- ---------------------------------------------------------------------------
-- 3) Indices. El de prospecto es compuesto (prospecto_id, fecha) igual que
--    `idx_dri_contacto_fecha`: la consulta natural es "el timeline de este
--    prospecto ordenado por fecha".
-- ---------------------------------------------------------------------------

SET @exists := (
  SELECT COUNT(*) FROM information_schema.STATISTICS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME   = 'datarocket_interacciones'
    AND INDEX_NAME   = 'idx_dri_prospecto_fecha'
);
SET @sql := IF(@exists = 0,
  'ALTER TABLE datarocket_interacciones
     ADD INDEX `idx_dri_prospecto_fecha` (`prospecto_id`, `fecha`)',
  'DO 0'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @exists := (
  SELECT COUNT(*) FROM information_schema.STATISTICS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME   = 'datarocket_interacciones'
    AND INDEX_NAME   = 'idx_dri_usuario'
);
SET @sql := IF(@exists = 0,
  'ALTER TABLE datarocket_interacciones
     ADD INDEX `idx_dri_usuario` (`usuario_id`)',
  'DO 0'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;


-- ---------------------------------------------------------------------------
-- 4) FK al prospecto.
-- ---------------------------------------------------------------------------

SET @exists := (
  SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS
  WHERE TABLE_SCHEMA    = DATABASE()
    AND TABLE_NAME      = 'datarocket_interacciones'
    AND CONSTRAINT_NAME = 'fk_dri_prospecto'
);
SET @sql := IF(@exists = 0,
  'ALTER TABLE datarocket_interacciones
     ADD CONSTRAINT `fk_dri_prospecto` FOREIGN KEY (`prospecto_id`)
         REFERENCES `datarocket_prospectos` (`id`) ON DELETE SET NULL ON UPDATE RESTRICT',
  'DO 0'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
