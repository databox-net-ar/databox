-- Crea `datarocket_contactos_etiquetas` — tabla puente N:N entre contactos y
-- etiquetas de Datarocket. Reemplaza al campo `datarocket_contactos.tags` que
-- hasta hoy guardaba los nombres de etiqueta en un VARCHAR(500) con formato
-- `(expo)(visitante)(tucuman)`. Ese formato viola 1FN: no permite indexar,
-- joinear ni filtrar por etiqueta, y las consultas terminan en `LIKE '%(...)%'`
-- (lento y fragil). Ademas quedaban strings huerfanos cuando alguien
-- renombraba una etiqueta en el catalogo.
--
-- Diferencia clave vs 20260811_1400 (bridge contactos <-> listas): alli la
-- VARCHAR guardaba IDs `(124)(125)`; aca guarda NOMBRES `(expo)(visitante)`.
-- Por eso el CTE recursivo extrae texto y despues hace INNER JOIN contra
-- `datarocket_etiquetas.nombre` (UNIQUE) para resolver a `etiqueta_id`. Los
-- nombres pueden contener espacios (p.ej. `mar del plata`) — no rompe porque
-- los unicos delimitadores son `(` y `)`, no whitespace.
--
-- Esta migracion:
--   1) Crea la tabla puente con PK compuesta (contacto_id, etiqueta_id) e
--      indice en `etiqueta_id` para el join inverso ("contactos con esta
--      etiqueta"). FKs con CASCADE en ambas direcciones.
--   2) Puebla la tabla parseando `datarocket_contactos.tags` con un CTE
--      recursivo que va cortando de a un `(nombre)` por iteracion; INNER
--      JOIN contra `datarocket_etiquetas` descarta silenciosamente cualquier
--      nombre que no exista en el catalogo (tags historicos huerfanos).
--      `INSERT IGNORE` tolera duplicados dentro del mismo string.
--
-- El VARCHAR original `datarocket_contactos.tags` NO se elimina en esta
-- migracion — queda como fallback historico hasta que el codigo migre a
-- leer/escribir contra la tabla puente. Un DROP COLUMN posterior lo limpia
-- (mismo camino que ya hicimos para `listas` en las migraciones 1400/1500).
--
-- Idempotente: `CREATE TABLE IF NOT EXISTS` + `INSERT IGNORE`. Correr dos
-- veces no duplica filas ni rompe FKs. Compatible con MySQL 8.0 (dev) y
-- MariaDB 10.11 (prod) — ambas soportan CTEs recursivos.

CREATE TABLE IF NOT EXISTS `datarocket_contactos_etiquetas` (
  `contacto_id`     int(11)  NOT NULL,
  `etiqueta_id`     int(11)  NOT NULL,
  `fecha_creacion`  datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`contacto_id`, `etiqueta_id`) USING BTREE,
  INDEX `idx_dce_etiqueta` (`etiqueta_id`) USING BTREE,
  CONSTRAINT `fk_dce_contacto` FOREIGN KEY (`contacto_id`)
      REFERENCES `datarocket_contactos` (`id`) ON DELETE CASCADE ON UPDATE RESTRICT,
  CONSTRAINT `fk_dce_etiqueta` FOREIGN KEY (`etiqueta_id`)
      REFERENCES `datarocket_etiquetas` (`id`) ON DELETE CASCADE ON UPDATE RESTRICT
) ENGINE = InnoDB CHARACTER SET = utf8mb4 COLLATE = utf8mb4_general_ci ROW_FORMAT = Dynamic;

INSERT IGNORE INTO `datarocket_contactos_etiquetas` (`contacto_id`, `etiqueta_id`)
WITH RECURSIVE
  parsed AS (
    SELECT
      `id`   AS contacto_id,
      `tags` AS resto
    FROM `datarocket_contactos`
    WHERE `tags` IS NOT NULL
      AND `tags` REGEXP '\\([^()]+\\)'
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
      SUBSTRING(
        resto,
        LOCATE('(', resto) + 1,
        LOCATE(')', resto) - LOCATE('(', resto) - 1
      ) AS etiqueta_nombre
    FROM parsed
    WHERE LOCATE('(', resto) > 0
      AND LOCATE(')', resto) > LOCATE('(', resto)
  )
SELECT e.contacto_id, de.id
FROM extraidos e
INNER JOIN `datarocket_etiquetas` de ON de.`nombre` = e.etiqueta_nombre;
