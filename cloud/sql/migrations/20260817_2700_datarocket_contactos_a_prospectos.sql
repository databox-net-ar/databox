-- Renombra el submodulo Datarocket > Contactos a Datarocket > Prospectos.
--
--   datarocket_contactos            -> datarocket_prospectos
--   datarocket_contactos_etiquetas  -> datarocket_prospectos_etiquetas
--   datarocket_contactos_listas     -> datarocket_prospectos_listas
--   <8 tablas>.contacto_id          -> prospecto_id
--   idx_dr_contactos_* / idx_dce_* / idx_dcl_* / idx_dri_contacto_* /
--   idx_dra_contacto_* / idx_dr_oportunidades_contacto  -> ..._prospecto(s)_...
--   fk_dr_contactos_* / fk_dce_* / fk_dcl_* /
--   fk_dr_oportunidades_contacto                        -> ..._prospecto(s)_...
--   permisos datarocket.contactos.*  -> datarocket.prospectos.*
--
-- OJO CON LOS HOMONIMOS — ninguno de estos se toca, son de otros modulos:
--   * `datarocketcontactos` (SIN guion bajo): tabla legacy del Datarocket
--     viejo, 42k filas. Se parece muchisimo al nombre nuevo.
--   * `evolutioncontactos`, `dataflycontactos`, `datamarketcontactos`.
--   * las columnas `contacto` (sin `_id`) de dataflyenvios, dataflysuscripciones,
--     datamarket* y datarocketmensajes / datarocketsuscripciones.
--   * los permisos `plataformas.evolution.contactos.*`.
--
-- SOBRE EL NOMBRE: `datarocket_prospectos` existio hasta la migracion
-- 20260817_2300 con OTRO significado — era la oportunidad de venta, hoy
-- `datarocket_oportunidades`. Las migraciones anteriores a esa fecha que
-- nombran `datarocket_prospectos` se refieren a la oportunidad, NO a esta
-- tabla. Mismo aviso para el slug `datarocket.prospectos.*`, que hasta la 2300
-- identificaba los permisos del ABM de oportunidades. Es deuda de legibilidad
-- del historial, no un problema funcional: el Migrador no reejecuta nada.
--
-- ORDEN DE DESPLIEGUE: codigo y migracion juntos. Es un rename de tablas y
-- columnas, no hay ventana de compatibilidad posible.
--
-- Idempotente: cada paso corre solo si el nombre viejo existe y el nuevo no.
-- Compatible MySQL 8 (dev) + MariaDB 10.11 (prod). `RENAME COLUMN` y
-- `RENAME INDEX` requieren MariaDB >= 10.5.2; prod corre 10.11.


-- ===========================================================================
-- Paso 1: las tres tablas. InnoDB reescribe solo las referencias de las FK
-- que las apuntan; los nombres de constraint los arregla el paso 3.
-- ===========================================================================
SET @v := (SELECT COUNT(*) FROM information_schema.TABLES
            WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'datarocket_contactos');
SET @n := (SELECT COUNT(*) FROM information_schema.TABLES
            WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'datarocket_prospectos');
SET @sql := IF(@v = 1 AND @n = 0,
  'RENAME TABLE `datarocket_contactos` TO `datarocket_prospectos`', 'DO 0');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

SET @v := (SELECT COUNT(*) FROM information_schema.TABLES
            WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'datarocket_contactos_etiquetas');
SET @n := (SELECT COUNT(*) FROM information_schema.TABLES
            WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'datarocket_prospectos_etiquetas');
SET @sql := IF(@v = 1 AND @n = 0,
  'RENAME TABLE `datarocket_contactos_etiquetas` TO `datarocket_prospectos_etiquetas`', 'DO 0');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

SET @v := (SELECT COUNT(*) FROM information_schema.TABLES
            WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'datarocket_contactos_listas');
SET @n := (SELECT COUNT(*) FROM information_schema.TABLES
            WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'datarocket_prospectos_listas');
SET @sql := IF(@v = 1 AND @n = 0,
  'RENAME TABLE `datarocket_contactos_listas` TO `datarocket_prospectos_listas`', 'DO 0');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;


-- ===========================================================================
-- Paso 2: indices de `datarocket_prospectos` (los geo).
-- ===========================================================================
SET @v := (SELECT COUNT(DISTINCT INDEX_NAME) FROM information_schema.STATISTICS
            WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'datarocket_prospectos'
              AND INDEX_NAME = 'idx_dr_contactos_pais');
