-- Datacount > Chequeras > Cheques: alta del submodulo.
--
-- Cada fila es un cheque concreto emitido de una chequera. El modelo sale del
-- ticket de "Emitir ECheq" del homebanking (Banco San Juan), que es el papel
-- del que se transcriben los datos.
--
-- ORDEN DE LOS CAMPOS
-- -------------------
-- Las columnas siguen el orden en que se lee un cheque, no el del ticket:
--
--   1. De donde salio      -> `chequera_id` (+ `empresa_id`, ver abajo)
--   2. Que cheque es       -> `numero`
--   3. Cuando              -> `fecha_emision`, `fecha_pago`
--   4. Cuanto              -> `importe`
--   5. A quien             -> `beneficiario_razon`, `_cuit`, `_correo`
--   6. Por que             -> `concepto`, `referencia`
--   7. Como se libro       -> `modo`, `caracter`
--   8. En que anda         -> `estado`
--   9. Rastro y notas      -> `operacion_numero`, `observaciones`
--
-- Es el orden de "identificar -> valorizar -> imputar", el mismo que usan el
-- formulario y el listado del ABM: quien busca un cheque lo busca por numero o
-- por beneficiario, nunca por su modo de libramiento.
--
-- QUE NO ESTA Y POR QUE
-- ---------------------
-- `moneda` y `tipo` (comun/diferido) NO son columnas. La moneda es de la
-- cuenta y el tipo es de la chequera: un cheque no puede estar en una moneda
-- distinta de su cuenta ni ser diferido si salio de un talonario de comunes.
-- Se leen por JOIN, igual que el banco y el numero de cuenta.
--
-- `empresa_id` SI es columna aunque tambien se pueda derivar
-- (cheque -> chequera -> cuenta -> empresa). Es un pedido explicito, y ademas
-- evita un JOIN de tres saltos en el filtro mas usado del modulo. Se completa
-- sola desde la chequera al dar de alta y al reasignar el cheque -- el ABM no
-- la deja editar -- asi que no puede divergir del camino largo.
--
-- Idempotente en los 4 pasos. Compatible MySQL 8 (dev) + MariaDB 10.11 (prod):
-- sin `ADD COLUMN IF NOT EXISTS` de MariaDB, sin funciones almacenadas.

-- ============================================================================
-- Paso 1: `datacount_bancos_cheques`.
-- ============================================================================
--
-- `fecha_pago` es lo que el ticket del banco llama "FECHA DE PAGO" y el uso
-- corriente, vencimiento: la fecha a partir de la cual el cheque se puede
-- presentar. En un diferido es futura; en un comun coincide con la emision.
-- Va NOT NULL en los dos casos porque el banco siempre la imprime, y tenerla
-- opcional obligaria a defender el NULL en cada reporte de vencimientos.
--
-- El UNIQUE (chequera_id, numero) es la regla del talonario: dos cheques del
-- mismo no pueden llevar el mismo numero. Es lo que ataja el error tipico de
-- cargar dos veces el mismo cheque desde el ticket.
--
-- `modo` y `caracter` quedan como enum sin catalogo en `estados`: son binarios
-- y los fija la ley de cheques (24.452), no una preferencia del operador. Los
-- rotulos viven en un mapa del ABM. `estado`, en cambio, si va a `estados`:
-- son seis valores y el circuito de cada empresa puede querer renombrarlos.

