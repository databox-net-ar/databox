-- Agrega `proyecto_id` a `datarocket_interacciones`.
--
-- EL PROBLEMA
-- -----------
-- La columna Proyecto del ABM de Interacciones sale hoy de la OPORTUNIDAD:
--
--   LEFT JOIN datarocket_oportunidades o  ON o.id  = i.oportunidad_id
--   LEFT JOIN proyectos                pr ON pr.id = o.proyecto_id
--
-- Las interacciones que escriben los encoladores al despachar una campana
-- (registrarInteraccionMensaje, en cloud/api/lib/datarocket_interacciones.php)
-- NO tienen oportunidad: cuelgan del prospecto y nada mas. Entonces ese JOIN da
-- NULL y la columna sale con guion para TODOS los envios de campana -- que son
-- justamente los que si tienen proyecto conocido, porque la campana lo exige
-- (drcaValidarLanzable frena si `proyecto_id` es NULL) y se lo pasa a los tres
-- encoladores en el payload.
--
-- O sea: el dato existia y se tiraba en el camino, por no tener donde ponerlo.
--
-- LA DECISION
-- -----------
-- Columna propia en la interaccion, y no derivarla del prospecto ni de la
-- campana:
--
--   - El prospecto NO tiene proyecto (es transversal: el mismo prospecto puede
--     recibir campanas de varios proyectos).
--   - Derivarla de la campana obligaria a guardar `campana_id` en la
--     interaccion y a que el ABM la resolviera con otro JOIN mas. La
--     interaccion es un registro historico: lo que importa es bajo que proyecto
--     se mando ESE mensaje, congelado en el momento del envio. Si manana la
--     campana cambia de proyecto, el historial no debe cambiar con ella.
--
-- La lectura pasa a ser COALESCE(i.proyecto_id, o.proyecto_id): la columna
-- nueva manda, y las interacciones viejas ligadas a una oportunidad siguen
-- mostrando el proyecto de esa oportunidad como hasta ahora. Nadie pierde nada.
--
-- FK CON RESTRICT
-- ---------------
-- Igual que `fk_dr_oportunidades_proyecto` y `fk_dr_plantillas_proyecto`, que
-- son las dos FK a `proyectos` que ya existen. No se usa SET NULL (que seria lo
-- natural en una tabla de historial) porque con esas dos ya vigentes un
-- proyecto con oportunidades o plantillas tampoco se puede borrar: sumar
-- RESTRICT aca no cambia nada en la practica y mantiene el criterio uniforme.
--
-- El backfill de las filas viejas va aparte, en la migracion 20260828_2310. Se
-- separa a proposito: ese UPDATE joinea contra `aws_mensajes`, que hoy no tiene
-- mas indice que la PK, y si el Migrador lo corta a los 30 s en produccion esta
-- migracion -- la que de verdad importa -- ya quedo aplicada.
--
-- Idempotente: patron information_schema + PREPARE/EXECUTE. No se usa
-- `ADD COLUMN IF NOT EXISTS` porque es sintaxis MariaDB-only y desarrollo corre
-- MySQL 8.0.

-- 1) La columna.
SET @existe := (
  SELECT COUNT(*) FROM information_schema.COLUMNS
   WHERE TABLE_SCHEMA = DATABASE()
     AND TABLE_NAME   = 'datarocket_interacciones'
     AND COLUMN_NAME  = 'proyecto_id'
);
SET @sql := IF(@existe = 0,
  'ALTER TABLE `datarocket_interacciones`
     ADD COLUMN `proyecto_id` int(11) NULL DEFAULT NULL AFTER `oportunidad_id`',
  'SELECT "columna proyecto_id ya existe" AS nota'
);
PREPARE st FROM @sql; EXECUTE st; DEALLOCATE PREPARE st;

-- 2) El indice. El ABM filtra y agrupa por proyecto, y la FK de abajo lo
--    necesita igual (InnoDB crea uno solo si no lo encuentra).
SET @existe := (
  SELECT COUNT(*) FROM information_schema.STATISTICS
   WHERE TABLE_SCHEMA = DATABASE()
     AND TABLE_NAME   = 'datarocket_interacciones'
     AND INDEX_NAME   = 'idx_dri_proyecto'
);
SET @sql := IF(@existe = 0,
  'ALTER TABLE `datarocket_interacciones`
     ADD INDEX `idx_dri_proyecto`(`proyecto_id`) USING BTREE',
  'SELECT "indice idx_dri_proyecto ya existe" AS nota'
);
PREPARE st FROM @sql; EXECUTE st; DEALLOCATE PREPARE st;

-- 3) La FK.
SET @existe := (
  SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS
   WHERE TABLE_SCHEMA     = DATABASE()
     AND TABLE_NAME       = 'datarocket_interacciones'
     AND CONSTRAINT_NAME  = 'fk_dri_proyecto'
     AND CONSTRAINT_TYPE  = 'FOREIGN KEY'
);
SET @sql := IF(@existe = 0,
  'ALTER TABLE `datarocket_interacciones`
     ADD CONSTRAINT `fk_dri_proyecto` FOREIGN KEY (`proyecto_id`)
         REFERENCES `proyectos` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT',
  'SELECT "FK fk_dri_proyecto ya existe" AS nota'
);
PREPARE st FROM @sql; EXECUTE st; DEALLOCATE PREPARE st;
