-- Indice `(estado, enviado)` en `aws_mensajes` y `telegram_mensajes`.
--
-- Es el mismo indice que la migracion 20260903_1000 le puso a
-- `evolution_mensajes`, por el mismo motivo: los graficos de los landings de
-- AWS (#/aws) y Telegram (#/telegram) corren en CADA entrada a esas pantallas
-- la consulta de api/lib/mensajes_grafico.php, que es
--
--   WHERE estado = 'enviado' AND enviado >= DATE_SUB(CURDATE(), INTERVAL 29 DAY)
--   GROUP BY canal_id, DATE(enviado)
--
-- y los landings son pantallas de paso: se entra muchas veces por dia.
--
-- `estado` primero y `enviado` despues porque el primero es igualdad y el
-- segundo es rango: asi el motor se posiciona en el bloque 'enviado' y recorre
-- solo el tramo de fechas pedido. Al reves, el rango consume la primera columna
-- y `estado` queda como filtro suelto.
--
-- `aws_mensajes` hoy no tiene mas indice que la PK. `telegram_mensajes` ya
-- tiene uno sobre `estado` solo: el compuesto lo mejora (evita releer las filas
-- para filtrar por fecha) y no lo reemplaza — el de `estado` sigue sirviendo a
-- las stats del ABM.
--
-- El mismo indice le sirve a los ABM de mensajes de ambas plataformas, que
-- filtran por estado y por rango de fechas desde el modal de Filtros.
--
-- Idempotente: patron information_schema + PREPARE/EXECUTE. No se usa
-- `ADD INDEX IF NOT EXISTS` porque es sintaxis MariaDB-only y desarrollo corre
-- MySQL 8.0.

-- 1) aws_mensajes
SET @existe := (
  SELECT COUNT(*) FROM information_schema.STATISTICS
   WHERE TABLE_SCHEMA = DATABASE()
     AND TABLE_NAME   = 'aws_mensajes'
     AND INDEX_NAME   = 'idx_awsmsg_estado_enviado'
);
SET @sql := IF(@existe = 0,
  'ALTER TABLE `aws_mensajes`
     ADD INDEX `idx_awsmsg_estado_enviado`(`estado`, `enviado`) USING BTREE',
  'SELECT "indice idx_awsmsg_estado_enviado ya existe" AS nota'
);
PREPARE st FROM @sql; EXECUTE st; DEALLOCATE PREPARE st;

-- 2) telegram_mensajes
SET @existe := (
  SELECT COUNT(*) FROM information_schema.STATISTICS
   WHERE TABLE_SCHEMA = DATABASE()
     AND TABLE_NAME   = 'telegram_mensajes'
     AND INDEX_NAME   = 'idx_tgmsg_estado_enviado'
);
SET @sql := IF(@existe = 0,
  'ALTER TABLE `telegram_mensajes`
     ADD INDEX `idx_tgmsg_estado_enviado`(`estado`, `enviado`) USING BTREE',
  'SELECT "indice idx_tgmsg_estado_enviado ya existe" AS nota'
);
PREPARE st FROM @sql; EXECUTE st; DEALLOCATE PREPARE st;
