-- datarocket_contactos: FKs geograficas a `paises` / `provincias` / `localidades`.
--
--   datarocket_contactos.pais_id      -> paises.id
--   datarocket_contactos.provincia_id -> provincias.id
--   datarocket_contactos.localidad_id -> localidades.id
--
-- Situacion de partida (relevada el 2026-08-15 contra dev y prod):
--
--   * Las tres columnas guardan IDs de catalogo, pero declaradas VARCHAR(255).
--     El 100% de los valores no vacios son numericos en ambos entornos, asi
--     que no hay nombres ("Argentina", "Cordoba") mezclados que rescatar.
--   * En DESARROLLO las columnas ya estaban renombradas a `pais_id` /
--     `provincia_id` / `localidad_id` (rename hecho a mano, sin migracion).
--     En PRODUCCION seguian llamandose `pais` / `provincia` / `localidad`.
--     Por eso el primer bloque renombra si hace falta: deja los dos entornos
--     convergidos antes de tocar tipos. `db/schema.sql` esta desactualizado
--     en esta tabla (todavia documenta los nombres viejos).
--   * `datarocket_contactos`, `paises`, `provincias` y `localidades` son
--     InnoDB en dev y prod, y los `id` de los tres catalogos son INT(11)
--     con signo — el tipo destino de las columnas hijas.
--
-- Limpieza de datos previa a la FK (necesaria: InnoDB rechaza el ALTER si
-- queda una sola fila colgada). Se pasa a NULL, nunca se borra la fila:
--
--   * Vacios ('') -> NULL. ~8.6k filas en pais, ~8.8k en provincia,
--     ~9.5k en localidad.
--   * El centinela '0' -> NULL. Son ~22.4k filas en `localidad_id`: '0' se
--     usaba como "sin localidad", no existe `localidades.id = 0`.
--   * Huerfanos (ID numerico sin fila en el catalogo) -> NULL:
--       - pais:      0 filas.
--       - provincia: 1589 filas, todas con el valor 17. `provincias` usa
--         los codigos INDEC de a 4 (2, 6, 10, 14 ... 94), asi que 17 no es
--         una provincia faltante sino un valor basura — las localidades de
--         esas mismas filas apuntan a provincias 10 / 14 / 82 / etc., que no
--         coinciden entre si. Se anula en vez de inventar el catalogo.
--       - localidad: 2 filas (IDs 2630 y 4400) ademas de los '0'.
--
--   Queda pendiente (fuera del alcance de esta migracion) un backfill de
--   `provincia_id` a partir de `localidades.provincia` para las filas que
--   perdieron la provincia pero conservan una localidad valida — hoy son
--   159 filas recuperables por esa via.
--
-- FK ON DELETE RESTRICT / ON UPDATE RESTRICT: mismo criterio que el resto
-- de las FKs del proyecto (fk_dr_prospectos_*, fk_datarocket_embudos_*).
-- No se usa SET NULL: borrar un pais/provincia/localidad del catalogo tiene
-- que fallar ruidosamente, no vaciar en silencio los contactos que lo usan.
--
-- Idempotente: cada paso se guarda con information_schema.
-- Compatible MySQL 8 (dev) + MariaDB 10.11 (prod).


-- ---------------------------------------------------------------------------
-- 1) Renombrar las columnas viejas (solo aplica a produccion).
--    CHANGE COLUMN y no RENAME COLUMN: RENAME requiere MariaDB >= 10.5.2.
-- ---------------------------------------------------------------------------

