-- Rescate previo al borrado del modulo Datasale (migracion 20260817_2600).
-- Pasa a Datarocket los dos restos de `datasaleprospectos` /
-- `datasaleprospectoscomunicaciones` que ningun backfill anterior se llevo. Sin
-- esto, el DROP los destruye.
--
--   BLOQUE A — los prospectos INCONTACTABLES (sin correo NI celular). El
--   backfill 20260812_1100 los borro deliberadamente del lado Datarocket
--   porque no se pueden deduplicar ni contactar. En dev son 13, todos de
--   Vigicom y todos con estado 3. Decision del usuario (2026-08-17): cargarlos
--   igual antes de dropear, en vez de perderlos. Se cargan tal cual estan —
--   incluyendo tres filas basura ("1 -" / organizacion "1", ids 3581-3583) y
--   tres donde el NOMBRE es en realidad un telefono (2645011454, 2645499595,
--   2645240000). Vale la pena revisarlos a mano en el ABM despues.
--
--   BLOQUE B — las 25 filas de `datasaleprospectoscomunicaciones`: el log de
--   contactos que llevaba el equipo comercial (transcripciones de WhatsApp
--   pegadas a mano y notas de llamada, 2024-03 a 2024-05, sobre 18
--   prospectos). Nunca se migraron: el backfill de interacciones
--   (20260816_1100) leyo `comentarios` y `acciones` del prospecto, no esta
--   tabla. Se verifico que ninguno de los 25 textos esta hoy en
--   `datarocket_interacciones`.
--
-- Los ids del legacy se conservaron al importar
-- (`datasaleprospectos.id` = `datarocket_oportunidades.id`, verificado por
-- correo sobre una muestra), por eso el bloque B puede atar cada comunicacion
-- a su oportunidad directamente por id.
--
-- NO TOCA NADA LEGACY: las tres tablas datasale* se leen y no se modifican. El
-- borrado va aparte, en la 20260817_2600.
--
-- ORDEN: despues de la 20260817_2400 (que carga los contactables) y antes de
-- la 20260817_2600 (que dropea).
--
-- Idempotente en los dos bloques (ver las guardas en cada uno).
-- Compatible MySQL 8 (dev) + MariaDB 10.11 (prod).


-- ###########################################################################
-- BLOQUE A — prospectos incontactables
-- ###########################################################################

-- ---------------------------------------------------------------------------
-- A1) Los candidatos, normalizados.
-- ---------------------------------------------------------------------------
DROP TEMPORARY TABLE IF EXISTS `tmp_inc`;
CREATE TEMPORARY TABLE `tmp_inc` (
  `id`           INT NOT NULL PRIMARY KEY,
  `proyecto`     INT           NULL,
  `ingreso`      DATETIME      NULL,
  `sentido`      VARCHAR(1)    NULL,
  `origen`       VARCHAR(10)   NULL,
  `producto`     VARCHAR(100)  NULL,
  `asunto`       VARCHAR(255)  NULL,
  `organizacion` VARCHAR(255)  NULL,
  `quien`        VARCHAR(255)  NULL,
  `domicilio`    VARCHAR(255)  NULL,
  `ciudad`       VARCHAR(255)  NULL,
  `provincia_id` INT           NULL,
  `pais_id`      INT           NULL,
  `ubicacion`    VARCHAR(255)  NULL,
  `calificacion` INT           NULL,
  `estado`       TINYINT       NULL,
  `asignado`     INT           NULL,
  `atendido`     INT           NULL,
  `actualizado`  DATETIME      NULL,
  `aplazado`     DATETIME      NULL,
  `comentarios`  VARCHAR(1000) NULL,
  `nombre_n`     VARCHAR(255)  NOT NULL,
  KEY (`nombre_n`)
) ENGINE = InnoDB CHARACTER SET = utf8mb4 COLLATE = utf8mb4_general_ci;

