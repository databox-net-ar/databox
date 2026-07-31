-- Agrega los permisos del ABM de canales (cuentas USUARIO / MTProto) de
-- Telegram: `plataformas.telegram.canales.*` (CRUD completo). Espeja el
-- patron del ABM de `telegram_bots` (migracion 20260727_2320): verbos
-- consultar / agregar / editar / eliminar, alineados con requirePermCrud()
-- (POST -> .agregar, PUT -> .editar, DELETE -> .eliminar). NO usar `crear`,
-- no matchea el helper.
--
-- Al final reprograma `desarrollador.permisos` con TODOS los permisos cloud
-- del env actual, igual que las migraciones previas de permisos, para que
-- los slugs nuevos queden incluidos inmediatamente en el rol.
--
-- Idempotente en los 2 pasos.

-- ============================================================================
-- Paso 1: catalogo de permisos (LEFT JOIN + IS NULL como el seed original).
-- ============================================================================

CREATE TEMPORARY TABLE tmp_permisos_telegram_can (
  slug   VARCHAR(100) NOT NULL,
  nombre VARCHAR(255) NOT NULL
) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci;

INSERT INTO tmp_permisos_telegram_can (slug, nombre) VALUES
('plataformas.telegram.canales.consultar', 'Plataformas > Telegram > Canales > Consultar'),
('plataformas.telegram.canales.agregar',   'Plataformas > Telegram > Canales > Agregar'),
('plataformas.telegram.canales.editar',    'Plataformas > Telegram > Canales > Editar'),
('plataformas.telegram.canales.eliminar',  'Plataformas > Telegram > Canales > Eliminar');

INSERT INTO `permisos` (`slug`, `nombre`)
SELECT t.slug, t.nombre
FROM tmp_permisos_telegram_can t
LEFT JOIN `permisos` p ON p.slug = t.slug
WHERE p.id IS NULL;

DROP TEMPORARY TABLE tmp_permisos_telegram_can;

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
