-- Carga en Datarocket los prospectos del ABM legacy `datasaleprospectos` que
-- todavia no tienen contacto. Por cada uno crea las 3 filas que le
-- corresponden: el CONTACTO, la OPORTUNIDAD y la INTERACCION de la consulta.
--
-- Por que hace falta: el backfill original (20260812_1100) corrio una sola vez
-- sobre la foto de ese momento. El ABM legacy sigue vivo y da de alta
-- prospectos nuevos, asi que la brecha se reabre sola. En dev al 2026-08-17
-- son 3 filas (una de 2021 que se escapo de aquel backfill y dos altas
-- posteriores al 12/08). En prod van a ser otras — la migracion las descubre,
-- no las tiene hardcodeadas, justamente para que sirva en los dos entornos.
--
-- ALCANCE — igual que el backfill original:
--   * Solo proyectos productivos: Vigicom (102), Vigia (103), Reactor (104),
--     Causam (109).
--   * Solo prospectos CONTACTABLES (con correo o con celular). Los que no
--     tienen ninguno de los dos no se pueden deduplicar ni contactar, y en su
--     momento fue decision explicita descartarlos. Siguen intactos en el
--     legacy, esta migracion los ignora.
--   * "Ya cargado" = existe un contacto que cumple CUALQUIERA de estas tres:
--       a) mismo correo normalizado (LOWER+TRIM);
--       b) mismo telefono normalizado (solo digitos, contra `celular` y
--          `whatsapp`);
--       c) mismos ULTIMOS 8 DIGITOS del telefono Y mismo nombre normalizado;
--       d) mismo nombre normalizado Y `registrado` del contacto EXACTAMENTE
--          igual al `ingreso` del prospecto.
--     (a) y (b) son el criterio del 20260812_1100. (c) y (d) se suman porque
--     aquel backfill reescribio los telefonos al pasarlos a
--     `datarocket_contactos` (saco el 0 inicial, el 15 intercalado, el prefijo
--     +549) mientras el legacy conserva el formato viejo: sin ellas, 4
--     personas de dev que YA estan cargadas se volverian a crear duplicadas.
--     (c) cubre los reformateos que preservan la cola del numero; (d) los que
--     no, como sacar el 15 del medio (264-15-5678329 -> 2645678329), donde ni
--     los ultimos 8 digitos coinciden. (d) es fiable porque el backfill
--     original puso `registrado` = ingreso del prospecto: mismo nombre y mismo
--     segundo de alta es la firma de una fila que salio de ese prospecto.
--     Las dos exigen el nombre para no descartar por colision a alguien que de
--     verdad falta — ante la duda preferimos duplicar un contacto (se fusiona)
--     antes que perder un lead (no se recupera).
--
-- NO TOCA NADA LEGACY: `datasaleprospectos` se lee y no se modifica. El ABM
-- legacy /prospectos sigue funcionando igual.
--
-- Idempotente: la segunda corrida no encuentra faltantes (los contactos recien
-- creados ya matchean) y no inserta nada. Los INSERT de oportunidad e
-- interaccion ademas se apoyan en marcas de alta (`@max_*`) tomadas dentro de
-- la misma corrida.
--
-- Compatible MySQL 8 (dev) + MariaDB 10.11 (prod): REGEXP_REPLACE, funciones
-- de ventana (>= 10.2), TEMPORARY TABLE, INSERT IGNORE y `<=>`. Sin funciones
-- almacenadas — prod (RDS) las rechaza con error 1419.
--
-- Nota de performance: cada tabla temporal se referencia UNA sola vez por
-- sentencia. MySQL no permite reabrir una TEMPORARY table dentro de la misma
-- query ("Can't reopen table"), de ahi que haya tantos pasos intermedios.


