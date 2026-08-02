-- Agrega el permiso cloud `datacount.comprobantes.autorizar_manual`. Es un
-- verbo propio (no CRUD): habilita al operador a disparar la autorizacion
-- AFIP de UN comprobante puntual desde el menu contextual del listado de
-- Datacount > Comprobantes (accion "Autorizar"), via el endpoint
-- `POST cloud/api/datacount_comprobantes.php?id=X&action=autorizar`.
--
-- Distinto de:
--   - .editar     (modificar campos del comprobante, sin AFIP)
--   - .agregar    (alta del comprobante)
--   - .consultar  (listar/leer)
-- Tener permiso para editar NO implica autoridad para obtener un CAE
-- (compromiso fiscal irreversible), por eso este verbo va aparte.
--
-- El endpoint NO toca el flag del motor automatico
-- (`parametros.datacount.comprobantes.autorizar`). Es una accion humana
-- puntual; el circuit breaker queda como capacidad exclusiva del cron.
--
-- El permiso NO se auto-asigna al rol `datacount.comprobantes.operador`
-- de proposito: darle esta capacidad al operador es una decision de
-- negocio y se hace desde el ABM de roles cuando corresponda.
--
-- Reprograma tambien `desarrollador.permisos` con TODOS los permisos cloud
-- del env actual -- mismo patron que las migraciones anteriores de permisos.
--
-- Idempotente en los 2 pasos.

-- ============================================================================
-- Paso 1: alta del permiso (LEFT JOIN + IS NULL, sin pisar existentes).
-- ============================================================================

CREATE TEMPORARY TABLE tmp_permiso_dcc_aut_manual (
  slug   VARCHAR(100) NOT NULL,
  nombre VARCHAR(255) NOT NULL
) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci;

INSERT INTO tmp_permiso_dcc_aut_manual (slug, nombre) VALUES
('datacount.comprobantes.autorizar_manual', 'Datacount > Comprobantes > Autorizar manualmente (obtener CAE)');

INSERT INTO `permisos` (`slug`, `nombre`)
SELECT t.slug, t.nombre
FROM tmp_permiso_dcc_aut_manual t
LEFT JOIN `permisos` p ON p.slug = t.slug
WHERE p.id IS NULL;

DROP TEMPORARY TABLE tmp_permiso_dcc_aut_manual;

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
