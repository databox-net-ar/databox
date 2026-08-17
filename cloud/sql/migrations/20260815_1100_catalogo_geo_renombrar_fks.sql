-- Catalogo geografico: renombrar las columnas de relacion a `*_id` y ponerles FK.
--
--   provincias.pais       -> provincias.pais_id       -> FK a paises.id
--   localidades.pais      -> localidades.pais_id      -> FK a paises.id
--   localidades.provincia -> localidades.provincia_id -> FK a provincias.id
--
-- Continuacion de 20260815_1000, que hizo lo mismo del lado de
-- `datarocket_contactos`. Ahora se cierra el catalogo sobre si mismo: la
-- jerarquia pais > provincia > localidad queda garantizada por InnoDB y no
-- solo por convencion.
--
-- Estado de partida (relevado el 2026-08-15 contra dev y prod, identicos):
--
--   * Las tres columnas ya son INT(11) NULL — no hay cambio de tipo, solo
--     rename. Esto las diferencia de la migracion anterior, donde ademas
--     habia que convertir VARCHAR -> INT.
--   * Datos perfectamente consistentes en ambos entornos: 54 provincias y
--     783 localidades, 0 NULL, 0 ceros y 0 huerfanos en las tres columnas.
--     La limpieza defensiva de abajo no deberia tocar ninguna fila; se deja
--     igual por si los datos derivan entre que esto se escribe y se aplica.
--   * `paises`, `provincias` y `localidades` son InnoDB en dev y prod, con
--     `id` INT(11) con signo.
--
-- OJO — estas tablas son compartidas con el admin legacy (repo
-- databox_legacy), que corre contra la MISMA base y consulta las columnas
-- viejas por SQL crudo. El rename obliga a actualizar tambien ese repo; ver
-- el detalle en el chat del 2026-08-15.
--
-- FK ON DELETE RESTRICT / ON UPDATE RESTRICT: mismo criterio que el resto del
-- proyecto. Borrar un pais que tiene provincias, o una provincia que tiene
-- localidades, tiene que fallar ruidosamente.
--
-- Idempotente: cada paso se guarda con information_schema.
-- Compatible MySQL 8 (dev) + MariaDB 10.11 (prod).


-- ---------------------------------------------------------------------------
-- 1) Renombrar. CHANGE COLUMN y no RENAME COLUMN: RENAME requiere
--    MariaDB >= 10.5.2. Se conserva el tipo exacto (INT NULL).
-- ---------------------------------------------------------------------------

SET @viejo := (
  SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'provincias' AND COLUMN_NAME = 'pais'
);
SET @nuevo := (
  SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'provincias' AND COLUMN_NAME = 'pais_id'
);
SET @sql := IF(@viejo = 1 AND @nuevo = 0,
  'ALTER TABLE provincias CHANGE COLUMN `pais` `pais_id` INT NULL DEFAULT NULL',
  'DO 0'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @viejo := (
  SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'localidades' AND COLUMN_NAME = 'pais'
);
SET @nuevo := (
  SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'localidades' AND COLUMN_NAME = 'pais_id'
);
SET @sql := IF(@viejo = 1 AND @nuevo = 0,
  'ALTER TABLE localidades CHANGE COLUMN `pais` `pais_id` INT NULL DEFAULT NULL',
  'DO 0'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @viejo := (
  SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'localidades' AND COLUMN_NAME = 'provincia'
);
SET @nuevo := (
  SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'localidades' AND COLUMN_NAME = 'provincia_id'
);
SET @sql := IF(@viejo = 1 AND @nuevo = 0,
  'ALTER TABLE localidades CHANGE COLUMN `provincia` `provincia_id` INT NULL DEFAULT NULL',
  'DO 0'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;


-- ---------------------------------------------------------------------------
-- 2) Limpieza defensiva: el centinela 0 y los huerfanos pasan a NULL. Hoy no
--    matchea ninguna fila en dev ni en prod; esta para que la migracion no
--    explote si los datos cambian antes de aplicarse. Naturalmente idempotente
--    (con la FK puesta ya no puede haber huerfanos).
-- ---------------------------------------------------------------------------

UPDATE provincias SET pais_id = NULL WHERE pais_id = 0;
UPDATE localidades SET pais_id = NULL WHERE pais_id = 0;
UPDATE localidades SET provincia_id = NULL WHERE provincia_id = 0;

UPDATE provincias pr
   SET pr.pais_id = NULL
 WHERE pr.pais_id IS NOT NULL
   AND NOT EXISTS (SELECT 1 FROM paises p WHERE p.id = pr.pais_id);

UPDATE localidades l
   SET l.pais_id = NULL
 WHERE l.pais_id IS NOT NULL
   AND NOT EXISTS (SELECT 1 FROM paises p WHERE p.id = l.pais_id);

UPDATE localidades l
   SET l.provincia_id = NULL
 WHERE l.provincia_id IS NOT NULL
   AND NOT EXISTS (SELECT 1 FROM provincias pr WHERE pr.id = l.provincia_id);


-- ---------------------------------------------------------------------------
-- 3) Indices explicitos (InnoDB crearia uno implicito con la FK, pero con
--    nombre automatico).
-- ---------------------------------------------------------------------------

SET @exists := (
  SELECT COUNT(*) FROM information_schema.STATISTICS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'provincias'
    AND INDEX_NAME = 'idx_provincias_pais'
);
SET @sql := IF(@exists = 0,
  'ALTER TABLE provincias ADD INDEX `idx_provincias_pais` (`pais_id`)',
  'DO 0'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @exists := (
  SELECT COUNT(*) FROM information_schema.STATISTICS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'localidades'
    AND INDEX_NAME = 'idx_localidades_pais'
);
SET @sql := IF(@exists = 0,
  'ALTER TABLE localidades ADD INDEX `idx_localidades_pais` (`pais_id`)',
  'DO 0'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @exists := (
  SELECT COUNT(*) FROM information_schema.STATISTICS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'localidades'
    AND INDEX_NAME = 'idx_localidades_provincia'
);
SET @sql := IF(@exists = 0,
  'ALTER TABLE localidades ADD INDEX `idx_localidades_provincia` (`provincia_id`)',
  'DO 0'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;


-- ---------------------------------------------------------------------------
-- 4) Las FKs.
-- ---------------------------------------------------------------------------

SET @exists := (
  SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'provincias'
    AND CONSTRAINT_NAME = 'fk_provincias_pais'
);
SET @sql := IF(@exists = 0,
  'ALTER TABLE provincias
     ADD CONSTRAINT `fk_provincias_pais` FOREIGN KEY (`pais_id`)
         REFERENCES `paises` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT',
  'DO 0'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @exists := (
  SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'localidades'
    AND CONSTRAINT_NAME = 'fk_localidades_pais'
);
SET @sql := IF(@exists = 0,
  'ALTER TABLE localidades
     ADD CONSTRAINT `fk_localidades_pais` FOREIGN KEY (`pais_id`)
         REFERENCES `paises` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT',
  'DO 0'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @exists := (
  SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'localidades'
    AND CONSTRAINT_NAME = 'fk_localidades_provincia'
);
SET @sql := IF(@exists = 0,
  'ALTER TABLE localidades
     ADD CONSTRAINT `fk_localidades_provincia` FOREIGN KEY (`provincia_id`)
         REFERENCES `provincias` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT',
  'DO 0'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
