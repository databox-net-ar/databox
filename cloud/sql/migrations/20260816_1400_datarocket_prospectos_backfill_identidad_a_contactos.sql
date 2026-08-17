-- datarocket_prospectos -> datarocket_contactos: rellena los huecos de identidad
-- que quedaron despues del backfill 20260812_1100, como paso previo obligatorio
-- al DROP de las 12 columnas legacy (migracion 20260816_1500).
--
-- Por que quedaron huecos: la 20260812_1100 solo volco la identidad del
-- prospecto en los contactos que ella misma CREO (su paso 4). Los prospectos
-- que matchearon contra un contacto YA EXISTENTE (pasos 2 y 3, por correo o
-- celular normalizado) no aportaron nada — el contacto se quedo con los datos
-- que ya tenia. Medido en dev al 2026-08-16, sobre 1336 prospectos:
--
--   empresa        121 contactos vacios con organizacion en el prospecto
--   provincia_id   177
--   pais_id        138
--   ciudad          86
--   domicilio       59
--   localidad_id    51
--   ubicacion       20
--   celular         10
--   nombre/correo/web  0  (se incluyen igual, para que la migracion sea
--                          auto-reparadora si prod difiere de dev)
--
-- Sin este paso, el DROP de la 20260816_1500 perderia esos datos para siempre.
--
-- Mapeo (mismo criterio que la 20260812_1100):
--   c.nombre       <- COALESCE(p.contacto, p.nombre)   `nombre` es compuesto
--                     ("Organizacion - Persona"), `contacto` es la persona sola
--   c.empresa      <- p.organizacion
--   c.correo       <- p.correo
--   c.celular      <- p.celular
--   c.web          <- p.web
--   c.domicilio    <- p.domicilio
--   c.ciudad       <- p.ciudad
--   c.ubicacion    <- p.ubicacion
--   c.localidad_id <- CAST(p.localidad  AS UNSIGNED)
--   c.provincia_id <- CAST(p.provincia  AS UNSIGNED)
--   c.pais_id      <- CAST(p.pais       AS UNSIGNED)
--
-- Ojo con localidad / provincia / pais: en `datarocket_prospectos` son
-- VARCHAR(255) pero guardan el **ID** del catalogo, no el nombre (verificado en
-- dev: 1466 valores, todos numericos y todos resuelven). Desde la 20260815_1000
-- el contacto los tiene como FK (`localidad_id` / `provincia_id` / `pais_id`),
-- asi que se castean. Cada UPDATE geografico exige ademas que el valor sea
-- numerico Y que la fila exista en el catalogo — si prod tuviera basura, la
-- fila se saltea en vez de romper la FK.
--
-- Solo se rellenan campos VACIOS del contacto: si el contacto ya tiene dato,
-- gana el contacto (es la fuente de verdad del refactor). Cuando un contacto
-- tiene varios prospectos, aporta el de `id` mas alto que tenga el campo
-- cargado.
--
-- Idempotente: todos los UPDATE llevan `WHERE <campo del contacto> vacio`, asi
-- que en la segunda corrida no matchea ninguna fila.
-- Compatible MySQL 8 (dev) + MariaDB 10.11 (prod).


-- ---------------------------------------------------------------------------
-- 1) nombre  <- COALESCE(p.contacto, p.nombre)
-- ---------------------------------------------------------------------------

UPDATE datarocket_contactos c
   SET c.nombre = (
         SELECT COALESCE(NULLIF(TRIM(p.contacto), ''), NULLIF(TRIM(p.nombre), ''))
           FROM datarocket_prospectos p
          WHERE p.contacto_id = c.id
            AND (TRIM(COALESCE(p.contacto, '')) <> '' OR TRIM(COALESCE(p.nombre, '')) <> '')
          ORDER BY p.id DESC LIMIT 1
       )
 WHERE TRIM(COALESCE(c.nombre, '')) = ''
   AND EXISTS (
         SELECT 1 FROM datarocket_prospectos p2
          WHERE p2.contacto_id = c.id
            AND (TRIM(COALESCE(p2.contacto, '')) <> '' OR TRIM(COALESCE(p2.nombre, '')) <> '')
       );


-- ---------------------------------------------------------------------------
-- 2) empresa <- p.organizacion
-- ---------------------------------------------------------------------------