SET @n := (SELECT COUNT(DISTINCT INDEX_NAME) FROM information_schema.STATISTICS
            WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'datarocket_prospectos'
              AND INDEX_NAME = 'idx_dr_prospectos_pais');
SET @sql := IF(@v = 1 AND @n = 0,
  'ALTER TABLE `datarocket_prospectos` RENAME INDEX `idx_dr_contactos_pais` TO `idx_dr_prospectos_pais`', 'DO 0');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

SET @v := (SELECT COUNT(DISTINCT INDEX_NAME) FROM information_schema.STATISTICS
            WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'datarocket_prospectos'
              AND INDEX_NAME = 'idx_dr_contactos_provincia');
SET @n := (SELECT COUNT(DISTINCT INDEX_NAME) FROM information_schema.STATISTICS
            WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'datarocket_prospectos'
              AND INDEX_NAME = 'idx_dr_prospectos_provincia');
SET @sql := IF(@v = 1 AND @n = 0,
  'ALTER TABLE `datarocket_prospectos` RENAME INDEX `idx_dr_contactos_provincia` TO `idx_dr_prospectos_provincia`', 'DO 0');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

SET @v := (SELECT COUNT(DISTINCT INDEX_NAME) FROM information_schema.STATISTICS
            WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'datarocket_prospectos'
              AND INDEX_NAME = 'idx_dr_contactos_localidad');
SET @n := (SELECT COUNT(DISTINCT INDEX_NAME) FROM information_schema.STATISTICS
            WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'datarocket_prospectos'
              AND INDEX_NAME = 'idx_dr_prospectos_localidad');
SET @sql := IF(@v = 1 AND @n = 0,
  'ALTER TABLE `datarocket_prospectos` RENAME INDEX `idx_dr_contactos_localidad` TO `idx_dr_prospectos_localidad`', 'DO 0');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;


-- ===========================================================================
-- Paso 3: constraints geo de `datarocket_prospectos`. No existe RENAME
-- CONSTRAINT, va DROP + ADD; el indice de respaldo ya esta renombrado.
-- ===========================================================================
SET @v := (SELECT COUNT(*) FROM information_schema.REFERENTIAL_CONSTRAINTS
            WHERE CONSTRAINT_SCHEMA = DATABASE() AND TABLE_NAME = 'datarocket_prospectos'
              AND CONSTRAINT_NAME = 'fk_dr_contactos_pais');
SET @sql := IF(@v = 1, 'ALTER TABLE `datarocket_prospectos` DROP FOREIGN KEY `fk_dr_contactos_pais`', 'DO 0');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;
SET @n := (SELECT COUNT(*) FROM information_schema.REFERENTIAL_CONSTRAINTS
            WHERE CONSTRAINT_SCHEMA = DATABASE() AND TABLE_NAME = 'datarocket_prospectos'
              AND CONSTRAINT_NAME = 'fk_dr_prospectos_pais');
SET @sql := IF(@n = 0,
  'ALTER TABLE `datarocket_prospectos` ADD CONSTRAINT `fk_dr_prospectos_pais` FOREIGN KEY (`pais_id`) REFERENCES `paises` (`id`)', 'DO 0');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

SET @v := (SELECT COUNT(*) FROM information_schema.REFERENTIAL_CONSTRAINTS
            WHERE CONSTRAINT_SCHEMA = DATABASE() AND TABLE_NAME = 'datarocket_prospectos'
              AND CONSTRAINT_NAME = 'fk_dr_contactos_provincia');
SET @sql := IF(@v = 1, 'ALTER TABLE `datarocket_prospectos` DROP FOREIGN KEY `fk_dr_contactos_provincia`', 'DO 0');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;
SET @n := (SELECT COUNT(*) FROM information_schema.REFERENTIAL_CONSTRAINTS
            WHERE CONSTRAINT_SCHEMA = DATABASE() AND TABLE_NAME = 'datarocket_prospectos'
              AND CONSTRAINT_NAME = 'fk_dr_prospectos_provincia');
SET @sql := IF(@n = 0,
  'ALTER TABLE `datarocket_prospectos` ADD CONSTRAINT `fk_dr_prospectos_provincia` FOREIGN KEY (`provincia_id`) REFERENCES `provincias` (`id`)', 'DO 0');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