INSERT INTO `tmp_inc`
SELECT `id`, `proyecto`, `ingreso`, `sentido`, `origen`, `producto`, `asunto`,
       `organizacion`,
       COALESCE(NULLIF(TRIM(`contacto`), ''), TRIM(`nombre`)),
       `domicilio`, `ciudad`,
       CASE WHEN TRIM(COALESCE(`provincia`, '')) REGEXP '^[0-9]+$'
            THEN CAST(TRIM(`provincia`) AS UNSIGNED) END,
       CASE WHEN TRIM(COALESCE(`pais`, '')) REGEXP '^[0-9]+$'
            THEN CAST(TRIM(`pais`) AS UNSIGNED) END,
       `ubicacion`, `calificacion`, `estado`, `asignado`, `atendido`,
       `actualizado`, `aplazado`, `comentarios`,
       LOWER(TRIM(COALESCE(NULLIF(TRIM(`contacto`), ''), `nombre`, '')))
  FROM `datasaleprospectos`
 WHERE `proyecto` IN (102, 103, 104, 109)
   AND TRIM(COALESCE(`correo`, '')) = ''
   AND REGEXP_REPLACE(COALESCE(`celular`, ''), '[^0-9]', '') = ''
   AND TRIM(COALESCE(COALESCE(NULLIF(TRIM(`contacto`), ''), `nombre`), '')) <> '';

-- ---------------------------------------------------------------------------
-- A2) Un contacto por nombre, no por prospecto: las tres filas "1 -"
--     comparten humano (ficticio) y no tiene sentido crear tres fichas.
--     `registrado` = ingreso mas viejo del grupo.
-- ---------------------------------------------------------------------------
DROP TEMPORARY TABLE IF EXISTS `tmp_inc_seed`;
CREATE TEMPORARY TABLE `tmp_inc_seed` (
  `nombre_n`   VARCHAR(255) NOT NULL PRIMARY KEY,
  `seed_id`    INT          NOT NULL,
  `registrado` DATETIME     NULL
) ENGINE = InnoDB CHARACTER SET = utf8mb4 COLLATE = utf8mb4_general_ci;

INSERT INTO `tmp_inc_seed` (`nombre_n`, `seed_id`, `registrado`)
SELECT `nombre_n`, MIN(`id`), MIN(`ingreso`) FROM `tmp_inc` GROUP BY `nombre_n`;

-- ---------------------------------------------------------------------------
-- A3) Guarda de idempotencia: sin correo ni telefono, la unica firma posible
--     es nombre + fecha de alta. Si ya existe un contacto con ese par, el
--     grupo entero se saltea (la segunda corrida no hace nada).
-- ---------------------------------------------------------------------------
DROP TEMPORARY TABLE IF EXISTS `tmp_inc_ya`;
CREATE TEMPORARY TABLE `tmp_inc_ya` (
  `nombre_n`   VARCHAR(255) NOT NULL,
  `registrado` DATETIME     NOT NULL,
  UNIQUE KEY (`nombre_n`, `registrado`)
) ENGINE = InnoDB CHARACTER SET = utf8mb4 COLLATE = utf8mb4_general_ci;

INSERT IGNORE INTO `tmp_inc_ya`
SELECT LOWER(TRIM(`nombre`)), `registrado` FROM `datarocket_contactos`
 WHERE TRIM(COALESCE(`nombre`, '')) <> '' AND `registrado` IS NOT NULL;

-- ---------------------------------------------------------------------------
-- A4) Alta de los contactos. Mismo mapeo que la 20260817_2400, sin las
--     columnas de contacto (que por definicion estan vacias).
-- ---------------------------------------------------------------------------
INSERT INTO `datarocket_contactos`
       (`uuid`, `tipo`, `nombre`, `empresa_nombre`, `domicilio`, `ciudad`,
        `ubicacion`, `provincia_id`, `pais_id`, `registrado`)
