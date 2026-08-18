-- Renombra el modulo Datarocket > Prospectos a Datarocket > Oportunidades.
-- El nombre viejo venia arrastrado del ABM legacy `datasaleprospectos`, pero la
-- tabla hace rato dejo de ser un "lead": la identidad vive en
-- `datarocket_contactos` y esta fila es un negocio concreto con embudo, etapa,
-- monto y fecha estimada de cierre — una oportunidad en el sentido CRM.
--
--   datarocket_prospectos                 -> datarocket_oportunidades
--   datarocket_interacciones.prospecto_id -> datarocket_interacciones.oportunidad_id
--   idx_dr_prospectos_*                   -> idx_dr_oportunidades_*
--   fk_dr_prospectos_*                    -> fk_dr_oportunidades_*
--   idx_dri_prospecto_fecha               -> idx_dri_oportunidad_fecha
--   fk_dri_prospecto                      -> fk_dri_oportunidad
--   estados.datarocket_prospecto_moneda   -> estados.datarocket_oportunidad_moneda
--   permisos datarocket.prospectos.*      -> permisos datarocket.oportunidades.*
--
-- LIMITE DEL ALCANCE — nada de esto se toca, es legacy compartido:
--   * la tabla `datasaleprospectos` y su ABM legacy /prospectos;
--   * las filas de `estados` con campo `datasale_prospecto_*` (sentido, origen,
--     estado, producto), que este modulo LEE pero comparte con el ABM legacy.
--     Renombrarlas romperia Datasale, asi que el prefijo heredado queda como
--     esta y el codigo lo sigue documentando como deuda consciente. Solo se
--     renombra `datarocket_prospecto_moneda`, que es propio de este modulo
--     (ver migracion 20260816_1300);
--   * los permisos `datasale.prospectos.*` del ABM legacy.
--
-- Los permisos nuevos conservan su `id`, y `roles.permisos` guarda un CSV de
-- ids (no de slugs), asi que los roles no se tocan: quien tenia acceso a
-- Prospectos lo mantiene sobre Oportunidades.
--
-- ORDEN DE DESPLIEGUE: codigo y migracion tienen que ir juntos. Entre uno y
-- otro el ABM tira 500 (el endpoint viejo consulta una tabla que ya no existe,
-- o el nuevo una que todavia no). No hay ventana de compatibilidad posible en
-- un rename de tabla.
--
-- Idempotente: cada paso corre solo si el nombre viejo todavia existe Y el
-- nuevo todavia no. En la segunda corrida no hace nada.
-- Compatible MySQL 8 (dev) + MariaDB 10.11 (prod). `RENAME COLUMN` y
-- `RENAME INDEX` requieren MariaDB >= 10.5.2; prod corre 10.11.


-- ---------------------------------------------------------------------------
-- 1) La tabla. InnoDB reescribe solas las FK que la referencian
--    (`fk_dri_prospecto` pasa a apuntar a `datarocket_oportunidades`), pero
--    NO renombra los constraints — de eso se ocupan los pasos 3 y 4.
-- ---------------------------------------------------------------------------
SET @viejo := (SELECT COUNT(*) FROM information_schema.TABLES
                WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'datarocket_prospectos');
SET @nuevo := (SELECT COUNT(*) FROM information_schema.TABLES
                WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'datarocket_oportunidades');
SET @sql := IF(@viejo = 1 AND @nuevo = 0,
  'RENAME TABLE `datarocket_prospectos` TO `datarocket_oportunidades`', 'DO 0');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;


-- ---------------------------------------------------------------------------
-- 2) Indices de la tabla renombrada. Metadata-only en InnoDB, instantaneo.
-- ---------------------------------------------------------------------------
SET @viejo := (SELECT COUNT(DISTINCT INDEX_NAME) FROM information_schema.STATISTICS
                WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'datarocket_oportunidades'
                  AND INDEX_NAME = 'idx_dr_prospectos_embudo');
SET @nuevo := (SELECT COUNT(DISTINCT INDEX_NAME) FROM information_schema.STATISTICS
                WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'datarocket_oportunidades'
                  AND INDEX_NAME = 'idx_dr_oportunidades_embudo');
