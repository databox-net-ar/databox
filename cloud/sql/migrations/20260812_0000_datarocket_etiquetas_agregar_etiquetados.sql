-- Agrega la columna `etiquetados` (INT UNSIGNED NOT NULL DEFAULT 0) a
-- `datarocket_etiquetas`. Es un contador denormalizado con la cantidad de
-- contactos asociados a cada etiqueta via la tabla puente
-- `datarocket_contactos_etiquetas` — evita hacer LEFT JOIN + GROUP BY en
-- cada lectura del listado.
--
-- La columna no se auto-actualiza al asignar / quitar etiquetas: el ABM
-- expone un boton "Recalcular etiquetados" (POST ?action=recalcular en el
-- endpoint) que hace el UPDATE masivo cuando el usuario lo pide. Es una
-- decision deliberada — asumimos que el count no tiene que ser
-- perfectamente en tiempo real, y evitamos triggers / callbacks en cada
-- INSERT/DELETE de la junction.
--
-- Ambos pasos son idempotentes:
--   * ALTER TABLE con IF NOT EXISTS via information_schema (MariaDB/MySQL).
--   * UPDATE inicial recomputa el valor desde cero, asi que correr la
--     migracion dos veces produce el mismo resultado.

SET @db := DATABASE();

-- ============================================================================
-- Paso 1: agregar columna si no existe.
-- ============================================================================

SET @sql := (
  SELECT IF(COUNT(*) = 0,
    'ALTER TABLE `datarocket_etiquetas`
       ADD COLUMN `etiquetados` INT UNSIGNED NOT NULL DEFAULT 0 AFTER `descripcion`',
    'DO 0'
  )
  FROM information_schema.columns
  WHERE table_schema = @db
    AND table_name   = 'datarocket_etiquetas'
    AND column_name  = 'etiquetados'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- ============================================================================
-- Paso 2: seed inicial del contador desde la junction. LEFT JOIN + subquery
-- agrupada para pisar cero en etiquetas sin ningun contacto asignado.
-- ============================================================================

UPDATE `datarocket_etiquetas` e
  LEFT JOIN (
    SELECT etiqueta_id, COUNT(*) AS c
      FROM `datarocket_contactos_etiquetas`
     GROUP BY etiqueta_id
  ) g ON g.etiqueta_id = e.id
   SET e.`etiquetados` = COALESCE(g.c, 0);
