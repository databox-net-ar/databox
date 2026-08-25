-- Datacount > Bancos: alta del modulo (cuentas + movimientos).
--
-- Crea las dos tablas del submodulo y migra las cuentas que hoy viven en la
-- legacy `datacountbilleteras`.
--
-- POR QUE UNA SOLA TABLA DE CUENTAS
-- ---------------------------------
-- Bancos y billeteras virtuales son la misma entidad contable: ambas son
-- disponibilidades (Activo corriente > Caja y Bancos). Separarlas obligaria a
-- un UNION en todo reporte de saldos y, sobre todo, dejaria las transferencias
-- entre cuentas propias (banco -> MercadoPago y vuelta, que es el movimiento
-- mas frecuente) partidas en dos tablas sin forma de aparearlas: cada
-- transferencia interna se contaria como un egreso y un ingreso reales y
-- ningun arqueo cerraria.
--
-- La diferencia que si existe -- un banco solo mueve plata por transferencia,
-- una billetera admite QR, tarjeta, debito automatico, rendimientos -- no es
-- estructural: es la lista de valores validos del campo `medio`. Se resuelve
-- con `datacount_bancos_cuentas.tipo` + una whitelist de medios por tipo
-- (DCBM_MEDIOS_POR_TIPO en cloud/api/datacount_bancos_movimientos.php). Es el
-- mismo modelo que usan Odoo (account.journal + account.payment.method por
-- journal), Xero/QuickBooks (PayPal y Stripe son "bank accounts") y Tango
-- ("cuentas de fondos").
--
-- La legacy `datacountbilleteras` YA modelaba esto sin nombrarlo: tiene `banco`
-- (FK a la institucion), `cvu`, `alias` y `saldo`. El banco nunca fue una
-- entidad paralela, siempre fue un atributo de la cuenta. Esta migracion solo
-- formaliza eso.
--
-- MOVIMIENTOS vs. ORDENES DE PAGO
-- -------------------------------
-- `datacount_pagos` es el documento comercial (a quien le pago, contra que
-- comprobante). `datacount_bancos_movimientos` es el hecho financiero (plata
-- que entra o sale de una cuenta). Se relacionan por `pago_id`, que es
-- NULLABLE a proposito: hay movimientos sin pago asociado (acreditaciones de
-- clientes, transferencias internas, comisiones, impuesto al debito y credito,
-- intereses, rendimientos de FCI). Mezclarlos ensuciaria la analitica de pagos
-- a proveedores con comisiones bancarias.
--
-- La legacy `datacountpagos.billetera` apunta a `datacountbilleteras.id`. Como
-- esta migracion preserva los IDs, ese FK sigue siendo valido contra
-- `datacount_bancos_cuentas` sin tocar una sola fila de pagos.
--
-- Idempotente en los 6 pasos. Compatible MySQL 8 (dev) + MariaDB 10.11 (prod):
-- sin `ADD COLUMN IF NOT EXISTS` de MariaDB, sin funciones almacenadas, sin
-- tablas temporales y sin REGEXP con flags inline.

-- ============================================================================
-- Paso 1: `datacount_bancos_cuentas` — las cuentas de fondos.
-- ============================================================================
--
-- `cbu` guarda CBU (bancos) o CVU (billeteras): ambos son 22 digitos y ocupan
-- el mismo rol funcional, asi que una sola columna alcanza. El `tipo` es quien
-- dice cual de los dos es.
--
-- `import_config` es un JSON con el mapeo de columnas del extracto de esa
-- cuenta (que columna del CSV/XLSX es la fecha, cual el importe, que separador
-- decimal usa, etc.). Se guarda por cuenta porque cada banco exporta distinto
-- y el mapeo se configura una sola vez. Es TEXT y no JSON nativo para no
-- depender del tipo JSON de MariaDB, que es un alias de LONGTEXT con CHECK.
--
-- `cuenta_contable_id` conecta la cuenta de fondos con su cuenta imputable del
-- plan (`datacount_cuentas`), para poder generar asientos desde un movimiento.