SET @v := (SELECT COUNT(*) FROM information_schema.REFERENTIAL_CONSTRAINTS
            WHERE CONSTRAINT_SCHEMA = DATABASE() AND TABLE_NAME = 'datarocket_prospectos'
              AND CONSTRAINT_NAME = 'fk_dr_contactos_localidad');
SET @sql := IF(@v = 1, 'ALTER TABLE `datarocket_prospectos` DROP FOREIGN KEY `fk_dr_contactos_localidad`', 'DO 0');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;
SET @n := (SELECT COUNT(*) FROM information_schema.REFERENTIAL_CONSTRAINTS
            WHERE CONSTRAINT_SCHEMA = DATABASE() AND TABLE_NAME = 'datarocket_prospectos'
              AND CONSTRAINT_NAME = 'fk_dr_prospectos_localidad');
SET @sql := IF(@n = 0,
  'ALTER TABLE `datarocket_prospectos` ADD CONSTRAINT `fk_dr_prospectos_localidad` FOREIGN KEY (`localidad_id`) REFERENCES `localidades` (`id`)', 'DO 0');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;


-- ===========================================================================
-- Paso 4: puente etiquetas. `contacto_id` es parte de la PK y de una FK, asi
-- que la FK se dropea antes del rename (MariaDB no acepta RENAME COLUMN sobre
-- una columna con foreign key). La PK sigue sola al nombre nuevo.
-- ===========================================================================
SET @v := (SELECT COUNT(*) FROM information_schema.REFERENTIAL_CONSTRAINTS
            WHERE CONSTRAINT_SCHEMA = DATABASE() AND TABLE_NAME = 'datarocket_prospectos_etiquetas'
              AND CONSTRAINT_NAME = 'fk_dce_contacto');
SET @sql := IF(@v = 1, 'ALTER TABLE `datarocket_prospectos_etiquetas` DROP FOREIGN KEY `fk_dce_contacto`', 'DO 0');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

SET @v := (SELECT COUNT(*) FROM information_schema.REFERENTIAL_CONSTRAINTS
            WHERE CONSTRAINT_SCHEMA = DATABASE() AND TABLE_NAME = 'datarocket_prospectos_etiquetas'
              AND CONSTRAINT_NAME = 'fk_dce_etiqueta');
SET @sql := IF(@v = 1, 'ALTER TABLE `datarocket_prospectos_etiquetas` DROP FOREIGN KEY `fk_dce_etiqueta`', 'DO 0');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

SET @v := (SELECT COUNT(*) FROM information_schema.COLUMNS
            WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'datarocket_prospectos_etiquetas'
              AND COLUMN_NAME = 'contacto_id');
SET @n := (SELECT COUNT(*) FROM information_schema.COLUMNS
            WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'datarocket_prospectos_etiquetas'
              AND COLUMN_NAME = 'prospecto_id');
SET @sql := IF(@v = 1 AND @n = 0,
  'ALTER TABLE `datarocket_prospectos_etiquetas` RENAME COLUMN `contacto_id` TO `prospecto_id`', 'DO 0');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

SET @v := (SELECT COUNT(DISTINCT INDEX_NAME) FROM information_schema.STATISTICS
            WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'datarocket_prospectos_etiquetas'
              AND INDEX_NAME = 'idx_dce_etiqueta');
SET @n := (SELECT COUNT(DISTINCT INDEX_NAME) FROM information_schema.STATISTICS
            WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'datarocket_prospectos_etiquetas'
              AND INDEX_NAME = 'idx_dpe_etiqueta');
SET @sql := IF(@v = 1 AND @n = 0,
  'ALTER TABLE `datarocket_prospectos_etiquetas` RENAME INDEX `idx_dce_etiqueta` TO `idx_dpe_etiqueta`', 'DO 0');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

SET @n := (SELECT COUNT(*) FROM information_schema.REFERENTIAL_CONSTRAINTS
            WHERE CONSTRAINT_SCHEMA = DATABASE() AND TABLE_NAME = 'datarocket_prospectos_etiquetas'
              AND CONSTRAINT_NAME = 'fk_dpe_prospecto');
SET @sql := IF(@n = 0,
  'ALTER TABLE `datarocket_prospectos_etiquetas` ADD CONSTRAINT `fk_dpe_prospecto` FOREIGN KEY (`prospecto_id`) REFERENCES `datarocket_prospectos` (`id`) ON DELETE CASCADE', 'DO 0');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

