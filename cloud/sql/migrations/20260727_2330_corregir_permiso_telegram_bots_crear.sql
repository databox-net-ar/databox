-- Fix: la migracion 20260727_2320 seedeo `plataformas.telegram.bots.crear`,
-- pero el helper `requirePermCrud()` (cloud/api/lib/auth_check.php) mapea
-- POST -> `<slug>.agregar`, no `.crear`. Resultado: aparecia
-- "Permiso denegado: plataformas.telegram.bots.agregar" al intentar dar de
-- alta un bot desde el ABM aunque el usuario tuviera todos los permisos.
--
-- Este parche:
--   1. Renombra el slug/nombre del permiso de `.crear` a `.agregar` si el
--      viejo existe (UPDATE en lugar de DELETE+INSERT para preservar el id
--      y no invalidar los CSVs de `roles.permisos` que ya lo referencian).
--   2. Si por alguna razon `.agregar` ya existe (ambiente que aplico la
--      version corregida de 20260727_2320), no hace nada (el UPDATE del
--      paso 1 no matchea filas).
--   3. Al final reprograma `desarrollador.permisos` con TODOS los permisos
--      cloud del env actual, para que el rol quede consistente.
--
-- Idempotente en los 3 pasos.

-- ============================================================================
-- Paso 1: renombrar el slug legacy `.crear` a `.agregar` si existe, y solo
-- si `.agregar` no existe ya (evitar violacion de unicidad).
-- ============================================================================

UPDATE `permisos`
SET
    slug   = 'plataformas.telegram.bots.agregar',
    nombre = 'Plataformas > Telegram > Bots > Agregar'
WHERE slug = 'plataformas.telegram.bots.crear'
  AND NOT EXISTS (
      SELECT 1 FROM (SELECT id FROM `permisos` WHERE slug = 'plataformas.telegram.bots.agregar') AS t
  );

-- ============================================================================
-- Paso 2: si por algun motivo quedaron los dos (crear y agregar), eliminar
-- el `.crear` remanente. Antes lo quitamos de `roles.permisos` (CSV) para
-- no dejar referencias colgadas.
-- ============================================================================

-- Quitar el id del CSV `roles.permisos` (patron REPLACE con separadores).
UPDATE `roles` r
JOIN `permisos` p ON p.slug = 'plataformas.telegram.bots.crear'
SET r.permisos = TRIM(BOTH ',' FROM REPLACE(CONCAT(',', r.permisos, ','),
                                            CONCAT(',', p.id, ','), ','))
WHERE FIND_IN_SET(p.id, r.permisos) > 0;

DELETE FROM `permisos` WHERE slug = 'plataformas.telegram.bots.crear';

-- ============================================================================
-- Paso 3: `desarrollador` = todos los permisos cloud del env actual.
-- ============================================================================

SET SESSION group_concat_max_len = 65535;

UPDATE `roles` r
CROSS JOIN (
    SELECT GROUP_CONCAT(id ORDER BY id) AS ids
    FROM `permisos`
    WHERE slug IS NOT NULL AND slug <> ''
) p
SET r.permisos = p.ids
WHERE r.slug = 'desarrollador';
