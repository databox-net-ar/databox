-- Tablas `tareas` y `tareas_ejecuciones`: catalogo del "Programador de tareas"
-- del modulo Herramientas y su historial de corridas.
-- Idempotente: CREATE TABLE IF NOT EXISTS permite que la migracion
-- corra tanto en entornos nuevos como en los ya inicializados.

CREATE TABLE IF NOT EXISTS `tareas` (
  `id`                 int(10) unsigned NOT NULL AUTO_INCREMENT,
  `nombre`             varchar(120)     NOT NULL,
  `descripcion`        varchar(255)     NULL DEFAULT NULL,
  `script`             varchar(255)     NOT NULL,
  `cron_expr`          varchar(80)      NOT NULL,
  `activo`             tinyint(1)       NOT NULL DEFAULT 1,
  `overlap`            enum('skip','allow') NOT NULL DEFAULT 'skip',
  `timeout_seg`        int(10) unsigned NOT NULL DEFAULT 300,
  `retencion_dias`     int(10) unsigned NOT NULL DEFAULT 7,
  `ultimo_run`         datetime         NULL DEFAULT NULL,
  `ultimo_estado`      enum('ok','error','timeout','killed','corriendo') NULL DEFAULT NULL,
  `ultimo_error`       text             NULL DEFAULT NULL,
  `fecha_creacion`     timestamp        NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `fecha_modificacion` timestamp        NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`) USING BTREE,
  UNIQUE KEY `uq_tareas_nombre` (`nombre`),
  KEY `idx_tareas_activo_ultimo_run` (`activo`, `ultimo_run`)
) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4 COLLATE = utf8mb4_unicode_ci ROW_FORMAT = Dynamic;

CREATE TABLE IF NOT EXISTS `tareas_ejecuciones` (
  `id`        int(10) unsigned NOT NULL AUTO_INCREMENT,
  `tarea_id`  int(10) unsigned NOT NULL,
  `pid`       int(10) unsigned NULL DEFAULT NULL,
  `inicio`    datetime         NOT NULL,
  `fin`       datetime         NULL DEFAULT NULL,
  `estado`    enum('corriendo','ok','error','timeout','killed') NOT NULL DEFAULT 'corriendo',
  `exit_code` int(11)          NULL DEFAULT NULL,
  `mensaje`   text             NULL DEFAULT NULL,
  `log_path`  varchar(255)     NULL DEFAULT NULL,
  `disparo`   enum('scheduler','manual') NOT NULL DEFAULT 'scheduler',
  PRIMARY KEY (`id`) USING BTREE,
  KEY `idx_tareas_ej_tarea_id` (`tarea_id`, `id`),
  KEY `idx_tareas_ej_estado`   (`estado`),
  KEY `idx_tareas_ej_inicio`   (`inicio`)
) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4 COLLATE = utf8mb4_unicode_ci ROW_FORMAT = Dynamic;
