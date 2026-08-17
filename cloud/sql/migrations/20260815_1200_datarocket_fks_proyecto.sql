-- Datarocket: FKs de `proyecto_id` a `proyectos`.
--
--   datarocket_listas.proyecto_id     -> proyectos.id
--   datarocket_plantillas.proyecto_id -> proyectos.id
--   datarocket_prospectos.proyecto_id -> proyectos.id
--
-- Cierra el mismo trabajo que ya hizo 20260812_0600 sobre
-- `datarocket_embudos.proyecto_id`: las tres tablas que faltaban del modulo
-- quedan ancladas al catalogo de proyectos por InnoDB y no por convencion.
--
-- Estado de partida (relevado el 2026-08-15 contra dev y prod, identicos):
--
--   * Las tres columnas ya son INT(11) NULL y `proyectos.id` es INT(11) con
--     signo — no hay rename ni conversion de tipo que hacer, a diferencia de
--     20260815_1000 (VARCHAR -> INT) y 20260815_1100 (rename). Esta migracion
--     es solo indice + constraint.
--   * Las cuatro tablas son InnoDB en dev y prod.
--   * Datos perfectamente consistentes en ambos entornos: 0 NULL, 0 ceros y
--     0 huerfanos en las tres columnas (dev: 29 listas / 52 plantillas /
--     1336 prospectos; prod: 29 / 51 / 1344). La limpieza defensiva de abajo
--     no deberia tocar ninguna fila; se deja igual por si los datos derivan
--     entre que esto se escribe y se aplica.
--
-- Nombres: se usa el prefijo corto `dr_`, el mismo de las FKs mas recientes
-- de la tabla (`fk_dr_prospectos_contacto/embudo/etapa`, `fk_dr_contactos_*`).
-- `fk_datarocket_embudos_proyecto` quedo con el prefijo largo y no se toca.
--
-- FK ON DELETE RESTRICT / ON UPDATE RESTRICT: mismo criterio que el resto del
-- proyecto. Borrar un proyecto que todavia tiene listas, plantillas o
-- prospectos tiene que fallar ruidosamente, no vaciar en silencio la relacion.
--
-- Idempotente: cada paso se guarda con information_schema.
-- Compatible MySQL 8 (dev) + MariaDB 10.11 (prod).


-- ---------------------------------------------------------------------------
-- 1) Limpieza defensiva: el centinela 0 y los huerfanos pasan a NULL. Hoy no
--    matchea ninguna fila en dev ni en prod; esta para que la migracion no
--    explote si los datos cambian antes de aplicarse. Naturalmente idempotente
--    (con la FK puesta ya no puede haber huerfanos).
-- ---------------------------------------------------------------------------

UPDATE datarocket_listas     SET proyecto_id = NULL WHERE proyecto_id = 0;
UPDATE datarocket_plantillas SET proyecto_id = NULL WHERE proyecto_id = 0;
UPDATE datarocket_prospectos SET proyecto_id = NULL WHERE proyecto_id = 0;

UPDATE datarocket_listas l
   SET l.proyecto_id = NULL
 WHERE l.proyecto_id IS NOT NULL
   AND NOT EXISTS (SELECT 1 FROM proyectos p WHERE p.id = l.proyecto_id);

UPDATE datarocket_plantillas t
   SET t.proyecto_id = NULL
 WHERE t.proyecto_id IS NOT NULL
   AND NOT EXISTS (SELECT 1 FROM proyectos p WHERE p.id = t.proyecto_id);

UPDATE datarocket_prospectos r
   SET r.proyecto_id = NULL
 WHERE r.proyecto_id IS NOT NULL
   AND NOT EXISTS (SELECT 1 FROM proyectos p WHERE p.id = r.proyecto_id);


-- ---------------------------------------------------------------------------
-- 2) Indices explicitos (InnoDB crearia uno implicito con la FK, pero con
--    nombre automatico).
-- ---------------------------------------------------------------------------

SET @exists := (
  SELECT COUNT(*) FROM information_schema.STATISTICS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'datarocket_listas'
    AND INDEX_NAME = 'idx_dr_listas_proyecto'
);
SET @sql := IF(@exists = 0,
  'ALTER TABLE datarocket_listas ADD INDEX `idx_dr_listas_proyecto` (`proyecto_id`)',
  'DO 0'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @exists := (
  SELECT COUNT(*) FROM information_schema.STATISTICS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'datarocket_plantillas'
    AND INDEX_NAME = 'idx_dr_plantillas_proyecto'
);
SET @sql := IF(@exists = 0,
  'ALTER TABLE datarocket_plantillas ADD INDEX `idx_dr_plantillas_proyecto` (`proyecto_id`)',
  'DO 0'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @exists := (
  SELECT COUNT(*) FROM information_schema.STATISTICS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'datarocket_prospectos'
    AND INDEX_NAME = 'idx_dr_prospectos_proyecto'
);
SET @sql := IF(@exists = 0,
  'ALTER TABLE datarocket_prospectos ADD INDEX `idx_dr_prospectos_proyecto` (`proyecto_id`)',
  'DO 0'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;


-- ---------------------------------------------------------------------------
-- 3) Las FKs.
-- ---------------------------------------------------------------------------

SET @exists := (
  SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'datarocket_listas'
    AND CONSTRAINT_NAME = 'fk_dr_listas_proyecto'
);
SET @sql := IF(@exists = 0,
  'ALTER TABLE datarocket_listas
     ADD CONSTRAINT `fk_dr_listas_proyecto` FOREIGN KEY (`proyecto_id`)
         REFERENCES `proyectos` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT',
  'DO 0'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @exists := (
  SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'datarocket_plantillas'
    AND CONSTRAINT_NAME = 'fk_dr_plantillas_proyecto'
);
SET @sql := IF(@exists = 0,
  'ALTER TABLE datarocket_plantillas
     ADD CONSTRAINT `fk_dr_plantillas_proyecto` FOREIGN KEY (`proyecto_id`)
         REFERENCES `proyectos` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT',
  'DO 0'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @exists := (
  SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'datarocket_prospectos'
    AND CONSTRAINT_NAME = 'fk_dr_prospectos_proyecto'
);
SET @sql := IF(@exists = 0,
  'ALTER TABLE datarocket_prospectos
     ADD CONSTRAINT `fk_dr_prospectos_proyecto` FOREIGN KEY (`proyecto_id`)
         REFERENCES `proyectos` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT',
  'DO 0'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
