-- Quita la columna `activo` (y su indice) de `datarocket_etiquetas`. La
-- columna se introdujo junto con la tabla en la migracion 20260811_1200 y
-- se descarta por decision del ABM: no aporta valor propio del catalogo.
-- La distincion "usada / no usada" vive naturalmente en las tablas de union
-- que asocien las etiquetas con cada recurso destino (plantillas, contactos,
-- interacciones, etc.), no en el catalogo.
--
-- Patron `information_schema` + PREPARE/EXECUTE para funcionar tanto en
-- MySQL 8.0 (dev) como MariaDB 10.11 (prod). Compatible con ambos motores
-- e idempotente: si la columna / indice no existen, es no-op.

SET @db := DATABASE();

-- ============================================================================
-- Paso 1: DROP INDEX idx_datarocket_etiquetas_activo (si existe).
-- MySQL puede dropear la columna teniendo el indice, pero explicitar el
-- DROP INDEX evita depender de ese comportamiento entre motores.
-- ============================================================================

SET @sql := (
  SELECT IF(COUNT(*) > 0,
    'ALTER TABLE `datarocket_etiquetas` DROP INDEX `idx_datarocket_etiquetas_activo`',
    'DO 0'
  )
  FROM information_schema.statistics
  WHERE table_schema = @db
    AND table_name   = 'datarocket_etiquetas'
    AND index_name   = 'idx_datarocket_etiquetas_activo'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- ============================================================================
-- Paso 2: DROP COLUMN activo (si existe).
-- ============================================================================

SET @sql := (
  SELECT IF(COUNT(*) > 0,
    'ALTER TABLE `datarocket_etiquetas` DROP COLUMN `activo`',
    'DO 0'
  )
  FROM information_schema.columns
  WHERE table_schema = @db
    AND table_name   = 'datarocket_etiquetas'
    AND column_name  = 'activo'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
