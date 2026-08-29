-- Mueve la base del enlace de la ficha de interaccion de cloud a www.
--
--   https://cloud.databox.net.ar/datarocket/interacciones/
--        -> https://www.databox.net.ar/datarocket/interacciones/
--
-- POR QUE HACE FALTA ESTA MIGRACION
-- ---------------------------------
-- La URL no vive en el codigo sino en `parametros`, clave
-- `datarocket.interacciones.enlace.base`. drIntEnlaceConfig() la siembra con
-- DR_INT_ENLACE_BASE_DEFAULT la primera vez y despues SIEMPRE lee la fila: el
-- default del PHP no se vuelve a mirar. O sea que cambiar la constante no
-- alcanza — sin este UPDATE el aviso de pendientes seguiria mandando enlaces
-- a cloud.databox.net.ar para siempre.
--
-- La pagina se mudo a www/ porque es publica y sin login, y cloud es el dominio
-- del panel (todo lo demas ahi exige sesion).
--
-- SOBRE LOS ENLACES YA EMITIDOS
-- -----------------------------
-- No se tocan y no se pueden tocar: el vencimiento va firmado dentro del token
-- y no hay tabla de control. Siguen apuntando a la URL vieja hasta 30 dias (el
-- default de `datarocket.interacciones.enlace.dias`). Por eso en
-- cloud/datarocket/interacciones/ quedo un index.php que redirige 301 a la
-- ruta nueva preservando el `?t=` — la firma cubre el id y el vencimiento, no
-- el host, asi que el mismo token vale en las dos URLs.
--
-- IDEMPOTENTE Y NO DESTRUCTIVA
-- ----------------------------
-- El WHERE exige el valor viejo exacto. Si alguien ya lo edito a mano desde
-- Herramientas > Editor de parametros, esta migracion no lo pisa: 0 filas
-- afectadas y el valor elegido por esa persona se respeta. Correrla dos veces
-- tampoco hace nada la segunda.
--
-- Si la fila todavia no existe (instalacion donde el aviso de pendientes nunca
-- corrio), tampoco pasa nada: la siembra drIntEnlaceConfig() en su primera
-- corrida, y ya lo hara con la constante nueva.

UPDATE `parametros`
   SET `valor` = 'https://www.databox.net.ar/datarocket/interacciones/'
 WHERE `variable` = 'datarocket.interacciones.enlace.base'
   AND `valor`    = 'https://cloud.databox.net.ar/datarocket/interacciones/';
