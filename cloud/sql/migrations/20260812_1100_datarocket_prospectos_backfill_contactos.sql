-- datarocket_prospectos: backfill de `contacto_id` desde los datos de identidad
-- embebidos en las columnas viejas (nombre / contacto / celular / correo /
-- organizacion / etc.). Segundo paso del refactor "prospectos = referencia a
-- contacto" (ver 20260812_1000).
--
-- El scope se limita a prospectos de los 4 proyectos productivos del grupo:
-- Vigicom (102), Vigia (103), Reactor (104), Causam (109). En este momento
-- todos los 1349 prospectos existentes caen dentro de ese subset; el WHERE
-- queda igual como red defensiva para futuras importaciones.
--
-- ============================================================================
-- Algoritmo, 6 pasos:
-- ============================================================================
--   1) DELETE de prospectos sin correo NI celular — no hay como contactar a
--      esa persona ni como deduplicarla contra un contacto existente. Antes
--      del cambio eran 13 filas en total. Decision del usuario: descartar.
--
--   2) Match a contactos EXISTENTES por correo normalizado
--      (LOWER + TRIM). Si el prospecto tiene correo y ya existe un contacto
--      con ese mismo correo, se enlaza.
--
--   3) Match a contactos EXISTENTES por celular normalizado (solo digitos,
--      via REGEXP_REPLACE). Solo aplica a los prospectos que quedaron
--      sin match en el paso 2.
--
--   4) Para los prospectos aun sin match, se crean contactos NUEVOS con
--      tipo='persona' y origen='migracion_datarocket_prospectos'. Dedupe:
--      dos prospectos con el mismo correo (o el mismo celular si no hay
--      correo) generan UN solo contacto. La eleccion del "seed" (que
--      prospecto aporta nombre / empresa / etc. al contacto nuevo) es el
--      de menor id — arbitrario pero deterministico. `registrado` = ingreso
--      mas viejo del grupo.
--
--   5) INSERT IGNORE en `datarocket_contactos_etiquetas`: por cada
--      combinacion (contacto, proyecto), agrega la etiqueta correspondiente
--      del proyecto de origen (Vigicom / Vigia / Reactor / Causam). Un
--      contacto que fue lead en 2 proyectos distintos recibe 2 etiquetas;
--      si tuvo N prospectos del mismo proyecto, la etiqueta se pone 1 sola
--      vez (INSERT IGNORE por PK compuesta).
--
--   6) UPDATE del contador denormalizado `datarocket_etiquetas.etiquetados`
--      para dejarlo sincronizado con la pivote. Misma logica que el endpoint
--      POST ?action=recalcular en api/datarocket_etiquetas.php.
--
-- ============================================================================
-- Mapeo de columnas prospecto -> contacto:
-- ============================================================================
--   contactos.tipo       = 'persona'   (decision del usuario, todos personas)
--   contactos.origen     = 'migracion_datarocket_prospectos'  (trazabilidad)
--   contactos.nombre     = COALESCE(NULLIF(TRIM(p.contacto),''), TRIM(p.nombre))
--                          — el legacy usa `nombre` con formato compuesto
--                          ("Empresa - Persona") y `contacto` con el nombre
--                          limpio del humano; preferimos el limpio.
--   contactos.empresa    = NULLIF(TRIM(p.organizacion), '')
--   contactos.correo     = NULLIF(TRIM(p.correo), '')
--   contactos.celular    = NULLIF(TRIM(p.celular), '')
--   contactos.web        = NULLIF(TRIM(p.web), '')
--   contactos.domicilio  = NULLIF(TRIM(p.domicilio), '')
--   contactos.ciudad     = NULLIF(TRIM(p.ciudad), '')
--   contactos.localidad  = NULLIF(TRIM(p.localidad), '')
--   contactos.provincia  = NULLIF(TRIM(p.provincia), '')
--   contactos.pais       = NULLIF(TRIM(p.pais), '')
--   contactos.ubicacion  = NULLIF(TRIM(p.ubicacion), '')
--   contactos.registrado = MIN(p.ingreso) por grupo (ingreso mas viejo)
--   contactos.uuid       = UUID() por fila (MySQL evalua UUID() por row)
--
-- Etiquetas ya existentes en `datarocket_etiquetas`:
--   Vigicom  (id resuelto por lookup, no hardcodeado)
--   Vigia
--   Reactor
--   Causam
--
-- ============================================================================
-- Idempotencia:
-- ============================================================================
--   - Paso 1 (DELETE) es no-op en rerun (rows ya no estan).
--   - Pasos 2-4 usan `WHERE contacto_id IS NULL` — despues del primer run
--     todos los prospectos tienen contacto_id, asi que los UPDATE/INSERT
--     no matchean nada en re-corridas.
--   - Paso 5 usa INSERT IGNORE contra PK (contacto_id, etiqueta_id).
--   - Paso 6 (UPDATE etiquetados) es idempotente por diseño.
--
-- Compatible MySQL 8 (dev) + MariaDB 10.11 (prod). Ambos soportan
-- REGEXP_REPLACE y CTEs. TEMPORARY TABLE existe en ambos.

