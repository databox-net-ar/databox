-- Migra `datarocket_prospectos` de MyISAM a InnoDB. La tabla se creo en el
-- legacy con MyISAM (motor default de la epoca) y en dev/prod sigue asi.
-- InnoDB es requisito para las siguientes migraciones del embudo:
--   1) FK a `datarocket_contactos` (proxima migracion, contacto_id).
--   2) Transacciones — el backfill de campos del embudo escribe multiples
--      columnas por fila y necesita all-or-nothing.
--   3) Consistencia con el resto del cloud (todo InnoDB desde 2026).
--
-- No hay conversion de encoding. La tabla ya declara `utf8mb4_general_ci` a
-- nivel tabla y columna, y una inspeccion HEX de las 1349 filas contra
-- `CAST AS BINARY` confirmo que los bytes son UTF-8 valido (0 mismatches).
-- Los "?" que se ven al consultar via `docker exec mysql -e ...` son del
-- terminal de Windows/PowerShell no rindiendo UTF-8, no de los datos.
--
-- ROW_FORMAT se declara Dynamic para alinear con el resto del schema y
-- soportar campos VARCHAR largos y TEXT sin overflow off-page innecesario.
--
-- Idempotente: consulta `information_schema.TABLES.ENGINE` primero y solo
-- corre el ALTER si sigue en MyISAM. Correr dos veces no vuelve a reescribir
-- la tabla (ALTER TABLE ENGINE reescribe toda la tabla aunque el motor ya
-- sea el destino, asi que el guard evita ese costo).
--
-- Compatible con MySQL 8.0 (dev) y MariaDB 10.11 (prod).

SET @db := DATABASE();

SET @engine := (
  SELECT ENGINE
  FROM information_schema.TABLES
  WHERE TABLE_SCHEMA = @db
    AND TABLE_NAME   = 'datarocket_prospectos'
);

SET @sql := IF(
  @engine = 'MyISAM',
  'ALTER TABLE `datarocket_prospectos` ENGINE = InnoDB, ROW_FORMAT = Dynamic',
  'DO 0'
);

PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
