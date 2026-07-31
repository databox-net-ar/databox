-- Crea la tabla `arca_autorizaciones`: log tecnico de cada FECAESolicitar
-- que sale del microservicio `/v4/arca/autorizar.php` contra AFIP.
--
-- Motivo:
--   Cada autorizacion emite (o intenta emitir) un CAE contra AFIP. Necesitamos
--   trazabilidad tecnica: quien pidio, a que empresa se le facturaba, que
--   punto/tipo/numero, cuanto tardo AFIP, si vino CAE o error, y el detalle
--   del error cuando aplique. NO duplicamos aca los datos financieros de la
--   factura (neto, iva, receptor, etc) -- eso vive en la factura misma
--   (`datacountcomprobantes` legacy o el modulo Comprobantes del cloud nuevo
--   cuando exista) y el link es (`empresa_id`, `punto`, `tipo`, `cbte_nro`).
--
--   El log de sucesos ya registra los errores en `sucesos` con origen
--   `arca.autorizar`, pero es JSON en un TEXT y no permite filtros/queries
--   estructurados. Esta tabla es la version estructurada, con indices, para
--   el visor del panel y para queries de troubleshooting.
--
-- Esquema:
--   * `fecha`          -> DEFAULT NOW, cuando se disparo el intento.
--   * `aplicacion_id`  -> FK logica a `aplicaciones` (que apikey pidio).
--   * `empresa_id`     -> FK logica a `datacount_empresas` (a quien se facturaba).
--   * `punto/tipo/cbte_nro` -> identifica el comprobante intentado.
--   * `resultado`      -> 'A' aceptado por AFIP, 'R' rechazado por AFIP (con
--                         Observaciones/Errors), 'error' fallo tecnico
--                         (timeout, cert vencido, SoapFault, etc).
--   * `cae/cae_vto`    -> solo si resultado='A'.
--   * `errores`        -> TEXT con el mensaje devuelto por AFIP (o la
--                         excepcion tecnica cuando resultado='error').
--   * `duracion_ms`    -> latencia AFIP incluyendo WSAA + WSFE, para medir.
--
-- Compatible con MySQL 8 (dev) y MariaDB 10.11 (prod).

CREATE TABLE IF NOT EXISTS `arca_autorizaciones` (
  `id`             int(11)     NOT NULL AUTO_INCREMENT,
  `fecha`          datetime    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `aplicacion_id`  int(11)     NOT NULL,
  `empresa_id`     int(11) UNSIGNED NOT NULL,
  `punto`          int(11)     NOT NULL,
  `tipo`           int(11)     NOT NULL,
  `cbte_nro`       int(11)     NOT NULL,
  `resultado`      enum('A','R','error') NOT NULL,
  `cae`            varchar(20) NULL DEFAULT NULL,
  `cae_vto`        date        NULL DEFAULT NULL,
  `errores`        text        NULL DEFAULT NULL,
  `duracion_ms`    int(11)     NULL DEFAULT NULL,
  PRIMARY KEY (`id`) USING BTREE,
  INDEX `idx_fecha`         (`fecha`) USING BTREE,
  INDEX `idx_empresa_fecha` (`empresa_id`, `fecha`) USING BTREE,
  INDEX `idx_resultado`     (`resultado`) USING BTREE,
  INDEX `idx_pto_tipo_nro`  (`punto`, `tipo`, `cbte_nro`) USING BTREE,
  INDEX `idx_cae`           (`cae`) USING BTREE
) ENGINE=InnoDB CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci ROW_FORMAT=Dynamic;
