-- datarocket_embudos: agregar `proyecto_id` OBLIGATORIO.
--
-- Decision de producto: cada embudo pertenece a UN proyecto (regla dura, la
-- UI marca el campo como required y el backend lo valida en POST/PUT). No
-- hay embudos compartidos entre proyectos — si dos proyectos necesitan el
-- mismo pipeline se crean dos embudos con la misma estructura de etapas.
--
-- Como el embudo default 'Captacion general' de la migracion 20260812_0200
-- fue creado sin proyecto, y los 1349 prospectos legacy repartidos entre 4
-- proyectos (Vigicom=102, Vigia=103, Reactor=104, Causam=109) apuntaban
-- todos al mismo embudo #1, esta migracion:
--
--   1) Agrega la columna `proyecto_id INT NULL` a `datarocket_embudos`.
--   2) Renombra el embudo #1 'Captacion general' -> proyecto principal de
--      Databox (Vigicom, id=102), que tenia 1194/1349 prospectos. Le setea
--      proyecto_id=102.
--   3) Crea un embudo nuevo para cada proyecto adicional que aparezca en
--      los prospectos legacy (Vigia, Reactor, Causam...), con las mismas 6
--      etapas default (color/orden/tipo/probabilidad de la migracion 0300).
--   4) Reasigna cada prospecto a la etapa homonima de su embudo (segun
--      `datarocket_prospectos.proyecto_id`). El JOIN empareja por nombre
--      de etapa, asi que un prospecto en "Ganado" del embudo #1 termina en
--      "Ganado" del embudo de su proyecto.
--   5) Reemplaza el UNIQUE `nombre` por UNIQUE (`proyecto_id`, `nombre`) —
--      permite dos embudos con el mismo nombre en proyectos distintos.
--   6) Marca `proyecto_id` como NOT NULL y agrega FK a `proyectos(id)`.
--
-- Los prospectos con `proyecto_id IS NULL` quedan apuntando al embudo #1
-- (renombrado a Vigicom). No es lo ideal pero es 0 filas en dev y no tiene
-- sentido inventar un embudo "sin proyecto" cuando la regla es obligatoria.
--
-- Idempotente en los 6 pasos. Compatible MySQL 8 (dev) + MariaDB 10.11 (prod).

-- ============================================================================
-- Paso 1: agregar la columna `proyecto_id` (nullable inicialmente).
-- ============================================================================

