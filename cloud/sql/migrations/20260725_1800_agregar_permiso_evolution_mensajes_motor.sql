-- Agrega el permiso cloud `plataformas.evolution.mensajes.motor`. Es un verbo
-- propio (no CRUD): habilita al operador a detener/iniciar manualmente el
-- motor de envio de evolution_mensajes desde el listado, via el endpoint
-- `cloud/api/evolutionmensajes_motor.php`. No confundir con:
--   - .consultar / .agregar / .editar / .eliminar (CRUD del ABM)
-- Un usuario puede encolar mensajes sin tener autoridad para parar el motor
-- y viceversa.
--
-- Mirror estructural de la migracion
-- 20260725_1700_agregar_permiso_aws_mensajes_motor.sql para la cola AWS.
--
-- Reprograma tambien `desarrollador.permisos` con TODOS los permisos cloud
-- del env actual — mismo patron que las migraciones anteriores de permisos,
-- para que el permiso nuevo quede incluido inmediatamente en el rol.
--
-- Idempotente en los 2 pasos.

-- ============================================================================
-- Paso 1: alta del permiso (LEFT JOIN + IS NULL, sin pisar existentes).
-- ============================================================================

CREATE TEMPORARY TABLE tmp_permiso_evo_msg_motor (
  slug   VARCHAR(100) NOT NULL,
  nombre VARCHAR(255) NOT NULL
) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci;

INSERT INTO tmp_permiso_evo_msg_motor (slug, nombre) VALUES
('plataformas.evolution.mensajes.motor', 'Plataformas > Evolution API > Mensajes > Motor (iniciar / detener)');

INSERT INTO `permisos` (`slug`, `nombre`)
SELECT t.slug, t.nombre
FROM tmp_permiso_evo_msg_motor t
LEFT JOIN `permisos` p ON p.slug = t.slug
WHERE p.id IS NULL;

DROP TEMPORARY TABLE tmp_permiso_evo_msg_motor;

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