SET @viejo := (
  SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME   = 'datarocket_contactos'
    AND COLUMN_NAME  = 'pais'
);
SET @nuevo := (
  SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME   = 'datarocket_contactos'
    AND COLUMN_NAME  = 'pais_id'
);
SET @sql := IF(@viejo = 1 AND @nuevo = 0,
  'ALTER TABLE datarocket_contactos
     CHANGE COLUMN `pais` `pais_id` VARCHAR(255) NULL DEFAULT NULL',
  'DO 0'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @viejo := (
  SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME   = 'datarocket_contactos'
    AND COLUMN_NAME  = 'provincia'
);
SET @nuevo := (
  SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME   = 'datarocket_contactos'
    AND COLUMN_NAME  = 'provincia_id'
);
SET @sql := IF(@viejo = 1 AND @nuevo = 0,
  'ALTER TABLE datarocket_contactos
     CHANGE COLUMN `provincia` `provincia_id` VARCHAR(255) NULL DEFAULT NULL',
  'DO 0'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @viejo := (
  SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME   = 'datarocket_contactos'
    AND COLUMN_NAME  = 'localidad'
);
SET @nuevo := (
  SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME   = 'datarocket_contactos'
    AND COLUMN_NAME  = 'localidad_id'
);
SET @sql := IF(@viejo = 1 AND @nuevo = 0,
  'ALTER TABLE datarocket_contactos
     CHANGE COLUMN `localidad` `localidad_id` VARCHAR(255) NULL DEFAULT NULL',
  'DO 0'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;


-- ---------------------------------------------------------------------------
-- 2) Normalizar los valores no-ID a NULL, mientras siguen siendo VARCHAR.
--    Guardado por DATA_TYPE: si la migracion ya corrio (columnas INT) el
--    bloque no hace nada, y asi un re-run no puede confundir el 0 numerico
--    con el centinela '0' de texto.
-- ---------------------------------------------------------------------------

SET @es_texto := (
  SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME   = 'datarocket_contactos'
    AND COLUMN_NAME  = 'pais_id'
    AND DATA_TYPE    = 'varchar'
);
SET @sql := IF(@es_texto = 1,
  'UPDATE datarocket_contactos
      SET pais_id = NULL
    WHERE pais_id IS NOT NULL
      AND (TRIM(pais_id) = '''' OR TRIM(pais_id) = ''0''
           OR TRIM(pais_id) NOT REGEXP ''^[0-9]+$'')',
  'DO 0'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @es_texto := (
  SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME   = 'datarocket_contactos'
    AND COLUMN_NAME  = 'provincia_id'
    AND DATA_TYPE    = 'varchar'
);
SET @sql := IF(@es_texto = 1,
  'UPDATE datarocket_contactos
      SET provincia_id = NULL
    WHERE provincia_id IS NOT NULL
      AND (TRIM(provincia_id) = '''' OR TRIM(provincia_id) = ''0''
           OR TRIM(provincia_id) NOT REGEXP ''^[0-9]+$'')',
  'DO 0'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @es_texto := (
  SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME   = 'datarocket_contactos'
    AND COLUMN_NAME  = 'localidad_id'
    AND DATA_TYPE    = 'varchar'
);
SET @sql := IF(@es_texto = 1,
  'UPDATE datarocket_contactos
      SET localidad_id = NULL
    WHERE localidad_id IS NOT NULL
      AND (TRIM(localidad_id) = '''' OR TRIM(localidad_id) = ''0''
           OR TRIM(localidad_id) NOT REGEXP ''^[0-9]+$'')',
  'DO 0'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;


-- ---------------------------------------------------------------------------
-- 3) Anular los huerfanos: IDs numericos sin fila en el catalogo.
--    Naturalmente idempotente — con la FK ya puesta no matchea ninguna fila.
-- ---------------------------------------------------------------------------

UPDATE datarocket_contactos c
   SET c.pais_id = NULL
 WHERE c.pais_id IS NOT NULL
   AND NOT EXISTS (SELECT 1 FROM paises p WHERE p.id = c.pais_id);

UPDATE datarocket_contactos c
   SET c.provincia_id = NULL
 WHERE c.provincia_id IS NOT NULL
   AND NOT EXISTS (SELECT 1 FROM provincias p WHERE p.id = c.provincia_id);

UPDATE datarocket_contactos c
   SET c.localidad_id = NULL
 WHERE c.localidad_id IS NOT NULL
   AND NOT EXISTS (SELECT 1 FROM localidades l WHERE l.id = c.localidad_id);


-- ---------------------------------------------------------------------------
-- 4) VARCHAR(255) -> INT, para que el tipo coincida con el `id` del catalogo
--    (InnoDB exige tipos compatibles en la FK).
-- ---------------------------------------------------------------------------

SET @es_texto := (
  SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME   = 'datarocket_contactos'
    AND COLUMN_NAME  = 'pais_id'
    AND DATA_TYPE    = 'varchar'
);
SET @sql := IF(@es_texto = 1,
  'ALTER TABLE datarocket_contactos MODIFY COLUMN `pais_id` INT NULL DEFAULT NULL',
  'DO 0'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @es_texto := (
  SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME   = 'datarocket_contactos'
    AND COLUMN_NAME  = 'provincia_id'
    AND DATA_TYPE    = 'varchar'
);
SET @sql := IF(@es_texto = 1,
  'ALTER TABLE datarocket_contactos MODIFY COLUMN `provincia_id` INT NULL DEFAULT NULL',
  'DO 0'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @es_texto := (
  SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME   = 'datarocket_contactos'
    AND COLUMN_NAME  = 'localidad_id'
    AND DATA_TYPE    = 'varchar'
);
SET @sql := IF(@es_texto = 1,
  'ALTER TABLE datarocket_contactos MODIFY COLUMN `localidad_id` INT NULL DEFAULT NULL',
  'DO 0'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;


-- ---------------------------------------------------------------------------
-- 5) Indices explicitos. InnoDB crearia uno implicito con la FK, pero con
--    nombre automatico; se declaran a mano para poder referenciarlos.
-- ---------------------------------------------------------------------------

SET @exists := (
  SELECT COUNT(*) FROM information_schema.STATISTICS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME   = 'datarocket_contactos'
    AND INDEX_NAME   = 'idx_dr_contactos_pais'
);
SET @sql := IF(@exists = 0,
  'ALTER TABLE datarocket_contactos ADD INDEX `idx_dr_contactos_pais` (`pais_id`)',
  'DO 0'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @exists := (
  SELECT COUNT(*) FROM information_schema.STATISTICS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME   = 'datarocket_contactos'
    AND INDEX_NAME   = 'idx_dr_contactos_provincia'
);
SET @sql := IF(@exists = 0,
  'ALTER TABLE datarocket_contactos ADD INDEX `idx_dr_contactos_provincia` (`provincia_id`)',
  'DO 0'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @exists := (
  SELECT COUNT(*) FROM information_schema.STATISTICS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME   = 'datarocket_contactos'
    AND INDEX_NAME   = 'idx_dr_contactos_localidad'
);
SET @sql := IF(@exists = 0,
  'ALTER TABLE datarocket_contactos ADD INDEX `idx_dr_contactos_localidad` (`localidad_id`)',
  'DO 0'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;


-- ---------------------------------------------------------------------------
-- 6) Las FKs.
-- ---------------------------------------------------------------------------

SET @exists := (
  SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS
  WHERE TABLE_SCHEMA    = DATABASE()
    AND TABLE_NAME      = 'datarocket_contactos'
    AND CONSTRAINT_NAME = 'fk_dr_contactos_pais'
);
SET @sql := IF(@exists = 0,
  'ALTER TABLE datarocket_contactos
     ADD CONSTRAINT `fk_dr_contactos_pais` FOREIGN KEY (`pais_id`)
         REFERENCES `paises` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT',
  'DO 0'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @exists := (
  SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS
  WHERE TABLE_SCHEMA    = DATABASE()
    AND TABLE_NAME      = 'datarocket_contactos'
    AND CONSTRAINT_NAME = 'fk_dr_contactos_provincia'
);
SET @sql := IF(@exists = 0,
  'ALTER TABLE datarocket_contactos
     ADD CONSTRAINT `fk_dr_contactos_provincia` FOREIGN KEY (`provincia_id`)
         REFERENCES `provincias` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT',
  'DO 0'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @exists := (
  SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS
  WHERE TABLE_SCHEMA    = DATABASE()
    AND TABLE_NAME      = 'datarocket_contactos'
    AND CONSTRAINT_NAME = 'fk_dr_contactos_localidad'
);
SET @sql := IF(@exists = 0,
  'ALTER TABLE datarocket_contactos
     ADD CONSTRAINT `fk_dr_contactos_localidad` FOREIGN KEY (`localidad_id`)
         REFERENCES `localidades` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT',
  'DO 0'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
