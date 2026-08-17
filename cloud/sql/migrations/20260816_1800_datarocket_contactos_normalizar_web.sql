-- Normaliza `datarocket_contactos`.`web`: host + path SIN esquema.
--
-- Es el mismo criterio que aplica ahora cloud/api/lib/contactos_normalizar.php
-- sobre toda alta y modificacion (tanto en el ABM del panel como en el
-- microservicio v4); esta migracion pone al dia lo que ya estaba cargado.
--
-- ---------------------------------------------------------------------------
-- POR QUE SIN ESQUEMA
-- ---------------------------------------------------------------------------
-- La columna pasa a guardar `bna.com.ar/sucursales`, no
-- `https://bna.com.ar/sucursales`. El esquema no aporta informacion — el ABM lo
-- antepone al armar el link (`linkCard()` en cloud/assets/js/app.js ya lo hace)
-- — y guardarlo obligaba a elegir entre respetar el `http://` historico de la
-- mitad de las filas o forzar `https://` y romper los sitios viejos que no
-- sirven TLS.
--
-- Pasos, en orden:
--   1. se recorta y se saca el ruido de copy/paste del principio (": ", "- ")
--   2. se saca el esquema (`http://`, `https://`, `//` protocol-relative)
--   3. se sacan los espacios internos: en este campo son siempre tipeos
--      ("www. pampasat.com", "www . jriseguridad.com.ar"), nunca separadores
--   4. se saca la puntuacion del final (`/`, `.`, `,`), que es lo que deja el
--      copiado desde el navegador — cubre las ~7.200 filas terminadas en `/`
--   5. se baja a minuscula SOLO el host. El path es case sensitive y bajarlo
--      rompe los acortadores (`bit.ly/3SSePnt` no es `bit.ly/3ssepnt`).
--
-- El `www.` se respeta tal cual venga: no se agrega ni se saca.
--
-- A diferencia de los telefonos de la 20260816_1700, lo que no queda como host
-- valido NO se guarda crudo: un "no posee" o un "en construccion" en `web` no
-- es un dato que alguien pueda corregir despues, es ruido de scraping. Va a
-- NULL. La excepcion son los correos cargados por error en `web`: se mueven a
-- `correo` si ese campo esta vacio, y si ya tiene algo se descartan igual.
--
-- Para revisar los que se pierden ANTES de aplicar, correr en el Explorador DB
-- (mismo criterio que usa la migracion, en una sola expresion):
--
--   SELECT id, nombre, web, correo FROM datarocket_contactos
--    WHERE web IS NOT NULL AND TRIM(web) <> ''
--      AND LOWER(REGEXP_REPLACE(REGEXP_REPLACE(REGEXP_REPLACE(REGEXP_REPLACE(
--            TRIM(web), '^[[:space:]:;,.-]+', ''),
--            '^[a-zA-Z][a-zA-Z0-9+.-]*://', ''), '[[:space:]]+', ''), '[/?#].*$', ''))
--          NOT REGEXP '^([^[:space:]./?#@:]+[.])+[^[:space:]./?#@:0-9-]{2,}(:[0-9]{1,5})?$';
--
-- ---------------------------------------------------------------------------
-- Por que no hay funciones almacenadas
-- ---------------------------------------------------------------------------
-- La primera version armaba la cadena con funciones `CREATE FUNCTION ...
-- DETERMINISTIC` temporales. Funciona en el MySQL 8 de desarrollo, pero **prod
-- la rechaza**: la base es MariaDB 10.11 en RDS con `log_bin = ON`,
-- `log_bin_trust_function_creators = OFF` y el usuario `admin` sin SUPER, asi
-- que cualquier CREATE FUNCTION muere con el error 1419 — y MariaDB no la deja
-- pasar ni declarandola `DETERMINISTIC NO SQL`, ni se puede setear la variable
-- desde la sesion (hace falta SUPER o BINLOG ADMIN). Mismo motivo y misma
-- solucion que en la 20260816_1700.
--
-- Aca la cadena no tiene ciclos ni candidatos multiples, asi que alcanza con
-- una tabla auxiliar `dr_ct_web_tmp` que materializa los pasos intermedios
-- (valor limpio, host, resto, host validado, correo rescatado) en columnas.
-- Se borra al terminar: no queda nada en el esquema.
--
-- ---------------------------------------------------------------------------
-- Notas de compatibilidad
-- ---------------------------------------------------------------------------
--  - REGEXP_REPLACE / REGEXP_SUBSTR (forma de 2 argumentos) existen en MariaDB
--    10.11 (prod) y en MySQL 8 (dev). No se usa la forma de 4 argumentos de
--    REGEXP_SUBSTR porque MariaDB solo acepta 2.
--  - Los caracteres validos del host se declaran por EXCLUSION (`[^...]`) en
--    vez de con una lista blanca `[a-z0-9-]`. Es a proposito: los dominios con
--    eñe del padron ("serdueño.com.ar", "cañuelas.gob.ar", "estudiomagariños")
--    son direcciones reales, y una lista blanca ASCII los mandaria a NULL. La
--    exclusion no depende de que el motor soporte `\p{L}`, que es justo lo que
--    difiere entre el ICU de MySQL 8 y el PCRE de MariaDB.
--  - El TLD ademas excluye digitos y guion, que es lo que descarta los restos
--    de prosa. Eso dejaba afuera las IPv4, que en esta tabla son direcciones
--    reales ("http://209.154.192.80/"), asi que se aceptan por separado.
--  - Los UPDATE son idempotentes: volver a correr la migracion sobre datos ya
--    normalizados no cambia nada.
--
-- Medido sobre la copia de los datos de produccion (43.240 contactos):
--   web  10.080 no vacios ->  1.091 sin cambio
--                             8.741 corregidos (esquema / barra final / mayusculas)
--                               248 a NULL (no son URL)
--                                 0 rescatados a `correo`
--   ademas las filas en '' (el default de la columna) pasan a NULL.

