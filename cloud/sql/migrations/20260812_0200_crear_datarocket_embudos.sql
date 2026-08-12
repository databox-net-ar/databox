-- Crea `datarocket_embudos` — catalogo de pipelines de venta / captacion.
--
-- Un embudo agrupa un conjunto ordenado de etapas por las que pasa un
-- prospecto (ver `datarocket_etapas` en la migracion siguiente). Un mismo
-- CRM puede tener varios embudos si distintos productos/proyectos requieren
-- procesos comerciales distintos (p.ej. Reactor con demo tecnica, Vigia
-- con aprobacion legal). Se puede arrancar con un solo embudo default y
-- crecer despues sin migracion.
--
-- Campos:
--   * `nombre`      — visible en tabs del kanban y en el selector del ABM
--                     de prospectos. UNIQUE para evitar duplicados por
--                     tipeo distinto.
--   * `descripcion` — texto libre para el ABM.
--   * `color`       — hex `#RRGGBB` para diferenciar embudos visualmente
--                     cuando conviven varios.
--   * `orden`       — controla el ordenamiento en tabs / selector.
--   * `activo`      — soft-disable: un embudo desactivado no aparece en el
--                     selector para nuevos prospectos, pero sus prospectos
--                     historicos siguen visibles.
--
-- Seed: se inserta un embudo default 'Captacion general' (id preferente 1)
-- para que los prospectos historicos puedan hacer backfill contra el en la
-- migracion `20260812_0400_datarocket_prospectos_agregar_embudo_etapa.sql`.
-- Se usa `INSERT ... SELECT ... WHERE NOT EXISTS` para idempotencia.
--
-- Compatible MySQL 8 (dev) + MariaDB 10.11 (prod). Idempotente en los 2 pasos.

CREATE TABLE IF NOT EXISTS `datarocket_embudos` (
  `id`                  int(11)      NOT NULL AUTO_INCREMENT,
  `nombre`              varchar(80)  NOT NULL,
  `descripcion`         varchar(500) NULL DEFAULT NULL,
  `color`               varchar(7)   NULL DEFAULT NULL,
  `orden`               int(11)      NOT NULL DEFAULT 0,
  `activo`              tinyint(1)   NOT NULL DEFAULT 1,
  `fecha_creacion`      datetime     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `fecha_modificacion`  datetime     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`) USING BTREE,
  UNIQUE INDEX `uq_datarocket_embudos_nombre` (`nombre`) USING BTREE,
  INDEX `idx_datarocket_embudos_activo` (`activo`) USING BTREE,
  INDEX `idx_datarocket_embudos_orden`  (`orden`) USING BTREE
) ENGINE = InnoDB CHARACTER SET = utf8mb4 COLLATE = utf8mb4_general_ci ROW_FORMAT = Dynamic;

-- Seed: embudo default. Es el que van a heredar los ~1349 prospectos legacy.
INSERT INTO `datarocket_embudos` (`nombre`, `descripcion`, `color`, `orden`, `activo`)
SELECT 'Captacion general',
       'Embudo default para prospectos que llegan por cualquier canal.',
       '#3b82f6', 1, 1
FROM DUAL
WHERE NOT EXISTS (
  SELECT 1 FROM `datarocket_embudos` WHERE `nombre` = 'Captacion general'
);
