-- datarocket_prospectos: agrega los tres campos que le faltaban a la tabla para
-- ser una oportunidad completa en el sentido CRM del termino:
--
--   monto            DECIMAL(14,2)  valor del negocio
--   moneda           VARCHAR(3)     ISO-4217 ('ARS' / 'USD')
--   cierre_esperado  DATE           fecha estimada de cierre
--
-- Sin `monto` no hay forecast, ni valor de embudo, ni win-rate por importe —
-- que es justamente para lo que sirve el objeto Oportunidad. La etapa ya
-- aportaba `tipo` (activa / ganada / perdida) y `probabilidad`, que hasta ahora
-- no se usaban para nada; con `monto` pasan a alimentar el pipeline ponderado
-- (SUM(monto * probabilidad / 100) sobre las etapas activas).
--
-- Sobre `moneda`: se usa el codigo ISO de 3 letras siguiendo la convencion de
-- las tablas cloud nuevas (`datainfra_dominios.moneda`), NO el varchar(1) 'P'/'D'
-- de las tablas legacy de Datacount. El DEFAULT 'ARS' hace que las 1336 filas
-- existentes queden en pesos; como todas tienen `monto` NULL, el valor es
-- inocuo hasta que alguien cargue un importe.
--
-- Sobre el catalogo de `estados`: el campo se llama `datarocket_prospecto_moneda`
-- y NO reusa el prefijo heredado `datasale_prospecto_` que usan los otros combos
-- del modulo (sentido / origen / tipo / estado / producto). Razon: `monto` y
-- `moneda` son campos propios de Datarocket — el ABM legacy de Datasale no
-- maneja importes, asi que compartir el catalogo seria mentir sobre el dominio.
--
-- Idempotente: cada ADD COLUMN se guarda con information_schema y el seed de
-- `estados` usa INSERT ... WHERE NOT EXISTS (la tabla no tiene UNIQUE).
-- Compatible MySQL 8 (dev) + MariaDB 10.11 (prod).


-- ---------------------------------------------------------------------------
-- 1) monto
-- ---------------------------------------------------------------------------

SET @existe := (
  SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME   = 'datarocket_prospectos'
    AND COLUMN_NAME  = 'monto'
);
SET @sql := IF(@existe = 0,
  'ALTER TABLE datarocket_prospectos
     ADD COLUMN `monto` DECIMAL(14,2) NULL DEFAULT NULL AFTER `producto`',
  'DO 0'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;


-- ---------------------------------------------------------------------------
-- 2) moneda
-- ---------------------------------------------------------------------------

SET @existe := (
  SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME   = 'datarocket_prospectos'
    AND COLUMN_NAME  = 'moneda'
);
SET @sql := IF(@existe = 0,
  'ALTER TABLE datarocket_prospectos
     ADD COLUMN `moneda` VARCHAR(3)
     CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NULL DEFAULT ''ARS'' AFTER `monto`',
  'DO 0'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;


-- ---------------------------------------------------------------------------
-- 3) cierre_esperado
-- ---------------------------------------------------------------------------

SET @existe := (
  SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME   = 'datarocket_prospectos'
    AND COLUMN_NAME  = 'cierre_esperado'
);
SET @sql := IF(@existe = 0,
  'ALTER TABLE datarocket_prospectos
     ADD COLUMN `cierre_esperado` DATE NULL DEFAULT NULL AFTER `moneda`',
  'DO 0'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;


-- ---------------------------------------------------------------------------
-- 4) Indice por fecha de cierre — el forecast filtra por periodo
--    ("que voy a cerrar este trimestre").
-- ---------------------------------------------------------------------------

SET @existe := (
  SELECT COUNT(*) FROM information_schema.STATISTICS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME   = 'datarocket_prospectos'
    AND INDEX_NAME   = 'idx_dr_prospectos_cierre'
);
SET @sql := IF(@existe = 0,
  'ALTER TABLE datarocket_prospectos
     ADD INDEX `idx_dr_prospectos_cierre` (`cierre_esperado`)',
  'DO 0'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;


-- ---------------------------------------------------------------------------
-- 5) Catalogo de monedas en `estados`.
-- ---------------------------------------------------------------------------

INSERT INTO estados (campo, valor, texto, orden)
SELECT 'datarocket_prospecto_moneda', 'ARS', 'Pesos', 1
 WHERE NOT EXISTS (
   SELECT 1 FROM estados
    WHERE campo = 'datarocket_prospecto_moneda' AND valor = 'ARS'
 );

INSERT INTO estados (campo, valor, texto, orden)
SELECT 'datarocket_prospecto_moneda', 'USD', 'Dolares', 2
 WHERE NOT EXISTS (
   SELECT 1 FROM estados
    WHERE campo = 'datarocket_prospecto_moneda' AND valor = 'USD'
 );