SELECT UUID(), 'persona',
       NULLIF(TRIM(`f`.`quien`), ''),
       NULLIF(TRIM(`f`.`organizacion`), ''),
       NULLIF(TRIM(`f`.`domicilio`), ''),
       NULLIF(TRIM(`f`.`ciudad`), ''),
       NULLIF(TRIM(`f`.`ubicacion`), ''),
       `pr`.`id`, `pa`.`id`, `s`.`registrado`
  FROM `tmp_inc_seed` `s`
  JOIN `tmp_inc` `f` ON `f`.`id` = `s`.`seed_id`
  LEFT JOIN `tmp_inc_ya` `y` ON `y`.`nombre_n` = `s`.`nombre_n`
                            AND `y`.`registrado` <=> `s`.`registrado`
  LEFT JOIN `provincias` `pr` ON `pr`.`id` = `f`.`provincia_id`
  LEFT JOIN `paises`     `pa` ON `pa`.`id` = `f`.`pais_id`
 WHERE `y`.`nombre_n` IS NULL;

-- ---------------------------------------------------------------------------
-- A5) Atar cada prospecto a su contacto (por nombre + fecha de alta del grupo).
-- ---------------------------------------------------------------------------
DROP TEMPORARY TABLE IF EXISTS `tmp_inc_link`;
CREATE TEMPORARY TABLE `tmp_inc_link` (
  `dsp_id`      INT NOT NULL PRIMARY KEY,
  `contacto_id` INT NOT NULL,
  KEY (`contacto_id`)
) ENGINE = InnoDB CHARACTER SET = utf8mb4 COLLATE = utf8mb4_general_ci;

INSERT INTO `tmp_inc_link` (`dsp_id`, `contacto_id`)
SELECT `f`.`id`, MIN(`c`.`id`)
  FROM `tmp_inc` `f`
  JOIN `tmp_inc_seed` `s` ON `s`.`nombre_n` = `f`.`nombre_n`
  JOIN `datarocket_contactos` `c` ON LOWER(TRIM(`c`.`nombre`)) = `s`.`nombre_n`
                                 AND `c`.`registrado` <=> `s`.`registrado`
 GROUP BY `f`.`id`;

-- ---------------------------------------------------------------------------
-- A6) Guarda de idempotencia de las oportunidades: el par (contacto, ingreso).
-- ---------------------------------------------------------------------------
DROP TEMPORARY TABLE IF EXISTS `tmp_opo_ya`;
CREATE TEMPORARY TABLE `tmp_opo_ya` (
  `contacto_id` INT NOT NULL,
  `ingreso`     DATETIME NULL,
  KEY (`contacto_id`, `ingreso`)
) ENGINE = InnoDB CHARACTER SET = utf8mb4 COLLATE = utf8mb4_general_ci;

INSERT INTO `tmp_opo_ya`
SELECT `contacto_id`, `ingreso` FROM `datarocket_oportunidades`
 WHERE `contacto_id` IS NOT NULL;

-- ---------------------------------------------------------------------------
-- A7) Embudo y etapa destino: mismas reglas que la 20260817_2400.
-- ---------------------------------------------------------------------------
DROP TEMPORARY TABLE IF EXISTS `tmp_embudo`;
CREATE TEMPORARY TABLE `tmp_embudo` (
  `proyecto_id` INT NOT NULL PRIMARY KEY,
  `embudo_id`   INT NOT NULL
) ENGINE = InnoDB CHARACTER SET = utf8mb4 COLLATE = utf8mb4_general_ci;

INSERT INTO `tmp_embudo` (`proyecto_id`, `embudo_id`)
SELECT `x`.`proyecto_id`, `x`.`embudo_id` FROM (
    SELECT `proyecto_id`, `embudo_id`,
           ROW_NUMBER() OVER (PARTITION BY `proyecto_id`
                              ORDER BY COUNT(*) DESC, `embudo_id` ASC) AS `rn`
      FROM `datarocket_oportunidades`
     WHERE `proyecto_id` IS NOT NULL AND `embudo_id` IS NOT NULL
     GROUP BY `proyecto_id`, `embudo_id`
) `x` WHERE `x`.`rn` = 1;