-- ===========================================================================
-- Paso 1: los prospectos legacy en alcance, ya normalizados.
-- ===========================================================================
DROP TEMPORARY TABLE IF EXISTS `tmp_dsp`;
CREATE TEMPORARY TABLE `tmp_dsp` (
  `id`           INT NOT NULL PRIMARY KEY,
  `proyecto`     INT          NULL,
  `ingreso`      DATETIME     NULL,
  `sentido`      VARCHAR(1)   NULL,
  `origen`       VARCHAR(10)  NULL,
  `producto`     VARCHAR(100) NULL,
  `asunto`       VARCHAR(255) NULL,
  `organizacion` VARCHAR(255) NULL,
  `quien`        VARCHAR(255) NULL,
  `celular`      VARCHAR(255) NULL,
  `correo`       VARCHAR(255) NULL,
  `web`          VARCHAR(255) NULL,
  `domicilio`    VARCHAR(255) NULL,
  `ciudad`       VARCHAR(255) NULL,
  `provincia_id` INT          NULL,
  `pais_id`      INT          NULL,
  `ubicacion`    VARCHAR(255) NULL,
  `calificacion` INT          NULL,
  `estado`       TINYINT      NULL,
  `asignado`     INT          NULL,
  `atendido`     INT          NULL,
  `actualizado`  DATETIME     NULL,
  `aplazado`     DATETIME     NULL,
  `comentarios`  VARCHAR(1000) NULL,
  `correo_n`     VARCHAR(255) NOT NULL,
  `tel_n`        VARCHAR(64)  NOT NULL,
  `tel8`         VARCHAR(8)   NOT NULL,
  `nombre_n`     VARCHAR(255) NOT NULL,
  KEY (`correo_n`),
  KEY (`tel_n`),
  KEY (`tel8`, `nombre_n`)
) ENGINE = InnoDB CHARACTER SET = utf8mb4 COLLATE = utf8mb4_general_ci;

INSERT INTO `tmp_dsp`
SELECT `id`, `proyecto`, `ingreso`, `sentido`, `origen`, `producto`, `asunto`,
       `organizacion`,
       COALESCE(NULLIF(TRIM(`contacto`), ''), TRIM(`nombre`)),
       `celular`, `correo`, `web`, `domicilio`, `ciudad`,
       -- El legacy guarda ids numericos como texto en `provincia` / `pais`.
       -- El CAST va DENTRO del CASE a proposito: en el ON de un JOIN MySQL
       -- puede evaluarlo aunque la guarda REGEXP no matchee, y castear '' a
       -- UNSIGNED aborta con error 1292 en modo estricto.
       CASE WHEN TRIM(COALESCE(`provincia`, '')) REGEXP '^[0-9]+$'
            THEN CAST(TRIM(`provincia`) AS UNSIGNED) END,
       CASE WHEN TRIM(COALESCE(`pais`, '')) REGEXP '^[0-9]+$'
            THEN CAST(TRIM(`pais`) AS UNSIGNED) END,
       `ubicacion`, `calificacion`, `estado`, `asignado`, `atendido`,
       `actualizado`, `aplazado`, `comentarios`,
       LOWER(TRIM(COALESCE(`correo`, ''))),
       REGEXP_REPLACE(COALESCE(`celular`, ''), '[^0-9]', ''),
       IF(CHAR_LENGTH(REGEXP_REPLACE(COALESCE(`celular`, ''), '[^0-9]', '')) >= 8,
          RIGHT(REGEXP_REPLACE(COALESCE(`celular`, ''), '[^0-9]', ''), 8), ''),
       LOWER(TRIM(COALESCE(NULLIF(TRIM(`contacto`), ''), `nombre`, '')))
  FROM `datasaleprospectos`
 WHERE `proyecto` IN (102, 103, 104, 109)
   AND (TRIM(COALESCE(`correo`, '')) <> ''
     OR REGEXP_REPLACE(COALESCE(`celular`, ''), '[^0-9]', '') <> '');


