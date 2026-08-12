-- Crea `datarocket_etapas` — columnas del kanban de cada embudo.
--
-- Cada fila representa una etapa dentro de un embudo (ver `datarocket_embudos`).
-- Un prospecto avanza por las etapas de su embudo hasta cerrarse en una etapa
-- terminal (`tipo = 'ganada' | 'perdida'`).
--
-- Campos:
--   * `embudo_id`     — FK a `datarocket_embudos`. ON DELETE CASCADE: si el
--                       embudo se borra, sus etapas se van con el (los
--                       prospectos apuntando a etapas de ese embudo bloquean
--                       la baja del embudo via el FK del prospecto).
--   * `nombre`        — texto de la columna del kanban.
--   * `orden`         — controla la posicion de la columna en el kanban.
--                       UNIQUE(embudo_id, orden) para forzar posiciones sin
--                       colisiones.
--   * `color`         — hex `#RRGGBB` de la cabecera de la columna.
--   * `tipo`          — clasifica la etapa:
--                         'activa'  — trabajo en curso (default)
--                         'ganada'  — cierre exitoso (deal ganado)
--                         'perdida' — cierre negativo
--                       Permite queries como "prospectos abiertos" via
--                       `WHERE etapa.tipo = 'activa'` sin hardcodear nombres.
--                       No usamos ENUM MySQL para tolerar futuros valores
--                       ('entregada', 'nurturing', etc.) sin migracion — la
--                       validacion vive en la capa PHP.
--   * `probabilidad`  — 0-100, para forecast opcional ("40 en Propuesta al
--                       75% => 30 esperados"). NULL = no aplica.
--
-- Seed: 6 etapas default en el embudo 1 (Captacion general):
--   1. Nuevo       — activa    prob=10  color=#6b7280 (gris)
--   2. Contactado  — activa    prob=25  color=#3b82f6 (azul)
--   3. Calificado  — activa    prob=50  color=#8b5cf6 (violeta)
--   4. Propuesta   — activa    prob=75  color=#f59e0b (amber)
--   5. Ganado      — ganada    prob=100 color=#22c55e (verde)
--   6. Perdido     — perdida   prob=0   color=#ef4444 (rojo)
--
-- El seed asume que la migracion 20260812_0200 corrio y dejo el embudo
-- default con nombre 'Captacion general' — se resuelve el id por lookup
-- (no se hardcodea 1) para tolerar que ese embudo tenga otro id si el
-- entorno ya tenia embudos previos.
--
-- Idempotente: `CREATE TABLE IF NOT EXISTS` + seed via NOT EXISTS por
-- (embudo_id, nombre). Compatible MySQL 8 + MariaDB 10.11.

CREATE TABLE IF NOT EXISTS `datarocket_etapas` (
  `id`                  int(11)      NOT NULL AUTO_INCREMENT,
  `embudo_id`           int(11)      NOT NULL,
  `nombre`              varchar(80)  NOT NULL,
  `orden`               int(11)      NOT NULL DEFAULT 0,
  `color`               varchar(7)   NULL DEFAULT NULL,
  `tipo`                varchar(20)  NOT NULL DEFAULT 'activa',
  `probabilidad`        tinyint(3) UNSIGNED NULL DEFAULT NULL,
  `fecha_creacion`      datetime     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `fecha_modificacion`  datetime     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`) USING BTREE,
  UNIQUE INDEX `uq_datarocket_etapas_embudo_orden`  (`embudo_id`, `orden`)  USING BTREE,
  UNIQUE INDEX `uq_datarocket_etapas_embudo_nombre` (`embudo_id`, `nombre`) USING BTREE,
  INDEX `idx_datarocket_etapas_embudo_tipo` (`embudo_id`, `tipo`) USING BTREE,
  CONSTRAINT `fk_datarocket_etapas_embudo` FOREIGN KEY (`embudo_id`)
      REFERENCES `datarocket_embudos` (`id`) ON DELETE CASCADE ON UPDATE RESTRICT
) ENGINE = InnoDB CHARACTER SET = utf8mb4 COLLATE = utf8mb4_general_ci ROW_FORMAT = Dynamic;

-- Seed de las 6 etapas default. Se usa NOT EXISTS por (embudo_id, nombre)
-- para no duplicar si la migracion ya corrio o si alguien las creo a mano.

INSERT INTO `datarocket_etapas` (`embudo_id`, `nombre`, `orden`, `color`, `tipo`, `probabilidad`)
SELECT e.id, s.nombre, s.orden, s.color, s.tipo, s.probabilidad
FROM `datarocket_embudos` e
CROSS JOIN (
  SELECT 'Nuevo'      AS nombre, 1 AS orden, '#6b7280' AS color, 'activa'  AS tipo, 10  AS probabilidad UNION ALL
  SELECT 'Contactado' AS nombre, 2 AS orden, '#3b82f6' AS color, 'activa'  AS tipo, 25  AS probabilidad UNION ALL
  SELECT 'Calificado' AS nombre, 3 AS orden, '#8b5cf6' AS color, 'activa'  AS tipo, 50  AS probabilidad UNION ALL
  SELECT 'Propuesta'  AS nombre, 4 AS orden, '#f59e0b' AS color, 'activa'  AS tipo, 75  AS probabilidad UNION ALL
  SELECT 'Ganado'     AS nombre, 5 AS orden, '#22c55e' AS color, 'ganada'  AS tipo, 100 AS probabilidad UNION ALL
  SELECT 'Perdido'    AS nombre, 6 AS orden, '#ef4444' AS color, 'perdida' AS tipo, 0   AS probabilidad
) s
WHERE e.nombre = 'Captacion general'
  AND NOT EXISTS (
    SELECT 1 FROM `datarocket_etapas` et
    WHERE et.embudo_id = e.id AND et.nombre = s.nombre
  );
