-- Mueve la base del enlace de baja de /datarocket/listas/ a
-- /datarocket/suscripcion/.
--
--   https://www.databox.net.ar/datarocket/listas/
--        -> https://www.databox.net.ar/datarocket/suscripcion/
--
-- POR QUE EL RENOMBRE
-- -------------------
-- Es la URL que ve el destinatario en la barra del navegador cuando toca el pie
-- de un correo. "suscripcion" describe lo que esa persona esta haciendo;
-- "listas" es vocabulario interno del CRM y ademas se confunde con el ABM de
-- listas del panel.
--
-- POR QUE HACE FALTA LA MIGRACION
-- -------------------------------
-- La URL no vive en el codigo sino en `parametros`, clave
-- `datarocket.listas.baja.enlace.base`. drListaBajaConfig() la siembra con
-- DR_LBAJA_BASE_DEFAULT la primera vez y despues SIEMPRE lee la fila: cambiar
-- la constante del PHP no mueve nada donde el parametro ya existe.
--
-- SIN REDIRECT, Y ESTA BIEN
-- -------------------------
-- A diferencia de la mudanza de la ficha de interaccion (migracion
-- 20260828_2400, que dejo un 301 en la ruta vieja), aca no hace falta: la
-- pagina de baja se creo hoy, ninguna plantilla tiene todavia el marcador
-- `{baja}` en el cuerpo y por lo tanto NO existe ni un solo correo enviado con
-- un enlace a la ruta vieja. No hay nada que preservar.
--
-- Si esta migracion se aplicara tarde, despues de que ya hubieran salido
-- correos con /datarocket/listas/, habria que agregar el redirect antes.
--
-- IDEMPOTENTE Y NO DESTRUCTIVA
-- ----------------------------
-- El WHERE exige el valor viejo exacto: si alguien ya lo edito a mano desde
-- Herramientas > Editor de parametros, no se pisa.

UPDATE `parametros`
   SET `valor` = 'https://www.databox.net.ar/datarocket/suscripcion/'
 WHERE `variable` = 'datarocket.listas.baja.enlace.base'
   AND `valor`    = 'https://www.databox.net.ar/datarocket/listas/';
