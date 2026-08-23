-- datarocket_prospectos: agregar `origen_url` — la URL de donde salio el dato.
--
-- Procedencia del prospecto: el link exacto desde el que se obtuvo la ficha
-- (la pagina del padron, el listado del portal, el perfil del marketplace, el
-- resultado de busqueda, el formulario que se completo). Sirve para volver a
-- la fuente y verificar / re-scrapear, y para saber de que campaña o barrido
-- vino cada fila.
--
-- NO se pisa con `web`, que es otra cosa: `web` es el sitio DEL prospecto
-- (`bna.com.ar/sucursales`, host+path sin esquema, normalizado y bajado a
-- minuscula por cloud/api/lib/prospectos_normalizar.php). `origen_url` es la
-- URL DONDE LO ENCONTRAMOS y se guarda TAL CUAL vino:
--   * con esquema (`https://...`), porque es un link para volver a abrir;
--   * sin bajar a minuscula, porque el path y el query son case sensitive y
--     ahi viven justamente los ids de resultado (`?id=Xk9Q`, `/p/MLA-123`)
--     que hacen unica a la fuente. Bajarlos, como hace `web`, la romperia.
-- Por eso tampoco pasa por prospectoNormalizarWeb(): solo se recorta y se
-- trunca (nullableStr en cloud/api/datarocketprospectos.php).
--
-- VARCHAR(500) y no 255 como `web`: las URLs de origen son URLs completas de
-- navegador, con esquema, query y parametros de tracking — 255 se queda corto
-- seguido.
--
-- La columna queda DESPUES de `web` (AFTER `web`) para que el orden fisico
-- acompañe al de la UI: en los dos modales del ABM (Consultar y Alta/Edicion)
-- `URL de origen` va inmediatamente debajo de `Web`, al final de la pestaña
-- Contacto.
--
-- Nullable y sin backfill: las ~148k filas historicas no tienen de donde
-- sacar el dato y quedan en NULL. Se completa al editarlas o desde las cargas
-- nuevas.
--
-- Idempotente. Compatible MySQL 8 (dev) + MariaDB 10.11 (prod): sin
-- `ADD COLUMN IF NOT EXISTS` de MariaDB (patron information_schema +
-- PREPARE/EXECUTE), sin funciones almacenadas y sin tablas temporales.

SET @exists := (
  SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME   = 'datarocket_prospectos'
    AND COLUMN_NAME  = 'origen_url'
);
SET @sql := IF(@exists = 0,
  'ALTER TABLE datarocket_prospectos ADD COLUMN `origen_url` VARCHAR(500) NULL DEFAULT NULL AFTER `web`',
  'DO 0'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
