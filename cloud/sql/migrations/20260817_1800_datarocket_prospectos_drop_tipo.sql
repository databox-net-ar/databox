-- datarocket_prospectos: DROP de la columna `tipo` (VARCHAR(1)).
--
-- Heredada del ABM legacy de Datasale. Se va sin backfill previo, y esto es
-- seguro por dos motivos verificados en dev al 2026-08-17:
--
-- 1) EL DATO NUNCA SE MOSTRO. La columna tiene 1038 valores cargados sobre
--    1336 filas, pero son U (703), I (319), O (9) y M (7) — y el catalogo
--    `estados.datasale_prospecto_tipo` solo define C = Consumidor y R =
--    Revendedor. Ningun valor guardado matchea el catalogo, asi que
--    `tipo_texto` resolvia NULL en el 100% de las filas y el panel mostraba
--    "—". Peor: el <select> del formulario ofrecia C/R, con lo cual editar
--    cualquier prospecto pisaba el valor legacy con vacio. El campo estaba
--    roto de nacimiento en el ABM cloud.
--
-- 2) EL DATO SOBREVIVE EN LA TABLA LEGACY. Los 1336 ids de
--    `datarocket_prospectos` existen en `datasaleprospectos` con el MISMO
--    valor de `tipo` (verificado: 1336 de 1336 coinciden). Si alguna vez se
--    decodifica que significan U/I/O/M, el dato se puede recuperar de ahi con
--    un UPDATE por id.
--
-- NO se tocan las filas de `estados` con campo `datasale_prospecto_tipo`: el
-- prefijo `datasale_prospecto_` es compartido con el ABM legacy /prospectos,
-- que sigue leyendo `datasaleprospectos.tipo` y necesita su catalogo.
--
-- ORDEN DE DESPLIEGUE: esta migracion se aplica DESPUES de publicar el codigo
-- que deja de leer la columna (DR_PRO_COLS, DR_PRO_COMBO_CAMPOS, filtro
-- `?tipo=`, orden por `tipo`, INSERT/UPDATE). Al reves, el endpoint viejo tira
-- 500 en cada listado hasta el deploy.
--
-- Idempotente: el DROP se guarda con information_schema.
-- Compatible MySQL 8 (dev) + MariaDB 10.11 (prod).


-- ---------------------------------------------------------------------------
-- 1) tipo
-- ---------------------------------------------------------------------------
SET @existe := (SELECT COUNT(*) FROM information_schema.COLUMNS
                 WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'datarocket_prospectos'
                   AND COLUMN_NAME = 'tipo');
SET @sql := IF(@existe = 1, 'ALTER TABLE datarocket_prospectos DROP COLUMN `tipo`', 'DO 0');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
