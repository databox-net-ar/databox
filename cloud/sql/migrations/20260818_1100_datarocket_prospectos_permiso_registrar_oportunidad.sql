-- Agrega el permiso `datarocket.prospectos.registrar_oportunidad`.
--
-- Es un permiso de ACCION, no de CRUD: no habilita una operacion nueva sobre
-- `datarocket_prospectos`, habilita el atajo "Registrar oportunidad" del menu
-- contextual de una fila de Prospectos, que salta al ABM de Oportunidades y
-- abre el alta con ese prospecto ya vinculado.
--
-- Por que no alcanzaba con `datarocket.oportunidades.agregar`: ese permiso dice
-- "puede crear oportunidades" y ya lo consumia el POST del endpoint. Este dice
-- "puede arrancar ese alta DESDE la ficha de un prospecto", que es una decision
-- distinta — un perfil puede tener que cargar oportunidades sin que se le abra
-- esa puerta desde el listado de prospectos, o al reves.
--
-- Cuelga de `datarocket.prospectos.*` y no de `datarocket.oportunidades.*`
-- porque lo que gobierna es la visibilidad de un item DENTRO del modulo
-- Prospectos. El slug se lee como "en Prospectos, puede registrar oportunidad".
--
-- OJO: este permiso solo controla que el atajo se vea. El flujo que dispara
-- sigue pasando por los permisos de Oportunidades — la ruta destino exige
-- `datarocket.oportunidades.consultar` y el POST exige
-- `datarocket.oportunidades.agregar` (requirePermCrud mapea POST -> agregar).
-- Un rol que tenga el atajo pero no esos dos va a ver el error del backend, no
-- una pantalla rota: es a proposito, para que la combinacion se decida desde el
-- ABM de Roles y no quede cableada en el front.
--
-- Al final reprograma `desarrollador.permisos` con TODOS los permisos cloud del
-- env actual, igual que las migraciones previas de permisos, para que el slug
-- nuevo quede incluido inmediatamente en el rol.
--
-- Idempotente en los 2 pasos.

-- ============================================================================
-- Paso 1: catalogo de permisos.
-- ============================================================================

INSERT INTO `permisos` (`slug`, `nombre`)
SELECT 'datarocket.prospectos.registrar_oportunidad',
       'Datarocket > Prospectos > Registrar oportunidad'
  FROM DUAL
 WHERE NOT EXISTS (
   SELECT 1 FROM `permisos` WHERE `slug` = 'datarocket.prospectos.registrar_oportunidad'
 );

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
