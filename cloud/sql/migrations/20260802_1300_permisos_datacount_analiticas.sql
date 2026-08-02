-- Agrega el permiso cloud `datacount.analiticas.consultar`. Habilita la nueva
-- tarjeta "Analiticas" dentro del panel Sistemas > Datacount y el endpoint
-- `cloud/api/datacount_analiticas.php` (agregaciones de solo lectura sobre
-- `datacount_comprobantes` para los distintos graficos por pestana).
--
-- Es un modulo de solo lectura (no CRUD): un unico verbo `.consultar`.
--
-- Reprograma tambien `desarrollador.permisos` con TODOS los permisos cloud
-- del env actual, igual que las migraciones previas de permisos, para que
-- el slug nuevo quede incluido inmediatamente en el rol.
--
-- Idempotente en los 2 pasos.

-- ============================================================================
-- Paso 1: alta del permiso (LEFT JOIN + IS NULL, sin pisar existentes).
-- ============================================================================

CREATE TEMPORARY TABLE tmp_permisos_datacount_analiticas (
  slug   VARCHAR(100) NOT NULL,
  nombre VARCHAR(255) NOT NULL
) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci;

INSERT INTO tmp_permisos_datacount_analiticas (slug, nombre) VALUES
('datacount.analiticas.consultar', 'Datacount > Analiticas > Consultar');

INSERT INTO `permisos` (`slug`, `nombre`)
SELECT t.slug, t.nombre
FROM tmp_permisos_datacount_analiticas t
LEFT JOIN `permisos` p ON p.slug = t.slug
WHERE p.id IS NULL;

DROP TEMPORARY TABLE tmp_permisos_datacount_analiticas;

-- ============================================================================
-- Paso 2: `desarrollador` = todos los permisos cloud del env actual.
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
