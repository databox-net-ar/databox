-- Agrega los 8 permisos cloud de los ABM `plataformas.aws.canales.*` y
-- `plataformas.aws.mensajes.*` (consultar / agregar / editar / eliminar
-- para cada uno). Habilitan las nuevas tarjetas "Canales" y "Mensajes"
-- dentro del panel Plataformas > AWS y sus endpoints
-- `awscanales.php` / `awsmensajes.php` (que leen/escriben sobre las
-- tablas `aws_canales` / `aws_mensajes`).
--
-- Reprograma tambien `desarrollador.permisos` con TODOS los permisos
-- cloud del env actual, igual que las migraciones anteriores, para
-- que los nuevos permisos queden incluidos inmediatamente en el rol.
--
-- Idempotente en los 2 pasos.

-- ============================================================================
-- Paso 1: catalogo de permisos (LEFT JOIN + IS NULL como el seed original).
-- ============================================================================

CREATE TEMPORARY TABLE tmp_permisos_aws_canmsg (
  slug   VARCHAR(100) NOT NULL,
  nombre VARCHAR(255) NOT NULL
) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci;

INSERT INTO tmp_permisos_aws_canmsg (slug, nombre) VALUES
('plataformas.aws.canales.consultar',  'Plataformas > AWS > Canales > Consultar'),
('plataformas.aws.canales.agregar',    'Plataformas > AWS > Canales > Agregar'),
('plataformas.aws.canales.editar',     'Plataformas > AWS > Canales > Editar'),
('plataformas.aws.canales.eliminar',   'Plataformas > AWS > Canales > Eliminar'),
('plataformas.aws.mensajes.consultar', 'Plataformas > AWS > Mensajes > Consultar'),
('plataformas.aws.mensajes.agregar',   'Plataformas > AWS > Mensajes > Agregar'),
('plataformas.aws.mensajes.editar',    'Plataformas > AWS > Mensajes > Editar'),
('plataformas.aws.mensajes.eliminar',  'Plataformas > AWS > Mensajes > Eliminar');

INSERT INTO `permisos` (`slug`, `nombre`)
SELECT t.slug, t.nombre
FROM tmp_permisos_aws_canmsg t
LEFT JOIN `permisos` p ON p.slug = t.slug
WHERE p.id IS NULL;

DROP TEMPORARY TABLE tmp_permisos_aws_canmsg;

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