SET @n := (SELECT COUNT(*) FROM information_schema.REFERENTIAL_CONSTRAINTS
            WHERE CONSTRAINT_SCHEMA = DATABASE() AND TABLE_NAME = 'datarocket_prospectos_etiquetas'
              AND CONSTRAINT_NAME = 'fk_dpe_etiqueta');
SET @sql := IF(@n = 0,
  'ALTER TABLE `datarocket_prospectos_etiquetas` ADD CONSTRAINT `fk_dpe_etiqueta` FOREIGN KEY (`etiqueta_id`) REFERENCES `datarocket_etiquetas` (`id`) ON DELETE CASCADE', 'DO 0');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;


-- ===========================================================================
-- Paso 5: puente listas. Mismo procedimiento que el paso 4.
-- ===========================================================================
SET @v := (SELECT COUNT(*) FROM information_schema.REFERENTIAL_CONSTRAINTS
            WHERE CONSTRAINT_SCHEMA = DATABASE() AND TABLE_NAME = 'datarocket_prospectos_listas'
              AND CONSTRAINT_NAME = 'fk_dcl_contacto');
SET @sql := IF(@v = 1, 'ALTER TABLE `datarocket_prospectos_listas` DROP FOREIGN KEY `fk_dcl_contacto`', 'DO 0');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

SET @v := (SELECT COUNT(*) FROM information_schema.REFERENTIAL_CONSTRAINTS
            WHERE CONSTRAINT_SCHEMA = DATABASE() AND TABLE_NAME = 'datarocket_prospectos_listas'
              AND CONSTRAINT_NAME = 'fk_dcl_lista');
SET @sql := IF(@v = 1, 'ALTER TABLE `datarocket_prospectos_listas` DROP FOREIGN KEY `fk_dcl_lista`', 'DO 0');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

SET @v := (SELECT COUNT(*) FROM information_schema.COLUMNS
            WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'datarocket_prospectos_listas'
              AND COLUMN_NAME = 'contacto_id');
SET @n := (SELECT COUNT(*) FROM information_schema.COLUMNS
            WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'datarocket_prospectos_listas'
              AND COLUMN_NAME = 'prospecto_id');
SET @sql := IF(@v = 1 AND @n = 0,
  'ALTER TABLE `datarocket_prospectos_listas` RENAME COLUMN `contacto_id` TO `prospecto_id`', 'DO 0');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

SET @v := (SELECT COUNT(DISTINCT INDEX_NAME) FROM information_schema.STATISTICS
            WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'datarocket_prospectos_listas'
              AND INDEX_NAME = 'idx_dcl_lista');
SET @n := (SELECT COUNT(DISTINCT INDEX_NAME) FROM information_schema.STATISTICS
            WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'datarocket_prospectos_listas'
              AND INDEX_NAME = 'idx_dpl_lista');
SET @sql := IF(@v = 1 AND @n = 0,
  'ALTER TABLE `datarocket_prospectos_listas` RENAME INDEX `idx_dcl_lista` TO `idx_dpl_lista`', 'DO 0');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

SET @n := (SELECT COUNT(*) FROM information_schema.REFERENTIAL_CONSTRAINTS
            WHERE CONSTRAINT_SCHEMA = DATABASE() AND TABLE_NAME = 'datarocket_prospectos_listas'
              AND CONSTRAINT_NAME = 'fk_dpl_prospecto');
SET @sql := IF(@n = 0,
  'ALTER TABLE `datarocket_prospectos_listas` ADD CONSTRAINT `fk_dpl_prospecto` FOREIGN KEY (`prospecto_id`) REFERENCES `datarocket_prospectos` (`id`) ON DELETE CASCADE', 'DO 0');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

SET @n := (SELECT COUNT(*) FROM information_schema.REFERENTIAL_CONSTRAINTS
            WHERE CONSTRAINT_SCHEMA = DATABASE() AND TABLE_NAME = 'datarocket_prospectos_listas'
              AND CONSTRAINT_NAME = 'fk_dpl_lista');
SET @sql := IF(@n = 0,
  'ALTER TABLE `datarocket_prospectos_listas` ADD CONSTRAINT `fk_dpl_lista` FOREIGN KEY (`lista_id`) REFERENCES `datarocket_listas` (`id`) ON DELETE CASCADE', 'DO 0');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;