-- ===========================================================================
-- Paso 2: indice de identidades ya presentes en `datarocket_contactos`.
-- ===========================================================================
DROP TEMPORARY TABLE IF EXISTS `tmp_ct_correo`;
CREATE TEMPORARY TABLE `tmp_ct_correo` (
  `correo_n` VARCHAR(255) NOT NULL, UNIQUE KEY (`correo_n`)
) ENGINE = InnoDB CHARACTER SET = utf8mb4 COLLATE = utf8mb4_general_ci;

INSERT IGNORE INTO `tmp_ct_correo`
SELECT LOWER(TRIM(`correo`)) FROM `datarocket_contactos`
 WHERE `correo` IS NOT NULL AND TRIM(`correo`) <> '';

DROP TEMPORARY TABLE IF EXISTS `tmp_ct_tel`;
CREATE TEMPORARY TABLE `tmp_ct_tel` (
  `tel_n` VARCHAR(64) NOT NULL, UNIQUE KEY (`tel_n`)
) ENGINE = InnoDB CHARACTER SET = utf8mb4 COLLATE = utf8mb4_general_ci;

-- `celular` y `whatsapp` por separado: desde la 20260817_2200 suelen ser
-- iguales, pero un contacto puede tener solo uno de los dos.
INSERT IGNORE INTO `tmp_ct_tel`
SELECT REGEXP_REPLACE(`celular`, '[^0-9]', '') FROM `datarocket_contactos`
 WHERE REGEXP_REPLACE(COALESCE(`celular`, ''), '[^0-9]', '') <> '';

INSERT IGNORE INTO `tmp_ct_tel`
SELECT REGEXP_REPLACE(`whatsapp`, '[^0-9]', '') FROM `datarocket_contactos`
 WHERE REGEXP_REPLACE(COALESCE(`whatsapp`, ''), '[^0-9]', '') <> '';

-- Indice laxo (ultimos 8 digitos + nombre) para la regla (c) del encabezado:
-- reconoce a los que ya estan cargados pero con el telefono reformateado.
DROP TEMPORARY TABLE IF EXISTS `tmp_ct_tel8`;
CREATE TEMPORARY TABLE `tmp_ct_tel8` (
  `tel8`     VARCHAR(8)   NOT NULL,
  `nombre_n` VARCHAR(255) NOT NULL,
  UNIQUE KEY (`tel8`, `nombre_n`)
) ENGINE = InnoDB CHARACTER SET = utf8mb4 COLLATE = utf8mb4_general_ci;

INSERT IGNORE INTO `tmp_ct_tel8`
SELECT RIGHT(REGEXP_REPLACE(`celular`, '[^0-9]', ''), 8), LOWER(TRIM(`nombre`))
  FROM `datarocket_contactos`
 WHERE CHAR_LENGTH(REGEXP_REPLACE(COALESCE(`celular`, ''), '[^0-9]', '')) >= 8
   AND TRIM(COALESCE(`nombre`, '')) <> '';

INSERT IGNORE INTO `tmp_ct_tel8`
SELECT RIGHT(REGEXP_REPLACE(`whatsapp`, '[^0-9]', ''), 8), LOWER(TRIM(`nombre`))
  FROM `datarocket_contactos`
 WHERE CHAR_LENGTH(REGEXP_REPLACE(COALESCE(`whatsapp`, ''), '[^0-9]', '')) >= 8
   AND TRIM(COALESCE(`nombre`, '')) <> '';

-- Indice nombre + fecha de alta para la regla (d): la firma que dejo el
-- backfill original al crear el contacto desde el prospecto.
DROP TEMPORARY TABLE IF EXISTS `tmp_ct_nombre_reg`;
CREATE TEMPORARY TABLE `tmp_ct_nombre_reg` (
  `nombre_n`   VARCHAR(255) NOT NULL,
  `registrado` DATETIME     NOT NULL,
  UNIQUE KEY (`nombre_n`, `registrado`)
) ENGINE = InnoDB CHARACTER SET = utf8mb4 COLLATE = utf8mb4_general_ci;

