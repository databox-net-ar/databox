-- Datacount > Chequeras: rehacer el submodulo sobre `datacount_bancos_chequeras`.
--
-- Corrige la migracion 20260908_1000, que creo `datacount_chequeras` con el
-- banco y el numero de cuenta cargados a mano. Tres cambios:
--
--   1. La tabla pasa a llamarse `datacount_bancos_chequeras`, hermana de
--      `datacount_bancos_cuentas` y `datacount_bancos_movimientos`.
--   2. `banco_id` + `numero_cuenta` + `empresa_id` desaparecen y los reemplaza
--      un unico `cuenta_id` contra `datacount_bancos_cuentas`. Una chequera se
--      emite SIEMPRE contra una cuenta corriente concreta: el banco, el numero
--      y la empresa son atributos de esa cuenta, no de la chequera. Tenerlos
--      duplicados permitia estados imposibles (una chequera del Banco Galicia
--      apuntando al numero de cuenta del Banco San Juan) que nada validaba.
--   3. `nombre` desaparece: se derivaba de la cuenta y ahora se lee del JOIN,
--      asi que renombrar la cuenta actualiza sus chequeras sin backfill.
--
-- Queda `tipo` como unico dato propio de la chequera -- COMUN (se cobra a la
-- vista) vs DIFERIDO (lleva fecha de pago futura) -- mas `observaciones` y
-- `activa`. Es correcto que sea lo unico: el banco entrega talonarios separados
-- para cada tipo, asi que dos chequeras de la misma cuenta se distinguen
-- justamente por ahi.
--
-- Idempotente en los 4 pasos. Compatible MySQL 8 (dev) + MariaDB 10.11 (prod):
-- sin `ADD COLUMN IF NOT EXISTS` de MariaDB, sin funciones almacenadas.

-- ============================================================================
-- Paso 1: bajar `datacount_chequeras`, y SOLO si esta vacia.
-- ============================================================================
--
-- La 20260908_1000 nunca se deployo, asi que la tabla no llego a tener datos
-- reales en ningun entorno. Aun asi el DROP va condicionado al COUNT: si algun
-- entorno alcanzo a cargar chequeras, la migracion las deja intactas y falla
-- despues -- al intentar crear la tabla nueva -- en vez de borrarlas en
-- silencio. Recuperarlas seria imposible; que la migracion se plante es
-- barato.

SET @filas  := 0;
SET @existe := (
  SELECT COUNT(*) FROM information_schema.TABLES
   WHERE TABLE_SCHEMA = DATABASE()
     AND TABLE_NAME   = 'datacount_chequeras'
);
SET @sql := IF(@existe = 1,
  'SELECT COUNT(*) INTO @filas FROM `datacount_chequeras`',
  'DO 0'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql := IF(@filas = 0,
  'DROP TABLE IF EXISTS `datacount_chequeras`',
  'DO 0'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- ============================================================================
-- Paso 2: `datacount_bancos_chequeras`.
-- ============================================================================
--
-- El FK contra la cuenta es CASCADE, igual que el de
-- `datacount_bancos_movimientos`: una chequera sin su cuenta corriente no
-- significa nada, no hay a que reasignarla.
--
-- No hay UNIQUE (cuenta_id, tipo): una cuenta puede tener varias chequeras
-- vigentes del mismo tipo (el talonario anterior sin agotar + el nuevo).

CREATE TABLE IF NOT EXISTS `datacount_bancos_chequeras` (
  `id`            int(11) UNSIGNED NOT NULL AUTO_INCREMENT,
  `cuenta_id`     int(11) UNSIGNED NOT NULL,
  `tipo`          enum('comun','diferido')
                    CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci
                    NOT NULL DEFAULT 'comun',
  `observaciones` text CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NULL,
  `activa`        tinyint(1) NOT NULL DEFAULT 1,
  `created_at`    timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`    timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`) USING BTREE,
  INDEX `idx_cuenta`(`cuenta_id`) USING BTREE,
  INDEX `idx_tipo`(`tipo`) USING BTREE,
  INDEX `idx_activa`(`activa`) USING BTREE,
  CONSTRAINT `fk_dcbch_cuenta` FOREIGN KEY (`cuenta_id`)
    REFERENCES `datacount_bancos_cuentas` (`id`) ON DELETE CASCADE ON UPDATE RESTRICT
) ENGINE = InnoDB CHARACTER SET = utf8mb4 COLLATE = utf8mb4_general_ci ROW_FORMAT = Dynamic;

-- ============================================================================
-- Paso 3: catalogo `estados` — el campo acompana al nombre de la tabla.
-- ============================================================================
--
-- `datacount_chequera_tipo` -> `datacount_bancos_chequera_tipo` (convencion:
-- snake_case + modelo en singular). El UPDATE solo corre si el campo nuevo
-- todavia no existe, para no duplicar ni pisar textos editados a mano desde
-- Herramientas > Editor de estados. La derivada `x` es obligatoria: MySQL no
-- deja subconsultar la misma tabla que se esta actualizando.
--
-- `estados` es MyISAM: estos cambios no son transaccionales ni reversibles con
-- ROLLBACK.

UPDATE `estados`
   SET `campo` = 'datacount_bancos_chequera_tipo'
 WHERE `campo` = 'datacount_chequera_tipo'
   AND NOT EXISTS (
     SELECT 1 FROM (
       SELECT 1 FROM `estados` WHERE `campo` = 'datacount_bancos_chequera_tipo' LIMIT 1
     ) x
   );

-- Alta de los valores por si la 20260908_1000 nunca corrio en este entorno.
-- `estados` no tiene UNIQUE, asi que el INSERT va con su NOT EXISTS.

INSERT INTO `estados` (`campo`, `texto`, `valor`, `orden`)
SELECT * FROM (
  SELECT 'datacount_bancos_chequera_tipo' AS c, 'Comun'    AS t, 'comun'    AS v, 1 AS o UNION ALL
  SELECT 'datacount_bancos_chequera_tipo',       'Diferido',      'diferido',      2
) src
WHERE NOT EXISTS (
  SELECT 1 FROM `estados` e
   WHERE e.`campo` = src.c AND e.`valor` = src.v
);

-- ============================================================================
-- Paso 4: permisos del submodulo.
-- ============================================================================
--
-- Los slugs `datacount.chequeras.*` que dio de alta la 20260908_1000 NO se
-- tocan: acompanan a la ruta `/datacount_chequeras`, que sigue siendo un
-- submodulo propio con su tarjeta en el landing de Datacount. El nombre de la
-- tabla cambio; el lugar del modulo en el panel, no.
--
-- Este INSERT esta solo para el caso de que este entorno nunca haya corrido la
-- 20260908_1000. OJO con el verbo: `agregar`, NO `crear` -- requirePermCrud()
-- mapea POST -> 'agregar' (cloud/api/lib/auth_check.php).

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

SET SESSION group_concat_max_len = 65535;

UPDATE `roles` r
CROSS JOIN (
    SELECT GROUP_CONCAT(id ORDER BY id) AS ids
    FROM `permisos`
    WHERE slug IS NOT NULL AND slug <> ''
) p
SET r.permisos = p.ids
WHERE r.slug = 'desarrollador';
