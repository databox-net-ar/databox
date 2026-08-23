-- datarocket_prospectos: sellar la procedencia de los prospectos Dex + Inmobiliaria.
--
-- Pedido explicito del 2026-08-23, gemelo de la migracion 20260823_1200 (que
-- hace lo mismo para Dex + Agencia con 'Eva Bot'). Los prospectos que tengan la
-- etiqueta `dex` Y ADEMAS `inmobiliaria` quedan con:
--
--   extraccion_autor = 'Martin Bot'
--   extraccion_url   = 'https://' + el valor de la columna `web`
--
-- Alcance: etiqueta 38 (`dex`) AND etiqueta 12 (`inmobiliaria`). En prod al
-- 2026-08-23 son 72 filas, todas con `web` cargado. `inmobiliaria` no tiene
-- variante en plural en el catalogo, asi que aca no hay ambiguedad de etiqueta
-- como si la habia entre `agencia` (40) y `agencias` (41).
--
-- NO SE PISA CON LA 20260823_1200: el solapamiento entre
-- (dex + inmobiliaria) y (dex + agencia/agencias) es de CERO filas, verificado
-- en prod antes de escribir esto. Las dos migraciones son independientes y el
-- orden en que se apliquen da igual.
--
-- ---------------------------------------------------------------------------
-- QUE SE PISA (leer antes de correr esto de nuevo)
-- ---------------------------------------------------------------------------
-- Estado previo de las 72 filas:
--   * `extraccion_autor`: 43 en NULL, 23 con 'Eva Bot' y 6 ya con 'Martin Bot'.
--     O SEA QUE ESTO REETIQUETA 23 FILAS DE 'Eva Bot' A 'Martin Bot'. Se hace
--     porque se pidio para TODOS los que tengan las dos etiquetas, y se
--     advirtio antes de correrlo.
--   * `extraccion_url`: 6 ya coincidian con 'https://' + web (justamente las de
--     'Martin Bot' — el patron ya estaba aplicado ahi); el resto estaban vacias.
--
-- A diferencia del lote de Agencia, aca NO se pierde precision: la columna `web`
-- de estas filas ya apunta a las paginas de `/tienda-virtual/contacto/...`, que
-- es la fuente real del dato, no al listado.
--
-- BACKUP de los valores previos (id / web / extraccion_url / extraccion_autor
-- de las 72 filas), tomado justo antes de correr esto:
--   c:\tmp\databox_backup\prod_extraccion_dex_inmobiliaria_20260823.json
-- Para revertir: reponer los dos campos fila por fila desde ese JSON.
--
-- ---------------------------------------------------------------------------
-- DETALLES
-- ---------------------------------------------------------------------------
-- Mismas reglas que la 20260823_1200: `web` se guarda SIN esquema (ver
-- cloud/api/lib/prospectos_normalizar.php) asi que se antepone `https://`, con
-- un CASE que evita duplicarlo si alguna fila ya lo trajera. Solo se tocan las
-- filas con `web` no vacio — NULL y '' cuentan los dos como vacio.
--
-- IDEMPOTENTE: la segunda corrida no matchea ninguna fila.
--
-- Compatible MySQL 8 (dev) + MariaDB 10.11 (prod).

-- ============================================================================
-- Paso 1: extraccion_autor = 'Martin Bot'
-- ============================================================================

UPDATE datarocket_prospectos p
   SET p.extraccion_autor = 'Martin Bot'
 WHERE EXISTS (SELECT 1 FROM datarocket_prospectos_etiquetas x
                WHERE x.prospecto_id = p.id AND x.etiqueta_id = 38)
   AND EXISTS (SELECT 1 FROM datarocket_prospectos_etiquetas y
                WHERE y.prospecto_id = p.id AND y.etiqueta_id = 12)
   AND (p.extraccion_autor IS NULL OR p.extraccion_autor <> 'Martin Bot');

-- ============================================================================
-- Paso 2: extraccion_url = 'https://' + web
-- ============================================================================

UPDATE datarocket_prospectos p
   SET p.extraccion_url = CASE
         WHEN p.web LIKE 'http://%' OR p.web LIKE 'https://%' THEN p.web
         ELSE CONCAT('https://', p.web)
       END
 WHERE EXISTS (SELECT 1 FROM datarocket_prospectos_etiquetas x
                WHERE x.prospecto_id = p.id AND x.etiqueta_id = 38)
   AND EXISTS (SELECT 1 FROM datarocket_prospectos_etiquetas y
                WHERE y.prospecto_id = p.id AND y.etiqueta_id = 12)
   AND p.web IS NOT NULL
   AND p.web <> ''
   AND (p.extraccion_url IS NULL
        OR p.extraccion_url <> CASE
             WHEN p.web LIKE 'http://%' OR p.web LIKE 'https://%' THEN p.web
             ELSE CONCAT('https://', p.web)
           END);
