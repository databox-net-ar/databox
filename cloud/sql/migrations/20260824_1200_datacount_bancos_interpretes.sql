-- Datacount > Bancos: intérpretes de extracto por banco.
--
-- POR QUE
-- -------
-- La primera versión del importador guardaba el mapeo de columnas en
-- `datacount_bancos_cuentas.import_config`, o sea POR CUENTA. Estaba mal: el
-- formato del extracto es una propiedad del BANCO, no de la cuenta. Con tres
-- cuentas en el mismo banco había que configurar el mismo mapeo tres veces, y
-- cada cuenta nueva arrancaba sin configurar aunque el banco ya se conociera.
--
-- Esta tabla asocia cada banco de `datacountbancos` con el intérprete PHP que
-- sabe leer sus exports (cloud/api/lib/bancos_interpretes/<clave>.php). El
-- importador resuelve el intérprete por el `banco_id` de la cuenta, así que
-- alcanza con configurar el banco una vez.
--
-- `import_config` NO se elimina: queda como override por cuenta y como camino
-- para los bancos que todavía no tienen intérprete. El importador la usa sólo
-- cuando ningún intérprete reconoce el archivo.
--
-- POR QUE UNA TABLA Y NO UNA COLUMNA EN `datacountbancos`
-- ------------------------------------------------------
-- `datacountbancos` es legacy y la comparten las UIs viejas del grupo. Sumarle
-- una columna es tocar una tabla de la que no controlamos todos los lectores;
-- una tabla nueva al lado es reversible y no le cambia la forma a nadie.
--
-- El seed matchea por NOMBRE y no por id porque los ids de `datacountbancos`
-- no tienen por qué coincidir entre dev y prod.
--
-- Idempotente en los 2 pasos. Compatible MySQL 8 (dev) + MariaDB 10.11 (prod).

-- ============================================================================
-- Paso 1: la tabla.
-- ============================================================================
--
-- UNIQUE en `banco_id`: un banco tiene un solo intérprete. Si mañana un banco
-- exporta en dos formatos distintos, eso lo resuelve el propio intérprete en
-- PHP (sus firmasEncabezado() admiten varias variantes), no una segunda fila.
--
-- Sin FK contra `datacountbancos` a propósito: esa tabla es MyISAM en algunos
-- entornos del grupo y un FK contra MyISAM no se puede crear. La integridad la
-- sostiene el seed y el ABM.

CREATE TABLE IF NOT EXISTS `datacount_bancos_interpretes` (
  `id`         int(11) UNSIGNED NOT NULL AUTO_INCREMENT,
  `banco_id`   int(11) NOT NULL,
  `interprete` varchar(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL,
  `notas`      varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NULL DEFAULT NULL,
  `activo`     tinyint(1) NOT NULL DEFAULT 1,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`) USING BTREE,
  UNIQUE INDEX `uk_banco`(`banco_id`) USING BTREE,
  INDEX `idx_interprete`(`interprete`) USING BTREE
) ENGINE = InnoDB CHARACTER SET = utf8mb4 COLLATE = utf8mb4_general_ci ROW_FORMAT = Dynamic;

-- ============================================================================
-- Paso 2: asociar los 5 bancos que hoy tienen intérprete.
-- ============================================================================
--
-- Banco Galicia queda afuera a propósito: está cargado en `datacountbancos`
-- pero todavía no hay intérprete para él, así que sus cuentas siguen usando el
-- mapeo manual de columnas. Cuando se escriba `banco_galicia.php`, se suma acá.
--
-- El LIKE va sobre `LOWER(nombre)` con `%` a los dos lados para tolerar cómo
-- esté escrito en cada entorno ("Mercadopago", "Mercado Pago", "MERCADOPAGO").
-- La colación de la tabla ya es _ci, pero el LOWER() explícito la hace
-- independiente de la colación de la conexión.

INSERT INTO `datacount_bancos_interpretes` (`banco_id`, `interprete`, `notas`)
SELECT b.`id`, src.`clave`, src.`nota`
  FROM (
    SELECT 'mercadopago'       AS clave, '%mercado%pago%' AS patron, 'Reporte de saldo / detalle de operaciones' AS nota UNION ALL
    SELECT 'brubank',                    '%brubank%',                'Resumen de la app'                              UNION ALL
    SELECT 'banco_san_juan',             '%san juan%',               'e-Bank Grupo Petersen'                          UNION ALL
    SELECT 'banco_supervielle',          '%supervielle%',            'Consulta de movimientos'                        UNION ALL
    SELECT 'naranja_x',                  '%naranja%',                'Resumen de la app'
  ) src
  JOIN `datacountbancos` b ON LOWER(b.`nombre`) LIKE src.`patron`
 WHERE NOT EXISTS (
   SELECT 1 FROM `datacount_bancos_interpretes` i WHERE i.`banco_id` = b.`id`
 );
