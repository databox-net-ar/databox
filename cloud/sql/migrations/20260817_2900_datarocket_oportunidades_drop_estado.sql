-- datarocket_oportunidades: dropea `estado` (tinyint legacy) y su catalogo.
--
--   1) DELETE de las 3 filas `estados.campo = 'datarocket_oportunidad_estado'`
--   2) DROP COLUMN `datarocket_oportunidades.estado`
--
-- POR QUE SE VA
-- `estado` es el ANTECESOR de `etapa_id`, no una dimension paralela: la
-- migracion 20260812_0400 creo `etapa_id` derivandolo de el (estado=1 -> etapa
-- 'Nuevo', 2 -> 'Contactado', 3 -> 'Ganado'). Desde entonces conviven el campo
-- viejo y el nuevo, y el viejo se actualiza a mano — por eso quedo fosilizado:
-- 1335 de 1348 filas en '3'.
--
-- Medido sobre las 1348 filas de dev antes del drop, no queda una sola fila con
-- informacion propia. El valor se reconstruye entero desde campos que siguen
-- vivos:
--
--   estado = 3 (Despachado)  <=>  etapa de tipo 'ganada'            1335/1335
--   estado = 2 (Atendido)    <=>  etapa 'activa' + `atendido` cargado    11/11
--   estado = 1 (Esperando)   <=>  etapa 'activa' + `atendido` vacio +
--                                 >=1 interaccion entrante sin responder  2/2
--
-- Cero filas contradicen el mapeo. El eje "hay algo pendiente de responder"
-- (que es lo que 'Esperando' queria decir) vive mejor en
-- `datarocket_interacciones`: se sella solo al marcar la respuesta, mientras
-- que `estado` dependia de que alguien se acordara de moverlo.
--
-- Si en el futuro hiciera falta el dato historico, la consulta de arriba lo
-- regenera. No se guarda copia: el UPDATE que lo mantenia ya no existe y una
-- tabla de respaldo congelada envejeceria peor que la formula.
--
-- La columna no tiene indice ni FK, y ningun consumidor fuera de `cloud/` la
-- lee (ni api/v4, ni robot, ni www). Del lado del panel se limpian en el mismo
-- deploy cloud/api/datarocket_oportunidades.php (columnas, combo, filtro
-- ?estado=, orden, sanitize, INSERT/UPDATE) y cloud/assets/js/app.js (columna
-- del listado, badge, chips de filtro, combo del modal de edicion y tarjeta del
-- modal Consultar).
--
-- OJO con los homonimos que NO se tocan:
--   * la TABLA `estados` sigue existiendo: solo se borran sus 3 filas del campo
--     `datarocket_oportunidad_estado`. Los otros prefijos
--     (`datarocket_oportunidad_sentido` / `_origen` / `_moneda`) quedan intactos.
--   * `usuarios.estado`, `servidores.estado` y demas no tienen nada que ver.
--
-- ORDEN DE DESPLIEGUE: esta migracion se aplica DESPUES de publicar el codigo
-- que ya no usa la columna. Al reves, el endpoint tira 500 hasta el deploy.
--
-- Idempotente: cada paso corre solo si hay algo que hacer. En la segunda
-- corrida no hace nada.
-- Compatible MySQL 8 (dev) + MariaDB 10.11 (prod).


-- ---------------------------------------------------------------------------
-- 1) Catalogo de combos: las 3 opciones (Esperando / Atendido / Despachado)
-- ---------------------------------------------------------------------------
DELETE FROM `estados` WHERE `campo` = 'datarocket_oportunidad_estado';


-- ---------------------------------------------------------------------------
-- 2) La columna
-- ---------------------------------------------------------------------------
SET @existe := (SELECT COUNT(*) FROM information_schema.COLUMNS
                 WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'datarocket_oportunidades'
                   AND COLUMN_NAME = 'estado');
SET @sql := IF(@existe = 1,
  'ALTER TABLE datarocket_oportunidades DROP COLUMN `estado`', 'DO 0');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
