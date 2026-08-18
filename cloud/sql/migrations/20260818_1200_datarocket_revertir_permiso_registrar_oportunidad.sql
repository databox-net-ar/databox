-- Revierte la 20260818_1100: elimina `datarocket.prospectos.registrar_oportunidad`.
--
-- Decision de producto: los dos atajos "Registrar X" del panel se gobiernan con
-- el permiso de alta del modulo DESTINO, no con un permiso de accion propio.
--
--   Prospectos  > "Registrar oportunidad"  -> datarocket.oportunidades.agregar
--   Oportunidades > "Registrar interaccion" -> datarocket.interacciones.agregar
--
-- Es el mismo permiso que ya exige el POST del endpoint (requirePermCrud mapea
-- POST -> agregar), asi que el atajo se ve exactamente cuando la operacion que
-- dispara esta autorizada: no hay forma de que aparezca y despues falle con
-- 403. El permiso dedicado de la 1100 permitia justamente esa desincronizacion.
--
-- La 20260818_1100 NO se toca ni se borra: ya esta aplicada y registrada en
-- `migraciones` con su hash. Se revierte hacia adelante, como corresponde.
--
-- El id se captura ANTES del DELETE porque `roles.permisos` guarda un CSV de
-- ids: si se borra la fila primero, ya no hay forma de saber que numero sacar
-- del CSV y queda un id colgado apuntando a nada.
--
-- Idempotente: si el permiso ya no existe, @pid queda NULL y los dos UPDATE no
-- afectan ninguna fila.
-- Compatible MySQL 8 (dev) + MariaDB 10.11 (prod).


-- ---------------------------------------------------------------------------
-- 1) Id del permiso a eliminar
-- ---------------------------------------------------------------------------
SET @pid := (SELECT id FROM `permisos`
              WHERE slug = 'datarocket.prospectos.registrar_oportunidad'
              LIMIT 1);


-- ---------------------------------------------------------------------------
-- 2) Sacarlo del CSV de TODOS los roles que lo tengan
--
-- Se envuelve el CSV en comas (",1,5,1542,") para que el id buscado siempre
-- aparezca rodeado de separadores y no haya coincidencias parciales: sin eso,
-- reemplazar "154" tambien romperia "1542", y "1542" matchearia dentro de
-- "215425". Despues se recortan las comas de los extremos.
-- ---------------------------------------------------------------------------
UPDATE `roles`
   SET `permisos` = TRIM(BOTH ',' FROM
         REPLACE(CONCAT(',', `permisos`, ','), CONCAT(',', @pid, ','), ','))
 WHERE @pid IS NOT NULL
   AND `permisos` IS NOT NULL
   AND FIND_IN_SET(@pid, `permisos`) > 0;


-- ---------------------------------------------------------------------------
-- 3) Borrar el permiso del catalogo
-- ---------------------------------------------------------------------------
DELETE FROM `permisos`
 WHERE @pid IS NOT NULL
   AND `id` = @pid;
