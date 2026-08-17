-- Normaliza `datarocket_contactos`: telefono / celular / whatsapp a 10 digitos
-- argentinos y correo a minuscula validada.
--
-- Es el mismo criterio que aplica ahora cloud/api/lib/contactos_normalizar.php
-- sobre toda alta y modificacion (tanto en el ABM del panel como en el
-- microservicio v4); esta migracion pone al dia lo que ya estaba cargado.
--
-- ---------------------------------------------------------------------------
-- TELEFONOS
-- ---------------------------------------------------------------------------
-- Todo numero nacional argentino son 10 digitos: area (2 a 4) + abonado, sin
-- el 0 de larga distancia y sin el 15 de movil. Pasos, en orden:
--   1. se descarta todo lo que no sea digito (espacios, guiones, parentesis, +)
--   2. prefijo internacional `00`             -> se saca
--   3. prefijo nacional `0`                   -> se saca
--   4. codigo de pais `54`                    -> se saca
--   5. marcador de movil `9`                  -> se saca (largo 11 = 9 + los 10
--      del numero nacional; largo 13 = 9 + area + 15 + abonado, que es como
--      queda "+54 9 341 15-307-4305", con los dos marcadores de movil)
--   6. `15` intercalado entre area y abonado  -> se saca
--   7. numero de 10 que arranca con `15`      -> movil de CABA en formato local
--      (15-6780-5502): pasa a `11` + los 8 digitos del abonado. Ningun codigo
--      de area del pais arranca con 15, asi que no hay ambiguedad.
-- Si el campo trae dos numeros ("4769-4037 1556-144420", tipico de las fichas
-- viejas con fijo + movil en la misma celda) se prueba token por token y gana
-- el primero que de un numero valido.
--
-- Lo que NO llega a 10 digitos validos no se descarta: quedan los digitos
-- crudos. Son numeros extranjeros reales (+52, +1, +58), fijos de CABA
-- cargados sin el codigo de area, o fragmentos incompletos — anularlos seria
-- perder informacion que alguien puede querer corregir a mano.
--
-- ---------------------------------------------------------------------------
-- CORREOS
-- ---------------------------------------------------------------------------
-- Siempre minuscula y sin espacios alrededor. Si el campo trae una lista
-- ("ventas@x.com.ar, soporte@x.com.ar", comun en los contactos scrapeados de
-- sitios institucionales) se rescata la primera direccion valida. Lo que no
-- contiene ninguna direccion parseable va a NULL: es basura de scraping
-- ("[email protected]", "webmail", "naxos.ar", "0800-333-2400").
--
-- Para revisar cuales se pierden ANTES de aplicar, en el Explorador DB:
--
--   SELECT id, nombre, correo FROM datarocket_contactos
--    WHERE correo IS NOT NULL AND TRIM(correo) <> ''
--      AND LOWER(TRIM(correo)) NOT REGEXP '^[a-z0-9._%+-]+@[a-z0-9.-]+[.][a-z]{2,}$';
--
-- ---------------------------------------------------------------------------
-- Por que no hay funciones almacenadas
-- ---------------------------------------------------------------------------
-- La primera version de esta migracion armaba la cadena de pasos con funciones
-- `CREATE FUNCTION ... DETERMINISTIC` temporales. Funciona en el MySQL 8 de
-- desarrollo, pero **prod la rechaza**: la base es MariaDB 10.11 en RDS con
-- `log_bin = ON`, `log_bin_trust_function_creators = OFF` y el usuario `admin`
-- sin SUPER, asi que cualquier CREATE FUNCTION muere con el error 1419 — y
-- MariaDB no la deja pasar ni declarandola `DETERMINISTIC NO SQL`, ni se puede
-- setear la variable desde la sesion (hace falta SUPER o BINLOG ADMIN).
--
-- Por eso la cadena se resuelve con una tabla auxiliar `dr_ct_tmp` y un UPDATE
-- por paso: la tabla guarda, para cada fila a corregir, los candidatos a
-- numero (el campo entero + los primeros tokens separados por espacios) y cada
-- UPDATE aplica un paso del pipeline sobre todos los candidatos a la vez. Al
-- final gana el primer candidato que sea un nacional valido de 10 digitos, y
-- si no hay ninguno quedan los digitos crudos del original. La tabla se borra
-- al terminar: no queda nada en el esquema.
--
-- ---------------------------------------------------------------------------
-- Notas de compatibilidad y costo
-- ---------------------------------------------------------------------------
--  - REGEXP_REPLACE, REGEXP_SUBSTR (forma de 2 argumentos) y SUBSTRING_INDEX
--    existen en MariaDB 10.11 (prod) y en MySQL 8 (dev). No se usa la forma de
--    4 argumentos de REGEXP_SUBSTR porque MariaDB solo acepta 2.
--  - El Migrador corre bajo Apache con max_execution_time = 30. Por eso los
--    UPDATE pesados solo alcanzan a las filas que realmente hay que corregir:
--    lo que ya esta normalizado se saltea con un REGEXP barato en el WHERE.
--  - Los UPDATE son idempotentes: volver a correr la migracion sobre datos ya
--    normalizados no cambia nada.
--
-- Medido sobre la copia de los datos de produccion (43.240 filas):
--   telefono  14.966 no vacios -> 10.705 a 10 digitos, 4.090 crudos, 171 a NULL
--   celular   22.685 no vacios -> 22.290 a 10 digitos,   374 crudos,  21 a NULL
--   whatsapp   8.827 no vacios ->  8.709 a 10 digitos,   117 crudos,   1 a NULL
--   correo    33.788 no vacios -> 32.475 sin cambio,   1.087 corregidos, 226 a NULL

