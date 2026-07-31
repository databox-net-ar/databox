-- datacount_empresas: agregar `slug` (VARCHAR(40) NOT NULL UNIQUE) despues de
-- `nombre`, con backfill automatico desde `nombre`.
--
-- Motivo:
--   El microservicio publico `/v4/arca/*` identifica a la empresa facturante
--   por un slug estable (kebab-case) en vez de por el ID interno. Ejemplos:
--   "Wescom SRL" -> "wescom-srl", "Databox Nube" -> "databox-nube". Esto
--   evita filtrar IDs internos por la API publica y da a los consumidores
--   un identificador legible y estable.
--
--   El backfill inicial deriva el slug del nombre:
--     1. Normaliza acentos ('a'cegido -> "acegido") con REPLACE en cascada
--        (portable, no depende de collations especiales).
--     2. Colapsa cualquier corrida de caracteres NO alfanumericos a un `-`
--        via REGEXP_REPLACE (soportado en MySQL 8 y MariaDB 10.4+).
--     3. Recorta guiones de bordes.
--     4. Fuerza minusculas.
--     5. Si dos empresas colisionan al normalizar, se agrega `-<id>` como
--        desempate a partir de la segunda ocurrencia (la de menor id gana el
--        slug limpio).
--
-- Compatible con MySQL 8 (dev) y MariaDB 10.11 (prod): se usa el patron
-- information_schema + PREPARE/EXECUTE porque MySQL 8 no soporta la sintaxis
-- MariaDB `ADD COLUMN IF NOT EXISTS`.

-- ---------------------------------------------------------------------------
-- Paso 1: agregar la columna como NULL (para poder backfillear sin romper
-- constraints).
-- ---------------------------------------------------------------------------
SET @exists := (
  SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME   = 'datacount_empresas'
    AND COLUMN_NAME  = 'slug'
);
SET @sql := IF(@exists = 0,
  'ALTER TABLE datacount_empresas ADD COLUMN `slug` VARCHAR(40) NULL DEFAULT NULL AFTER `nombre`',
  'DO 0'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- ---------------------------------------------------------------------------
-- Paso 2: backfill base -- normalizar `nombre` a un slug candidato.
-- Solo actualiza filas con `slug IS NULL` para ser idempotente en re-corridas.
-- ---------------------------------------------------------------------------
UPDATE datacount_empresas
SET slug = LEFT(
  TRIM(BOTH '-' FROM
    LOWER(
      REGEXP_REPLACE(
        REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(
        REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(
        REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(
        REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(
        REPLACE(REPLACE(REPLACE(REPLACE(nombre,
          'á','a'),'é','e'),'í','i'),'ó','o'),'ú','u'),
          'à','a'),'è','e'),'ì','i'),'ò','o'),'ù','u'),
          'ä','a'),'ë','e'),'ï','i'),'ö','o'),'ü','u'),
          'Á','a'),'É','e'),'Í','i'),'Ó','o'),'Ú','u'),
          'ñ','n'),'Ñ','n'),'ç','c'),'Ç','c'),
        '[^a-zA-Z0-9]+', '-'
      )
    )
  ),
  40
)
WHERE slug IS NULL;

-- Fallback si el nombre queda vacio despues de normalizar (todo caracteres
-- no imprimibles) -> usar 'empresa-<id>' para no violar NOT NULL.
UPDATE datacount_empresas SET slug = CONCAT('empresa-', id) WHERE slug = '' OR slug IS NULL;

-- ---------------------------------------------------------------------------
-- Paso 3: resolver colisiones -- agregar `-<id>` a la segunda y siguientes
-- ocurrencias de cada slug repetido. La empresa con menor id conserva el
-- slug limpio. Se hace en un unico UPDATE con JOIN a subquery.
-- ---------------------------------------------------------------------------
UPDATE datacount_empresas e
JOIN (
  SELECT id, slug FROM (
    SELECT id, slug,
           ROW_NUMBER() OVER (PARTITION BY slug ORDER BY id ASC) AS rn
    FROM datacount_empresas
  ) t
  WHERE t.rn > 1
) dup ON dup.id = e.id
SET e.slug = LEFT(CONCAT(e.slug, '-', e.id), 40);

-- ---------------------------------------------------------------------------
-- Paso 4: bloquear la columna como NOT NULL. Si por algun motivo quedo NULL
-- alguna fila (no deberia), la conversion fallara -- eso es lo que queremos.
-- ---------------------------------------------------------------------------
SET @nullable := (
  SELECT IS_NULLABLE FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME   = 'datacount_empresas'
    AND COLUMN_NAME  = 'slug'
);
SET @sql := IF(@nullable = 'YES',
  'ALTER TABLE datacount_empresas MODIFY COLUMN `slug` VARCHAR(40) NOT NULL',
  'DO 0'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- ---------------------------------------------------------------------------
-- Paso 5: indice UNIQUE. Idempotente.
-- ---------------------------------------------------------------------------
SET @exists := (
  SELECT COUNT(*) FROM information_schema.STATISTICS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME   = 'datacount_empresas'
    AND INDEX_NAME   = 'uk_datacount_empresas_slug'
);
SET @sql := IF(@exists = 0,
  'ALTER TABLE datacount_empresas ADD UNIQUE INDEX `uk_datacount_empresas_slug` (`slug`)',
  'DO 0'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