UPDATE datarocket_contactos c
   SET c.empresa = (
         SELECT NULLIF(TRIM(p.organizacion), '')
           FROM datarocket_prospectos p
          WHERE p.contacto_id = c.id AND TRIM(COALESCE(p.organizacion, '')) <> ''
          ORDER BY p.id DESC LIMIT 1
       )
 WHERE TRIM(COALESCE(c.empresa, '')) = ''
   AND EXISTS (
         SELECT 1 FROM datarocket_prospectos p2
          WHERE p2.contacto_id = c.id AND TRIM(COALESCE(p2.organizacion, '')) <> ''
       );


-- ---------------------------------------------------------------------------
-- 3) correo <- p.correo
-- ---------------------------------------------------------------------------

UPDATE datarocket_contactos c
   SET c.correo = (
         SELECT NULLIF(TRIM(p.correo), '')
           FROM datarocket_prospectos p
          WHERE p.contacto_id = c.id AND TRIM(COALESCE(p.correo, '')) <> ''
          ORDER BY p.id DESC LIMIT 1
       )
 WHERE TRIM(COALESCE(c.correo, '')) = ''
   AND EXISTS (
         SELECT 1 FROM datarocket_prospectos p2
          WHERE p2.contacto_id = c.id AND TRIM(COALESCE(p2.correo, '')) <> ''
       );


-- ---------------------------------------------------------------------------
-- 4) celular <- p.celular
-- ---------------------------------------------------------------------------

UPDATE datarocket_contactos c
   SET c.celular = (
         SELECT NULLIF(TRIM(p.celular), '')
           FROM datarocket_prospectos p
          WHERE p.contacto_id = c.id AND TRIM(COALESCE(p.celular, '')) <> ''
          ORDER BY p.id DESC LIMIT 1
       )
 WHERE TRIM(COALESCE(c.celular, '')) = ''
   AND EXISTS (
         SELECT 1 FROM datarocket_prospectos p2
          WHERE p2.contacto_id = c.id AND TRIM(COALESCE(p2.celular, '')) <> ''
       );


-- ---------------------------------------------------------------------------
-- 5) web <- p.web
-- ---------------------------------------------------------------------------

UPDATE datarocket_contactos c
   SET c.web = (
         SELECT NULLIF(TRIM(p.web), '')
           FROM datarocket_prospectos p
          WHERE p.contacto_id = c.id AND TRIM(COALESCE(p.web, '')) <> ''
          ORDER BY p.id DESC LIMIT 1
       )
 WHERE TRIM(COALESCE(c.web, '')) = ''
   AND EXISTS (
         SELECT 1 FROM datarocket_prospectos p2
          WHERE p2.contacto_id = c.id AND TRIM(COALESCE(p2.web, '')) <> ''
       );


-- ---------------------------------------------------------------------------
-- 6) domicilio <- p.domicilio
-- ---------------------------------------------------------------------------

UPDATE datarocket_contactos c
   SET c.domicilio = (
         SELECT NULLIF(TRIM(p.domicilio), '')
           FROM datarocket_prospectos p
          WHERE p.contacto_id = c.id AND TRIM(COALESCE(p.domicilio, '')) <> ''
          ORDER BY p.id DESC LIMIT 1
       )
 WHERE TRIM(COALESCE(c.domicilio, '')) = ''
   AND EXISTS (
         SELECT 1 FROM datarocket_prospectos p2
          WHERE p2.contacto_id = c.id AND TRIM(COALESCE(p2.domicilio, '')) <> ''
       );


-- ---------------------------------------------------------------------------
-- 7) ciudad <- p.ciudad
-- ---------------------------------------------------------------------------

UPDATE datarocket_contactos c
   SET c.ciudad = (
         SELECT NULLIF(TRIM(p.ciudad), '')
           FROM datarocket_prospectos p
          WHERE p.contacto_id = c.id AND TRIM(COALESCE(p.ciudad, '')) <> ''
          ORDER BY p.id DESC LIMIT 1
       )
 WHERE TRIM(COALESCE(c.ciudad, '')) = ''
   AND EXISTS (
         SELECT 1 FROM datarocket_prospectos p2
          WHERE p2.contacto_id = c.id AND TRIM(COALESCE(p2.ciudad, '')) <> ''
       );


