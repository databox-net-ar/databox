-- Crea la tabla `arca_ta_cache`, cache persistente del "Ticket de Acceso"
-- (TA) que emite el WSAA de AFIP para autenticar contra los webservices
-- fiscales (WSFEv1, etc.).
--
-- Motivo:
--   Cada llamada a AFIP requiere pasar un `<Auth><Token><Sign><Cuit></Auth>`.
--   El TA se obtiene mediante WSAA (firmando un TRA con el certificado
--   X.509 y la clave privada) y tiene una validez de 12 horas. Si pedimos
--   un TA nuevo mientras el anterior sigue vivo, AFIP responde con el
--   error 25 "Ya existe un TA valido para el CUIT/servicio". Por lo tanto
--   HAY que cachearlo entre requests.
--
--   La lib historica (afipsdk 0.8.1) cacheaba el TA en filesystem
--   (`Afip_res/TA-<CUIT>-<service>-production.xml`). Eso no sirve en
--   nuestro entorno: multi-worker, contenedor efimero, y varias empresas
--   comparten el mismo micro `/v4/arca/*`. Persistimos entonces en DB.
--
-- Esquema:
--   * `id`              -> PK autoincremental.
--   * `certificado_id`  -> FK logica a `arca_certificados.id`. El TA es
--                          propiedad del par (cert + service), no de la
--                          empresa: si dos empresas comparten cert
--                          (raro pero valido cuando el cert factura para
--                          varios CUITs delegados), comparten el TA para
--                          ese service.
--   * `service`         -> nombre del webservice AFIP para el que se emitio
--                          el TA (ej. 'wsfe' para WSFEv1).
--   * `token`           -> string opaco que AFIP puso en <token> del TA.
--   * `sign`            -> string opaco que AFIP puso en <sign> del TA.
--   * `expira_en`       -> timestamp UTC en que el TA deja de ser valido.
--                          Al leer verificamos NOW() < expira_en - margen.
--   * `actualizado`     -> ultima vez que este cache fue renovado.
--
--   UNIQUE (certificado_id, service): un unico TA vivo por par. En cada
--   renovacion hacemos UPSERT sobre ese unico.
--
-- Compatible con MySQL 8 (dev) y MariaDB 10.11 (prod).

CREATE TABLE IF NOT EXISTS `arca_ta_cache` (
  `id`              INT(11)      NOT NULL AUTO_INCREMENT,
  `certificado_id`  INT(11)      NOT NULL,
  `service`         VARCHAR(40)  NOT NULL,
  `token`           MEDIUMTEXT   NOT NULL,
  `sign`            MEDIUMTEXT   NOT NULL,
  `expira_en`       DATETIME     NOT NULL,
  `actualizado`     DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`) USING BTREE,
  UNIQUE INDEX `uk_arca_ta_cache_cert_service` (`certificado_id`, `service`) USING BTREE,
  INDEX `idx_arca_ta_cache_expira_en` (`expira_en`) USING BTREE
) ENGINE=InnoDB CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci ROW_FORMAT=Dynamic;