-- ===========================================================================
-- Paso 6: `datarocket_oportunidades.contacto_id`.
-- ===========================================================================
SET @v := (SELECT COUNT(*) FROM information_schema.REFERENTIAL_CONSTRAINTS
            WHERE CONSTRAINT_SCHEMA = DATABASE() AND TABLE_NAME = 'datarocket_oportunidades'
              AND CONSTRAINT_NAME = 'fk_dr_oportunidades_contacto');
SET @sql := IF(@v = 1, 'ALTER TABLE `datarocket_oportunidades` DROP FOREIGN KEY `fk_dr_oportunidades_contacto`', 'DO 0');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

SET @v := (SELECT COUNT(*) FROM information_schema.COLUMNS
            WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'datarocket_oportunidades'
              AND COLUMN_NAME = 'contacto_id');
SET @n := (SELECT COUNT(*) FROM information_schema.COLUMNS
            WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'datarocket_oportunidades'
              AND COLUMN_NAME = 'prospecto_id');
SET @sql := IF(@v = 1 AND @n = 0,
  'ALTER TABLE `datarocket_oportunidades` RENAME COLUMN `contacto_id` TO `prospecto_id`', 'DO 0');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

SET @v := (SELECT COUNT(DISTINCT INDEX_NAME) FROM information_schema.STATISTICS
            WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'datarocket_oportunidades'
              AND INDEX_NAME = 'idx_dr_oportunidades_contacto');
SET @n := (SELECT COUNT(DISTINCT INDEX_NAME) FROM information_schema.STATISTICS
            WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'datarocket_oportunidades'
              AND INDEX_NAME = 'idx_dr_oportunidades_prospecto');
SET @sql := IF(@v = 1 AND @n = 0,
  'ALTER TABLE `datarocket_oportunidades` RENAME INDEX `idx_dr_oportunidades_contacto` TO `idx_dr_oportunidades_prospecto`', 'DO 0');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

SET @n := (SELECT COUNT(*) FROM information_schema.REFERENTIAL_CONSTRAINTS
            WHERE CONSTRAINT_SCHEMA = DATABASE() AND TABLE_NAME = 'datarocket_oportunidades'
              AND CONSTRAINT_NAME = 'fk_dr_oportunidades_prospecto');
SET @sql := IF(@n = 0,
  'ALTER TABLE `datarocket_oportunidades` ADD CONSTRAINT `fk_dr_oportunidades_prospecto` FOREIGN KEY (`prospecto_id`) REFERENCES `datarocket_prospectos` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT', 'DO 0');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;


-- ===========================================================================
-- Paso 7: `datarocket_interacciones.contacto_id`. Sin FK (nunca la tuvo,
-- ver el comentario de la tabla en db/schema.sql), solo columna e indice.
-- ===========================================================================
SET @v := (SELECT COUNT(*) FROM information_schema.COLUMNS
            WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'datarocket_interacciones'
              AND COLUMN_NAME = 'contacto_id');
SET @n := (SELECT COUNT(*) FROM information_schema.COLUMNS
            WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'datarocket_interacciones'
              AND COLUMN_NAME = 'prospecto_id');
SET @sql := IF(@v = 1 AND @n = 0,
  'ALTER TABLE `datarocket_interacciones` RENAME COLUMN `contacto_id` TO `prospecto_id`', 'DO 0');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

SET @v := (SELECT COUNT(DISTINCT INDEX_NAME) FROM information_schema.STATISTICS
            WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'datarocket_interacciones'
              AND INDEX_NAME = 'idx_dri_contacto_fecha');
SET @n := (SELECT COUNT(DISTINCT INDEX_NAME) FROM information_schema.STATISTICS
            WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'datarocket_interacciones'
              AND INDEX_NAME = 'idx_dri_prospecto_fecha');
SET @sql := IF(@v = 1 AND @n = 0,
  'ALTER TABLE `datarocket_interacciones` RENAME INDEX `idx_dri_contacto_fecha` TO `idx_dri_prospecto_fecha`', 'DO 0');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;


-- ===========================================================================
-- Paso 8: `datarocket_actividades`. Tabla vacia y sin ninguna referencia en el
-- codigo (verificado por grep); se renombra por consistencia para que no quede
-- como el unico `contacto_id` del modulo.
-- ===========================================================================
SET @v := (SELECT COUNT(*) FROM information_schema.COLUMNS
            WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'datarocket_actividades'
              AND COLUMN_NAME = 'contacto_id');