-- ---------------------------------------------------------------------------
-- 1. Lo trivial, con un REGEXP barato
-- ---------------------------------------------------------------------------
-- Un campo sin un solo digito no es un telefono: cubre los '' (default de la
-- columna) y los "no dispogo" / "Yxgzrn ghwq".

UPDATE `datarocket_contactos` SET `telefono` = NULL
 WHERE `telefono` IS NOT NULL AND `telefono` NOT REGEXP '[0-9]';
UPDATE `datarocket_contactos` SET `celular` = NULL
 WHERE `celular` IS NOT NULL AND `celular` NOT REGEXP '[0-9]';
UPDATE `datarocket_contactos` SET `whatsapp` = NULL
 WHERE `whatsapp` IS NOT NULL AND `whatsapp` NOT REGEXP '[0-9]';
UPDATE `datarocket_contactos` SET `correo` = NULL
 WHERE `correo` IS NOT NULL AND TRIM(`correo`) = '';

-- ---------------------------------------------------------------------------
-- 2. Correo
-- ---------------------------------------------------------------------------
-- No necesita la tabla auxiliar: bajar a minuscula y quedarse con la primera
-- direccion parseable es un solo REGEXP_SUBSTR.
--
-- El WHERE va con COLLATE utf8mb4_bin porque la columna es *_general_ci: sin
-- forzar sensibilidad a mayusculas, un "Info@Empresa.com" ya se daria por
-- canonico y la fila nunca entraria al UPDATE.

UPDATE `datarocket_contactos`
   SET `correo` = NULLIF(
         REGEXP_SUBSTR(LOWER(TRIM(`correo`)), '[a-z0-9._%+-]+@[a-z0-9.-]+[.][a-z]{2,}'),
         '')
 WHERE `correo` IS NOT NULL
   AND NOT (`correo` COLLATE utf8mb4_bin REGEXP '^[a-z0-9._%+-]+@[a-z0-9.-]+[.][a-z]{2,}$');

-- ---------------------------------------------------------------------------
-- 3. Telefonos: tabla auxiliar con los candidatos
-- ---------------------------------------------------------------------------
-- `c0` es el campo entero en digitos; `c1`..`c4` son los primeros cuatro
-- tokens separados por espacios, tambien en digitos. `a0`..`a4` guardan el
-- largo del codigo de area de cada candidato, que hace falta para sacar el 15.
-- Solo entran las filas que hay algo que corregir.

