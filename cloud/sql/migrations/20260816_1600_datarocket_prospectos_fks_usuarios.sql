-- Datarocket: FKs de prospectos a `usuarios`.
--
--   datarocket_prospectos.asignado -> usuarios.id
--   datarocket_prospectos.atendido -> usuarios.id
--
-- Cierra las dos ultimas columnas de `datarocket_prospectos` que apuntaban a
-- otra tabla por convencion y no por InnoDB (las otras cuatro ya quedaron
-- ancladas en 20260812_0400, 20260812_1000 y 20260815_1200).
--
-- Estado de partida (relevado el 2026-08-16 contra dev y prod, identicos):
--
--   * Ambas columnas ya son INT(11) NULL y `usuarios.id` es INT(11) con signo
--     — no hay conversion de tipo que hacer, esto es solo indice + constraint.
--     Las tres tablas involucradas son InnoDB en ambos entornos.
--   * 0 NULL en las dos columnas, pero el centinela historico "sin usuario" es
--     el 0, no el NULL: 1056 filas con `asignado` = 0 y 879 con `atendido` = 0
--     (identico en dev y prod, sobre 1336 / 1344 filas). El paso 1 los pasa a
--     NULL, que es lo que ya escribe el ABM cloud hoy (`nullIfEmpty` en el
--     front + `drProNullableInt` en la API mandan NULL cuando el combo esta
--     vacio, nunca 0).
--   * `asignado` no tiene huerfanos. `atendido` si: 86 filas apuntan a los
--     usuarios 101 (16), 102 (6), 103 (54), 104 (4) y 139 (6), que no existen
--     mas en `usuarios` (la tabla arranca hoy en el id 5001). Son ids del admin
--     viejo, borrados en algun momento; el dato de "quien lo atendio" ya estaba
--     perdido — la FK solo lo hace explicito pasandolos a NULL. Mismos numeros
--     exactos en dev y prod.
--
-- Nombres: prefijo corto `dr_`, el mismo del resto de la tabla
-- (`fk_dr_prospectos_contacto/embudo/etapa/proyecto`).
--
-- FK ON DELETE RESTRICT / ON UPDATE RESTRICT: mismo criterio que el resto del
-- proyecto. OJO que estas son las primeras FKs del esquema que apuntan a
-- `usuarios`, tabla compartida con el admin legacy: a partir de aca, borrar un
-- usuario que todavia figura como asignado o atendido en un prospecto va a
-- fallar ruidosamente tambien desde esa UI. Es justamente lo que queremos —
-- son los 86 huerfanos de arriba los que muestran como termina la alternativa.
--
-- Idempotente: cada paso se guarda con information_schema.
-- Compatible MySQL 8 (dev) + MariaDB 10.11 (prod).


-- ---------------------------------------------------------------------------
-- 1) Limpieza previa: el centinela 0 y los huerfanos pasan a NULL. A diferencia
--    de 20260815_1200, aca si matchea filas (ver relevamiento de arriba).
--    Naturalmente idempotente (con la FK puesta ya no puede haber huerfanos).
-- ---------------------------------------------------------------------------

UPDATE datarocket_prospectos SET asignado = NULL WHERE asignado = 0;
UPDATE datarocket_prospectos SET atendido = NULL WHERE atendido = 0;

UPDATE datarocket_prospectos p
   SET p.asignado = NULL
 WHERE p.asignado IS NOT NULL
   AND NOT EXISTS (SELECT 1 FROM usuarios u WHERE u.id = p.asignado);

UPDATE datarocket_prospectos p
   SET p.atendido = NULL
 WHERE p.atendido IS NOT NULL
   AND NOT EXISTS (SELECT 1 FROM usuarios u WHERE u.id = p.atendido);


-- ---------------------------------------------------------------------------
-- 2) Indices explicitos (InnoDB crearia uno implicito con la FK, pero con
--    nombre automatico). Ademas los usa el filtro por asignado / atendido del
--    listado de prospectos, que hoy hace scan.
-- ---------------------------------------------------------------------------

SET @exists := (
  SELECT COUNT(*) FROM information_schema.STATISTICS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'datarocket_prospectos'
    AND INDEX_NAME = 'idx_dr_prospectos_asignado'
);
SET @sql := IF(@exists = 0,
  'ALTER TABLE datarocket_prospectos ADD INDEX `idx_dr_prospectos_asignado` (`asignado`)',
  'DO 0'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @exists := (
  SELECT COUNT(*) FROM information_schema.STATISTICS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'datarocket_prospectos'
    AND INDEX_NAME = 'idx_dr_prospectos_atendido'
);
SET @sql := IF(@exists = 0,
  'ALTER TABLE datarocket_prospectos ADD INDEX `idx_dr_prospectos_atendido` (`atendido`)',
  'DO 0'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;


-- ---------------------------------------------------------------------------
-- 3) Las FKs.
-- ---------------------------------------------------------------------------

SET @exists := (
  SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'datarocket_prospectos'
    AND CONSTRAINT_NAME = 'fk_dr_prospectos_asignado'
);
SET @sql := IF(@exists = 0,
  'ALTER TABLE datarocket_prospectos
     ADD CONSTRAINT `fk_dr_prospectos_asignado` FOREIGN KEY (`asignado`)
         REFERENCES `usuarios` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT',
  'DO 0'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @exists := (
  SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'datarocket_prospectos'
    AND CONSTRAINT_NAME = 'fk_dr_prospectos_atendido'
);
SET @sql := IF(@exists = 0,
  'ALTER TABLE datarocket_prospectos
     ADD CONSTRAINT `fk_dr_prospectos_atendido` FOREIGN KEY (`atendido`)
         REFERENCES `usuarios` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT',
  'DO 0'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
