-- datarocket_prospectos: indices de busqueda sobre `correo` y `celular`.
--
-- El microservicio api/v4/datarocket/prospectos.php rechaza con 409 el alta
-- cuyo correo o celular ya este cargado (drPrAssertUnico / drPrBuscarDuplicados),
-- y expone el mismo chequeo como `GET ?verificar=1`. Sin indice, cada una de
-- esas consultas es un full scan de la tabla — 43.244 filas en dev y 148.287 en
-- prod al 2026-08-18. Con dos altas por segundo de un importador eso se nota.
--
-- SON INDICES COMUNES, NO UNIQUE. La unicidad la sostiene la capa PHP a
-- proposito: los datos historicos todavia tienen duplicados y un UNIQUE no
-- entraria. Medido en dev al 2026-08-18:
--
--     correo  no vacio: 33.565 filas / 30.689 valores distintos -> 2.876 repetidos
--     celular no vacio: 22.669 filas / 20.638 valores distintos -> 2.031 repetidos
--
-- Ademas la columna tiene DEFAULT '' y ~9.600 filas sin correo: un UNIQUE
-- tampoco toleraria esa cadena vacia repetida (a diferencia de NULL, que si se
-- repite bajo UNIQUE). Convertirlo en unico requiere primero depurar los
-- duplicados y pasar los '' a NULL — es otra migracion, y una decision de
-- producto (cual de las dos filas sobrevive), no de schema.
--
-- Idempotente: se chequea information_schema.STATISTICS antes de crear.
-- Compatible MySQL 8 (dev) + MariaDB 10.11 (prod) — sin `IF NOT EXISTS`, que
-- es sintaxis MariaDB-only y en dev falla.


-- ---------------------------------------------------------------------------
-- 1) correo
-- ---------------------------------------------------------------------------
SET @existe := (SELECT COUNT(*) FROM information_schema.STATISTICS
                 WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'datarocket_prospectos'
                   AND INDEX_NAME = 'idx_dr_prospectos_correo');
SET @sql := IF(@existe = 0,
               'ALTER TABLE datarocket_prospectos ADD INDEX `idx_dr_prospectos_correo` (`correo`)',
               'DO 0');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;


-- ---------------------------------------------------------------------------
-- 2) celular
-- ---------------------------------------------------------------------------
SET @existe := (SELECT COUNT(*) FROM information_schema.STATISTICS
                 WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'datarocket_prospectos'
                   AND INDEX_NAME = 'idx_dr_prospectos_celular');
SET @sql := IF(@existe = 0,
               'ALTER TABLE datarocket_prospectos ADD INDEX `idx_dr_prospectos_celular` (`celular`)',
               'DO 0');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