SET @sql := IF(@viejo = 1 AND @nuevo = 0,
  'ALTER TABLE `datarocket_oportunidades` RENAME INDEX `idx_dr_prospectos_embudo` TO `idx_dr_oportunidades_embudo`', 'DO 0');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @viejo := (SELECT COUNT(DISTINCT INDEX_NAME) FROM information_schema.STATISTICS
                WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'datarocket_oportunidades'
                  AND INDEX_NAME = 'idx_dr_prospectos_etapa');
SET @nuevo := (SELECT COUNT(DISTINCT INDEX_NAME) FROM information_schema.STATISTICS
                WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'datarocket_oportunidades'
                  AND INDEX_NAME = 'idx_dr_oportunidades_etapa');
SET @sql := IF(@viejo = 1 AND @nuevo = 0,
  'ALTER TABLE `datarocket_oportunidades` RENAME INDEX `idx_dr_prospectos_etapa` TO `idx_dr_oportunidades_etapa`', 'DO 0');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @viejo := (SELECT COUNT(DISTINCT INDEX_NAME) FROM information_schema.STATISTICS
                WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'datarocket_oportunidades'
                  AND INDEX_NAME = 'idx_dr_prospectos_etapa_ingreso');
SET @nuevo := (SELECT COUNT(DISTINCT INDEX_NAME) FROM information_schema.STATISTICS
                WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'datarocket_oportunidades'
                  AND INDEX_NAME = 'idx_dr_oportunidades_etapa_ingreso');
SET @sql := IF(@viejo = 1 AND @nuevo = 0,
  'ALTER TABLE `datarocket_oportunidades` RENAME INDEX `idx_dr_prospectos_etapa_ingreso` TO `idx_dr_oportunidades_etapa_ingreso`', 'DO 0');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @viejo := (SELECT COUNT(DISTINCT INDEX_NAME) FROM information_schema.STATISTICS
                WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'datarocket_oportunidades'
                  AND INDEX_NAME = 'idx_dr_prospectos_contacto');
SET @nuevo := (SELECT COUNT(DISTINCT INDEX_NAME) FROM information_schema.STATISTICS
                WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'datarocket_oportunidades'
                  AND INDEX_NAME = 'idx_dr_oportunidades_contacto');
SET @sql := IF(@viejo = 1 AND @nuevo = 0,
  'ALTER TABLE `datarocket_oportunidades` RENAME INDEX `idx_dr_prospectos_contacto` TO `idx_dr_oportunidades_contacto`', 'DO 0');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @viejo := (SELECT COUNT(DISTINCT INDEX_NAME) FROM information_schema.STATISTICS
                WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'datarocket_oportunidades'
                  AND INDEX_NAME = 'idx_dr_prospectos_proyecto');
SET @nuevo := (SELECT COUNT(DISTINCT INDEX_NAME) FROM information_schema.STATISTICS
                WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'datarocket_oportunidades'
                  AND INDEX_NAME = 'idx_dr_oportunidades_proyecto');
SET @sql := IF(@viejo = 1 AND @nuevo = 0,
  'ALTER TABLE `datarocket_oportunidades` RENAME INDEX `idx_dr_prospectos_proyecto` TO `idx_dr_oportunidades_proyecto`', 'DO 0');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @viejo := (SELECT COUNT(DISTINCT INDEX_NAME) FROM information_schema.STATISTICS
                WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'datarocket_oportunidades'
                  AND INDEX_NAME = 'idx_dr_prospectos_cierre');
SET @nuevo := (SELECT COUNT(DISTINCT INDEX_NAME) FROM information_schema.STATISTICS
                WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'datarocket_oportunidades'
                  AND INDEX_NAME = 'idx_dr_oportunidades_cierre');
SET @sql := IF(@viejo = 1 AND @nuevo = 0,
  'ALTER TABLE `datarocket_oportunidades` RENAME INDEX `idx_dr_prospectos_cierre` TO `idx_dr_oportunidades_cierre`', 'DO 0');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @viejo := (SELECT COUNT(DISTINCT INDEX_NAME) FROM information_schema.STATISTICS
                WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'datarocket_oportunidades'
                  AND INDEX_NAME = 'idx_dr_prospectos_asignado');
SET @nuevo := (SELECT COUNT(DISTINCT INDEX_NAME) FROM information_schema.STATISTICS
                WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'datarocket_oportunidades'
                  AND INDEX_NAME = 'idx_dr_oportunidades_asignado');