CREATE TABLE IF NOT EXISTS `datacount_bancos_cuentas` (
  `id`                 int(11) UNSIGNED NOT NULL AUTO_INCREMENT,
  `empresa_id`         int(11) NULL DEFAULT NULL,
  `proyecto_id`        int(11) NULL DEFAULT NULL,
  `banco_id`           int(11) NULL DEFAULT NULL,
  `tipo`               enum('banco','billetera','efectivo','tarjeta','cripto')
                         CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci
                         NOT NULL DEFAULT 'banco',
  `nombre`             varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL,
  `moneda`             varchar(1)   CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL DEFAULT 'P',
  `cbu`                varchar(22)  CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NULL DEFAULT NULL,
  `alias`              varchar(50)  CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NULL DEFAULT NULL,
  `numero`             varchar(50)  CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NULL DEFAULT NULL,
  `titular`            varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NULL DEFAULT NULL,
  `cuit`               varchar(20)  CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NULL DEFAULT NULL,
  `correo`             varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NULL DEFAULT NULL,
  `celular`            varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NULL DEFAULT NULL,
  `contrasena`         varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NULL DEFAULT NULL,
  `cuenta_contable_id` int(11) UNSIGNED NULL DEFAULT NULL,
  `saldo`              decimal(14, 2) NOT NULL DEFAULT 0.00,
  `saldo_fecha`        date NULL DEFAULT NULL,
  `import_config`      text CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NULL,
  `observaciones`      text CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NULL,
  `activa`             tinyint(1) NOT NULL DEFAULT 1,
  `created_at`         timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`         timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`) USING BTREE,
  INDEX `idx_empresa`(`empresa_id`) USING BTREE,
  INDEX `idx_proyecto`(`proyecto_id`) USING BTREE,
  INDEX `idx_banco`(`banco_id`) USING BTREE,
  INDEX `idx_tipo`(`tipo`) USING BTREE,
  INDEX `idx_activa`(`activa`) USING BTREE
) ENGINE = InnoDB CHARACTER SET = utf8mb4 COLLATE = utf8mb4_general_ci ROW_FORMAT = Dynamic;

-- ============================================================================
-- Paso 2: `datacount_bancos_movimientos` — el extracto.
-- ============================================================================
--
-- `importe` es SIEMPRE positivo; el signo lo lleva `tipo` (ingreso/egreso).
-- Guardar importes negativos ademas de un tipo permite estados contradictorios
-- (egreso de -500) que despues hay que defender en cada SUM.
--
-- `saldo` es el saldo que informa el extracto DESPUES del movimiento. Sirve
-- para detectar huecos: si el saldo de una fila no es el de la anterior mas o
-- menos el importe, falta un movimiento en el medio.
--
-- `huella` es el hash de dedup. Es lo que permite reimportar el mismo archivo
-- (o un extracto que se solapa con el mes anterior) sin duplicar: el UNIQUE
-- (cuenta_id, huella) rechaza la fila y el importador la cuenta como omitida.
-- Se calcula en PHP -- dcbmHuella() en cloud/api/lib/planilla.php -- sobre
-- fecha + tipo + importe + referencia + descripcion normalizada.
--
-- `contrapartida_id` aparea las dos patas de una transferencia entre cuentas
-- propias. Es self-referencial y queda sin FK a proposito: las dos filas se
-- insertan en el mismo lote y un FK obligaria a ordenar los INSERT.
--
-- El FK contra la cuenta es CASCADE: borrar una cuenta se lleva su extracto,
-- que sin la cuenta no significa nada.

