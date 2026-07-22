-- Agrega los 8 permisos cloud de los ABM `plataformas.aws.plantillas.*` y
-- `plataformas.aws.contactos.*` (consultar / agregar / editar / eliminar
-- para cada uno). Habilitan las nuevas tarjetas "AWS Plantillas" y
-- "AWS Contactos" dentro del panel Plataformas > AWS y sus endpoints
-- `awsplantillas.php` / `awscontactos.php` (que leen/escriben sobre las
-- tablas `aws_plantillas` / `aws_contactos`).
--
-- Reprograma tambien `desarrollador.permisos` con TODOS los permisos
-- cloud del env actual, igual que las migraciones anteriores, para
-- que los nuevos permisos queden incluidos inmediatamente en el rol.
--
-- Idempotente en los 2 pasos.

-- ============================================================================
-- Paso 1: catalogo de permisos (LEFT JOIN + IS NULL como el seed original).
-- ============================================================================

CREATE TEMPORARY TABLE tmp_permisos_aws_plctos (
  slug   VARCHAR(100) NOT NULL,
  nombre VARCHAR(255) NOT NULL
) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci;

INSERT INTO tmp_permisos_aws_plctos (slug, nombre) VALUES
('plataformas.aws.plantillas.consultar', 'Plataformas > AWS > Plantillas > Consultar'),
('plataformas.aws.plantillas.agregar',   'Plataformas > AWS > Plantillas > Agregar'),
('plataformas.aws.plantillas.editar',    'Plataformas > AWS > Plantillas > Editar'),
('plataformas.aws.plantillas.eliminar',  'Plataformas > AWS > Plantillas > Eliminar'),
('plataformas.aws.contactos.consultar',  'Plataformas > AWS > Contactos > Consultar'),
('plataformas.aws.contactos.agregar',    'Plataformas > AWS > Contactos > Agregar'),
('plataformas.aws.contactos.editar',     'Plataformas > AWS > Contactos > Editar'),
('plataformas.aws.contactos.eliminar',   'Plataformas > AWS > Contactos > Eliminar');

INSERT INTO `permisos` (`slug`, `nombre`)
SELECT t.slug, t.nombre
FROM tmp_permisos_aws_plctos t
LEFT JOIN `permisos` p ON p.slug = t.slug
WHERE p.id IS NULL;

DROP TEMPORARY TABLE tmp_permisos_aws_plctos;

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
