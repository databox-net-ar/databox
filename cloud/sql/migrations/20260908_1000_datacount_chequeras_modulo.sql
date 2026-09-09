-- Datacount > Chequeras: alta del submodulo.
--
-- QUE ES UNA CHEQUERA
-- -------------------
-- El talonario de cheques que el banco entrega contra una cuenta corriente.
-- Una fila = una chequera fisica: a que cuenta pertenece (nombre + numero),
-- de que banco es (`banco_id`) y si los cheques que trae son COMUNES (se
-- cobran a la vista, desde la fecha de emision) o DIFERIDOS (llevan fecha de
-- pago futura y no se pueden presentar antes). Esa distincion no es cosmetica:
-- un cheque comun es caja de hoy y uno diferido es una cuenta a cobrar/pagar
-- con vencimiento, y un mismo banco entrega chequeras distintas para cada uno.
-- Por eso el tipo va en la chequera y no en cada cheque.
--
-- POR QUE `numero_cuenta` ES TEXTO Y NO UN FK A `datacount_bancos_cuentas`
-- -----------------------------------------------------------------------
-- El numero de cuenta corriente que figura impreso en la chequera es un dato
-- del papel, y las chequeras suelen cargarse antes (o sin) que exista la
-- cuenta de fondos correspondiente en el modulo Bancos. Se guarda como viene
-- impreso, con guiones y barras si los tiene. La institucion, en cambio, si es
-- una entidad del sistema y va por FK.
--
-- EL BANCO SALE DE `datacountbancos` (SIN GUION BAJO)
-- --------------------------------------------------
-- Es el catalogo legacy de instituciones del grupo, compartido con las UIs
-- viejas; no existe ninguna `datacount_bancos`. Es el mismo catalogo al que
-- apunta `datacount_bancos_cuentas.banco_id`, asi que las dos tablas hablan de
-- las mismas entidades. El FK va con ON DELETE SET NULL: si el sistema legacy
-- borra una institucion, la chequera queda sin banco pero no se pierde.
--
-- Idempotente en los 4 pasos. Compatible MySQL 8 (dev) + MariaDB 10.11 (prod):
-- sin `ADD COLUMN IF NOT EXISTS` de MariaDB, sin funciones almacenadas.

-- ============================================================================
-- Paso 1: `datacount_chequeras`.
-- ============================================================================
--
-- `empresa_id` scopea el listado igual que el resto de los modulos Datacount
-- (el selector de empresa de la toolbar es contexto compartido). Es NULLABLE
-- porque el catalogo legacy no lo tiene y una chequera sin empresa asignada
-- sigue siendo una chequera valida.
--
-- `tipo` es enum y ademas se seedea en `estados` (paso 2): el enum blinda la
-- tabla y el catalogo `estados` alimenta los combos, que es el patron que ya
-- usa `datacount_bancos_cuentas.tipo`.

CREATE TABLE IF NOT EXISTS `datacount_chequeras` (
  `id`            int(11) UNSIGNED NOT NULL AUTO_INCREMENT,
  `empresa_id`    int(11) NULL DEFAULT NULL,
  `banco_id`      int(11) NULL DEFAULT NULL,
  `nombre`        varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL,
  `numero_cuenta` varchar(50)  CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NULL DEFAULT NULL,
  `tipo`          enum('comun','diferido')
                    CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci
                    NOT NULL DEFAULT 'comun',
  `observaciones` text CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NULL,
  `activa`        tinyint(1) NOT NULL DEFAULT 1,
  `created_at`    timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`    timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`) USING BTREE,
  INDEX `idx_empresa`(`empresa_id`) USING BTREE,
  INDEX `idx_banco`(`banco_id`) USING BTREE,
  INDEX `idx_tipo`(`tipo`) USING BTREE,
  INDEX `idx_activa`(`activa`) USING BTREE,
  CONSTRAINT `fk_dcch_banco` FOREIGN KEY (`banco_id`)
    REFERENCES `datacountbancos` (`id`) ON DELETE SET NULL ON UPDATE RESTRICT
) ENGINE = InnoDB CHARACTER SET = utf8mb4 COLLATE = utf8mb4_general_ci ROW_FORMAT = Dynamic;

-- ============================================================================
-- Paso 2: catalogo `estados` — tipos de chequera.
-- ============================================================================
--
-- Alimenta los chips del modal de filtros y el <select> del formulario, y se
-- puede editar desde Herramientas > Editor de estados sin tocar codigo. El
-- campo sigue la convencion snake_case + modelo en singular.
--
-- `estados` no tiene UNIQUE, asi que el INSERT va con su NOT EXISTS.

INSERT INTO `estados` (`campo`, `texto`, `valor`, `orden`)
SELECT * FROM (
  SELECT 'datacount_chequera_tipo' AS c, 'Comun'    AS t, 'comun'    AS v, 1 AS o UNION ALL
  SELECT 'datacount_chequera_tipo',       'Diferido',      'diferido',      2
) src
WHERE NOT EXISTS (
  SELECT 1 FROM `estados` e
   WHERE e.`campo` = src.c AND e.`valor` = src.v
);

-- ============================================================================
-- Paso 3: permisos del submodulo.
-- ============================================================================
--
-- OJO con el verbo: `agregar`, NO `crear`. requirePermCrud() mapea POST ->
-- 'agregar' (cloud/api/lib/auth_check.php); un slug `.crear` no matchea y el
-- POST devuelve 403 aunque el permiso este asignado al rol.

INSERT INTO `permisos` (`slug`, `nombre`)
SELECT * FROM (
  SELECT 'datacount.chequeras.consultar' AS s, 'Datacount > Chequeras > Consultar' AS n UNION ALL
  SELECT 'datacount.chequeras.agregar',        'Datacount > Chequeras > Agregar'          UNION ALL
  SELECT 'datacount.chequeras.editar',         'Datacount > Chequeras > Editar'           UNION ALL
  SELECT 'datacount.chequeras.eliminar',       'Datacount > Chequeras > Eliminar'
) src
WHERE NOT EXISTS (
  SELECT 1 FROM `permisos` p WHERE p.`slug` = src.s
);

-- ============================================================================
-- Paso 4: `desarrollador` = todos los permisos cloud del env actual.
-- ============================================================================
--
-- Mismo cierre que el resto de las migraciones de permisos. El filtro
-- `slug IS NOT NULL AND slug <> ''` excluye los permisos del sistema legacy,
-- que comparten tabla.

SET SESSION group_concat_max_len = 65535;

UPDATE `roles` r
CROSS JOIN (
    SELECT GROUP_CONCAT(id ORDER BY id) AS ids
    FROM `permisos`
    WHERE slug IS NOT NULL AND slug <> ''
) p
SET r.permisos = p.ids
WHERE r.slug = 'desarrollador';