CREATE TABLE IF NOT EXISTS `datacount_bancos_movimientos` (
  `id`               int(11) UNSIGNED NOT NULL AUTO_INCREMENT,
  `cuenta_id`        int(11) UNSIGNED NOT NULL,
  `fecha`            date NOT NULL,
  `fecha_valor`      date NULL DEFAULT NULL,
  `tipo`             enum('ingreso','egreso')
                       CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL,
  `medio`            varchar(30)  CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NULL DEFAULT NULL,
  `descripcion`      varchar(500) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NULL DEFAULT NULL,
  `referencia`       varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NULL DEFAULT NULL,
  `importe`          decimal(14, 2) NOT NULL DEFAULT 0.00,
  `saldo`            decimal(14, 2) NULL DEFAULT NULL,
  `moneda`           varchar(1)   CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL DEFAULT 'P',
  `contraparte`      varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NULL DEFAULT NULL,
  `cuit`             varchar(20)  CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NULL DEFAULT NULL,
  `conciliado`       tinyint(1) NOT NULL DEFAULT 0,
  `pago_id`          int(11) NULL DEFAULT NULL,
  `contrapartida_id` int(11) UNSIGNED NULL DEFAULT NULL,
  `asiento_id`       int(11) UNSIGNED NULL DEFAULT NULL,
  `importacion_id`   varchar(32) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NULL DEFAULT NULL,
  `huella`           varchar(64) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL,
  `origen`           enum('importado','manual')
                       CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL DEFAULT 'importado',
  `observaciones`    text CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NULL,
  `created_at`       timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`       timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`) USING BTREE,
  UNIQUE INDEX `uk_cuenta_huella`(`cuenta_id`, `huella`) USING BTREE,
  INDEX `idx_cuenta_fecha`(`cuenta_id`, `fecha`) USING BTREE,
  INDEX `idx_fecha`(`fecha`) USING BTREE,
  INDEX `idx_tipo`(`tipo`) USING BTREE,
  INDEX `idx_conciliado`(`conciliado`) USING BTREE,
  INDEX `idx_importacion`(`importacion_id`) USING BTREE,
  INDEX `idx_pago`(`pago_id`) USING BTREE,
  CONSTRAINT `fk_dcbm_cuenta` FOREIGN KEY (`cuenta_id`)
    REFERENCES `datacount_bancos_cuentas` (`id`) ON DELETE CASCADE ON UPDATE RESTRICT
) ENGINE = InnoDB CHARACTER SET = utf8mb4 COLLATE = utf8mb4_general_ci ROW_FORMAT = Dynamic;

-- ============================================================================
-- Paso 3: migrar las cuentas de `datacountbilleteras` preservando los IDs.
-- ============================================================================
--
-- Preservar el ID no es cosmetico: `datacountpagos.billetera` (1868 filas)
-- referencia esos IDs. Si la tabla nueva reasignara IDs, todo pago quedaria
-- apuntando a la cuenta equivocada.
--
-- El `tipo` se infiere en dos escalones:
--   1. Por institucion: MercadoPago, Naranja X, Uala, Personal Pay, etc. son
--      billeteras aunque tengan un CBU real de entidad financiera. Se usa LIKE
--      con LOWER() en vez de REGEXP porque MySQL 8 (ICU) y MariaDB (PCRE) no
--      comparten dialecto de expresiones regulares.
--   2. Por prefijo del CBU: los CVU emitidos por PSPs arrancan en '000'. Cubre
--      las cuentas cuya institucion no este en la lista de arriba.
-- Todo lo demas queda 'banco', que es el default sensato.
--
-- La legacy no distingue moneda: todas las cuentas cargadas son en pesos, asi
-- que entran como 'P'. Si mañana hay una en dolares se corrige desde el ABM.
--
-- El `NOT EXISTS` deja el paso idempotente y, mas importante, no pisa ediciones
-- hechas desde el ABM si la migracion se vuelve a correr.

INSERT INTO `datacount_bancos_cuentas`
  (`id`, `empresa_id`, `proyecto_id`, `banco_id`, `tipo`, `nombre`, `moneda`,
   `cbu`, `alias`, `correo`, `celular`, `contrasena`, `saldo`, `activa`)
