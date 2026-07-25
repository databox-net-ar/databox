-- Agrega los 8 permisos cloud de los nuevos ABMs
-- `plataformas.aws.servidores.*` y `plataformas.aws.bases_datos.*`
-- (consultar / agregar / editar / eliminar cada uno). Habilitan las
-- nuevas tarjetas Servidores y Bases de Datos dentro del panel
-- Plataformas > AWS y sus endpoints `awsservidores.php` / `awsbases.php`.
--
-- Reprograma tambien `desarrollador.permisos` con TODOS los permisos
-- cloud del env actual, igual que las migraciones previas de permisos,
-- para que los slugs nuevos queden incluidos inmediatamente en el rol.
--
-- Idempotente en los 2 pasos.

-- ============================================================================
-- Paso 1: catalogo de permisos (LEFT JOIN + IS NULL como el seed original).
-- ============================================================================

CREATE TEMPORARY TABLE tmp_permisos_aws_servidores_y_bases (
  slug   VARCHAR(100) NOT NULL,
  nombre VARCHAR(255) NOT NULL
) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci;

INSERT INTO tmp_permisos_aws_servidores_y_bases (slug, nombre) VALUES
('plataformas.aws.servidores.consultar',   'Plataformas > AWS > Servidores > Consultar'),
('plataformas.aws.servidores.agregar',     'Plataformas > AWS > Servidores > Agregar'),
('plataformas.aws.servidores.editar',      'Plataformas > AWS > Servidores > Editar'),
('plataformas.aws.servidores.eliminar',    'Plataformas > AWS > Servidores > Eliminar'),
('plataformas.aws.bases_datos.consultar',  'Plataformas > AWS > Bases de Datos > Consultar'),
('plataformas.aws.bases_datos.agregar',    'Plataformas > AWS > Bases de Datos > Agregar'),
('plataformas.aws.bases_datos.editar',     'Plataformas > AWS > Bases de Datos > Editar'),
('plataformas.aws.bases_datos.eliminar',   'Plataformas > AWS > Bases de Datos > Eliminar');

INSERT INTO `permisos` (`slug`, `nombre`)
SELECT t.slug, t.nombre
FROM tmp_permisos_aws_servidores_y_bases t
LEFT JOIN `permisos` p ON p.slug = t.slug
WHERE p.id IS NULL;

DROP TEMPORARY TABLE tmp_permisos_aws_servidores_y_bases;

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
