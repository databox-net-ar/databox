-- Transferencia one-shot de la tabla legacy `hipervisorservicios` a la nueva
-- `datainfra_endpoints`. Ambas tablas modelan lo mismo (endpoints contra los
-- que se corre un health-check periodico), pero con esquemas distintos: la
-- legacy separa protocolo/puerto/endpoint/recurso en 4 columnas, guarda el
-- resultado como '1'/'0' y usa el estado 'nunca' implicitamente por
-- `probado IS NULL`. La nueva guarda la URL completa lista para cURL y
-- normaliza el estado como ENUM.
--
-- REGLAS DE MAPEO
--
--   nombre           <- nombre                (cabe en VARCHAR(120))
--   descripcion      <- 'Migrado desde hipervisorservicios #<id>' (trazabilidad)
--   url              <- protocolo://endpoint[:puerto]/recurso
--                       * puerto solo si NO es el default del protocolo
--                         (http:80, https:443)
--                       * recurso solo si no esta vacio; se prepende '/' si falta
--   metodo           <- 'GET' fijo (el legacy siempre hacia GET)
--   codigo_esperado  <- 200  fijo
--   timeout_seg      <- 15   default
--   activo           <- habilitado='1' -> 1 ; else 0
--   ultimo_check     <- probado
--   ultimo_estado    <- probado IS NULL         -> 'nunca'
--                       resultado = '1'         -> 'ok'
--                       resto                   -> 'error'
--   ultimo_codigo    <- NULL  (el legacy no guardaba HTTP code)
--   ultimo_tiempo_ms <- NULL  (idem)
--   ultimo_error     <- detalle  (NULL si vacio)
--
-- QUE SE FILTRA
--
--   Solo se migran filas con protocolo IN ('http','https'). Las mqtt/mysql
--   (11 filas en el snapshot inicial) no son endpoints HTTP y el
--   health-check con cURL no puede testearlos -- si se los migrara,
--   quedarian en `error` permanentemente y ensuciarian el dashboard.
--
--   usuario / contrasena de la legacy no se migran. En el snapshot inicial
--   ninguna de las 81 filas migrables los usa, y el legacy no arma Basic
--   Auth con ellos (los tenia como campos informativos). Si mas adelante
--   hace falta autenticacion, se agrega manualmente al `headers` desde el
--   ABM.
--
-- IDEMPOTENCIA
--
--   INSERT ... SELECT ... WHERE NOT EXISTS por `nombre`: si la fila ya se
--   migro (o el operador ya cargo un endpoint con el mismo nombre desde el
--   ABM) no se duplica. Safe re-run. NO se actualizan filas ya presentes --
--   si el operador edito el endpoint despues de la primera migracion,
--   respetamos su version.

INSERT INTO `datainfra_endpoints` (
  nombre,
  descripcion,
  url,
  metodo,
  codigo_esperado,
  timeout_seg,
  activo,
  ultimo_check,
  ultimo_estado,
  ultimo_codigo,
  ultimo_tiempo_ms,
  ultimo_error
)
SELECT
  h.nombre                                                              AS nombre,
  CONCAT('Migrado desde hipervisorservicios #', h.id)                   AS descripcion,
  CONCAT(
    h.protocolo, '://', h.endpoint,
    CASE
      WHEN h.puerto IS NULL OR h.puerto = ''                THEN ''
      WHEN h.protocolo = 'http'  AND h.puerto = '80'        THEN ''
      WHEN h.protocolo = 'https' AND h.puerto = '443'       THEN ''
      ELSE CONCAT(':', h.puerto)
    END,
    CASE
      WHEN h.recurso IS NULL OR h.recurso = ''              THEN ''
      WHEN LEFT(h.recurso, 1) = '/'                         THEN h.recurso
      ELSE CONCAT('/', h.recurso)
    END
  )                                                                     AS url,
  'GET'                                                                 AS metodo,
  200                                                                   AS codigo_esperado,
  15                                                                    AS timeout_seg,
  CASE WHEN h.habilitado = '1' THEN 1 ELSE 0 END                        AS activo,
  h.probado                                                             AS ultimo_check,
  CASE
    WHEN h.probado IS NULL     THEN 'nunca'
    WHEN h.resultado = '1'     THEN 'ok'
    ELSE                            'error'
  END                                                                   AS ultimo_estado,
  NULL                                                                  AS ultimo_codigo,
  NULL                                                                  AS ultimo_tiempo_ms,
  CASE
    WHEN h.detalle IS NULL OR h.detalle = '' THEN NULL
    ELSE h.detalle
  END                                                                   AS ultimo_error
FROM `hipervisorservicios` h
WHERE h.protocolo IN ('http','https')
  AND NOT EXISTS (
    SELECT 1 FROM `datainfra_endpoints` d
     WHERE d.nombre = h.nombre
  );
