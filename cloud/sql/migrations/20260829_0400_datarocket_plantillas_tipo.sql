-- datarocket_plantillas: agregar `tipo` — transaccional vs comunicacional.
--
-- POR QUE HACE FALTA
-- ------------------
-- Hasta ahora las plantillas solo se clasificaban por `medio` (por donde sale)
-- y `formato` (como se arma el cuerpo). Faltaba la distincion de INTENCION:
--
--   * `transaccional`  -> disparada por un hecho concreto del destinatario
--                         (confirmacion, aviso, comprobante, recuperacion de
--                         clave). Es la plantilla que el operador espera que
--                         llegue SIEMPRE, sin importar preferencias de
--                         marketing. El patron ya existia de hecho en los
--                         datos: `datarocket_plantillas` #101 se llama
--                         literalmente "Transaccional | Databox".
--   * `comunicacional` -> difusion / marketing: newsletters, promociones,
--                         campanas masivas. Es lo que una baja de lista
--                         (`datarocket_listas_baja_*`) tiene que poder frenar.
--
-- Sin esta columna la distincion vivia solo en el nombre que le pusiera el
-- operador a la plantilla, que no es consultable ni filtrable.
--
-- POR QUE ES NULLABLE Y SIN BACKFILL
-- ----------------------------------
-- Las filas existentes quedan en NULL a proposito: clasificar retroactivamente
-- por heuristica (adivinando por el nombre de la plantilla) meteria datos
-- inventados en una columna que despues se usa para decidir si un envio ignora
-- o no una baja. El ABM muestra "—" para las no clasificadas, deja filtrarlas
-- con la opcion "Sin clasificar" y exige el campo al crear o editar, asi la
-- clasificacion la pone una persona.
--
-- Los valores se guardan en minuscula ('transaccional' / 'comunicacional'),
-- igual que `formato` ('texto' / 'html' / 'imagen' / ...). VARCHAR(20) alcanza
-- para el mas largo ('comunicacional', 14) con margen.
--
-- Se ubica despues de `medio` para que el orden fisico acompane al del
-- formulario, donde Tipo va al lado de Medio en la fila de identidad.
--
-- Idempotente. Compatible MySQL 8 (dev) + MariaDB 10.11 (prod): sin
-- `ADD COLUMN IF NOT EXISTS` de MariaDB (patron information_schema + PREPARE),
-- sin funciones almacenadas.

SET @exists := (
  SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME   = 'datarocket_plantillas'
    AND COLUMN_NAME  = 'tipo'
);
SET @sql := IF(@exists = 0,
  'ALTER TABLE `datarocket_plantillas` ADD COLUMN `tipo` VARCHAR(20) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NULL DEFAULT NULL AFTER `medio`',
  'DO 0'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
