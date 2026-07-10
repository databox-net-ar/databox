-- Consolida la relación "cada empresa tiene su propio plan de cuentas" en
-- `datacount_cuentas`. La columna `empresa_id` ya existía en la tabla pero
-- estaba subutilizada: seguía existiendo un UNIQUE global sobre `codigo`
-- (impedía que el mismo código se repitiera entre empresas) y todas las
-- filas apuntaban a empresa_id = 1.
--
-- Esta migración:
--   1. Reemplaza el UNIQUE global de `codigo` por uno compuesto
--      `(empresa_id, codigo)` — el mismo código puede aparecer una vez
--      por empresa.
--   2. Duplica el plan actual (empresa_id = 1) a todas las demás empresas
--      registradas en `datacount_empresas` que aún no tengan cuentas,
--      preservando la jerarquía por `parent_id`. Saldos arrancan en 0
--      para las duplicadas (los asientos existentes siguen colgando de
--      las cuentas originales de empresa 1).
--
-- Orden importa: el DROP INDEX debe ocurrir antes de los INSERT
-- duplicados, si no viola el UNIQUE viejo.
--
-- Idempotente: usa el patrón `information_schema` + PREPARE/EXECUTE para
-- que los DROP/ADD INDEX se apliquen solo cuando hace falta (dev = MySQL 8,
-- prod = MariaDB 10 — evitamos IF NOT EXISTS).

-- --------------------------------------------------------------------
-- 1) Sacar el UNIQUE global de `codigo` (si existe) para que la
--    duplicación no viole la restricción.
-- --------------------------------------------------------------------
SET @idx_exists := (
  SELECT COUNT(*) FROM information_schema.statistics
  WHERE table_schema = DATABASE()
    AND table_name   = 'datacount_cuentas'
    AND index_name   = 'codigo'
);
SET @sql := IF(@idx_exists > 0,
  'ALTER TABLE `datacount_cuentas` DROP INDEX `codigo`',
  'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- --------------------------------------------------------------------
-- 2) Duplicar el plan de empresa 1 a toda otra empresa existente que
--    todavía no tenga cuentas. Primer paso: INSERT copiando todo,
--    manteniendo `parent_id` como está (apuntando temporalmente a las
--    cuentas de empresa 1 — se remapea en el paso 3).
-- --------------------------------------------------------------------
INSERT INTO `datacount_cuentas`
  (`empresa_id`, `codigo`, `nombre`, `tipo`, `parent_id`, `nivel`,
   `imputable`, `naturaleza`, `descripcion`, `activa`, `saldo`)
SELECT e.id, c.codigo, c.nombre, c.tipo, c.parent_id, c.nivel,
       c.imputable, c.naturaleza, c.descripcion, c.activa, 0
FROM `datacount_empresas` e
CROSS JOIN `datacount_cuentas` c
WHERE c.empresa_id = 1
  AND e.id <> 1
  AND NOT EXISTS (
        SELECT 1 FROM `datacount_cuentas` x WHERE x.empresa_id = e.id
      );

-- --------------------------------------------------------------------
-- 3) Remapear `parent_id` para las filas duplicadas: buscar la cuenta
--    equivalente (mismo `codigo`) dentro de la propia empresa y usar
--    ese id. Solo afecta filas cuyo parent aún apunta a otra empresa.
-- --------------------------------------------------------------------
UPDATE `datacount_cuentas` c
JOIN   `datacount_cuentas` parent         ON parent.id = c.parent_id
JOIN   `datacount_cuentas` correct_parent ON correct_parent.empresa_id = c.empresa_id
                                         AND correct_parent.codigo     = parent.codigo
SET    c.parent_id = correct_parent.id
WHERE  c.parent_id IS NOT NULL
  AND  c.empresa_id <> parent.empresa_id;

-- --------------------------------------------------------------------
-- 4) Añadir el UNIQUE compuesto `(empresa_id, codigo)`.
-- --------------------------------------------------------------------
SET @idx_exists := (
  SELECT COUNT(*) FROM information_schema.statistics
  WHERE table_schema = DATABASE()
    AND table_name   = 'datacount_cuentas'
    AND index_name   = 'uk_empresa_codigo'
);
SET @sql := IF(@idx_exists = 0,
  'ALTER TABLE `datacount_cuentas` ADD UNIQUE INDEX `uk_empresa_codigo` (`empresa_id`, `codigo`)',
  'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
