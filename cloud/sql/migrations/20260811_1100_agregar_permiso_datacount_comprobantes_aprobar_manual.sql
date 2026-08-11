-- Agrega el permiso cloud `datacount.comprobantes.aprobar_manual`. Es un
-- verbo propio (no CRUD): habilita al operador a cerrar UN comprobante NO
-- fiscal desde el menu contextual del listado de Datacount > Comprobantes
-- (accion "Aprobar"), via el endpoint
-- `POST cloud/api/datacount_comprobantes.php?id=X&action=aprobar`.
--
-- Aprobar es el equivalente no-fiscal de Autorizar: asigna el proximo
-- correlativo del talonario y transiciona el comprobante al estado '5'
-- Aprobado (terminal en cuanto al numero — solo admite Anular como
-- transicion posterior). No hay llamada a AFIP.
--
-- Distinto de:
--   - .autorizar_manual (obtener CAE contra AFIP para talonarios fiscales)
--   - .editar           (modificar campos del comprobante)
--   - .agregar          (alta del comprobante)
--   - .consultar        (listar/leer)
-- Va aparte de .autorizar_manual porque son decisiones de negocio distintas:
-- un operador podria estar autorizado a cerrar remitos/presupuestos internos
-- sin tener autoridad para comprometer facturacion fiscal, o viceversa.
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

CREATE TEMPORARY TABLE tmp_permiso_dcc_apr_manual (
  slug   VARCHAR(100) NOT NULL,
  nombre VARCHAR(255) NOT NULL
) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci;

INSERT INTO tmp_permiso_dcc_apr_manual (slug, nombre) VALUES
('datacount.comprobantes.aprobar_manual', 'Datacount > Comprobantes > Aprobar manualmente (comprobantes no fiscales)');

INSERT INTO `permisos` (`slug`, `nombre`)
SELECT t.slug, t.nombre
FROM tmp_permiso_dcc_apr_manual t
LEFT JOIN `permisos` p ON p.slug = t.slug
WHERE p.id IS NULL;

DROP TEMPORARY TABLE tmp_permiso_dcc_apr_manual;

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