DROP TABLE IF EXISTS `dr_ct_tmp`;
CREATE TABLE `dr_ct_tmp` (
  `id`    int(11)     NOT NULL,
  `campo` varchar(10) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL,
  `orig`  varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NULL,
  `c0`    varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NULL,
  `c1`    varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NULL,
  `c2`    varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NULL,
  `c3`    varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NULL,
  `c4`    varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NULL,
  `a0`    int(11) NULL, `a1` int(11) NULL, `a2` int(11) NULL,
  `a3`    int(11) NULL, `a4` int(11) NULL,
  PRIMARY KEY (`id`, `campo`)
) ENGINE = InnoDB CHARACTER SET = utf8mb4 COLLATE = utf8mb4_general_ci;

INSERT INTO `dr_ct_tmp` (`id`, `campo`, `orig`, `c0`, `c1`, `c2`, `c3`, `c4`)
SELECT `id`, 'telefono', `telefono`,
       REGEXP_REPLACE(`telefono`, '[^0-9]', ''),
       REGEXP_REPLACE(SUBSTRING_INDEX(SUBSTRING_INDEX(TRIM(REGEXP_REPLACE(`telefono`, '[[:space:]]+', ' ')), ' ', 1), ' ', -1), '[^0-9]', ''),
       REGEXP_REPLACE(SUBSTRING_INDEX(SUBSTRING_INDEX(TRIM(REGEXP_REPLACE(`telefono`, '[[:space:]]+', ' ')), ' ', 2), ' ', -1), '[^0-9]', ''),
       REGEXP_REPLACE(SUBSTRING_INDEX(SUBSTRING_INDEX(TRIM(REGEXP_REPLACE(`telefono`, '[[:space:]]+', ' ')), ' ', 3), ' ', -1), '[^0-9]', ''),
       REGEXP_REPLACE(SUBSTRING_INDEX(SUBSTRING_INDEX(TRIM(REGEXP_REPLACE(`telefono`, '[[:space:]]+', ' ')), ' ', 4), ' ', -1), '[^0-9]', '')
  FROM `datarocket_contactos`
 WHERE `telefono` IS NOT NULL
   AND `telefono` REGEXP '[0-9]'
   AND NOT (`telefono` REGEXP '^[0-9]{10}$' AND `telefono` REGEXP '^(11|[23]|600|800|810)');

INSERT INTO `dr_ct_tmp` (`id`, `campo`, `orig`, `c0`, `c1`, `c2`, `c3`, `c4`)
SELECT `id`, 'celular', `celular`,
       REGEXP_REPLACE(`celular`, '[^0-9]', ''),
       REGEXP_REPLACE(SUBSTRING_INDEX(SUBSTRING_INDEX(TRIM(REGEXP_REPLACE(`celular`, '[[:space:]]+', ' ')), ' ', 1), ' ', -1), '[^0-9]', ''),
       REGEXP_REPLACE(SUBSTRING_INDEX(SUBSTRING_INDEX(TRIM(REGEXP_REPLACE(`celular`, '[[:space:]]+', ' ')), ' ', 2), ' ', -1), '[^0-9]', ''),
       REGEXP_REPLACE(SUBSTRING_INDEX(SUBSTRING_INDEX(TRIM(REGEXP_REPLACE(`celular`, '[[:space:]]+', ' ')), ' ', 3), ' ', -1), '[^0-9]', ''),
       REGEXP_REPLACE(SUBSTRING_INDEX(SUBSTRING_INDEX(TRIM(REGEXP_REPLACE(`celular`, '[[:space:]]+', ' ')), ' ', 4), ' ', -1), '[^0-9]', '')
  FROM `datarocket_contactos`
 WHERE `celular` IS NOT NULL
   AND `celular` REGEXP '[0-9]'
   AND NOT (`celular` REGEXP '^[0-9]{10}$' AND `celular` REGEXP '^(11|[23]|600|800|810)');