SET @n := (SELECT COUNT(*) FROM information_schema.COLUMNS
            WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'datarocket_actividades'
              AND COLUMN_NAME = 'prospecto_id');
SET @sql := IF(@v = 1 AND @n = 0,
  'ALTER TABLE `datarocket_actividades` RENAME COLUMN `contacto_id` TO `prospecto_id`', 'DO 0');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

SET @v := (SELECT COUNT(DISTINCT INDEX_NAME) FROM information_schema.STATISTICS
            WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'datarocket_actividades'
              AND INDEX_NAME = 'idx_dra_contacto_fecha');
SET @n := (SELECT COUNT(DISTINCT INDEX_NAME) FROM information_schema.STATISTICS
            WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'datarocket_actividades'
              AND INDEX_NAME = 'idx_dra_prospecto_fecha');
SET @sql := IF(@v = 1 AND @n = 0,
  'ALTER TABLE `datarocket_actividades` RENAME INDEX `idx_dra_contacto_fecha` TO `idx_dra_prospecto_fecha`', 'DO 0');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;


-- ===========================================================================
-- Paso 9: las tres colas de mensajeria. `contacto_id` apunta al prospecto:
-- lo escriben los canalizadores (lib/aws_mensajes.php, lib/evolution_mensajes.php,
-- lib/telegram_mensajes.php) y el microservicio api/v4/aws/mensajes.php.
-- Ninguna tiene FK ni indice sobre la columna.
-- ===========================================================================
SET @v := (SELECT COUNT(*) FROM information_schema.COLUMNS
            WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'aws_mensajes' AND COLUMN_NAME = 'contacto_id');
SET @n := (SELECT COUNT(*) FROM information_schema.COLUMNS
            WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'aws_mensajes' AND COLUMN_NAME = 'prospecto_id');
SET @sql := IF(@v = 1 AND @n = 0,
  'ALTER TABLE `aws_mensajes` RENAME COLUMN `contacto_id` TO `prospecto_id`', 'DO 0');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

SET @v := (SELECT COUNT(*) FROM information_schema.COLUMNS
            WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'evolution_mensajes' AND COLUMN_NAME = 'contacto_id');
SET @n := (SELECT COUNT(*) FROM information_schema.COLUMNS
            WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'evolution_mensajes' AND COLUMN_NAME = 'prospecto_id');
SET @sql := IF(@v = 1 AND @n = 0,
  'ALTER TABLE `evolution_mensajes` RENAME COLUMN `contacto_id` TO `prospecto_id`', 'DO 0');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

SET @v := (SELECT COUNT(*) FROM information_schema.COLUMNS
            WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'telegram_mensajes' AND COLUMN_NAME = 'contacto_id');
SET @n := (SELECT COUNT(*) FROM information_schema.COLUMNS
            WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'telegram_mensajes' AND COLUMN_NAME = 'prospecto_id');
SET @sql := IF(@v = 1 AND @n = 0,
  'ALTER TABLE `telegram_mensajes` RENAME COLUMN `contacto_id` TO `prospecto_id`', 'DO 0');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;


-- ===========================================================================
-- Paso 10: permisos del ABM. Mismo `id`, y `roles.permisos` guarda ids, asi
-- que nadie pierde el acceso. Los `plataformas.evolution.contactos.*` NO se
-- tocan: son de otro modulo.
-- ===========================================================================
UPDATE `permisos` SET `slug` = 'datarocket.prospectos.consultar',
       `nombre` = 'Datarocket > Prospectos > Consultar'
 WHERE `slug` = 'datarocket.contactos.consultar';

UPDATE `permisos` SET `slug` = 'datarocket.prospectos.agregar',
       `nombre` = 'Datarocket > Prospectos > Agregar'
 WHERE `slug` = 'datarocket.contactos.agregar';

UPDATE `permisos` SET `slug` = 'datarocket.prospectos.editar',
       `nombre` = 'Datarocket > Prospectos > Editar'
 WHERE `slug` = 'datarocket.contactos.editar';

UPDATE `permisos` SET `slug` = 'datarocket.prospectos.eliminar',
       `nombre` = 'Datarocket > Prospectos > Eliminar'
 WHERE `slug` = 'datarocket.contactos.eliminar';
