-- Crea `datacount_asientos_adjuntos` — mismo patron que `datacount_pagos_adjuntos`.
-- Guarda la metadata de los archivos adjuntados a un asiento contable
-- (`datacount_asientos.id`). El binario en si vive en S3 bajo
-- `<bucket>/datacount/asientos/<uuid>.<ext>`; esta tabla solo lleva la
-- referencia + nombre original + fecha de carga + tipo/formato.
--
-- ON DELETE CASCADE contra el asiento padre: si algun dia se elimina el
-- asiento (hoy la UI usa "Anular" en su lugar, pero la baja duro sigue
-- disponible desde la API), tambien se van sus adjuntos.
--
-- Idempotente: CREATE TABLE IF NOT EXISTS. Compatible con MySQL 8.0 (dev)
-- y MariaDB 10.11 (prod).

CREATE TABLE IF NOT EXISTS `datacount_asientos_adjuntos` (
  `id`         int(11) UNSIGNED NOT NULL AUTO_INCREMENT,
  `uuid`       varchar(32)  CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL,
  `asiento`    int(11) UNSIGNED NOT NULL,
  `nombre`     varchar(50)  CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL,
  `cargado`    datetime NOT NULL,
  `tipo`       varchar(20)  CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NULL DEFAULT NULL,
  `archivo`    varchar(80)  CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL,
  `formato`    varchar(10)  CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NULL DEFAULT NULL,
  PRIMARY KEY (`id`) USING BTREE,
  UNIQUE INDEX `uk_uuid`(`uuid`) USING BTREE,
  INDEX `idx_asiento`(`asiento`) USING BTREE,
  CONSTRAINT `fk_dcaa_asiento` FOREIGN KEY (`asiento`)
      REFERENCES `datacount_asientos` (`id`) ON DELETE CASCADE ON UPDATE RESTRICT
) ENGINE = InnoDB CHARACTER SET = utf8mb4 COLLATE = utf8mb4_general_ci ROW_FORMAT = Dynamic;
