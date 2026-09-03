-- Indice `idx_evomsg_estado_enviado` sobre `evolution_mensajes(estado, enviado)`.
--
-- EL PROBLEMA
-- -----------
-- `evolution_mensajes` no tiene mas indice que la PK, y en produccion ya pasa
-- las 300.000 filas. El grafico nuevo del landing de Evolution API
-- (api/evolutionmensajes_grafico.php) corre en CADA entrada a #/evolution:
--
--   WHERE estado = 'enviado' AND enviado >= DATE_SUB(CURDATE(), INTERVAL 6 DAY)
--   GROUP BY canal_id, DATE(enviado)
--
-- Sin indice eso es un full scan de la tabla entera para leer, en la practica,
-- los mensajes de una semana. El landing es una pantalla de paso — se entra
-- muchas veces por dia — asi que el scan se paga una y otra vez.
--
-- LA DECISION
-- -----------
-- Indice compuesto `(estado, enviado)` y en ese orden: `estado` es igualdad y
-- `enviado` es rango, asi que el motor puede posicionarse en el bloque
-- 'enviado' y recorrer solo el tramo de fechas pedido. Al reves (`enviado`,
-- `estado`) el rango consumiria la primera columna y `estado` quedaria como
-- filtro suelto.
--
-- No se indexa `canal_id`: el GROUP BY se resuelve sobre las pocas filas que
-- sobreviven al WHERE, y sumar la columna al indice solo lo engorda.
--
-- El mismo indice le sirve al ABM de mensajes (api/evolutionmensajes.php), que
-- filtra por `estado` y por rango de fechas desde el modal de Filtros, y a las
-- stats de esa pantalla, que cuentan por estado.
--
-- Idempotente: patron information_schema + PREPARE/EXECUTE. No se usa
-- `ADD INDEX IF NOT EXISTS` porque es sintaxis MariaDB-only y desarrollo corre
-- MySQL 8.0.

SET @existe := (
  SELECT COUNT(*) FROM information_schema.STATISTICS
   WHERE TABLE_SCHEMA = DATABASE()
     AND TABLE_NAME   = 'evolution_mensajes'
     AND INDEX_NAME   = 'idx_evomsg_estado_enviado'
);
SET @sql := IF(@existe = 0,
  'ALTER TABLE `evolution_mensajes`
     ADD INDEX `idx_evomsg_estado_enviado`(`estado`, `enviado`) USING BTREE',
  'SELECT "indice idx_evomsg_estado_enviado ya existe" AS nota'
);
PREPARE st FROM @sql; EXECUTE st; DEALLOCATE PREPARE st;
