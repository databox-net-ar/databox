-- Crea `datarocket_contactos_listas` — tabla puente N:N entre contactos y
-- listas de Datarocket. Reemplaza al campo `datarocket_contactos.listas`
-- que hasta hoy guardaba los ids de lista en un VARCHAR(500) con formato
-- `(124)(125)(445)`. Ese formato viola 1FN: no permite indexar, joinear
-- ni filtrar por lista, y las consultas terminan en `LIKE '%(125)%'` —
-- lentas y fragiles.
--
-- Esta migracion:
--   1) Crea la tabla puente con PK compuesta (contacto_id, lista_id) e
--      indice en `lista_id` para el join inverso ("contactos de la lista X").
--   2) Puebla la tabla parseando `datarocket_contactos.listas` con un CTE
--      recursivo que va cortando de a un `(id)` por iteracion, y hace INNER
--      JOIN contra `datarocket_listas` para descartar ids huerfanos que
--      violarian la FK. `INSERT IGNORE` tolera duplicados dentro del mismo
--      string (p.ej. `(125)(125)`) contra la PK compuesta.
--
-- El VARCHAR original `datarocket_contactos.listas` NO se elimina en esta
-- migracion — queda como fallback historico hasta que el codigo migre a
-- leer/escribir contra la tabla puente. Un DROP COLUMN posterior lo limpia.
--
-- Idempotente: `CREATE TABLE IF NOT EXISTS` + `INSERT IGNORE`. Correr dos
-- veces no duplica filas ni rompe FKs. Compatible con MySQL 8.0 (dev) y
-- MariaDB 10.11 (prod) — ambas soportan CTEs recursivos.

CREATE TABLE IF NOT EXISTS `datarocket_contactos_listas` (
  `contacto_id`     int(11)  NOT NULL,
  `lista_id`        int(11)  NOT NULL,
  `fecha_creacion`  datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`contacto_id`, `lista_id`) USING BTREE,
  INDEX `idx_dcl_lista` (`lista_id`) USING BTREE,
  CONSTRAINT `fk_dcl_contacto` FOREIGN KEY (`contacto_id`)
      REFERENCES `datarocket_contactos` (`id`) ON DELETE CASCADE ON UPDATE RESTRICT,
  CONSTRAINT `fk_dcl_lista` FOREIGN KEY (`lista_id`)
      REFERENCES `datarocket_listas` (`id`) ON DELETE CASCADE ON UPDATE RESTRICT
) ENGINE = InnoDB CHARACTER SET = utf8mb4 COLLATE = utf8mb4_general_ci ROW_FORMAT = Dynamic;

INSERT IGNORE INTO `datarocket_contactos_listas` (`contacto_id`, `lista_id`)
WITH RECURSIVE
  parsed AS (
    SELECT
      `id`     AS contacto_id,
      `listas` AS resto
    FROM `datarocket_contactos`
    WHERE `listas` IS NOT NULL
      AND `listas` REGEXP '\\([0-9]+\\)'
    UNION ALL
    SELECT
      contacto_id,
      SUBSTRING(resto, LOCATE(')', resto) + 1)
    FROM parsed
    WHERE LOCATE('(', resto) > 0
      AND LOCATE(')', resto) > LOCATE('(', resto)
  ),
  extraidos AS (
    SELECT
      contacto_id,
      CAST(
        SUBSTRING(
          resto,
          LOCATE('(', resto) + 1,
          LOCATE(')', resto) - LOCATE('(', resto) - 1
        ) AS UNSIGNED
      ) AS lista_id
    FROM parsed
    WHERE LOCATE('(', resto) > 0
      AND LOCATE(')', resto) > LOCATE('(', resto)
  )
SELECT e.contacto_id, e.lista_id
FROM extraidos e
INNER JOIN `datarocket_listas` dl ON dl.`id` = e.lista_id;