INSERT IGNORE INTO `tmp_embudo` (`proyecto_id`, `embudo_id`)
SELECT `proyecto_id`, MIN(`id`) FROM `datarocket_embudos`
 WHERE `activo` = 1 AND `proyecto_id` IS NOT NULL
 GROUP BY `proyecto_id`;

DROP TEMPORARY TABLE IF EXISTS `tmp_etapa`;
CREATE TEMPORARY TABLE `tmp_etapa` (
  `embudo_id` INT         NOT NULL,
  `nombre`    VARCHAR(80) NOT NULL,
  `etapa_id`  INT         NOT NULL,
  PRIMARY KEY (`embudo_id`, `nombre`)
) ENGINE = InnoDB CHARACTER SET = utf8mb4 COLLATE = utf8mb4_general_ci;

INSERT INTO `tmp_etapa` (`embudo_id`, `nombre`, `etapa_id`)
SELECT `embudo_id`, `nombre`, MIN(`id`) FROM `datarocket_etapas`
 WHERE `nombre` IN ('Nuevo', 'Contactado', 'Ganado')
 GROUP BY `embudo_id`, `nombre`;

-- ---------------------------------------------------------------------------
-- A8) Alta de las oportunidades.
-- ---------------------------------------------------------------------------
SET @max_opo_inc := (SELECT COALESCE(MAX(`id`), 0) FROM `datarocket_oportunidades`);

INSERT INTO `datarocket_oportunidades`
       (`contacto_id`, `ingreso`, `proyecto_id`, `sentido`, `origen`, `producto`,
        `calificacion`, `estado`, `embudo_id`, `etapa_id`, `etapa_ingreso`,
        `asignado`, `atendido`, `actualizado`, `aplazado`, `comentarios`)
SELECT `l`.`contacto_id`, `f`.`ingreso`, `p`.`id`,
       NULLIF(TRIM(COALESCE(`f`.`sentido`, '')), ''),
       NULLIF(TRIM(COALESCE(`f`.`origen`, '')), ''),
       NULLIF(TRIM(COALESCE(`f`.`producto`, '')), ''),
       `f`.`calificacion`, `f`.`estado`,
       `em`.`embudo_id`, `te`.`etapa_id`,
       COALESCE(`f`.`actualizado`, `f`.`ingreso`, NOW()),
       `ua`.`id`, `ub`.`id`, `f`.`actualizado`, `f`.`aplazado`,
       CASE
         WHEN TRIM(COALESCE(`f`.`asunto`, '')) = ''      THEN `f`.`comentarios`
         WHEN TRIM(COALESCE(`f`.`comentarios`, '')) = '' THEN TRIM(`f`.`asunto`)
         ELSE LEFT(CONCAT(TRIM(`f`.`asunto`), CHAR(10), `f`.`comentarios`), 1000)
       END
  FROM `tmp_inc_link` `l`
  JOIN `tmp_inc` `f` ON `f`.`id` = `l`.`dsp_id`
  LEFT JOIN `tmp_opo_ya`  `ya` ON `ya`.`contacto_id` = `l`.`contacto_id`
                              AND `ya`.`ingreso` <=> `f`.`ingreso`
  LEFT JOIN `proyectos`   `p`  ON `p`.`id` = `f`.`proyecto`
  LEFT JOIN `tmp_embudo`  `em` ON `em`.`proyecto_id` = `f`.`proyecto`
  LEFT JOIN `tmp_etapa`   `te` ON `te`.`embudo_id` = `em`.`embudo_id`
                              AND `te`.`nombre` = CASE `f`.`estado`
                                                    WHEN 2 THEN 'Contactado'
                                                    WHEN 3 THEN 'Ganado'
                                                    ELSE        'Nuevo' END
  LEFT JOIN `usuarios` `ua` ON `ua`.`id` = NULLIF(`f`.`asignado`, 0)
  LEFT JOIN `usuarios` `ub` ON `ub`.`id` = NULLIF(`f`.`atendido`, 0)
 WHERE `ya`.`contacto_id` IS NULL;