INSERT INTO `dr_ct_tmp` (`id`, `campo`, `orig`, `c0`, `c1`, `c2`, `c3`, `c4`)
SELECT `id`, 'whatsapp', `whatsapp`,
       REGEXP_REPLACE(`whatsapp`, '[^0-9]', ''),
       REGEXP_REPLACE(SUBSTRING_INDEX(SUBSTRING_INDEX(TRIM(REGEXP_REPLACE(`whatsapp`, '[[:space:]]+', ' ')), ' ', 1), ' ', -1), '[^0-9]', ''),
       REGEXP_REPLACE(SUBSTRING_INDEX(SUBSTRING_INDEX(TRIM(REGEXP_REPLACE(`whatsapp`, '[[:space:]]+', ' ')), ' ', 2), ' ', -1), '[^0-9]', ''),
       REGEXP_REPLACE(SUBSTRING_INDEX(SUBSTRING_INDEX(TRIM(REGEXP_REPLACE(`whatsapp`, '[[:space:]]+', ' ')), ' ', 3), ' ', -1), '[^0-9]', ''),
       REGEXP_REPLACE(SUBSTRING_INDEX(SUBSTRING_INDEX(TRIM(REGEXP_REPLACE(`whatsapp`, '[[:space:]]+', ' ')), ' ', 4), ' ', -1), '[^0-9]', '')
  FROM `datarocket_contactos`
 WHERE `whatsapp` IS NOT NULL
   AND `whatsapp` REGEXP '[0-9]'
   AND NOT (`whatsapp` REGEXP '^[0-9]{10}$' AND `whatsapp` REGEXP '^(11|[23]|600|800|810)');

-- ---------------------------------------------------------------------------
-- 4. El pipeline, un UPDATE por paso sobre todos los candidatos
-- ---------------------------------------------------------------------------

-- Paso 2: prefijo internacional 00.
UPDATE `dr_ct_tmp` SET
  `c0` = CASE WHEN `c0` LIKE '00%' THEN SUBSTRING(`c0`, 3) ELSE `c0` END,
  `c1` = CASE WHEN `c1` LIKE '00%' THEN SUBSTRING(`c1`, 3) ELSE `c1` END,
  `c2` = CASE WHEN `c2` LIKE '00%' THEN SUBSTRING(`c2`, 3) ELSE `c2` END,
  `c3` = CASE WHEN `c3` LIKE '00%' THEN SUBSTRING(`c3`, 3) ELSE `c3` END,
  `c4` = CASE WHEN `c4` LIKE '00%' THEN SUBSTRING(`c4`, 3) ELSE `c4` END;

-- Paso 3: prefijo nacional 0 de larga distancia. Cuatro pasadas equivalen al
-- `while` del lib PHP (en los datos reales nunca hace falta mas de dos).
UPDATE `dr_ct_tmp` SET
  `c0` = CASE WHEN CHAR_LENGTH(`c0`) > 10 AND `c0` LIKE '0%' THEN SUBSTRING(`c0`, 2) ELSE `c0` END,
  `c1` = CASE WHEN CHAR_LENGTH(`c1`) > 10 AND `c1` LIKE '0%' THEN SUBSTRING(`c1`, 2) ELSE `c1` END,
  `c2` = CASE WHEN CHAR_LENGTH(`c2`) > 10 AND `c2` LIKE '0%' THEN SUBSTRING(`c2`, 2) ELSE `c2` END,
  `c3` = CASE WHEN CHAR_LENGTH(`c3`) > 10 AND `c3` LIKE '0%' THEN SUBSTRING(`c3`, 2) ELSE `c3` END,
  `c4` = CASE WHEN CHAR_LENGTH(`c4`) > 10 AND `c4` LIKE '0%' THEN SUBSTRING(`c4`, 2) ELSE `c4` END;
UPDATE `dr_ct_tmp` SET
  `c0` = CASE WHEN CHAR_LENGTH(`c0`) > 10 AND `c0` LIKE '0%' THEN SUBSTRING(`c0`, 2) ELSE `c0` END,
  `c1` = CASE WHEN CHAR_LENGTH(`c1`) > 10 AND `c1` LIKE '0%' THEN SUBSTRING(`c1`, 2) ELSE `c1` END,
  `c2` = CASE WHEN CHAR_LENGTH(`c2`) > 10 AND `c2` LIKE '0%' THEN SUBSTRING(`c2`, 2) ELSE `c2` END,
  `c3` = CASE WHEN CHAR_LENGTH(`c3`) > 10 AND `c3` LIKE '0%' THEN SUBSTRING(`c3`, 2) ELSE `c3` END,
  `c4` = CASE WHEN CHAR_LENGTH(`c4`) > 10 AND `c4` LIKE '0%' THEN SUBSTRING(`c4`, 2) ELSE `c4` END;