CREATE TABLE IF NOT EXISTS `datacount_bancos_cheques` (
  `id`                  int(11) UNSIGNED NOT NULL AUTO_INCREMENT,
  `empresa_id`          int(11) NULL DEFAULT NULL,
  `chequera_id`         int(11) UNSIGNED NOT NULL,
  `numero`              varchar(30)  CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL,
  `fecha_emision`       date NOT NULL,
  `fecha_pago`          date NOT NULL,
  `importe`             decimal(14, 2) NOT NULL DEFAULT 0.00,
  `beneficiario_razon`  varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL,
  `beneficiario_cuit`   varchar(20)  CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NULL DEFAULT NULL,
  `beneficiario_correo` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NULL DEFAULT NULL,
  `concepto`            varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NULL DEFAULT NULL,
  `referencia`          varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NULL DEFAULT NULL,
  `modo`                enum('cruzado','no_cruzado')
                          CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci
                          NOT NULL DEFAULT 'cruzado',
  `caracter`            enum('a_la_orden','no_a_la_orden')
                          CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci
                          NOT NULL DEFAULT 'a_la_orden',
  `estado`              enum('emitido','entregado','depositado','pagado','rechazado','anulado')
                          CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci
                          NOT NULL DEFAULT 'emitido',
  `operacion_numero`    varchar(64)  CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NULL DEFAULT NULL,
  `observaciones`       text CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NULL,
  `created_at`          timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`          timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`) USING BTREE,
  UNIQUE INDEX `uk_chequera_numero`(`chequera_id`, `numero`) USING BTREE,
  INDEX `idx_empresa`(`empresa_id`) USING BTREE,
  INDEX `idx_chequera`(`chequera_id`) USING BTREE,
  INDEX `idx_fecha_pago`(`fecha_pago`) USING BTREE,
  INDEX `idx_estado`(`estado`) USING BTREE,
  INDEX `idx_beneficiario_cuit`(`beneficiario_cuit`) USING BTREE,
  CONSTRAINT `fk_dcbq_chequera` FOREIGN KEY (`chequera_id`)
    REFERENCES `datacount_bancos_chequeras` (`id`) ON DELETE CASCADE ON UPDATE RESTRICT
) ENGINE = InnoDB CHARACTER SET = utf8mb4 COLLATE = utf8mb4_general_ci ROW_FORMAT = Dynamic;

-- ============================================================================
-- Paso 2: catalogo `estados` — situacion del cheque.
-- ============================================================================
--
-- El circuito visto desde el emisor: se emite, se entrega al beneficiario, el
-- beneficiario lo deposita, el banco lo paga (debita la cuenta). `rechazado` y
-- `anulado` son las dos salidas por afuera de ese camino.
--
-- `estados` no tiene UNIQUE, asi que el INSERT va con su NOT EXISTS.

INSERT INTO `estados` (`campo`, `texto`, `valor`, `orden`)
SELECT * FROM (
  SELECT 'datacount_bancos_cheque_estado' AS c, 'Emitido'    AS t, 'emitido'    AS v, 1 AS o UNION ALL
  SELECT 'datacount_bancos_cheque_estado',       'Entregado',      'entregado',       2 UNION ALL
  SELECT 'datacount_bancos_cheque_estado',       'Depositado',     'depositado',      3 UNION ALL
  SELECT 'datacount_bancos_cheque_estado',       'Pagado',         'pagado',          4 UNION ALL
  SELECT 'datacount_bancos_cheque_estado',       'Rechazado',      'rechazado',       5 UNION ALL
  SELECT 'datacount_bancos_cheque_estado',       'Anulado',        'anulado',         6
) src
WHERE NOT EXISTS (
  SELECT 1 FROM `estados` e
   WHERE e.`campo` = src.c AND e.`valor` = src.v
);

-- ============================================================================
-- Paso 3: permisos del submodulo.
-- ============================================================================
--
-- Acompanan a la ruta `/datacount_cheques`, hermana de `/datacount_chequeras`
-- dentro del mismo submodulo (las dos vistas se alternan con el selector de
-- arriba a la izquierda, igual que Bancos > Cuentas / Movimientos).
--
-- OJO con el verbo: `agregar`, NO `crear`. requirePermCrud() mapea POST ->
-- 'agregar' (cloud/api/lib/auth_check.php); un slug `.crear` no matchea y el
-- POST devuelve 403 aunque el permiso este asignado al rol.

INSERT INTO `permisos` (`slug`, `nombre`)
SELECT * FROM (
  SELECT 'datacount.cheques.consultar' AS s, 'Datacount > Cheques > Consultar' AS n UNION ALL
  SELECT 'datacount.cheques.agregar',        'Datacount > Cheques > Agregar'          UNION ALL
  SELECT 'datacount.cheques.editar',         'Datacount > Cheques > Editar'           UNION ALL
  SELECT 'datacount.cheques.eliminar',       'Datacount > Cheques > Eliminar'
) src
WHERE NOT EXISTS (
  SELECT 1 FROM `permisos` p WHERE p.`slug` = src.s
);

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