-- ---------------------------------------------------------------------------
-- A9) Interaccion de la consulta, solo para las que tienen texto.
--     Mismas reglas de sentido / canal / respondida que la 20260817_2400.
-- ---------------------------------------------------------------------------
INSERT INTO `datarocket_interacciones`
       (`fecha`, `contacto_id`, `oportunidad_id`, `sentido`, `canal`,
        `respondida`, `asunto`, `mensaje`)
SELECT COALESCE(`f`.`ingreso`, `f`.`actualizado`, `o`.`etapa_ingreso`, NOW()),
       `o`.`contacto_id`, `o`.`id`,
       IF(`f`.`sentido` = 'E', 'entrante', 'interna'),
       IF(`f`.`sentido` = 'E', 'web', NULL),
       CASE
         WHEN `f`.`sentido` <> 'E' OR `f`.`sentido` IS NULL          THEN NULL
         WHEN `e`.`nombre` IS NULL OR `e`.`nombre` = 'Nuevo'         THEN NULL
         WHEN `o`.`etapa_ingreso` IS NULL                            THEN NULL
         WHEN `o`.`etapa_ingreso` < COALESCE(`f`.`ingreso`, `o`.`etapa_ingreso`) THEN NULL
         ELSE `o`.`etapa_ingreso`
       END,
       LEFT(TRIM(REPLACE(REPLACE(
              COALESCE(NULLIF(TRIM(`f`.`asunto`), ''), `o`.`comentarios`),
            '\r', ' '), '\n', ' ')), 200),
       `o`.`comentarios`
  FROM `datarocket_oportunidades` `o`
  JOIN (SELECT `contacto_id`, MIN(`dsp_id`) AS `dsp_id`
          FROM `tmp_inc_link` GROUP BY `contacto_id`) `l`
    ON `l`.`contacto_id` = `o`.`contacto_id`
  JOIN `tmp_inc` `f` ON `f`.`id` = `l`.`dsp_id`
  LEFT JOIN `datarocket_etapas` `e` ON `e`.`id` = `o`.`etapa_id`
 WHERE `o`.`id` > @max_opo_inc
   AND `o`.`comentarios` IS NOT NULL
   AND TRIM(`o`.`comentarios`) <> '';

-- ---------------------------------------------------------------------------
-- A10) Etiqueta del proyecto de origen.
-- ---------------------------------------------------------------------------
INSERT IGNORE INTO `datarocket_contactos_etiquetas` (`contacto_id`, `etiqueta_id`)
SELECT DISTINCT `o`.`contacto_id`, `et`.`id`
  FROM `datarocket_oportunidades` `o`
  JOIN `proyectos`            `p`  ON `p`.`id` = `o`.`proyecto_id`
  JOIN `datarocket_etiquetas` `et` ON `et`.`nombre` = `p`.`nombre`
 WHERE `o`.`id` > @max_opo_inc
   AND `o`.`contacto_id` IS NOT NULL;


