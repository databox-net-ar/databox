-- Renumera `datacount_empresas.id` (cloud, snake_case) para que cada empresa
-- comparta el mismo ID que su fila equivalente en la tabla legacy
-- `datacountempresas`. Al mismo tiempo, propaga el cambio a todas las FKs
-- cloud que apuntan a `datacount_empresas.id`.
--
-- Contexto:
--   En algun momento se aplico esta renumeracion a mano en dev (via SQL
--   directo, no como migracion), lo que dejo dev alineado con legacy pero
--   prod desincronizado. Esta migracion captura formalmente ese cambio para
--   poder aplicarlo de forma repetible y auditable en cualquier entorno.
--
-- Match cloud <-> legacy: por `nombre` normalizado (LOWER + TRIM). Filas de
-- `datacount_empresas` cuyo nombre no matchea ninguna legacy quedan como
-- estan.
--
-- Idempotencia:
--   - `WHERE dn.id <> dl.id` evita mover filas que ya estan en el ID target.
--     En dev, donde el fix ya se aplico, el INSERT al mapping devuelve 0 filas
--     y el resto es NO-OP.
--   - Correr esta migracion 2 veces es seguro (aunque el Migrador DB del
--     panel lo bloquea al insertar en `migraciones`).
--
-- Estrategia sin colisiones (dos fases):
--   Fase 1: mueve cada empresa a un ID de "parking" (old_id + 1000000).
--           Actualiza las FKs de las tablas hijas al parking. Como el rango
--           parking > 1000000 no colisiona con ningun ID real, el cambio de
--           PK no pisa filas existentes.
--   Fase 2: baja del parking al ID target (= legacy.id). Actualiza las FKs
--           hijas al ID target.
--
-- Atomicidad:
--   Todo dentro de START TRANSACTION + COMMIT. `CREATE TEMPORARY TABLE` NO
--   dispara implicit commit en MySQL/MariaDB (contrario a CREATE TABLE
--   comun), asi que la transaccion se mantiene.
--
--   La unica sentencia DDL (el ALTER AUTO_INCREMENT del final) va DESPUES del
--   COMMIT porque hace auto-commit por si sola.
--
-- Compatible con MySQL 8 (dev) y MariaDB 10.11 (prod).
-- Requiere que la tabla legacy `datacountempresas` exista (asuncion valida
-- en los entornos del grupo — el panel cloud convive con la legacy).

START TRANSACTION;

-- ---------------------------------------------------------------------------
-- Mapping: (old_id -> new_id, parking_id). Solo empresas que necesitan mover.
-- ---------------------------------------------------------------------------
CREATE TEMPORARY TABLE _empresa_renumber_map (
  old_id     INT NOT NULL PRIMARY KEY,
  new_id     INT NOT NULL,
  parking_id INT NOT NULL,
  UNIQUE KEY uk_new     (new_id),
  UNIQUE KEY uk_parking (parking_id)
) ENGINE = InnoDB;

INSERT INTO _empresa_renumber_map (old_id, new_id, parking_id)
SELECT dn.id, dl.id, dn.id + 1000000
FROM   datacount_empresas dn
JOIN   datacountempresas  dl ON LOWER(TRIM(dl.nombre)) = LOWER(TRIM(dn.nombre))
WHERE  dn.id <> dl.id;

-- ---------------------------------------------------------------------------
-- Fase 1: mover al rango parking (evita choque con filas que ocupan el target).
-- ---------------------------------------------------------------------------
UPDATE datacount_empresas   dn JOIN _empresa_renumber_map m ON dn.id         = m.old_id SET dn.id         = m.parking_id;

UPDATE datacount_recurrentes  t JOIN _empresa_renumber_map m ON t.empresa    = m.old_id SET t.empresa    = m.parking_id;
UPDATE datacount_talonarios   t JOIN _empresa_renumber_map m ON t.empresa    = m.old_id SET t.empresa    = m.parking_id;
UPDATE datacount_comprobantes t JOIN _empresa_renumber_map m ON t.empresa    = m.old_id SET t.empresa    = m.parking_id;
UPDATE datacount_pagos        t JOIN _empresa_renumber_map m ON t.empresa    = m.old_id SET t.empresa    = m.parking_id;
UPDATE datacount_empleados    t JOIN _empresa_renumber_map m ON t.empresa_id = m.old_id SET t.empresa_id = m.parking_id;
UPDATE datacount_cuentas      t JOIN _empresa_renumber_map m ON t.empresa_id = m.old_id SET t.empresa_id = m.parking_id;
UPDATE datacount_asientos     t JOIN _empresa_renumber_map m ON t.empresa_id = m.old_id SET t.empresa_id = m.parking_id;
UPDATE accesos                t JOIN _empresa_renumber_map m ON t.empresa_id = m.old_id SET t.empresa_id = m.parking_id;
UPDATE arca_autorizaciones    t JOIN _empresa_renumber_map m ON t.empresa_id = m.old_id SET t.empresa_id = m.parking_id;

-- ---------------------------------------------------------------------------
-- Fase 2: bajar del parking al ID target (= legacy.id).
-- ---------------------------------------------------------------------------
UPDATE datacount_empresas   dn JOIN _empresa_renumber_map m ON dn.id         = m.parking_id SET dn.id         = m.new_id;

UPDATE datacount_recurrentes  t JOIN _empresa_renumber_map m ON t.empresa    = m.parking_id SET t.empresa    = m.new_id;
UPDATE datacount_talonarios   t JOIN _empresa_renumber_map m ON t.empresa    = m.parking_id SET t.empresa    = m.new_id;
UPDATE datacount_comprobantes t JOIN _empresa_renumber_map m ON t.empresa    = m.parking_id SET t.empresa    = m.new_id;
UPDATE datacount_pagos        t JOIN _empresa_renumber_map m ON t.empresa    = m.parking_id SET t.empresa    = m.new_id;
UPDATE datacount_empleados    t JOIN _empresa_renumber_map m ON t.empresa_id = m.parking_id SET t.empresa_id = m.new_id;
UPDATE datacount_cuentas      t JOIN _empresa_renumber_map m ON t.empresa_id = m.parking_id SET t.empresa_id = m.new_id;
UPDATE datacount_asientos     t JOIN _empresa_renumber_map m ON t.empresa_id = m.parking_id SET t.empresa_id = m.new_id;
UPDATE accesos                t JOIN _empresa_renumber_map m ON t.empresa_id = m.parking_id SET t.empresa_id = m.new_id;
UPDATE arca_autorizaciones    t JOIN _empresa_renumber_map m ON t.empresa_id = m.parking_id SET t.empresa_id = m.new_id;

DROP TEMPORARY TABLE _empresa_renumber_map;

COMMIT;

-- ---------------------------------------------------------------------------
-- Reset AUTO_INCREMENT a MAX(id)+1 para que la proxima alta arranque limpia
-- despues del salto (fuera del transaction — es DDL, hace implicit commit).
-- Usa PREPARE porque MySQL/MariaDB no admiten expresiones en ALTER TABLE ...
-- AUTO_INCREMENT.
-- ---------------------------------------------------------------------------
SET @next := (SELECT COALESCE(MAX(id), 0) + 1 FROM datacount_empresas);
SET @sql  := CONCAT('ALTER TABLE datacount_empresas AUTO_INCREMENT = ', @next);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