INSERT IGNORE INTO `tmp_ct_nombre_reg`
SELECT LOWER(TRIM(`nombre`)), `registrado` FROM `datarocket_contactos`
 WHERE TRIM(COALESCE(`nombre`, '')) <> '' AND `registrado` IS NOT NULL;


-- ===========================================================================
-- Paso 3: los faltantes = en alcance y sin match por correo ni por telefono.
-- ===========================================================================
DROP TEMPORARY TABLE IF EXISTS `tmp_falta`;
CREATE TEMPORARY TABLE `tmp_falta` LIKE `tmp_dsp`;

INSERT INTO `tmp_falta`
SELECT `d`.* FROM `tmp_dsp` `d`
  LEFT JOIN `tmp_ct_correo` `c`  ON `d`.`correo_n` <> '' AND `c`.`correo_n` = `d`.`correo_n`
  LEFT JOIN `tmp_ct_tel`    `t`  ON `d`.`tel_n`    <> '' AND `t`.`tel_n`    = `d`.`tel_n`
  LEFT JOIN `tmp_ct_tel8`   `t8` ON `d`.`tel8` <> '' AND `d`.`nombre_n` <> ''
                                AND `t8`.`tel8`     = `d`.`tel8`
                                AND `t8`.`nombre_n` = `d`.`nombre_n`
  LEFT JOIN `tmp_ct_nombre_reg` `nr` ON `d`.`nombre_n` <> '' AND `d`.`ingreso` IS NOT NULL
                                   AND `nr`.`nombre_n`   = `d`.`nombre_n`
                                   AND `nr`.`registrado` = `d`.`ingreso`
 WHERE `c`.`correo_n` IS NULL AND `t`.`tel_n` IS NULL
   AND `t8`.`tel8` IS NULL AND `nr`.`nombre_n` IS NULL;


-- ===========================================================================
-- Paso 4: un contacto por HUMANO, no por prospecto.
-- Dedup key = correo normalizado, o 'cel_<digitos>' si no hay correo. El seed
-- (la fila que aporta nombre / empresa / domicilio) es la de menor id, y
-- `registrado` sale del ingreso mas viejo del grupo. Mismo criterio que el
-- paso 4 del 20260812_1100.
-- ===========================================================================
DROP TEMPORARY TABLE IF EXISTS `tmp_seed`;
CREATE TEMPORARY TABLE `tmp_seed` (
  `dedup_key`  VARCHAR(255) NOT NULL PRIMARY KEY,
  `seed_id`    INT          NOT NULL,
  `registrado` DATETIME     NULL
) ENGINE = InnoDB CHARACTER SET = utf8mb4 COLLATE = utf8mb4_general_ci;

INSERT INTO `tmp_seed` (`dedup_key`, `seed_id`, `registrado`)
SELECT COALESCE(NULLIF(`correo_n`, ''), CONCAT('cel_', `tel_n`)),
       MIN(`id`), MIN(`ingreso`)
  FROM `tmp_falta`
 GROUP BY COALESCE(NULLIF(`correo_n`, ''), CONCAT('cel_', `tel_n`));


-- ===========================================================================
-- Paso 5: alta de los contactos.
--
--   tipo         'persona' — misma decision que el backfill original.
--   nombre       `contacto` del legacy (el nombre limpio del humano) y como
--                fallback `nombre` (que trae el formato compuesto
--                "Empresa - Persona").
--   empresa_..   `organizacion`.
--   provincia_id / pais_id  el legacy ya guarda ids numericos ahi; se validan
--                contra `provincias` / `paises` y si no matchean quedan NULL.
--                La guarda REGEXP evita castear texto libre a 0.
--   whatsapp     = celular (convencion fijada en la 20260817_2200).
-- ===========================================================================
SET @max_contacto := (SELECT COALESCE(MAX(`id`), 0) FROM `datarocket_contactos`);

