-- Agrega el permiso cloud `plataformas.arca.certificados.invalidar_ta_cache`.
-- Es un verbo propio (no CRUD): habilita al operador a purgar desde el menu
-- contextual del ABM de Arca > Certificados los tickets WSAA cacheados en
-- `arca_ta_cache` para el certificado seleccionado, via el endpoint
-- `POST cloud/api/arcacertificados.php?id=X&action=invalidar_ta_cache`.
--
-- Distinto de:
--   - .editar     (modificar la llave/certificado en si).
--   - .agregar    (alta de un certificado nuevo).
--   - .eliminar   (baja del certificado).
-- Tener permiso para editar el certificado NO implica autoridad para forzar
-- una nueva negociacion contra el WSAA de AFIP, por eso el verbo va aparte.
--
-- Nota: puede borrar N filas (una por cada `service` AFIP -- wsfe, wsmtxca,
-- ws_sr_padron_a4, etc.), porque `arca_ta_cache` es UNIQUE por
-- (certificado_id, service).
--
-- Reprograma tambien `desarrollador.permisos` con TODOS los permisos cloud
-- del env actual -- mismo patron que las migraciones anteriores de permisos.
--
-- Idempotente en los 2 pasos.

-- ============================================================================
-- Paso 1: alta del permiso (LEFT JOIN + IS NULL, sin pisar existentes).
-- ============================================================================

CREATE TEMPORARY TABLE tmp_permiso_arca_cert_inv_ta (
  slug   VARCHAR(100) NOT NULL,
  nombre VARCHAR(255) NOT NULL
) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci;

INSERT INTO tmp_permiso_arca_cert_inv_ta (slug, nombre) VALUES
('plataformas.arca.certificados.invalidar_ta_cache', 'Plataformas > Arca > Certificados > Invalidar cache de TA (WSAA)');

INSERT INTO `permisos` (`slug`, `nombre`)
SELECT t.slug, t.nombre
FROM tmp_permiso_arca_cert_inv_ta t
LEFT JOIN `permisos` p ON p.slug = t.slug
WHERE p.id IS NULL;

DROP TEMPORARY TABLE tmp_permiso_arca_cert_inv_ta;

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
