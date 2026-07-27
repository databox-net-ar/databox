-- Agrega el permiso cloud `plataformas.aws.eventos.consultar` — habilita la
-- nueva tarjeta "Eventos" en el panel Plataformas > AWS y el endpoint de
-- lectura `cloud/api/awseventos.php`. Es solo lectura por diseno: los
-- eventos son inmutables y los produce AWS SES via SNS
-- (api/v4/aws/eventos.php).
--
-- Reprograma `desarrollador.permisos` con TODOS los permisos cloud del env
-- actual — mismo patron que las migraciones anteriores de permisos.
--
-- Idempotente.

CREATE TEMPORARY TABLE tmp_permiso_aws_eventos (
  slug   VARCHAR(100) NOT NULL,
  nombre VARCHAR(255) NOT NULL
) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci;

INSERT INTO tmp_permiso_aws_eventos (slug, nombre) VALUES
('plataformas.aws.eventos.consultar', 'Plataformas > AWS > Eventos > Consultar');

INSERT INTO `permisos` (`slug`, `nombre`)
SELECT t.slug, t.nombre
FROM tmp_permiso_aws_eventos t
LEFT JOIN `permisos` p ON p.slug = t.slug
WHERE p.id IS NULL;

DROP TEMPORARY TABLE tmp_permiso_aws_eventos;

-- Reasignar todos los permisos al rol desarrollador.
SET SESSION group_concat_max_len = 65535;
UPDATE `roles` r
CROSS JOIN (
    SELECT GROUP_CONCAT(id ORDER BY id) AS ids
    FROM `permisos`
    WHERE slug IS NOT NULL AND slug <> ''
) p
SET r.permisos = p.ids
WHERE r.slug = 'desarrollador';