INSERT INTO `datarocket_contactos`
       (`uuid`, `tipo`, `nombre`, `empresa_nombre`, `domicilio`, `ciudad`,
        `ubicacion`, `provincia_id`, `pais_id`, `celular`, `whatsapp`,
        `correo`, `web`, `registrado`)
SELECT UUID(), 'persona',
       NULLIF(TRIM(`f`.`quien`), ''),
       NULLIF(TRIM(`f`.`organizacion`), ''),
       NULLIF(TRIM(`f`.`domicilio`), ''),
       NULLIF(TRIM(`f`.`ciudad`), ''),
       NULLIF(TRIM(`f`.`ubicacion`), ''),
       `pr`.`id`, `pa`.`id`,
       NULLIF(TRIM(`f`.`celular`), ''),
       NULLIF(TRIM(`f`.`celular`), ''),
       NULLIF(TRIM(`f`.`correo`), ''),
       NULLIF(TRIM(`f`.`web`), ''),
       `s`.`registrado`
  FROM `tmp_seed` `s`
  JOIN `tmp_falta` `f` ON `f`.`id` = `s`.`seed_id`
  LEFT JOIN `provincias` `pr` ON `pr`.`id` = `f`.`provincia_id`
  LEFT JOIN `paises`     `pa` ON `pa`.`id` = `f`.`pais_id`;


-- ===========================================================================
-- Paso 6: cada prospecto faltante apunta a su contacto recien creado.
-- Dos pasadas (correo, despues telefono) en vez de un JOIN con OR, que
-- duplicaria filas cuando matchean los dos.
-- ===========================================================================
DROP TEMPORARY TABLE IF EXISTS `tmp_link`;
CREATE TEMPORARY TABLE `tmp_link` (
  `dsp_id`      INT NOT NULL PRIMARY KEY,
  `contacto_id` INT NULL,
  `ingreso`     DATETIME NULL,
  KEY (`contacto_id`)
) ENGINE = InnoDB CHARACTER SET = utf8mb4 COLLATE = utf8mb4_general_ci;

INSERT INTO `tmp_link` (`dsp_id`, `contacto_id`, `ingreso`)
SELECT `id`, NULL, `ingreso` FROM `tmp_falta`;

DROP TEMPORARY TABLE IF EXISTS `tmp_falta_b`;
CREATE TEMPORARY TABLE `tmp_falta_b` LIKE `tmp_dsp`;
INSERT INTO `tmp_falta_b` SELECT * FROM `tmp_falta`;

UPDATE `tmp_link` `l`
  JOIN `tmp_falta_b` `f` ON `f`.`id` = `l`.`dsp_id` AND `f`.`correo_n` <> ''
  JOIN `datarocket_contactos` `c` ON LOWER(TRIM(`c`.`correo`)) = `f`.`correo_n`
                                 AND `c`.`id` > @max_contacto
   SET `l`.`contacto_id` = `c`.`id`
 WHERE `l`.`contacto_id` IS NULL;

DROP TEMPORARY TABLE IF EXISTS `tmp_falta_c`;
CREATE TEMPORARY TABLE `tmp_falta_c` LIKE `tmp_dsp`;
INSERT INTO `tmp_falta_c` SELECT * FROM `tmp_falta`;

UPDATE `tmp_link` `l`
  JOIN `tmp_falta_c` `f` ON `f`.`id` = `l`.`dsp_id` AND `f`.`tel_n` <> ''
  JOIN `datarocket_contactos` `c`
    ON REGEXP_REPLACE(COALESCE(`c`.`celular`, ''), '[^0-9]', '') = `f`.`tel_n`
   AND `c`.`id` > @max_contacto
   SET `l`.`contacto_id` = `c`.`id`
 WHERE `l`.`contacto_id` IS NULL;


-- ===========================================================================
-- Paso 7: embudo destino por proyecto.
-- Prioridad 1: el embudo que ya usan las oportunidades existentes de ese
-- proyecto (asi las nuevas caen donde estan las demas). Prioridad 2, para
-- proyectos sin oportunidades previas: el menor embudo activo del proyecto.
-- ===========================================================================
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