SELECT
  v.`id`,
  v.`empresa`,
  v.`proyecto`,
  v.`banco`,
  CASE
    WHEN LOWER(COALESCE(b.`nombre`, '')) LIKE '%mercado%pago%'  THEN 'billetera'
    WHEN LOWER(COALESCE(b.`nombre`, '')) LIKE '%naranja%'       THEN 'billetera'
    WHEN LOWER(COALESCE(b.`nombre`, '')) LIKE '%ual%'           THEN 'billetera'
    WHEN LOWER(COALESCE(b.`nombre`, '')) LIKE '%personal pay%'  THEN 'billetera'
    WHEN LOWER(COALESCE(b.`nombre`, '')) LIKE '%cuenta dni%'    THEN 'billetera'
    WHEN LOWER(COALESCE(b.`nombre`, '')) LIKE '%prex%'          THEN 'billetera'
    WHEN LOWER(COALESCE(b.`nombre`, '')) LIKE '%belo%'          THEN 'billetera'
    WHEN LOWER(COALESCE(b.`nombre`, '')) LIKE '%lemon%'         THEN 'billetera'
    WHEN LOWER(COALESCE(b.`nombre`, '')) LIKE '%modo%'          THEN 'billetera'
    WHEN LEFT(COALESCE(v.`cvu`, ''), 3) = '000'                 THEN 'billetera'
    ELSE 'banco'
  END,
  COALESCE(NULLIF(TRIM(v.`nombre`), ''), CONCAT('Cuenta #', v.`id`)),
  'P',
  NULLIF(TRIM(COALESCE(v.`cvu`, '')), ''),
  NULLIF(TRIM(COALESCE(v.`alias`, '')), ''),
  NULLIF(TRIM(COALESCE(v.`correo`, '')), ''),
  NULLIF(TRIM(COALESCE(v.`celular`, '')), ''),
  NULLIF(TRIM(COALESCE(v.`contrasena`, '')), ''),
  COALESCE(v.`saldo`, 0.00),
  1
FROM `datacountbilleteras` v
LEFT JOIN `datacountbancos` b ON b.`id` = v.`banco`
WHERE NOT EXISTS (
  SELECT 1 FROM `datacount_bancos_cuentas` c WHERE c.`id` = v.`id`
);

-- ============================================================================
-- Paso 4: catalogo `estados` — tipos de cuenta y medios de movimiento.
-- ============================================================================
--
-- Los medios son el catalogo COMPLETO; que subconjunto aplica a cada tipo de
-- cuenta lo decide DCBM_MEDIOS_POR_TIPO en el endpoint PHP (una cuenta 'banco'
-- no ofrece 'qr' ni 'rendimiento'). El catalogo va en `estados` igual que el
-- resto de los combos Datacount, para que se pueda editar desde
-- Herramientas > Editor de estados sin tocar codigo.
--
-- `estados` no tiene UNIQUE, asi que cada INSERT va con su NOT EXISTS.

INSERT INTO `estados` (`campo`, `texto`, `valor`, `orden`)
SELECT * FROM (
  SELECT 'datacount_bancos_cuenta_tipo' AS c, 'Banco'             AS t, 'banco'     AS v, 1 AS o UNION ALL
  SELECT 'datacount_bancos_cuenta_tipo',       'Billetera virtual',      'billetera',      2 UNION ALL
  SELECT 'datacount_bancos_cuenta_tipo',       'Efectivo',               'efectivo',       3 UNION ALL
  SELECT 'datacount_bancos_cuenta_tipo',       'Tarjeta de credito',     'tarjeta',        4 UNION ALL
  SELECT 'datacount_bancos_cuenta_tipo',       'Cripto',                 'cripto',         5
) src
WHERE NOT EXISTS (
  SELECT 1 FROM `estados` e
   WHERE e.`campo` = src.c AND e.`valor` = src.v
);

