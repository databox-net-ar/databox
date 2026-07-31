-- Importa los certificados de AFIP desde la tabla legacy `datacountempresas`
-- (mismo `databox_dev` que la app nueva) hacia `arca_certificados`, y asigna
-- cada certificado a la empresa correspondiente en `datacount_empresas`
-- (nueva) matcheando por CUIT.
--
-- Motivo:
--   En el sistema legacy los certificados X.509 + llaves RSA que AFIP emitio
--   para cada CUIT viven directamente en columnas TEXT de `datacountempresas`.
--   El microservicio nuevo `/v4/arca/*` habla directo con AFIP y necesita
--   los mismos PEM para firmar el TRA con openssl_pkcs7_sign. Esta migracion:
--     1. Copia los PEM tal cual (ya vienen con newlines reales 0x0D0A -- el
--        REPLACE de '\n' literal es defensivo por si alguien alguna vez graba
--        certs escapados, no toca los datos actuales del legacy).
--     2. Solo importa lo que aun no esta en `arca_certificados` (idempotente).
--     3. Solo asigna `certificado_id` a empresas nuevas que aun lo tienen NULL
--        (no pisa asignaciones que el operador ya hizo desde el ABM).
--     4. Es tolerante al caso "no hay tabla legacy" (fresh install en servidor
--        nuevo sin databox_legacy): chequea existencia via information_schema
--        y hace no-op si no esta.
--
--   Match empresa nueva <-> empresa legacy: se hace por CUIT normalizado
--   (quitando guiones), no por nombre. Es la unica identificacion estable
--   compartida entre los dos schemas.
--
-- Compatible con MySQL 8 (dev) y MariaDB 10.11 (prod).

-- ---------------------------------------------------------------------------
-- Paso 1: importar certs del legacy que aun no esten en arca_certificados.
-- Se identifica cada cert por `nombre` (la empresa legacy). Si ya existe una
-- fila con ese nombre en arca_certificados, se asume que ya se importo.
--
-- Todo envuelto en PREPARE + IF (tabla existe) para no romper fresh installs.
-- ---------------------------------------------------------------------------
SET @has_legacy := (
  SELECT COUNT(*) FROM information_schema.TABLES
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME   = 'datacountempresas'
);

SET @sql := IF(@has_legacy = 1,
  'INSERT INTO arca_certificados (nombre, llave, certificado, actualizado)
   SELECT
     l.nombre,
     REPLACE(l.llave,       ''\\\\n'', CHAR(10)),
     REPLACE(l.certificado, ''\\\\n'', CHAR(10)),
     NOW()
   FROM datacountempresas l
   WHERE l.certificado IS NOT NULL AND l.certificado <> ''''
     AND l.llave       IS NOT NULL AND l.llave       <> ''''
     AND l.cuit        IS NOT NULL AND l.cuit        <> ''''
     AND NOT EXISTS (
       SELECT 1 FROM arca_certificados a WHERE a.nombre = l.nombre
     )',
  'DO 0'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- ---------------------------------------------------------------------------
-- Paso 2: asignar certificado_id a empresas nuevas que no lo tengan, buscando
-- la empresa legacy con el mismo CUIT (normalizado quitando guiones) y
-- tomando el arca_certificados que tenga el mismo nombre que esa empresa
-- legacy.
--
-- El JOIN triple garantiza que solo asignamos si:
--   - la empresa nueva no tiene cert (dn.certificado_id IS NULL)
--   - hay una empresa legacy con mismo CUIT
--   - existe un cert en arca_certificados con el nombre de esa empresa legacy
-- ---------------------------------------------------------------------------
SET @sql := IF(@has_legacy = 1,
  'UPDATE datacount_empresas dn
     JOIN datacountempresas  dl ON REPLACE(dl.cuit, ''-'', '''') = dn.cuit
     JOIN arca_certificados  ac ON ac.nombre = dl.nombre
      SET dn.certificado_id = ac.id
    WHERE dn.certificado_id IS NULL
      AND dl.certificado IS NOT NULL AND dl.certificado <> ''''
      AND dl.cuit        IS NOT NULL AND dl.cuit        <> ''''
      AND dn.cuit        IS NOT NULL AND dn.cuit        <> ''''',
  'DO 0'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
