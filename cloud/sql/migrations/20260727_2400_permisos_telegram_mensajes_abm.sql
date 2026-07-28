-- Agrega los permisos faltantes del ABM de mensajes de Telegram:
-- `plataformas.telegram.mensajes.agregar` y
-- `plataformas.telegram.mensajes.eliminar`. El `.consultar` ya lo seedeo
-- la migracion 20260727_2320. El ABM cloud usa requirePermCrud() que mapea
-- POST -> .agregar, DELETE -> .eliminar (NO usar `.crear` -- no matchea).
--
-- No hay `.editar` a proposito: los mensajes de Telegram se envian de forma
-- sincrona en el POST (a diferencia de Evolution API que los encola). Una
-- vez enviados no se pueden modificar, solo consultar / clonar / eliminar.
--
-- Al final reprograma `desarrollador.permisos` con TODOS los permisos cloud
-- del env actual, igual que las migraciones previas de permisos.
--
-- Idempotente en los 2 pasos.

-- ============================================================================
-- Paso 1: catalogo de permisos (LEFT JOIN + IS NULL como el seed original).
-- ============================================================================

CREATE TEMPORARY TABLE tmp_permisos_telegram_msg (
  slug   VARCHAR(100) NOT NULL,
  nombre VARCHAR(255) NOT NULL
) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci;

INSERT INTO tmp_permisos_telegram_msg (slug, nombre) VALUES
('plataformas.telegram.mensajes.agregar',   'Plataformas > Telegram > Mensajes > Agregar'),
('plataformas.telegram.mensajes.eliminar',  'Plataformas > Telegram > Mensajes > Eliminar');

INSERT INTO `permisos` (`slug`, `nombre`)
SELECT t.slug, t.nombre
FROM tmp_permisos_telegram_msg t
LEFT JOIN `permisos` p ON p.slug = t.slug
WHERE p.id IS NULL;

DROP TEMPORARY TABLE tmp_permisos_telegram_msg;

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
