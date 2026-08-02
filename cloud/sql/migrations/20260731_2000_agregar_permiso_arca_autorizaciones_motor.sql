-- Agrega el permiso cloud `plataformas.arca.autorizaciones.motor`. Es un
-- verbo propio (no CRUD): habilita al operador a detener/iniciar
-- manualmente el motor de autorizacion automatica de comprobantes AFIP
-- desde el visor de Arca > Autorizaciones, via el endpoint
-- `cloud/api/arca_autorizaciones_motor.php`. No confundir con:
--   - .consultar (visor read-only del log de FECAESolicitar).
-- Ver el log no implica autoridad para parar la autorizacion.
--
-- El endpoint escribe sobre el parametro `datacount.comprobantes.autorizar`
-- (booleano: '1' = habilitado; otro = detenido) que consume el cron
-- `cloud/jobs/datacount_comprobantes_autorizar.php`.
--
-- Reprograma tambien `desarrollador.permisos` con TODOS los permisos cloud
-- del env actual -- mismo patron que las migraciones anteriores de permisos.
--
-- Idempotente en los 2 pasos.

-- ============================================================================
-- Paso 1: alta del permiso (LEFT JOIN + IS NULL, sin pisar existentes).
-- ============================================================================

CREATE TEMPORARY TABLE tmp_permiso_arca_aut_motor (
  slug   VARCHAR(100) NOT NULL,
  nombre VARCHAR(255) NOT NULL
) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci;

INSERT INTO tmp_permiso_arca_aut_motor (slug, nombre) VALUES
('plataformas.arca.autorizaciones.motor', 'Plataformas > Arca > Autorizaciones > Motor (iniciar / detener)');

INSERT INTO `permisos` (`slug`, `nombre`)
SELECT t.slug, t.nombre
FROM tmp_permiso_arca_aut_motor t
LEFT JOIN `permisos` p ON p.slug = t.slug
WHERE p.id IS NULL;

DROP TEMPORARY TABLE tmp_permiso_arca_aut_motor;

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
