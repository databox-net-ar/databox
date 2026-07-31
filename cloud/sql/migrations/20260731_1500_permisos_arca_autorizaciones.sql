-- Agrega el permiso del Visor de autorizaciones de Arca:
-- `plataformas.arca.autorizaciones.consultar`.
--
-- El visor es solo lectura (no hay alta/edicion/borrado desde la UI --
-- las filas las escribe el microservicio `/v4/arca/autorizar.php` al
-- procesar cada FECAESolicitar), asi que solo se seedea el permiso
-- consultar (no CRUD completo). Como el resto de las migraciones de
-- permisos, al final reprograma `desarrollador.permisos` con TODOS los
-- slugs cloud vigentes para que quede incluido automaticamente.
--
-- Idempotente.

CREATE TEMPORARY TABLE tmp_permisos_arca_aut (
  slug   VARCHAR(100) NOT NULL,
  nombre VARCHAR(255) NOT NULL
) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci;

INSERT INTO tmp_permisos_arca_aut (slug, nombre) VALUES
('plataformas.arca.autorizaciones.consultar', 'Plataformas > Arca > Autorizaciones > Consultar');

INSERT INTO `permisos` (`slug`, `nombre`)
SELECT t.slug, t.nombre
FROM tmp_permisos_arca_aut t
LEFT JOIN `permisos` p ON p.slug = t.slug
WHERE p.id IS NULL;

DROP TEMPORARY TABLE tmp_permisos_arca_aut;

-- Reprograma desarrollador con todos los permisos cloud vigentes.
SET SESSION group_concat_max_len = 65535;

UPDATE `roles` r
CROSS JOIN (
    SELECT GROUP_CONCAT(id ORDER BY id) AS ids
    FROM `permisos`
    WHERE slug IS NOT NULL AND slug <> ''
) p
SET r.permisos = p.ids
WHERE r.slug = 'desarrollador';
