-- datarocket_prospectos: sellar la procedencia de los prospectos Dex + Agencia.
--
-- Pedido explicito del 2026-08-23: los prospectos que tengan la etiqueta `dex`
-- Y ADEMAS `agencia` o `agencias` quedan con:
--
--   extraccion_autor = 'Eva Bot'
--   extraccion_url   = 'https://' + el valor de la columna `web`
--
-- Alcance: etiqueta 38 (`dex`) AND etiqueta 40 (`agencia`) o 41 (`agencias`).
-- En prod al 2026-08-23 son 126 filas (124 con `agencia` + 2 con `agencias`),
-- todas con `web` cargado.
--
-- ---------------------------------------------------------------------------
-- QUE SE PISA (leer antes de correr esto de nuevo)
-- ---------------------------------------------------------------------------
-- Las 126 filas YA tenian los dos campos cargados por Eva Bot:
--   * `extraccion_autor` ya decia 'Eva Bot' -> ese UPDATE es un no-op.
--   * `extraccion_url` apuntaba a la pagina de CONTACTO del anuncio
--     (`…/tienda-virtual/contacto/98308/…`), mientras que `web` apunta al
--     LISTADO (`…/tienda-virtual/anuncios-listado/98308/…`). Son URLs
--     DISTINTAS: ninguna de las 126 coincidia.
--
-- O sea que esto DESCARTA la URL de contacto y la reemplaza por la del
-- listado. Se hace porque se pidio explicitamente despues de que se
-- advirtieran las dos consecuencias:
--   1. la URL de contacto no se puede reconstruir desde la del listado;
--   2. si el bot venia deduplicando contra la URL de contacto, deja de
--      matchear y hay que reapuntarlo a la del listado.
--
-- BACKUP de los valores previos (id / web / extraccion_url / extraccion_autor
-- de las 126 filas), tomado justo antes de correr esto:
--   c:\tmp\databox_backup\prod_extraccion_dex_agencia_20260823.json
-- Para revertir: reponer `extraccion_url` fila por fila desde ese JSON.
--
-- ---------------------------------------------------------------------------
-- DETALLES
-- ---------------------------------------------------------------------------
-- `web` se guarda SIN esquema y en minuscula (ver
-- cloud/api/lib/prospectos_normalizar.php), asi que hay que anteponer el
-- `https://` a mano. El CASE lo hace condicional igual: si alguna fila tuviera
-- el esquema adentro —no deberia, pero la columna es texto libre para lo
-- historico— no se le pone dos veces.
--
-- Solo se tocan las filas con `web` no vacio: dejar `extraccion_url` en
-- 'https://' pelado seria peor que dejarlo como estaba. La columna arrastra
-- NULL y '' como vacio (el '' viene del default historico), asi que se
-- descartan los dos.
--
-- IDEMPOTENTE: correrlo dos veces deja exactamente lo mismo — el segundo pase
-- encuentra `extraccion_url` ya igual al valor destino y el WHERE no matchea
-- ninguna fila.
--
-- Compatible MySQL 8 (dev) + MariaDB 10.11 (prod): sin funciones almacenadas,
-- sin tablas temporales y sin sintaxis exclusiva de ninguno de los dos.

-- ============================================================================
-- Paso 1: extraccion_autor = 'Eva Bot'
-- ============================================================================

UPDATE datarocket_prospectos p
   SET p.extraccion_autor = 'Eva Bot'
 WHERE EXISTS (SELECT 1 FROM datarocket_prospectos_etiquetas x
                WHERE x.prospecto_id = p.id AND x.etiqueta_id = 38)
   AND EXISTS (SELECT 1 FROM datarocket_prospectos_etiquetas y
                WHERE y.prospecto_id = p.id AND y.etiqueta_id IN (40, 41))
   AND (p.extraccion_autor IS NULL OR p.extraccion_autor <> 'Eva Bot');

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
                WHERE y.prospecto_id = p.id AND y.etiqueta_id IN (40, 41))
   AND p.web IS NOT NULL
   AND p.web <> ''
   AND (p.extraccion_url IS NULL
        OR p.extraccion_url <> CASE
             WHEN p.web LIKE 'http://%' OR p.web LIKE 'https://%' THEN p.web
             ELSE CONCAT('https://', p.web)
           END);
