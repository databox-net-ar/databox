-- Reacomoda los permisos de Datacount > Asientos a lo que el modulo hace de
-- verdad: no es un ABM de 4 verbos.
--
--   + `datacount.asientos.anular`   (nuevo)
--   - `datacount.asientos.editar`   (de baja)
--   - `datacount.asientos.eliminar` (de baja)
--
-- Contexto:
--
-- Por integridad contable un asiento no se modifica ni se borra: se revierte
-- con "Anular", que deja el original intacto y da de alta el contra-asiento
-- (mismas lineas, debe/haber invertidos). El panel nunca expuso Editar ni
-- Eliminar en el menu contextual, y `api/datacount_asientos.php` ahora responde
-- 405 a PUT y DELETE, asi que `.editar` y `.eliminar` no autorizan ningun verbo
-- alcanzable — se dan de baja del catalogo.
--
-- Del otro lado, Anular consumia `.agregar` (porque tecnicamente da de alta un
-- asiento). Eso impedia separar "puede cargar asientos" de "puede revertir
-- asientos ya cargados", que son decisiones distintas: ahora tiene slug propio.
--
-- OJO: `.editar` era ademas lo que gateaba los botones de adjuntos hasta la
-- migracion 20260825_1200, que les dio permisos propios
-- (`agregar_adjunto` / `quitar_adjunto`) y ya los backfilleo sobre los roles
-- que tenian `.editar`. Esta migracion tiene que correr DESPUES de aquella
-- (el orden por nombre de archivo lo garantiza) o esos roles perderian los
-- adjuntos sin recibir el reemplazo.
--
-- Pasos:
--   1. Alta de `datacount.asientos.anular` en el catalogo.
--   2. Backfill: todo rol cloud con `datacount.asientos.agregar` hereda
--      `.anular`, que es el permiso con el que venia funcionando el boton.
--   3. Baja de `.editar` y `.eliminar`: primero se sacan sus ids de las CSV
--      `roles.permisos` (para no dejar referencias colgadas) y despues se
--      borran las filas de `permisos`.
--   4. Reprograma `desarrollador.permisos` con TODOS los permisos cloud del
--      env actual, igual que las migraciones previas de permisos.
--
-- Idempotente en los 4 pasos.

-- ============================================================================
-- Paso 1: catalogo de permisos (NOT EXISTS como el resto de los seeds).
-- ============================================================================

INSERT INTO `permisos` (`slug`, `nombre`)
SELECT 'datacount.asientos.anular', 'Datacount > Asientos > Anular'
  FROM DUAL
 WHERE NOT EXISTS (
   SELECT 1 FROM `permisos` WHERE `slug` = 'datacount.asientos.anular'
 );

-- ============================================================================
-- Paso 2: backfill sobre los roles que ya podian dar de alta asientos.
--
-- Solo roles cloud (slug NOT NULL): los legacy guardan la lista como
-- "(111)(112)" y FIND_IN_SET no les aplica. El guard `FIND_IN_SET(nuevo) = 0`
-- hace la operacion repetible.
-- ============================================================================

UPDATE `roles` r
JOIN `permisos` pa ON pa.slug = 'datacount.asientos.agregar'
JOIN `permisos` pn ON pn.slug = 'datacount.asientos.anular'
SET r.permisos = CONCAT(r.permisos, ',', pn.id)
WHERE r.slug IS NOT NULL AND r.slug <> ''
  AND FIND_IN_SET(pa.id, r.permisos) > 0
  AND FIND_IN_SET(pn.id, r.permisos) = 0;

-- ============================================================================
-- Paso 3: baja de `.editar` y `.eliminar`.
--
-- Primero se quita el id de la CSV `roles.permisos` con el patron REPLACE +
-- separadores (mismo que 20260727_2330_corregir_permiso_telegram_bots_crear),
-- y recien despues se borra la fila del catalogo.
-- ============================================================================

UPDATE `roles` r
JOIN `permisos` p ON p.slug = 'datacount.asientos.editar'
SET r.permisos = TRIM(BOTH ',' FROM REPLACE(CONCAT(',', r.permisos, ','),
                                            CONCAT(',', p.id, ','), ','))
WHERE FIND_IN_SET(p.id, r.permisos) > 0;

UPDATE `roles` r
JOIN `permisos` p ON p.slug = 'datacount.asientos.eliminar'
SET r.permisos = TRIM(BOTH ',' FROM REPLACE(CONCAT(',', r.permisos, ','),
                                            CONCAT(',', p.id, ','), ','))
WHERE FIND_IN_SET(p.id, r.permisos) > 0;

DELETE FROM `permisos`
 WHERE slug IN ('datacount.asientos.editar', 'datacount.asientos.eliminar');

-- ============================================================================
-- Paso 4: `desarrollador` = todos los permisos cloud del env actual.
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
