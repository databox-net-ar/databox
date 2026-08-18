-- Elimina el modulo Datasale de la base: catalogo, permisos, rol y las tres
-- tablas. Datarocket lo reemplazo por completo — Contactos guarda la identidad,
-- Oportunidades el negocio e Interacciones el historial — y el ABM legacy
-- /prospectos era la ultima razon para sostenerlas.
--
-- PRE-REQUISITO: las migraciones 20260817_2400 (carga los prospectos legacy
-- contactables que faltaban) y 20260817_2500 (carga los incontactables y el
-- log de comunicaciones) tienen que estar aplicadas. Esta migracion DESTRUYE
-- los datos; si aquellas no corrieron, se pierde lo que no se migro.
--
-- ORDEN DE DESPLIEGUE: junto con el codigo que deja de leer el prefijo viejo
-- (`cloud/api/datarocket_oportunidades.php`). El paso 1 renombra el catalogo
-- que ese endpoint consulta: aplicar la migracion sin el codigo deja los
-- combos sentido / origen / estado del ABM de Oportunidades en blanco.
--
-- Idempotente: UPDATE/DELETE con WHERE y DROP TABLE IF EXISTS. Correr dos
-- veces no falla.
-- Compatible MySQL 8 (dev) + MariaDB 10.11 (prod).


-- ===========================================================================
-- Paso 1: el catalogo `estados` pasa a ser de Datarocket.
--
-- `datasale_prospecto_{sentido,origen,estado}` los LEE hoy el ABM de
-- Oportunidades (constante DR_OPO_CAMPO_PREFIX). Conservaban el prefijo
-- heredado solo porque los compartia el ABM legacy; al irse Datasale dejan de
-- ser compartidos y pasan al prefijo propio, que ya usaba `moneda`. Con esto
-- el endpoint queda con UN solo prefijo y desaparece la deuda que arrastraba
-- desde el rename (20260817_2300).
-- ===========================================================================
UPDATE `estados` SET `campo` = 'datarocket_oportunidad_sentido'
 WHERE `campo` = 'datasale_prospecto_sentido';

UPDATE `estados` SET `campo` = 'datarocket_oportunidad_origen'
 WHERE `campo` = 'datasale_prospecto_origen';

UPDATE `estados` SET `campo` = 'datarocket_oportunidad_estado'
 WHERE `campo` = 'datasale_prospecto_estado';


-- ===========================================================================
-- Paso 2: los dos campos del catalogo que quedan huerfanos.
--   `tipo`   — la columna se dropeo en la 20260817_1800. La 1800 dejo estas
--              filas a proposito porque el ABM legacy todavia las usaba; ese
--              motivo desaparece aca.
--   `interes` — no existe como columna en ninguna tabla Datarocket y ningun
--              endpoint la consulta (verificado por grep sobre cloud/).
-- ===========================================================================
DELETE FROM `estados`
 WHERE `campo` IN ('datasale_prospecto_tipo', 'datasale_prospecto_interes');


-- ===========================================================================
-- Paso 3: el rol dedicado al ABM legacy.
-- Se borra por `slug`, no por id: los ids no son estables entre entornos.
-- Los roles legacy (slug NULL, permisos en formato "(1001)(1010)") NO se
-- tocan — pertenecen a las UIs legacy del grupo, no al panel cloud. Entre
-- ellos queda "Gestion de prospectos" (id 110 en dev), que es de esa familia.
-- ===========================================================================
DELETE FROM `roles` WHERE `slug` = 'datasale.prospectos.operador';


-- ===========================================================================
-- Paso 4: los 4 permisos del ABM. Tienen `slug`, o sea que son cloud; los
-- permisos legacy van con slug NULL y no entran en este DELETE.
-- ===========================================================================
DELETE FROM `permisos` WHERE `slug` LIKE 'datasale.%';


-- ===========================================================================
-- Paso 5: sacar los ids borrados de los CSV de `roles.permisos`.
--
-- `roles.permisos` guarda un CSV de ids (no de slugs), asi que borrar filas de
-- `permisos` deja ids colgados. Se reconstruye cada CSV cloud quedandose solo
-- con los ids que todavia existen — vale para estos 4 y para cualquier resto
-- de limpiezas anteriores.
--
-- Va por tabla temporal porque MySQL no admite un UPDATE con subconsulta sobre
-- la propia tabla que actualiza (error 1093).
--
-- `group_concat_max_len` al maximo: el rol `desarrollador` concatena los ~300
-- permisos del panel y con el default de 1024 bytes el CSV saldria truncado.
-- ===========================================================================
SET SESSION group_concat_max_len = 65535;

DROP TEMPORARY TABLE IF EXISTS `tmp_roles_permisos`;
CREATE TEMPORARY TABLE `tmp_roles_permisos` (
  `id`  INT NOT NULL PRIMARY KEY,
  `ids` MEDIUMTEXT NULL
) ENGINE = InnoDB CHARACTER SET = utf8mb4 COLLATE = utf8mb4_general_ci;

INSERT INTO `tmp_roles_permisos` (`id`, `ids`)
SELECT `r`.`id`, COALESCE(GROUP_CONCAT(`p`.`id` ORDER BY `p`.`id`), '')
  FROM `roles` `r`
  LEFT JOIN `permisos` `p` ON FIND_IN_SET(`p`.`id`, `r`.`permisos`)
 WHERE `r`.`slug` IS NOT NULL AND `r`.`slug` <> ''
 GROUP BY `r`.`id`;

UPDATE `roles` `r`
  JOIN `tmp_roles_permisos` `t` ON `t`.`id` = `r`.`id`
   SET `r`.`permisos` = `t`.`ids`;

DROP TEMPORARY TABLE IF EXISTS `tmp_roles_permisos`;


-- ===========================================================================
-- Paso 6: las tablas. Ninguna tiene FK entrante ni saliente (las tres son
-- islas del legacy), asi que el orden no importa; van de la dependiente a la
-- principal igual, por prolijidad.
--
--   datasaleprospectoscomunicaciones  25 filas — migradas por la 2500.
--   datasaleprospectos              1359 filas — migradas por la 2400 + 2500.
--   datasalecarteras                   4 filas — mapeo usuario->nombre sin
--                                      ninguna referencia en el codigo, muerto
--                                      desde antes.
-- ===========================================================================
DROP TABLE IF EXISTS `datasaleprospectoscomunicaciones`;
DROP TABLE IF EXISTS `datasaleprospectos`;
DROP TABLE IF EXISTS `datasalecarteras`;