SET @sql := IF(@viejo = 1 AND @nuevo = 0,
  'ALTER TABLE `datarocket_oportunidades` RENAME INDEX `idx_dr_prospectos_asignado` TO `idx_dr_oportunidades_asignado`', 'DO 0');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @viejo := (SELECT COUNT(DISTINCT INDEX_NAME) FROM information_schema.STATISTICS
                WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'datarocket_oportunidades'
                  AND INDEX_NAME = 'idx_dr_prospectos_atendido');
SET @nuevo := (SELECT COUNT(DISTINCT INDEX_NAME) FROM information_schema.STATISTICS
                WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'datarocket_oportunidades'
                  AND INDEX_NAME = 'idx_dr_oportunidades_atendido');
SET @sql := IF(@viejo = 1 AND @nuevo = 0,
  'ALTER TABLE `datarocket_oportunidades` RENAME INDEX `idx_dr_prospectos_atendido` TO `idx_dr_oportunidades_atendido`', 'DO 0');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;


-- ---------------------------------------------------------------------------
-- 3) Constraints de la tabla renombrada. No existe RENAME CONSTRAINT en
--    MySQL/MariaDB, asi que va DROP + ADD. El indice de respaldo ya existe
--    (paso 2) y el ADD lo reutiliza en lugar de crear uno nuevo. La validacion
--    de filas es despreciable: la tabla ronda las 5k.
-- ---------------------------------------------------------------------------
SET @viejo := (SELECT COUNT(*) FROM information_schema.REFERENTIAL_CONSTRAINTS
                WHERE CONSTRAINT_SCHEMA = DATABASE() AND TABLE_NAME = 'datarocket_oportunidades'
                  AND CONSTRAINT_NAME = 'fk_dr_prospectos_asignado');
SET @sql := IF(@viejo = 1,
  'ALTER TABLE `datarocket_oportunidades` DROP FOREIGN KEY `fk_dr_prospectos_asignado`', 'DO 0');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
SET @nuevo := (SELECT COUNT(*) FROM information_schema.REFERENTIAL_CONSTRAINTS
                WHERE CONSTRAINT_SCHEMA = DATABASE() AND TABLE_NAME = 'datarocket_oportunidades'
                  AND CONSTRAINT_NAME = 'fk_dr_oportunidades_asignado');
SET @sql := IF(@nuevo = 0,
  'ALTER TABLE `datarocket_oportunidades` ADD CONSTRAINT `fk_dr_oportunidades_asignado` FOREIGN KEY (`asignado`) REFERENCES `usuarios` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT', 'DO 0');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @viejo := (SELECT COUNT(*) FROM information_schema.REFERENTIAL_CONSTRAINTS
                WHERE CONSTRAINT_SCHEMA = DATABASE() AND TABLE_NAME = 'datarocket_oportunidades'
                  AND CONSTRAINT_NAME = 'fk_dr_prospectos_atendido');
SET @sql := IF(@viejo = 1,
  'ALTER TABLE `datarocket_oportunidades` DROP FOREIGN KEY `fk_dr_prospectos_atendido`', 'DO 0');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
SET @nuevo := (SELECT COUNT(*) FROM information_schema.REFERENTIAL_CONSTRAINTS
                WHERE CONSTRAINT_SCHEMA = DATABASE() AND TABLE_NAME = 'datarocket_oportunidades'
                  AND CONSTRAINT_NAME = 'fk_dr_oportunidades_atendido');
SET @sql := IF(@nuevo = 0,
  'ALTER TABLE `datarocket_oportunidades` ADD CONSTRAINT `fk_dr_oportunidades_atendido` FOREIGN KEY (`atendido`) REFERENCES `usuarios` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT', 'DO 0');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @viejo := (SELECT COUNT(*) FROM information_schema.REFERENTIAL_CONSTRAINTS
                WHERE CONSTRAINT_SCHEMA = DATABASE() AND TABLE_NAME = 'datarocket_oportunidades'
                  AND CONSTRAINT_NAME = 'fk_dr_prospectos_contacto');
SET @sql := IF(@viejo = 1,
  'ALTER TABLE `datarocket_oportunidades` DROP FOREIGN KEY `fk_dr_prospectos_contacto`', 'DO 0');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