-- ---------------------------------------------------------------------------
-- 8) ubicacion <- p.ubicacion
-- ---------------------------------------------------------------------------

UPDATE datarocket_contactos c
   SET c.ubicacion = (
         SELECT NULLIF(TRIM(p.ubicacion), '')
           FROM datarocket_prospectos p
          WHERE p.contacto_id = c.id AND TRIM(COALESCE(p.ubicacion, '')) <> ''
          ORDER BY p.id DESC LIMIT 1
       )
 WHERE TRIM(COALESCE(c.ubicacion, '')) = ''
   AND EXISTS (
         SELECT 1 FROM datarocket_prospectos p2
          WHERE p2.contacto_id = c.id AND TRIM(COALESCE(p2.ubicacion, '')) <> ''
       );


-- ---------------------------------------------------------------------------
-- 9) pais_id <- CAST(p.pais AS UNSIGNED)
-- ---------------------------------------------------------------------------

UPDATE datarocket_contactos c
   SET c.pais_id = (
         SELECT CAST(TRIM(p.pais) AS UNSIGNED)
           FROM datarocket_prospectos p
          WHERE p.contacto_id = c.id
            AND TRIM(COALESCE(p.pais, '')) REGEXP '^[0-9]+$'
            AND EXISTS (SELECT 1 FROM paises x WHERE x.id = CAST(TRIM(p.pais) AS UNSIGNED))
          ORDER BY p.id DESC LIMIT 1
       )
 WHERE c.pais_id IS NULL
   AND EXISTS (
         SELECT 1 FROM datarocket_prospectos p2
          WHERE p2.contacto_id = c.id
            AND TRIM(COALESCE(p2.pais, '')) REGEXP '^[0-9]+$'
            AND EXISTS (SELECT 1 FROM paises x2 WHERE x2.id = CAST(TRIM(p2.pais) AS UNSIGNED))
       );


-- ---------------------------------------------------------------------------
-- 10) provincia_id <- CAST(p.provincia AS UNSIGNED)
-- ---------------------------------------------------------------------------

UPDATE datarocket_contactos c
   SET c.provincia_id = (
         SELECT CAST(TRIM(p.provincia) AS UNSIGNED)
           FROM datarocket_prospectos p
          WHERE p.contacto_id = c.id
            AND TRIM(COALESCE(p.provincia, '')) REGEXP '^[0-9]+$'
            AND EXISTS (SELECT 1 FROM provincias x WHERE x.id = CAST(TRIM(p.provincia) AS UNSIGNED))
          ORDER BY p.id DESC LIMIT 1
       )
 WHERE c.provincia_id IS NULL
   AND EXISTS (
         SELECT 1 FROM datarocket_prospectos p2
          WHERE p2.contacto_id = c.id
            AND TRIM(COALESCE(p2.provincia, '')) REGEXP '^[0-9]+$'
            AND EXISTS (SELECT 1 FROM provincias x2 WHERE x2.id = CAST(TRIM(p2.provincia) AS UNSIGNED))
       );


-- ---------------------------------------------------------------------------
-- 11) localidad_id <- CAST(p.localidad AS UNSIGNED)
-- ---------------------------------------------------------------------------

UPDATE datarocket_contactos c
   SET c.localidad_id = (
         SELECT CAST(TRIM(p.localidad) AS UNSIGNED)
           FROM datarocket_prospectos p
          WHERE p.contacto_id = c.id
            AND TRIM(COALESCE(p.localidad, '')) REGEXP '^[0-9]+$'
            AND EXISTS (SELECT 1 FROM localidades x WHERE x.id = CAST(TRIM(p.localidad) AS UNSIGNED))
          ORDER BY p.id DESC LIMIT 1
       )
 WHERE c.localidad_id IS NULL
   AND EXISTS (
         SELECT 1 FROM datarocket_prospectos p2
          WHERE p2.contacto_id = c.id
            AND TRIM(COALESCE(p2.localidad, '')) REGEXP '^[0-9]+$'
            AND EXISTS (SELECT 1 FROM localidades x2 WHERE x2.id = CAST(TRIM(p2.localidad) AS UNSIGNED))
       );