-- ============================================================================
-- Paso 1: DELETE de prospectos sin correo NI celular.
-- ============================================================================

DELETE FROM `datarocket_prospectos`
 WHERE `proyecto_id` IN (102, 103, 104, 109)
   AND (`correo`  IS NULL OR TRIM(`correo`)  = '')
   AND (`celular` IS NULL OR TRIM(`celular`) = '');

-- ============================================================================
-- Paso 2: match por correo normalizado a contactos EXISTENTES.
-- ============================================================================

UPDATE `datarocket_prospectos` p
  JOIN `datarocket_contactos` c
    ON LOWER(TRIM(c.`correo`)) = LOWER(TRIM(p.`correo`))
   AND LOWER(TRIM(p.`correo`)) <> ''
   SET p.`contacto_id` = c.`id`
 WHERE p.`contacto_id` IS NULL
   AND p.`proyecto_id` IN (102, 103, 104, 109);

-- ============================================================================
-- Paso 3: match por celular normalizado (solo digitos) a contactos EXISTENTES.
-- ============================================================================

UPDATE `datarocket_prospectos` p
  JOIN `datarocket_contactos` c
    ON REGEXP_REPLACE(COALESCE(c.`celular`, ''), '[^0-9]', '')
     = REGEXP_REPLACE(COALESCE(p.`celular`, ''), '[^0-9]', '')
   AND REGEXP_REPLACE(COALESCE(p.`celular`, ''), '[^0-9]', '') <> ''
   SET p.`contacto_id` = c.`id`
 WHERE p.`contacto_id` IS NULL
   AND p.`proyecto_id` IN (102, 103, 104, 109);

-- ============================================================================
-- Paso 4: crear contactos NUEVOS para prospectos aun sin match.
-- ============================================================================

-- 4a) Identificar el "seed" prospecto por humano unico. Dedup_key:
--       - correo normalizado si existe
--       - CONCAT('cel_', celular_norm) si no
--     GROUP BY dedup_key -> un contacto por humano, MIN(id) elige la fila
--     que aporta los datos, MIN(ingreso) da el `registrado` mas viejo.
DROP TEMPORARY TABLE IF EXISTS `tmp_dp_nuevos_contactos`;
CREATE TEMPORARY TABLE `tmp_dp_nuevos_contactos` (
  `dedup_key`      VARCHAR(255) NOT NULL,
  `prospecto_seed` INT          NOT NULL,
  `registrado`     DATETIME     NULL,
  PRIMARY KEY (`dedup_key`)
) ENGINE = InnoDB CHARACTER SET = utf8mb4 COLLATE = utf8mb4_general_ci;

INSERT INTO `tmp_dp_nuevos_contactos` (`dedup_key`, `prospecto_seed`, `registrado`)
SELECT
    COALESCE(
      NULLIF(LOWER(TRIM(p.`correo`)), ''),
      CONCAT('cel_', REGEXP_REPLACE(COALESCE(p.`celular`, ''), '[^0-9]', ''))
    ) AS dedup_key,
    MIN(p.`id`)      AS prospecto_seed,
    MIN(p.`ingreso`) AS registrado
  FROM `datarocket_prospectos` p
 WHERE p.`contacto_id` IS NULL
   AND p.`proyecto_id` IN (102, 103, 104, 109)
GROUP BY dedup_key;