SET @nuevo := (SELECT COUNT(*) FROM information_schema.REFERENTIAL_CONSTRAINTS
                WHERE CONSTRAINT_SCHEMA = DATABASE() AND TABLE_NAME = 'datarocket_oportunidades'
                  AND CONSTRAINT_NAME = 'fk_dr_oportunidades_contacto');
SET @sql := IF(@nuevo = 0,
  'ALTER TABLE `datarocket_oportunidades` ADD CONSTRAINT `fk_dr_oportunidades_contacto` FOREIGN KEY (`contacto_id`) REFERENCES `datarocket_contactos` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT', 'DO 0');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @viejo := (SELECT COUNT(*) FROM information_schema.REFERENTIAL_CONSTRAINTS
                WHERE CONSTRAINT_SCHEMA = DATABASE() AND TABLE_NAME = 'datarocket_oportunidades'
                  AND CONSTRAINT_NAME = 'fk_dr_prospectos_embudo');
SET @sql := IF(@viejo = 1,
  'ALTER TABLE `datarocket_oportunidades` DROP FOREIGN KEY `fk_dr_prospectos_embudo`', 'DO 0');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
SET @nuevo := (SELECT COUNT(*) FROM information_schema.REFERENTIAL_CONSTRAINTS
                WHERE CONSTRAINT_SCHEMA = DATABASE() AND TABLE_NAME = 'datarocket_oportunidades'
                  AND CONSTRAINT_NAME = 'fk_dr_oportunidades_embudo');
SET @sql := IF(@nuevo = 0,
  'ALTER TABLE `datarocket_oportunidades` ADD CONSTRAINT `fk_dr_oportunidades_embudo` FOREIGN KEY (`embudo_id`) REFERENCES `datarocket_embudos` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT', 'DO 0');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @viejo := (SELECT COUNT(*) FROM information_schema.REFERENTIAL_CONSTRAINTS
                WHERE CONSTRAINT_SCHEMA = DATABASE() AND TABLE_NAME = 'datarocket_oportunidades'
                  AND CONSTRAINT_NAME = 'fk_dr_prospectos_etapa');
SET @sql := IF(@viejo = 1,
  'ALTER TABLE `datarocket_oportunidades` DROP FOREIGN KEY `fk_dr_prospectos_etapa`', 'DO 0');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
SET @nuevo := (SELECT COUNT(*) FROM information_schema.REFERENTIAL_CONSTRAINTS
                WHERE CONSTRAINT_SCHEMA = DATABASE() AND TABLE_NAME = 'datarocket_oportunidades'
                  AND CONSTRAINT_NAME = 'fk_dr_oportunidades_etapa');
SET @sql := IF(@nuevo = 0,
  'ALTER TABLE `datarocket_oportunidades` ADD CONSTRAINT `fk_dr_oportunidades_etapa` FOREIGN KEY (`etapa_id`) REFERENCES `datarocket_etapas` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT', 'DO 0');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @viejo := (SELECT COUNT(*) FROM information_schema.REFERENTIAL_CONSTRAINTS
                WHERE CONSTRAINT_SCHEMA = DATABASE() AND TABLE_NAME = 'datarocket_oportunidades'
                  AND CONSTRAINT_NAME = 'fk_dr_prospectos_proyecto');
SET @sql := IF(@viejo = 1,
  'ALTER TABLE `datarocket_oportunidades` DROP FOREIGN KEY `fk_dr_prospectos_proyecto`', 'DO 0');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
SET @nuevo := (SELECT COUNT(*) FROM information_schema.REFERENTIAL_CONSTRAINTS
                WHERE CONSTRAINT_SCHEMA = DATABASE() AND TABLE_NAME = 'datarocket_oportunidades'
                  AND CONSTRAINT_NAME = 'fk_dr_oportunidades_proyecto');
SET @sql := IF(@nuevo = 0,
  'ALTER TABLE `datarocket_oportunidades` ADD CONSTRAINT `fk_dr_oportunidades_proyecto` FOREIGN KEY (`proyecto_id`) REFERENCES `proyectos` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT', 'DO 0');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;