SET @exists := (
  SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME   = 'datarocket_embudos'
    AND COLUMN_NAME  = 'proyecto_id'
);
SET @sql := IF(@exists = 0,
  'ALTER TABLE datarocket_embudos ADD COLUMN `proyecto_id` INT NULL DEFAULT NULL AFTER `id`',
  'DO 0'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- ============================================================================
-- Paso 2: renombrar el embudo default y asignarle proyecto principal.
-- ============================================================================

-- Determinar el proyecto principal dinamicamente: el que mas prospectos tiene.
-- Fallback: primer proyecto por id si no hay prospectos.
SET @proy_principal := (
  SELECT proyecto_id
    FROM datarocket_prospectos
   WHERE proyecto_id IS NOT NULL
GROUP BY proyecto_id
ORDER BY COUNT(*) DESC
   LIMIT 1
);
SET @proy_principal := COALESCE(@proy_principal, (SELECT MIN(id) FROM proyectos));

SET @proy_principal_nombre := COALESCE(
  (SELECT nombre FROM proyectos WHERE id = @proy_principal),
  'Principal'
);

-- Solo tocamos el embudo default si sigue con proyecto_id NULL Y su nombre
-- sigue siendo 'Captacion general' (idempotencia: no pisamos edits manuales).
UPDATE datarocket_embudos
   SET proyecto_id = @proy_principal,
       nombre      = @proy_principal_nombre,
       descripcion = CONCAT('Embudo de captacion / ventas de ', @proy_principal_nombre, '.')
 WHERE nombre      = 'Captacion general'
   AND proyecto_id IS NULL;

-- ============================================================================
-- Paso 3: crear embudos faltantes (uno por proyecto usado por los prospectos).
-- ============================================================================

INSERT INTO datarocket_embudos (proyecto_id, nombre, descripcion, color, orden, activo)
SELECT DISTINCT
       pr.proyecto_id,
       proyectos.nombre,
       CONCAT('Embudo de captacion / ventas de ', proyectos.nombre, '.'),
       '#3b82f6',
       0,
       1
  FROM datarocket_prospectos pr
  JOIN proyectos ON proyectos.id = pr.proyecto_id
 WHERE pr.proyecto_id IS NOT NULL
   AND NOT EXISTS (
        SELECT 1
          FROM datarocket_embudos e
         WHERE e.proyecto_id = pr.proyecto_id
       );

-- ============================================================================
-- Paso 4: para cada embudo sin etapas, copiar las 6 etapas default del
-- embudo semilla (el que tenga mas etapas, tipicamente el original #1).
-- ============================================================================

-- Detectar el embudo semilla (el que ya tiene las 6 etapas cargadas).
SET @embudo_semilla := (
  SELECT embudo_id
    FROM datarocket_etapas
GROUP BY embudo_id
ORDER BY COUNT(*) DESC
   LIMIT 1
);

INSERT INTO datarocket_etapas (embudo_id, nombre, orden, color, tipo, probabilidad)
SELECT b.id, s.nombre, s.orden, s.color, s.tipo, s.probabilidad
  FROM datarocket_embudos b
  JOIN datarocket_etapas s ON s.embudo_id = @embudo_semilla
 WHERE NOT EXISTS (
        SELECT 1 FROM datarocket_etapas et WHERE et.embudo_id = b.id
       );

-- ============================================================================
-- Paso 5: reasignar prospectos a la etapa homonima de su embudo (por proyecto_id).
-- ============================================================================

UPDATE datarocket_prospectos p
  JOIN datarocket_etapas   e_old ON e_old.id = p.etapa_id
  JOIN datarocket_embudos  b_new ON b_new.proyecto_id = p.proyecto_id
  JOIN datarocket_etapas   e_new ON e_new.embudo_id = b_new.id
                                 AND e_new.nombre  = e_old.nombre
   SET p.embudo_id = b_new.id,
       p.etapa_id  = e_new.id
 WHERE p.proyecto_id IS NOT NULL
   AND (p.embudo_id IS NULL OR p.embudo_id <> b_new.id);

-- ============================================================================
-- Paso 6a: rehacer indices UNIQUE — de UNIQUE(nombre) a UNIQUE(proyecto_id, nombre).
-- ============================================================================

SET @exists := (
  SELECT COUNT(*) FROM information_schema.STATISTICS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME   = 'datarocket_embudos'
    AND INDEX_NAME   = 'uq_datarocket_embudos_nombre'
);
SET @sql := IF(@exists > 0,
  'ALTER TABLE datarocket_embudos DROP INDEX `uq_datarocket_embudos_nombre`',
  'DO 0'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @exists := (
  SELECT COUNT(*) FROM information_schema.STATISTICS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME   = 'datarocket_embudos'
    AND INDEX_NAME   = 'uq_datarocket_embudos_proyecto_nombre'
);
SET @sql := IF(@exists = 0,
  'ALTER TABLE datarocket_embudos ADD UNIQUE INDEX `uq_datarocket_embudos_proyecto_nombre` (`proyecto_id`, `nombre`)',
  'DO 0'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- Indice simple para el filtro por proyecto.
SET @exists := (
  SELECT COUNT(*) FROM information_schema.STATISTICS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME   = 'datarocket_embudos'
    AND INDEX_NAME   = 'idx_datarocket_embudos_proyecto'
);
SET @sql := IF(@exists = 0,
  'ALTER TABLE datarocket_embudos ADD INDEX `idx_datarocket_embudos_proyecto` (`proyecto_id`)',
  'DO 0'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- ============================================================================
-- Paso 6b: NOT NULL + FK a `proyectos`.
-- ============================================================================

-- Marcar NOT NULL (idempotente: si ya es NOT NULL, el ALTER es un no-op).
SET @is_nullable := (
  SELECT IS_NULLABLE FROM information_schema.COLUMNS
   WHERE TABLE_SCHEMA = DATABASE()
     AND TABLE_NAME   = 'datarocket_embudos'
     AND COLUMN_NAME  = 'proyecto_id'
);
SET @sql := IF(@is_nullable = 'YES',
  'ALTER TABLE datarocket_embudos MODIFY COLUMN `proyecto_id` INT NOT NULL',
  'DO 0'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- FK a proyectos(id). ON DELETE RESTRICT — no borrar un proyecto que tiene
-- embudos vinculados.
SET @exists := (
  SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS
  WHERE TABLE_SCHEMA    = DATABASE()
    AND TABLE_NAME      = 'datarocket_embudos'
    AND CONSTRAINT_NAME = 'fk_datarocket_embudos_proyecto'
);
SET @sql := IF(@exists = 0,
  'ALTER TABLE datarocket_embudos
    ADD CONSTRAINT `fk_datarocket_embudos_proyecto` FOREIGN KEY (`proyecto_id`)
        REFERENCES `proyectos` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT',
  'DO 0'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
