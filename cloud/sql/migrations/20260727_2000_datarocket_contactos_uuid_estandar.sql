-- Regenera `datarocket_contactos.uuid` con UUID() en TODAS las filas para
-- unificar el formato al estandar RFC 4122 (36 chars con guiones). Hasta ahora
-- convivian tres formatos: vacio (~6.6k filas heredadas del import legacy),
-- 32 chars hex sin guiones (bin2hex(random_bytes(16)) del ABM nuevo, ~6 filas)
-- y 40 chars (sha1 legacy, ~35.6k filas). El uuid no lo referencia ninguna
-- otra tabla del esquema (los enlaces contacto/contacto_id de mensajes,
-- interacciones, actividades, etc. usan el id numerico), asi que regenerar
-- todos los valores no rompe integridad referencial.
--
-- UUID() se re-evalua por cada fila en un UPDATE, por lo que un unico
-- statement basta para asignar un valor distinto a cada registro. Funciona
-- igual en MySQL 8 (dev) y MariaDB 10.11 (prod).
--
-- Nota: correr esta migracion de nuevo generaria uuids nuevos otra vez. Si en
-- el futuro hace falta re-normalizar, crear una migracion nueva; no reaplicar
-- esta.

UPDATE `datarocket_contactos` SET `uuid` = UUID();