UPDATE `dr_ct_tmp` SET
  `c0` = CASE WHEN CHAR_LENGTH(`c0`) > 10 AND `c0` LIKE '0%' THEN SUBSTRING(`c0`, 2) ELSE `c0` END,
  `c1` = CASE WHEN CHAR_LENGTH(`c1`) > 10 AND `c1` LIKE '0%' THEN SUBSTRING(`c1`, 2) ELSE `c1` END,
  `c2` = CASE WHEN CHAR_LENGTH(`c2`) > 10 AND `c2` LIKE '0%' THEN SUBSTRING(`c2`, 2) ELSE `c2` END,
  `c3` = CASE WHEN CHAR_LENGTH(`c3`) > 10 AND `c3` LIKE '0%' THEN SUBSTRING(`c3`, 2) ELSE `c3` END,
  `c4` = CASE WHEN CHAR_LENGTH(`c4`) > 10 AND `c4` LIKE '0%' THEN SUBSTRING(`c4`, 2) ELSE `c4` END;
UPDATE `dr_ct_tmp` SET
  `c0` = CASE WHEN CHAR_LENGTH(`c0`) > 10 AND `c0` LIKE '0%' THEN SUBSTRING(`c0`, 2) ELSE `c0` END,
  `c1` = CASE WHEN CHAR_LENGTH(`c1`) > 10 AND `c1` LIKE '0%' THEN SUBSTRING(`c1`, 2) ELSE `c1` END,
  `c2` = CASE WHEN CHAR_LENGTH(`c2`) > 10 AND `c2` LIKE '0%' THEN SUBSTRING(`c2`, 2) ELSE `c2` END,
  `c3` = CASE WHEN CHAR_LENGTH(`c3`) > 10 AND `c3` LIKE '0%' THEN SUBSTRING(`c3`, 2) ELSE `c3` END,
  `c4` = CASE WHEN CHAR_LENGTH(`c4`) > 10 AND `c4` LIKE '0%' THEN SUBSTRING(`c4`, 2) ELSE `c4` END;

-- Paso 4: codigo de pais 54.
UPDATE `dr_ct_tmp` SET
  `c0` = CASE WHEN CHAR_LENGTH(`c0`) > 10 AND `c0` LIKE '54%' THEN SUBSTRING(`c0`, 3) ELSE `c0` END,
  `c1` = CASE WHEN CHAR_LENGTH(`c1`) > 10 AND `c1` LIKE '54%' THEN SUBSTRING(`c1`, 3) ELSE `c1` END,
  `c2` = CASE WHEN CHAR_LENGTH(`c2`) > 10 AND `c2` LIKE '54%' THEN SUBSTRING(`c2`, 3) ELSE `c2` END,
  `c3` = CASE WHEN CHAR_LENGTH(`c3`) > 10 AND `c3` LIKE '54%' THEN SUBSTRING(`c3`, 3) ELSE `c3` END,
  `c4` = CASE WHEN CHAR_LENGTH(`c4`) > 10 AND `c4` LIKE '54%' THEN SUBSTRING(`c4`, 3) ELSE `c4` END;

-- Paso 5: el 9 de movil.
UPDATE `dr_ct_tmp` SET
  `c0` = CASE WHEN CHAR_LENGTH(`c0`) IN (11, 13) AND `c0` LIKE '9%' THEN SUBSTRING(`c0`, 2) ELSE `c0` END,
  `c1` = CASE WHEN CHAR_LENGTH(`c1`) IN (11, 13) AND `c1` LIKE '9%' THEN SUBSTRING(`c1`, 2) ELSE `c1` END,
  `c2` = CASE WHEN CHAR_LENGTH(`c2`) IN (11, 13) AND `c2` LIKE '9%' THEN SUBSTRING(`c2`, 2) ELSE `c2` END,
  `c3` = CASE WHEN CHAR_LENGTH(`c3`) IN (11, 13) AND `c3` LIKE '9%' THEN SUBSTRING(`c3`, 2) ELSE `c3` END,
  `c4` = CASE WHEN CHAR_LENGTH(`c4`) IN (11, 13) AND `c4` LIKE '9%' THEN SUBSTRING(`c4`, 2) ELSE `c4` END;

