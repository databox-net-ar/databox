-- Datacount > Bancos: mover Chequeras y Cheques bajo el modulo Bancos.
--
-- Chequeras y Cheques nacieron como submodulos sueltos de Datacount, con
-- tarjeta propia en el landing y slugs `datacount.chequeras.*` /
-- `datacount.cheques.*`. Pasan a ser dos de las cuatro sub-vistas de Bancos
-- (Cuentas, Movimientos, Chequeras, Cheques), asi que los slugs acompanan:
--
--   datacount.chequeras.<verbo>  ->  datacount.bancos.chequeras.<verbo>
--   datacount.cheques.<verbo>    ->  datacount.bancos.cheques.<verbo>
--
-- NO ES COSMETICO
-- ---------------
-- El landing `/datacount_bancos` se habilita con `prefix: 'datacount.bancos.'`
-- (ROUTE_PERMS en cloud/assets/js/app.js): un usuario que solo tuviera
-- `datacount.chequeras.consultar` no podria entrar a la portada del modulo y
-- por lo tanto no llegaria a sus propias chequeras. Sin este renombre la
-- reubicacion deja gente afuera.
--
-- El UPDATE preserva el `id` de cada permiso, que es lo que importa: la
-- asignacion a roles vive en `roles.permisos` como lista de ids separada por
-- comas. Al no reasignar ids, todo rol que ya tuviera estos permisos los
-- conserva sin tocar una sola fila de `roles`. Por eso es un UPDATE y no un
-- DELETE + INSERT.
--
-- Idempotente en los 3 pasos. Compatible MySQL 8 (dev) + MariaDB 10.11 (prod):
-- sin `ADD COLUMN IF NOT EXISTS` de MariaDB, sin funciones almacenadas.

-- ============================================================================
-- Paso 1: renombrar los slugs y sus nombres visibles.
-- ============================================================================
--
-- El WHERE con NOT EXISTS deja el paso repetible: si el slug destino ya existe
-- (porque la migracion se corrio antes, o porque alguien lo dio de alta desde
-- el ABM de Permisos), el UPDATE no hace nada en vez de morir contra el UNIQUE
-- de `permisos.slug`. La derivada `x` es obligatoria: MySQL no deja
-- subconsultar la misma tabla que se esta actualizando.

UPDATE `permisos` p
   SET p.`slug`   = REPLACE(p.`slug`,   'datacount.chequeras.', 'datacount.bancos.chequeras.'),
       p.`nombre` = REPLACE(p.`nombre`, 'Datacount > Chequeras', 'Datacount > Bancos > Chequeras')
 WHERE p.`slug` LIKE 'datacount.chequeras.%'
   AND NOT EXISTS (
     SELECT 1 FROM (
       SELECT `slug` FROM `permisos` WHERE `slug` LIKE 'datacount.bancos.chequeras.%'
     ) x
     WHERE x.`slug` = REPLACE(p.`slug`, 'datacount.chequeras.', 'datacount.bancos.chequeras.')
   );

UPDATE `permisos` p
   SET p.`slug`   = REPLACE(p.`slug`,   'datacount.cheques.', 'datacount.bancos.cheques.'),
       p.`nombre` = REPLACE(p.`nombre`, 'Datacount > Cheques', 'Datacount > Bancos > Cheques')
 WHERE p.`slug` LIKE 'datacount.cheques.%'
   AND NOT EXISTS (
     SELECT 1 FROM (
       SELECT `slug` FROM `permisos` WHERE `slug` LIKE 'datacount.bancos.cheques.%'
     ) x
     WHERE x.`slug` = REPLACE(p.`slug`, 'datacount.cheques.', 'datacount.bancos.cheques.')
   );

-- ============================================================================
-- Paso 2: alta de los slugs nuevos por si este entorno nunca corrio las
--         migraciones 1100 / 1200 que crearon los viejos.
-- ============================================================================
--
-- OJO con el verbo: `agregar`, NO `crear`. requirePermCrud() mapea POST ->
-- 'agregar' (cloud/api/lib/auth_check.php); un slug `.crear` no matchea y el
-- POST devuelve 403 aunque el permiso este asignado al rol.

INSERT INTO `permisos` (`slug`, `nombre`)
SELECT * FROM (
  SELECT 'datacount.bancos.chequeras.consultar' AS s, 'Datacount > Bancos > Chequeras > Consultar' AS n UNION ALL
  SELECT 'datacount.bancos.chequeras.agregar',        'Datacount > Bancos > Chequeras > Agregar'          UNION ALL
  SELECT 'datacount.bancos.chequeras.editar',         'Datacount > Bancos > Chequeras > Editar'           UNION ALL
  SELECT 'datacount.bancos.chequeras.eliminar',       'Datacount > Bancos > Chequeras > Eliminar'         UNION ALL
  SELECT 'datacount.bancos.cheques.consultar',        'Datacount > Bancos > Cheques > Consultar'          UNION ALL
  SELECT 'datacount.bancos.cheques.agregar',          'Datacount > Bancos > Cheques > Agregar'            UNION ALL
  SELECT 'datacount.bancos.cheques.editar',           'Datacount > Bancos > Cheques > Editar'             UNION ALL
  SELECT 'datacount.bancos.cheques.eliminar',         'Datacount > Bancos > Cheques > Eliminar'
) src
WHERE NOT EXISTS (
  SELECT 1 FROM `permisos` p WHERE p.`slug` = src.s
);

-- ============================================================================
-- Paso 3: `desarrollador` = todos los permisos cloud del env actual.
-- ============================================================================
--
-- El filtro `slug IS NOT NULL AND slug <> ''` excluye los permisos del sistema
-- legacy, que comparten tabla.

SET SESSION group_concat_max_len = 65535;

UPDATE `roles` r
CROSS JOIN (
    SELECT GROUP_CONCAT(id ORDER BY id) AS ids
    FROM `permisos`
    WHERE slug IS NOT NULL AND slug <> ''
) p
SET r.permisos = p.ids
WHERE r.slug = 'desarrollador';