-- ---------------------------------------------------------------------------
-- 1. Tabla auxiliar con los pasos intermedios
-- ---------------------------------------------------------------------------
-- `limpio` = pasos 1 a 4 (recorte, ruido inicial, esquema, blancos internos,
-- puntuacion final). `host` / `resto` = `limpio` partido por el primer
-- `/`, `?` o `#`. `host_ok` = el host en minuscula si valida, o NULL.
-- `correo` = el correo rescatado si lo que habia era una direccion de mail.

DROP TABLE IF EXISTS `dr_ct_web_tmp`;
CREATE TABLE `dr_ct_web_tmp` (
  `id`      int(11) NOT NULL,
  `limpio`  varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NULL,
  `host`    varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NULL,
  `resto`   varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NULL,
  `host_ok` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NULL,
  `correo`  varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NULL,
  PRIMARY KEY (`id`)
) ENGINE = InnoDB CHARACTER SET = utf8mb4 COLLATE = utf8mb4_general_ci;

INSERT INTO `dr_ct_web_tmp` (`id`, `limpio`)
SELECT `id`,
       REGEXP_REPLACE(                                     -- 4) puntuacion final
         REGEXP_REPLACE(                                   -- 3) blancos internos
           REGEXP_REPLACE(                                 -- 2b) protocol-relative
             REGEXP_REPLACE(                               -- 2a) esquema
               REGEXP_REPLACE(                             -- 1b) ruido inicial
                 TRIM(`web`),                              -- 1a) recorte
                 '^[[:space:]:;,.-]+', ''),
               '^[a-zA-Z][a-zA-Z0-9+.-]*://', ''),
             '^//', ''),
           '[[:space:]]+', ''),
         '[/.,;:-]+$', '')
  FROM `datarocket_contactos`
 WHERE `web` IS NOT NULL AND TRIM(`web`) <> '';

-- Host y resto. El resto arranca en el `/`, `?` o `#` y se guarda tal cual: el
-- path es case sensitive.
UPDATE `dr_ct_web_tmp` SET
  `host`  = REGEXP_REPLACE(`limpio`, '[/?#].*$', ''),
  `resto` = SUBSTRING(`limpio`, CHAR_LENGTH(REGEXP_REPLACE(`limpio`, '[/?#].*$', '')) + 1);

-- Host valido en minuscula, o NULL. Un `@` adentro es un correo mal cargado, no
-- una URL (el `@` del path, "youtube.com/@canal", no llega aca porque quedo del
-- lado del resto).
UPDATE `dr_ct_web_tmp` SET
  `host_ok` = CASE
      WHEN `host` = '' OR LOCATE('@', `host`) > 0 THEN NULL
      WHEN LOWER(`host`) REGEXP '^([^[:space:]./?#@:]+[.])+[^[:space:]./?#@:0-9-]{2,}(:[0-9]{1,5})?$'
           THEN LOWER(`host`)
      WHEN `host` REGEXP '^([0-9]{1,3}[.]){3}[0-9]{1,3}(:[0-9]{1,5})?$'
           THEN `host`
      ELSE NULL
    END;

-- Correo cargado por error en `web`. El `www.` pegado adelante
-- ("www.tecnicatotal@hotmail.com") es un tipeo frecuente y se descarta antes de
-- parsear. El patron es el mismo que usa la 20260816_1700 para `correo`.
UPDATE `dr_ct_web_tmp` SET
  `correo` = CASE
      WHEN LOCATE('@', `host`) = 0 THEN NULL
      ELSE NULLIF(
             REGEXP_SUBSTR(LOWER(REGEXP_REPLACE(`limpio`, '^www[.]', '')),
                           '[a-z0-9._%+-]+@[a-z0-9.-]+[.][a-z]{2,}'),
             '')
    END;

-- ---------------------------------------------------------------------------
-- 2. Backfill
-- ---------------------------------------------------------------------------

-- 1) Rescate del correo. Solo pisa `correo` cuando esta vacio — si el contacto
--    ya tiene correo, gana el que ya estaba.
UPDATE `datarocket_contactos` `c`
  JOIN `dr_ct_web_tmp` `t` ON `t`.`id` = `c`.`id`
   SET `c`.`correo` = `t`.`correo`
 WHERE `t`.`correo` IS NOT NULL
   AND TRIM(COALESCE(`c`.`correo`, '')) = '';

-- 2) El '' que trae la columna como default no necesita pasar por la cadena.
UPDATE `datarocket_contactos` SET `web` = NULL
 WHERE `web` IS NOT NULL AND TRIM(`web`) = '';

-- 3) Host validado en minuscula + resto tal cual. CONCAT devuelve NULL si el
--    host no valida, que es justo lo que se quiere: lo que no es una URL se
--    descarta.
UPDATE `datarocket_contactos` `c`
  JOIN `dr_ct_web_tmp` `t` ON `t`.`id` = `c`.`id`
   SET `c`.`web` = CONCAT(`t`.`host_ok`, `t`.`resto`);

-- ---------------------------------------------------------------------------
-- 3. Limpieza: la tabla era auxiliar de esta migracion
-- ---------------------------------------------------------------------------

DROP TABLE IF EXISTS `dr_ct_web_tmp`;