-- Largo del codigo de area de cada candidato. `11` es el unico de 2 digitos;
-- los de 3 estan listados; todo lo demas es de 4. Se calcula aparte para no
-- repetir la lista de areas dentro del CASE del paso 6.
UPDATE `dr_ct_tmp` SET
  `a0` = CASE WHEN LEFT(`c0`, 2) = '11' THEN 2
              WHEN LEFT(`c0`, 3) IN ('220','221','223','230','236','237','249','260','261','263','264','266','280','291','297','299','341','342','343','345','348','351','353','358','362','364','370','376','379','381','383','385','387','388') THEN 3
              ELSE 4 END,
  `a1` = CASE WHEN LEFT(`c1`, 2) = '11' THEN 2
              WHEN LEFT(`c1`, 3) IN ('220','221','223','230','236','237','249','260','261','263','264','266','280','291','297','299','341','342','343','345','348','351','353','358','362','364','370','376','379','381','383','385','387','388') THEN 3
              ELSE 4 END,
  `a2` = CASE WHEN LEFT(`c2`, 2) = '11' THEN 2
              WHEN LEFT(`c2`, 3) IN ('220','221','223','230','236','237','249','260','261','263','264','266','280','291','297','299','341','342','343','345','348','351','353','358','362','364','370','376','379','381','383','385','387','388') THEN 3
              ELSE 4 END,
  `a3` = CASE WHEN LEFT(`c3`, 2) = '11' THEN 2
              WHEN LEFT(`c3`, 3) IN ('220','221','223','230','236','237','249','260','261','263','264','266','280','291','297','299','341','342','343','345','348','351','353','358','362','364','370','376','379','381','383','385','387','388') THEN 3
              ELSE 4 END,
  `a4` = CASE WHEN LEFT(`c4`, 2) = '11' THEN 2
              WHEN LEFT(`c4`, 3) IN ('220','221','223','230','236','237','249','260','261','263','264','266','280','291','297','299','341','342','343','345','348','351','353','358','362','364','370','376','379','381','383','385','387','388') THEN 3
              ELSE 4 END;