-- ===========================================================================
-- Paso 8: etapa destino, mapeada desde el `estado` tinyint del legacy.
--   estado 1 (esperando)  -> Nuevo
--   estado 2 (atendido)   -> Contactado
--   estado 3 (despachado) -> Ganado
-- Mismo mapeo que la 20260812_0400.
-- ===========================================================================
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


-- ===========================================================================
-- Paso 9: alta de las oportunidades.
--
--   comentarios   asunto + salto de linea + comentarios, la fusion que fijo la
--                 20260817_1600 (el legacy todavia tiene `asunto` separado).
--   etapa_ingreso COALESCE(actualizado, ingreso, NOW()) — igual que la 0400.
--   asignado /    LEFT JOIN contra `usuarios`: el legacy usa 0 como "nadie" y
--   atendido      puede referenciar usuarios que ya no existen. Las FK son
--                 RESTRICT, un id colgado abortaria la migracion entera.
--   monto/moneda  NULL / default 'ARS': el legacy no maneja importes.
-- ===========================================================================
SET @max_oportunidad := (SELECT COALESCE(MAX(`id`), 0) FROM `datarocket_oportunidades`);

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
  FROM `tmp_link` `l`
  JOIN `tmp_dsp`  `f`  ON `f`.`id` = `l`.`dsp_id`
  LEFT JOIN `proyectos`  `p`  ON `p`.`id` = `f`.`proyecto`
  LEFT JOIN `tmp_embudo` `em` ON `em`.`proyecto_id` = `f`.`proyecto`
  LEFT JOIN `tmp_etapa`  `te` ON `te`.`embudo_id` = `em`.`embudo_id`
                             AND `te`.`nombre` = CASE `f`.`estado`
                                                   WHEN 2 THEN 'Contactado'
                                                   WHEN 3 THEN 'Ganado'
                                                   ELSE        'Nuevo' END
  LEFT JOIN `usuarios` `ua` ON `ua`.`id` = NULLIF(`f`.`asignado`, 0)
  LEFT JOIN `usuarios` `ub` ON `ub`.`id` = NULLIF(`f`.`atendido`, 0)
 WHERE `l`.`contacto_id` IS NOT NULL;


-- ===========================================================================
-- Paso 10: la interaccion de la consulta.
--
--   sentido   `E` del legacy -> 'entrante' (la persona nos escribio); el resto
--             -> 'interna' (prospeccion saliente o sentido desconocido, el
--             texto es una anotacion nuestra). Mismo criterio que la
--             20260816_1100 + el mapeo tipo->sentido de la 20260817_1000.
--   canal     'web' para las entrantes (equivalente de 'consulta_recibida'),
--             NULL para las internas. No se adivina el canal real desde el
--             texto libre.
--   asunto    el `asunto` legacy si lo hay; si no, los primeros 200 caracteres
--             del cuerpo con los saltos aplanados.
--   respondida se aplica la regla de la 20260817_1400 (= etapa_ingreso) SALVO
--             para las que quedan en etapa 'Nuevo': una consulta que sigue sin
--             trabajar esta pendiente por definicion, y marcarla respondida
--             ensuciaria la tarjeta "pendientes" del ABM.
--
-- Solo se crea si hay texto: una oportunidad sin comentarios ni asunto no
-- tiene consulta que registrar.
-- ===========================================================================
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
       -- El `asunto` legacy solo; el cuerpo aplanado es el fallback. Se lee de
       -- `tmp_dsp` y no de la oportunidad porque ahi ya viene fusionado dentro
       -- de `comentarios` (20260817_1600) y quedaria duplicado en la etiqueta.
       LEFT(TRIM(REPLACE(REPLACE(
              COALESCE(NULLIF(TRIM(`f`.`asunto`), ''), `o`.`comentarios`),
            '\r', ' '), '\n', ' ')), 200),
       `o`.`comentarios`
  FROM `datarocket_oportunidades` `o`
  -- Las oportunidades recien creadas se reconocen por `id > @max_oportunidad`
  -- y se atan a su prospecto por (contacto_id, ingreso). El GROUP BY es la red
  -- por si un mismo contacto tuviera dos prospectos faltantes con identico
  -- `ingreso`: se queda con uno y no duplica la interaccion.
  JOIN (SELECT `contacto_id`, `ingreso`, MIN(`dsp_id`) AS `dsp_id`
          FROM `tmp_link` WHERE `contacto_id` IS NOT NULL
         GROUP BY `contacto_id`, `ingreso`) `l`
    ON `l`.`contacto_id` = `o`.`contacto_id` AND `l`.`ingreso` <=> `o`.`ingreso`
  JOIN `tmp_dsp` `f` ON `f`.`id` = `l`.`dsp_id`
  LEFT JOIN `datarocket_etapas` `e` ON `e`.`id` = `o`.`etapa_id`
 WHERE `o`.`id` > @max_oportunidad
   AND `o`.`comentarios` IS NOT NULL
   AND TRIM(`o`.`comentarios`) <> '';


