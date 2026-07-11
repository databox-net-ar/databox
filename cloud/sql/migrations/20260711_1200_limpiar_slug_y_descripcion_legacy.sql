-- Blanquea `slug` y `descripcion` en `roles` y `permisos`.
--
-- Todo lo cargado hasta hoy corresponde al sistema legacy (otra UI del grupo),
-- que solo usa el `id` de cada rol/permiso: nunca lee `slug` ni `descripcion`.
-- El backfill previo (20260702_1500 / 20260702_1600) habia autogenerado un slug
-- a partir de `nombre` para todas las filas — lo revertimos aca para que el
-- discriminador "legacy vs cloud" sea limpio: legacy => slug NULL, cloud => slug
-- con valor. Aprovechamos y limpiamos `descripcion` en las mismas filas, ya que
-- tampoco se uso desde el legacy y de esta forma queda toda la data historica en
-- estado neutro.
--
-- Idempotente: los WHERE evitan reescribir filas ya en NULL, asi correrla dos
-- veces no hace ruido (no cambia filas, no dispara triggers).

UPDATE `roles`    SET `slug`        = NULL WHERE `slug`        IS NOT NULL;
UPDATE `roles`    SET `descripcion` = NULL WHERE `descripcion` IS NOT NULL;
UPDATE `permisos` SET `slug`        = NULL WHERE `slug`        IS NOT NULL;
UPDATE `permisos` SET `descripcion` = NULL WHERE `descripcion` IS NOT NULL;