-- 4b) Insertar los contactos nuevos usando el seed como fuente de datos.
INSERT INTO `datarocket_contactos`
   (`uuid`, `tipo`, `origen`, `nombre`, `empresa`,
    `celular`, `correo`, `web`,
    `domicilio`, `ciudad`, `localidad`, `provincia`, `pais`, `ubicacion`,
    `registrado`)
SELECT
    UUID(),
    'persona',
    'migracion_datarocket_prospectos',
    COALESCE(NULLIF(TRIM(p.`contacto`), ''), NULLIF(TRIM(p.`nombre`), '')),
    NULLIF(TRIM(p.`organizacion`), ''),
    NULLIF(TRIM(p.`celular`),      ''),
    NULLIF(TRIM(p.`correo`),       ''),
    NULLIF(TRIM(p.`web`),          ''),
    NULLIF(TRIM(p.`domicilio`),    ''),
    NULLIF(TRIM(p.`ciudad`),       ''),
    NULLIF(TRIM(p.`localidad`),    ''),
    NULLIF(TRIM(p.`provincia`),    ''),
    NULLIF(TRIM(p.`pais`),         ''),
    NULLIF(TRIM(p.`ubicacion`),    ''),
    t.`registrado`
  FROM `tmp_dp_nuevos_contactos` t
  JOIN `datarocket_prospectos`   p ON p.`id` = t.`prospecto_seed`;

DROP TEMPORARY TABLE `tmp_dp_nuevos_contactos`;

-- 4c) Match los contactos recien creados con los prospectos que los originaron.
--     Filtramos por `origen = 'migracion_datarocket_prospectos'` para no
--     colisionar con contactos preexistentes que puedan tener el mismo correo
--     (si hubieran matcheado antes, el prospecto ya tendria contacto_id
--     seteado y esta query no los tocaria, pero el filtro es defensa extra).
UPDATE `datarocket_prospectos` p
  JOIN `datarocket_contactos` c
    ON LOWER(TRIM(c.`correo`)) = LOWER(TRIM(p.`correo`))
   AND LOWER(TRIM(p.`correo`)) <> ''
   AND c.`origen` = 'migracion_datarocket_prospectos'
   SET p.`contacto_id` = c.`id`
 WHERE p.`contacto_id` IS NULL
   AND p.`proyecto_id` IN (102, 103, 104, 109);

UPDATE `datarocket_prospectos` p
  JOIN `datarocket_contactos` c
    ON REGEXP_REPLACE(COALESCE(c.`celular`, ''), '[^0-9]', '')
     = REGEXP_REPLACE(COALESCE(p.`celular`, ''), '[^0-9]', '')
   AND REGEXP_REPLACE(COALESCE(p.`celular`, ''), '[^0-9]', '') <> ''
   AND c.`origen` = 'migracion_datarocket_prospectos'
   SET p.`contacto_id` = c.`id`
 WHERE p.`contacto_id` IS NULL
   AND p.`proyecto_id` IN (102, 103, 104, 109);

-- ============================================================================
-- Paso 5: etiquetar los contactos con el nombre del proyecto de origen.
-- ============================================================================

INSERT IGNORE INTO `datarocket_contactos_etiquetas` (`contacto_id`, `etiqueta_id`)
SELECT DISTINCT p.`contacto_id`, e.`id`
  FROM `datarocket_prospectos` p
  JOIN `datarocket_etiquetas`  e
    ON e.`nombre` = CASE p.`proyecto_id`
                      WHEN 102 THEN 'Vigicom'
                      WHEN 103 THEN 'Vigia'
                      WHEN 104 THEN 'Reactor'
                      WHEN 109 THEN 'Causam'
                    END
 WHERE p.`contacto_id` IS NOT NULL
   AND p.`proyecto_id` IN (102, 103, 104, 109);

-- ============================================================================
-- Paso 6: recalcular contador `datarocket_etiquetas.etiquetados`.
-- ============================================================================

UPDATE `datarocket_etiquetas` e
  LEFT JOIN (
       SELECT `etiqueta_id`, COUNT(*) AS c
         FROM `datarocket_contactos_etiquetas`
       GROUP BY `etiqueta_id`
     ) g ON g.`etiqueta_id` = e.`id`
   SET e.`etiquetados` = COALESCE(g.`c`, 0);