-- ===========================================================================
-- Paso 11: etiqueta del proyecto de origen sobre el contacto nuevo.
-- El vinculo proyecto <-> etiqueta es por nombre ('Vigicom', 'Vigia',
-- 'Reactor', 'Causam' existen en las dos tablas), no por id hardcodeado.
-- INSERT IGNORE contra la PK compuesta: un contacto que fue lead en 2
-- proyectos recibe 2 etiquetas, y repetir el mismo proyecto no duplica.
-- ===========================================================================
INSERT IGNORE INTO `datarocket_contactos_etiquetas` (`contacto_id`, `etiqueta_id`)
SELECT DISTINCT `o`.`contacto_id`, `et`.`id`
  FROM `datarocket_oportunidades` `o`
  JOIN `proyectos`            `p`  ON `p`.`id` = `o`.`proyecto_id`
  JOIN `datarocket_etiquetas` `et` ON `et`.`nombre` = `p`.`nombre`
 WHERE `o`.`id` > @max_oportunidad
   AND `o`.`contacto_id` IS NOT NULL;


-- ===========================================================================
-- Paso 12: resincronizar el contador denormalizado de etiquetados.
-- Misma logica que POST ?action=recalcular en api/datarocket_etiquetas.php.
-- ===========================================================================
UPDATE `datarocket_etiquetas` `e`
  LEFT JOIN (
        SELECT `etiqueta_id`, COUNT(*) AS `c`
          FROM `datarocket_contactos_etiquetas`
         GROUP BY `etiqueta_id`
  ) `x` ON `x`.`etiqueta_id` = `e`.`id`
   SET `e`.`etiquetados` = COALESCE(`x`.`c`, 0);


-- ===========================================================================
-- Limpieza de temporales (la sesion del Migrador las descartaria igual, pero
-- explicito es mejor).
-- ===========================================================================
DROP TEMPORARY TABLE IF EXISTS `tmp_dsp`;
DROP TEMPORARY TABLE IF EXISTS `tmp_ct_correo`;
DROP TEMPORARY TABLE IF EXISTS `tmp_ct_tel`;
DROP TEMPORARY TABLE IF EXISTS `tmp_ct_tel8`;
DROP TEMPORARY TABLE IF EXISTS `tmp_ct_nombre_reg`;
DROP TEMPORARY TABLE IF EXISTS `tmp_falta`;
DROP TEMPORARY TABLE IF EXISTS `tmp_falta_b`;
DROP TEMPORARY TABLE IF EXISTS `tmp_falta_c`;
DROP TEMPORARY TABLE IF EXISTS `tmp_seed`;
DROP TEMPORARY TABLE IF EXISTS `tmp_link`;
DROP TEMPORARY TABLE IF EXISTS `tmp_embudo`;
DROP TEMPORARY TABLE IF EXISTS `tmp_etapa`;