-- Paso 6: el 15 intercalado entre area y abonado. Se prueba primero el area
-- que declara la lista y, si ahi no hay 15, los tres largos posibles.
UPDATE `dr_ct_tmp` SET
  `c0` = CASE WHEN CHAR_LENGTH(`c0`) <> 12 THEN `c0`
              WHEN SUBSTRING(`c0`, `a0` + 1, 2) = '15' THEN CONCAT(LEFT(`c0`, `a0`), SUBSTRING(`c0`, `a0` + 3))
              WHEN SUBSTRING(`c0`, 3, 2) = '15' THEN CONCAT(LEFT(`c0`, 2), SUBSTRING(`c0`, 5))
              WHEN SUBSTRING(`c0`, 4, 2) = '15' THEN CONCAT(LEFT(`c0`, 3), SUBSTRING(`c0`, 6))
              WHEN SUBSTRING(`c0`, 5, 2) = '15' THEN CONCAT(LEFT(`c0`, 4), SUBSTRING(`c0`, 7))
              ELSE `c0` END,
  `c1` = CASE WHEN CHAR_LENGTH(`c1`) <> 12 THEN `c1`
              WHEN SUBSTRING(`c1`, `a1` + 1, 2) = '15' THEN CONCAT(LEFT(`c1`, `a1`), SUBSTRING(`c1`, `a1` + 3))
              WHEN SUBSTRING(`c1`, 3, 2) = '15' THEN CONCAT(LEFT(`c1`, 2), SUBSTRING(`c1`, 5))
              WHEN SUBSTRING(`c1`, 4, 2) = '15' THEN CONCAT(LEFT(`c1`, 3), SUBSTRING(`c1`, 6))
              WHEN SUBSTRING(`c1`, 5, 2) = '15' THEN CONCAT(LEFT(`c1`, 4), SUBSTRING(`c1`, 7))
              ELSE `c1` END,
  `c2` = CASE WHEN CHAR_LENGTH(`c2`) <> 12 THEN `c2`
              WHEN SUBSTRING(`c2`, `a2` + 1, 2) = '15' THEN CONCAT(LEFT(`c2`, `a2`), SUBSTRING(`c2`, `a2` + 3))
              WHEN SUBSTRING(`c2`, 3, 2) = '15' THEN CONCAT(LEFT(`c2`, 2), SUBSTRING(`c2`, 5))
              WHEN SUBSTRING(`c2`, 4, 2) = '15' THEN CONCAT(LEFT(`c2`, 3), SUBSTRING(`c2`, 6))
              WHEN SUBSTRING(`c2`, 5, 2) = '15' THEN CONCAT(LEFT(`c2`, 4), SUBSTRING(`c2`, 7))
              ELSE `c2` END,
  `c3` = CASE WHEN CHAR_LENGTH(`c3`) <> 12 THEN `c3`
              WHEN SUBSTRING(`c3`, `a3` + 1, 2) = '15' THEN CONCAT(LEFT(`c3`, `a3`), SUBSTRING(`c3`, `a3` + 3))
              WHEN SUBSTRING(`c3`, 3, 2) = '15' THEN CONCAT(LEFT(`c3`, 2), SUBSTRING(`c3`, 5))
              WHEN SUBSTRING(`c3`, 4, 2) = '15' THEN CONCAT(LEFT(`c3`, 3), SUBSTRING(`c3`, 6))
              WHEN SUBSTRING(`c3`, 5, 2) = '15' THEN CONCAT(LEFT(`c3`, 4), SUBSTRING(`c3`, 7))
              ELSE `c3` END,
  `c4` = CASE WHEN CHAR_LENGTH(`c4`) <> 12 THEN `c4`
              WHEN SUBSTRING(`c4`, `a4` + 1, 2) = '15' THEN CONCAT(LEFT(`c4`, `a4`), SUBSTRING(`c4`, `a4` + 3))
              WHEN SUBSTRING(`c4`, 3, 2) = '15' THEN CONCAT(LEFT(`c4`, 2), SUBSTRING(`c4`, 5))
              WHEN SUBSTRING(`c4`, 4, 2) = '15' THEN CONCAT(LEFT(`c4`, 3), SUBSTRING(`c4`, 6))
              WHEN SUBSTRING(`c4`, 5, 2) = '15' THEN CONCAT(LEFT(`c4`, 4), SUBSTRING(`c4`, 7))
              ELSE `c4` END;

-- Paso 7: movil de CABA escrito en formato local (15-6780-5502 -> 11-6780-5502).
UPDATE `dr_ct_tmp` SET
  `c0` = CASE WHEN CHAR_LENGTH(`c0`) = 10 AND `c0` LIKE '15%' THEN CONCAT('11', SUBSTRING(`c0`, 3)) ELSE `c0` END,
  `c1` = CASE WHEN CHAR_LENGTH(`c1`) = 10 AND `c1` LIKE '15%' THEN CONCAT('11', SUBSTRING(`c1`, 3)) ELSE `c1` END,
  `c2` = CASE WHEN CHAR_LENGTH(`c2`) = 10 AND `c2` LIKE '15%' THEN CONCAT('11', SUBSTRING(`c2`, 3)) ELSE `c2` END,
  `c3` = CASE WHEN CHAR_LENGTH(`c3`) = 10 AND `c3` LIKE '15%' THEN CONCAT('11', SUBSTRING(`c3`, 3)) ELSE `c3` END,
  `c4` = CASE WHEN CHAR_LENGTH(`c4`) = 10 AND `c4` LIKE '15%' THEN CONCAT('11', SUBSTRING(`c4`, 3)) ELSE `c4` END;

-- ---------------------------------------------------------------------------
-- 5. Vuelco: gana el primer candidato valido; si no hay, los digitos crudos
-- ---------------------------------------------------------------------------
-- Prefijos validos de un nacional de 10 digitos: `11` (CABA), cualquier area
-- que arranque con 2 o 3, y los servicios especiales 0600 / 0800 / 0810 (que
-- sin el 0 tambien son de 10).