INSERT INTO `estados` (`campo`, `texto`, `valor`, `orden`)
SELECT * FROM (
  SELECT 'datacount_bancos_movimiento_medio' AS c, 'Transferencia'        AS t, 'transferencia' AS v,  1 AS o UNION ALL
  SELECT 'datacount_bancos_movimiento_medio',       'Debito automatico',        'debito_auto',        2 UNION ALL
  SELECT 'datacount_bancos_movimiento_medio',       'Pago con QR',              'qr',                 3 UNION ALL
  SELECT 'datacount_bancos_movimiento_medio',       'Tarjeta de debito',        'tarjeta_debito',     4 UNION ALL
  SELECT 'datacount_bancos_movimiento_medio',       'Tarjeta de credito',       'tarjeta_credito',    5 UNION ALL
  SELECT 'datacount_bancos_movimiento_medio',       'Efectivo',                 'efectivo',           6 UNION ALL
  SELECT 'datacount_bancos_movimiento_medio',       'Cheque / echeq',           'cheque',             7 UNION ALL
  SELECT 'datacount_bancos_movimiento_medio',       'Comision',                 'comision',           8 UNION ALL
  SELECT 'datacount_bancos_movimiento_medio',       'Impuesto',                 'impuesto',           9 UNION ALL
  SELECT 'datacount_bancos_movimiento_medio',       'Interes',                  'interes',           10 UNION ALL
  SELECT 'datacount_bancos_movimiento_medio',       'Rendimiento',              'rendimiento',       11 UNION ALL
  SELECT 'datacount_bancos_movimiento_medio',       'Ajuste',                   'ajuste',            12 UNION ALL
  SELECT 'datacount_bancos_movimiento_medio',       'Otro',                     'otro',              13
) src
WHERE NOT EXISTS (
  SELECT 1 FROM `estados` e
   WHERE e.`campo` = src.c AND e.`valor` = src.v
);

-- ============================================================================
-- Paso 5: permisos del submodulo.
-- ============================================================================
--
-- Slugs de 4 segmentos (`datacount.bancos.<recurso>.<verbo>`), igual que
-- `plataformas.aws.cuentas.*`. El prefijo `datacount.bancos.` es el que usa
-- ROUTE_PERMS para habilitar la navegacion al landing.
--
-- OJO con el verbo: `agregar`, NO `crear`. requirePermCrud() mapea POST ->
-- 'agregar' (cloud/api/lib/auth_check.php); un slug `.crear` no matchea y el
-- POST devuelve 403 aunque el permiso este asignado al rol.

INSERT INTO `permisos` (`slug`, `nombre`)
SELECT * FROM (
  SELECT 'datacount.bancos.cuentas.consultar'     AS s, 'Datacount > Bancos > Cuentas > Consultar'     AS n UNION ALL
  SELECT 'datacount.bancos.cuentas.agregar',            'Datacount > Bancos > Cuentas > Agregar'              UNION ALL
  SELECT 'datacount.bancos.cuentas.editar',             'Datacount > Bancos > Cuentas > Editar'               UNION ALL
  SELECT 'datacount.bancos.cuentas.eliminar',           'Datacount > Bancos > Cuentas > Eliminar'             UNION ALL
  SELECT 'datacount.bancos.movimientos.consultar',      'Datacount > Bancos > Movimientos > Consultar'        UNION ALL
  SELECT 'datacount.bancos.movimientos.agregar',        'Datacount > Bancos > Movimientos > Agregar'          UNION ALL
  SELECT 'datacount.bancos.movimientos.editar',         'Datacount > Bancos > Movimientos > Editar'           UNION ALL
  SELECT 'datacount.bancos.movimientos.eliminar',       'Datacount > Bancos > Movimientos > Eliminar'
) src
WHERE NOT EXISTS (
  SELECT 1 FROM `permisos` p WHERE p.`slug` = src.s
);

-- ============================================================================
-- Paso 6: `desarrollador` = todos los permisos cloud del env actual.
-- ============================================================================
--
-- Mismo cierre que el resto de las migraciones de permisos: reprograma el rol
-- con el listado completo para que los slugs nuevos queden incluidos sin pasar
-- por el ABM de Roles. El filtro `slug IS NOT NULL AND slug <> ''` excluye los
-- permisos del sistema legacy, que comparten tabla.

SET SESSION group_concat_max_len = 65535;

UPDATE `roles` r
CROSS JOIN (
    SELECT GROUP_CONCAT(id ORDER BY id) AS ids
    FROM `permisos`
    WHERE slug IS NOT NULL AND slug <> ''
) p
SET r.permisos = p.ids
WHERE r.slug = 'desarrollador';