-- ---------------------------------------------------------------------------
-- 4) datarocket_interacciones.prospecto_id -> oportunidad_id.
--    La FK se dropea ANTES del rename: MariaDB no acepta RENAME COLUMN sobre
--    una columna que participa de una foreign key. Se recrea al final, con el
--    ON DELETE SET NULL original (la interaccion es un hecho sobre el contacto
--    y tiene que sobrevivir al descarte de la oportunidad).
-- ---------------------------------------------------------------------------
SET @viejo := (SELECT COUNT(*) FROM information_schema.REFERENTIAL_CONSTRAINTS
                WHERE CONSTRAINT_SCHEMA = DATABASE() AND TABLE_NAME = 'datarocket_interacciones'
                  AND CONSTRAINT_NAME = 'fk_dri_prospecto');
SET @sql := IF(@viejo = 1,
  'ALTER TABLE `datarocket_interacciones` DROP FOREIGN KEY `fk_dri_prospecto`', 'DO 0');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @viejo := (SELECT COUNT(*) FROM information_schema.COLUMNS
                WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'datarocket_interacciones'
                  AND COLUMN_NAME = 'prospecto_id');
SET @nuevo := (SELECT COUNT(*) FROM information_schema.COLUMNS
                WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'datarocket_interacciones'
                  AND COLUMN_NAME = 'oportunidad_id');
SET @sql := IF(@viejo = 1 AND @nuevo = 0,
  'ALTER TABLE `datarocket_interacciones` RENAME COLUMN `prospecto_id` TO `oportunidad_id`', 'DO 0');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @viejo := (SELECT COUNT(DISTINCT INDEX_NAME) FROM information_schema.STATISTICS
                WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'datarocket_interacciones'
                  AND INDEX_NAME = 'idx_dri_prospecto_fecha');
SET @nuevo := (SELECT COUNT(DISTINCT INDEX_NAME) FROM information_schema.STATISTICS
                WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'datarocket_interacciones'
                  AND INDEX_NAME = 'idx_dri_oportunidad_fecha');
SET @sql := IF(@viejo = 1 AND @nuevo = 0,
  'ALTER TABLE `datarocket_interacciones` RENAME INDEX `idx_dri_prospecto_fecha` TO `idx_dri_oportunidad_fecha`', 'DO 0');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @nuevo := (SELECT COUNT(*) FROM information_schema.REFERENTIAL_CONSTRAINTS
                WHERE CONSTRAINT_SCHEMA = DATABASE() AND TABLE_NAME = 'datarocket_interacciones'
                  AND CONSTRAINT_NAME = 'fk_dri_oportunidad');
SET @sql := IF(@nuevo = 0,
  'ALTER TABLE `datarocket_interacciones` ADD CONSTRAINT `fk_dri_oportunidad` FOREIGN KEY (`oportunidad_id`) REFERENCES `datarocket_oportunidades` (`id`) ON DELETE SET NULL ON UPDATE RESTRICT', 'DO 0');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;


-- ---------------------------------------------------------------------------
-- 5) Catalogo `estados`. SOLO el campo propio de este modulo. Las filas
--    `datasale_prospecto_*` (sentido / origen / estado / producto) NO se
--    tocan: las comparte el ABM legacy /prospectos de Datasale.
-- ---------------------------------------------------------------------------
UPDATE `estados`
   SET `campo` = 'datarocket_oportunidad_moneda'
 WHERE `campo` = 'datarocket_prospecto_moneda';


-- ---------------------------------------------------------------------------
-- 6) Permisos del ABM. Mismo `id`, y `roles.permisos` referencia ids, asi que
--    nadie pierde el acceso. Los `datasale.prospectos.*` del ABM legacy no se
--    tocan.
-- ---------------------------------------------------------------------------
UPDATE `permisos`
   SET `slug`   = 'datarocket.oportunidades.consultar',
       `nombre` = 'Datarocket > Oportunidades > Consultar'
 WHERE `slug` = 'datarocket.prospectos.consultar';

UPDATE `permisos`
   SET `slug`   = 'datarocket.oportunidades.agregar',
       `nombre` = 'Datarocket > Oportunidades > Agregar'
 WHERE `slug` = 'datarocket.prospectos.agregar';

UPDATE `permisos`
   SET `slug`   = 'datarocket.oportunidades.editar',
       `nombre` = 'Datarocket > Oportunidades > Editar'
 WHERE `slug` = 'datarocket.prospectos.editar';

UPDATE `permisos`
   SET `slug`   = 'datarocket.oportunidades.eliminar',
       `nombre` = 'Datarocket > Oportunidades > Eliminar'
 WHERE `slug` = 'datarocket.prospectos.eliminar';
