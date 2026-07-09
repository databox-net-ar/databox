-- Tabla `openai_consumos`: snapshots del estado de cuenta de OpenAI.
-- Cada fila es una captura completa (KPIs globales + tabla por API key +
-- rangos + moneda) tomada al consultar la Admin API de OpenAI. Se guarda
-- como JSON en `datos` para permitir consultas historicas sin migraciones
-- por cada nueva metrica y para reconstruir la vista sin volver a golpear
-- la API.
-- La vista /openai lee el ultimo registro por `fecha` desc; el boton
-- "Refrescar" inserta uno nuevo con rate limit de 60s en el endpoint.
-- Idempotente: CREATE TABLE IF NOT EXISTS.

CREATE TABLE IF NOT EXISTS `openai_consumos` (
  `id`     int(11)  NOT NULL AUTO_INCREMENT,
  `fecha`  datetime NOT NULL,
  `datos`  json     NULL DEFAULT NULL,
  PRIMARY KEY (`id`) USING BTREE,
  KEY `idx_openai_consumos_fecha` (`fecha`) USING BTREE
) ENGINE = InnoDB CHARACTER SET = utf8mb4 COLLATE = utf8mb4_general_ci ROW_FORMAT = Dynamic;