-- ###########################################################################
-- BLOQUE B — el log de comunicaciones
-- ###########################################################################
--
-- Mapeo:
--   fecha           cuando se registro la comunicacion.
--   oportunidad_id  = `prospecto` (los ids se conservaron en la importacion).
--   contacto_id     el de esa oportunidad.
--   sentido         'saliente' en las dos variantes: es el log de lo que HIZO
--                   el equipo comercial, no lo que entro. Las transcripciones
--                   arrancan siempre con nosotros escribiendo ("Vigia
--                   Clientes: Buenas tardes ...") aunque incluyan la respuesta.
--   canal           `medio` del legacy, catalogo `datafly_conserje_registro_medio`:
--                     M = Mensaje -> 'whatsapp' (las 20 filas son
--                         transcripciones de WhatsApp con el formato
--                         "[HH:MM, D/M/AAAA] Remitente: texto")
--                     L = Llamado -> 'telefono'
--   asunto          primeros 200 caracteres del detalle, saltos aplanados.
--   mensaje         el detalle completo.
--   respondida      NULL: es una saliente, no espera respuesta (el PUT del ABM
--                   solo acepta marcar respondidas las entrantes).
--
-- Se pierde `usuario` (quien lo cargo): `datarocket_interacciones` dropeo esa
-- columna en la 20260817_1200 y todas las filas existentes la tienen en NULL.
--
-- Idempotente por el par (oportunidad_id, fecha), que es unico en el origen.
-- ---------------------------------------------------------------------------
DROP TEMPORARY TABLE IF EXISTS `tmp_int_ya`;
CREATE TEMPORARY TABLE `tmp_int_ya` (
  `oportunidad_id` INT NOT NULL,
  `fecha`          DATETIME NOT NULL,
  KEY (`oportunidad_id`, `fecha`)
) ENGINE = InnoDB CHARACTER SET = utf8mb4 COLLATE = utf8mb4_general_ci;

INSERT INTO `tmp_int_ya`
SELECT `oportunidad_id`, `fecha` FROM `datarocket_interacciones`
 WHERE `oportunidad_id` IS NOT NULL;

INSERT INTO `datarocket_interacciones`
       (`fecha`, `contacto_id`, `oportunidad_id`, `sentido`, `canal`,
        `respondida`, `asunto`, `mensaje`)
SELECT `cm`.`fecha`, `o`.`contacto_id`, `o`.`id`,
       'saliente',
       CASE `cm`.`medio` WHEN 'M' THEN 'whatsapp'
                         WHEN 'L' THEN 'telefono' END,
       NULL,
       LEFT(TRIM(REPLACE(REPLACE(`cm`.`detalle`, '\r', ' '), '\n', ' ')), 200),
       `cm`.`detalle`
  FROM `datasaleprospectoscomunicaciones` `cm`
  JOIN `datarocket_oportunidades` `o` ON `o`.`id` = `cm`.`prospecto`
  LEFT JOIN `tmp_int_ya` `ya` ON `ya`.`oportunidad_id` = `o`.`id`
                             AND `ya`.`fecha` = `cm`.`fecha`
 WHERE `cm`.`fecha` IS NOT NULL
   AND `cm`.`detalle` IS NOT NULL
   AND TRIM(`cm`.`detalle`) <> ''
   AND `ya`.`oportunidad_id` IS NULL;


-- ---------------------------------------------------------------------------
-- Resincronizar el contador denormalizado de etiquetados.
-- ---------------------------------------------------------------------------
UPDATE `datarocket_etiquetas` `e`
  LEFT JOIN (
        SELECT `etiqueta_id`, COUNT(*) AS `c`
          FROM `datarocket_contactos_etiquetas`
         GROUP BY `etiqueta_id`
  ) `x` ON `x`.`etiqueta_id` = `e`.`id`
   SET `e`.`etiquetados` = COALESCE(`x`.`c`, 0);


DROP TEMPORARY TABLE IF EXISTS `tmp_inc`;
DROP TEMPORARY TABLE IF EXISTS `tmp_inc_seed`;
DROP TEMPORARY TABLE IF EXISTS `tmp_inc_ya`;
DROP TEMPORARY TABLE IF EXISTS `tmp_inc_link`;
DROP TEMPORARY TABLE IF EXISTS `tmp_opo_ya`;
DROP TEMPORARY TABLE IF EXISTS `tmp_int_ya`;
DROP TEMPORARY TABLE IF EXISTS `tmp_embudo`;
DROP TEMPORARY TABLE IF EXISTS `tmp_etapa`;
