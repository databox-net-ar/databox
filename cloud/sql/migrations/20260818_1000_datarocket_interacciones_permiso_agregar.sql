-- Agrega el permiso `datarocket.interacciones.agregar`.
--
-- La 20260727_1910 seedeo solo `consultar` y `eliminar` a proposito: en ese
-- momento las interacciones las escribian unicamente las APIs de envio
-- (aws_mensajes / evolution_mensajes) y el ABM del panel no exponia alta.
-- `editar` se sumo despues para el PUT que marca `respondida`.
--
-- Eso cambia con "Registrar interaccion" del menu contextual de Oportunidades:
-- el panel ahora da de alta interacciones manuales (notas, llamados, reuniones
-- — lo que no pasa por un canalizador), asi que el endpoint expone POST y
-- requirePermCrud('datarocket.interacciones') le exige el verbo `agregar`.
--
-- OJO con el verbo: tiene que ser `agregar` y no `crear`. requirePermCrud()
-- mapea POST -> 'agregar' (cloud/api/lib/auth_check.php); un slug `.crear` no
-- matchea y el POST responde 403 aunque el permiso este asignado.
--
-- Al final reprograma `desarrollador.permisos` con TODOS los permisos cloud del
-- env actual, igual que las migraciones previas de permisos, para que el slug
-- nuevo quede incluido inmediatamente en el rol.
--
-- Idempotente en los 2 pasos.

-- ============================================================================
-- Paso 1: catalogo de permisos (LEFT JOIN + IS NULL como el seed original).
-- ============================================================================

INSERT INTO `permisos` (`slug`, `nombre`)
SELECT 'datarocket.interacciones.agregar', 'Datarocket > Interacciones > Agregar'
  FROM DUAL
 WHERE NOT EXISTS (
   SELECT 1 FROM `permisos` WHERE `slug` = 'datarocket.interacciones.agregar'
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
