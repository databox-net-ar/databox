-- Crea la tabla `arca_certificados`, el catalogo de certificados fiscales de
-- ARCA/AFIP que la plataforma usa para firmar y autenticar requests contra
-- los webservices de AFIP (WSAA, WSFEv1, etc.).
--
-- Cada fila representa un contribuyente/CUIT con su certificado X.509 (PEM),
-- la clave privada RSA asociada y un token de acceso persistido. La UI del
-- ABM (Arca > Certificados) es solo de carga: no genera ni renueva
-- certificados -- se pegan los que emite AFIP desde el portal fiscal.
--
-- Esquema:
--   * `nombre`      -> nombre humano del contribuyente ("Wescom").
--   * `cuit`        -> CUIT del contribuyente en formato XX-XXXXXXXX-X.
--   * `llave`       -> clave privada RSA en PEM (BEGIN PRIVATE KEY ...).
--   * `certificado` -> certificado X.509 en PEM (BEGIN CERTIFICATE ...).
--   * `token`       -> token de acceso emitido por AFIP (opaco, base64 largo).
--   * `actualizado` -> ultima modificacion, seteado por la API.
--
-- Idempotente: CREATE TABLE IF NOT EXISTS. Compatible con MySQL 8 y
-- MariaDB 10.11 (ver memoria dev vs prod).

CREATE TABLE IF NOT EXISTS `arca_certificados` (
  `id`          INT(11)      NOT NULL AUTO_INCREMENT,
  `nombre`      VARCHAR(255) NULL DEFAULT NULL,
  `cuit`        VARCHAR(13)  NULL DEFAULT NULL,
  `llave`       TEXT         NULL,
  `certificado` TEXT         NULL,
  `token`       TEXT         NULL,
  `actualizado` DATETIME     NULL DEFAULT NULL,
  PRIMARY KEY (`id`) USING BTREE,
  INDEX `idx_arca_certificados_cuit` (`cuit`) USING BTREE
) ENGINE=InnoDB CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci ROW_FORMAT=Dynamic;