UPDATE `datarocket_contactos` `c`
  JOIN `dr_ct_tmp` `t` ON `t`.`id` = `c`.`id` AND `t`.`campo` = 'telefono'
   SET `c`.`telefono` = COALESCE(
         CASE WHEN CHAR_LENGTH(`t`.`c0`) = 10 AND `t`.`c0` REGEXP '^(11|[23]|600|800|810)' THEN `t`.`c0` END,
         CASE WHEN CHAR_LENGTH(`t`.`c1`) = 10 AND `t`.`c1` REGEXP '^(11|[23]|600|800|810)' THEN `t`.`c1` END,
         CASE WHEN CHAR_LENGTH(`t`.`c2`) = 10 AND `t`.`c2` REGEXP '^(11|[23]|600|800|810)' THEN `t`.`c2` END,
         CASE WHEN CHAR_LENGTH(`t`.`c3`) = 10 AND `t`.`c3` REGEXP '^(11|[23]|600|800|810)' THEN `t`.`c3` END,
         CASE WHEN CHAR_LENGTH(`t`.`c4`) = 10 AND `t`.`c4` REGEXP '^(11|[23]|600|800|810)' THEN `t`.`c4` END,
         NULLIF(REGEXP_REPLACE(`t`.`orig`, '[^0-9]', ''), ''));

UPDATE `datarocket_contactos` `c`
  JOIN `dr_ct_tmp` `t` ON `t`.`id` = `c`.`id` AND `t`.`campo` = 'celular'
   SET `c`.`celular` = COALESCE(
         CASE WHEN CHAR_LENGTH(`t`.`c0`) = 10 AND `t`.`c0` REGEXP '^(11|[23]|600|800|810)' THEN `t`.`c0` END,
         CASE WHEN CHAR_LENGTH(`t`.`c1`) = 10 AND `t`.`c1` REGEXP '^(11|[23]|600|800|810)' THEN `t`.`c1` END,
         CASE WHEN CHAR_LENGTH(`t`.`c2`) = 10 AND `t`.`c2` REGEXP '^(11|[23]|600|800|810)' THEN `t`.`c2` END,
         CASE WHEN CHAR_LENGTH(`t`.`c3`) = 10 AND `t`.`c3` REGEXP '^(11|[23]|600|800|810)' THEN `t`.`c3` END,
         CASE WHEN CHAR_LENGTH(`t`.`c4`) = 10 AND `t`.`c4` REGEXP '^(11|[23]|600|800|810)' THEN `t`.`c4` END,
         NULLIF(REGEXP_REPLACE(`t`.`orig`, '[^0-9]', ''), ''));

UPDATE `datarocket_contactos` `c`
  JOIN `dr_ct_tmp` `t` ON `t`.`id` = `c`.`id` AND `t`.`campo` = 'whatsapp'
   SET `c`.`whatsapp` = COALESCE(
         CASE WHEN CHAR_LENGTH(`t`.`c0`) = 10 AND `t`.`c0` REGEXP '^(11|[23]|600|800|810)' THEN `t`.`c0` END,
         CASE WHEN CHAR_LENGTH(`t`.`c1`) = 10 AND `t`.`c1` REGEXP '^(11|[23]|600|800|810)' THEN `t`.`c1` END,
         CASE WHEN CHAR_LENGTH(`t`.`c2`) = 10 AND `t`.`c2` REGEXP '^(11|[23]|600|800|810)' THEN `t`.`c2` END,
         CASE WHEN CHAR_LENGTH(`t`.`c3`) = 10 AND `t`.`c3` REGEXP '^(11|[23]|600|800|810)' THEN `t`.`c3` END,
         CASE WHEN CHAR_LENGTH(`t`.`c4`) = 10 AND `t`.`c4` REGEXP '^(11|[23]|600|800|810)' THEN `t`.`c4` END,
         NULLIF(REGEXP_REPLACE(`t`.`orig`, '[^0-9]', ''), ''));

-- ---------------------------------------------------------------------------
-- 6. Limpieza: la tabla era auxiliar de esta migracion
-- ---------------------------------------------------------------------------

DROP TABLE IF EXISTS `dr_ct_tmp`;
