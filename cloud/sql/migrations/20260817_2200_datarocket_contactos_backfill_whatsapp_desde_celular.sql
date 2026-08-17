-- datarocket_contactos: rellena `whatsapp` con `celular` cuando `whatsapp`
-- esta vacio (NULL o cadena vacia) y `celular` tiene algo cargado.
--
-- POR QUE
--
-- Las dos columnas guardan el mismo tipo de dato (numero nacional de 10
-- digitos, sin 0, sin 15 y sin separadores — ver
-- cloud/api/lib/contactos_normalizar.php) y en la enorme mayoria de los
-- contactos el WhatsApp ES el celular. La carga historica solo completo
-- `celular`, asi que los envios por WhatsApp se quedaban sin destino en filas
-- que si tenian el numero a mano.
--
-- ALCANCE (medido en dev, 43.239 filas, 17/08)
--
--   * 34.413 filas tienen `whatsapp` vacio.
--   * De esas, 16.014 tienen `celular` cargado -> son las que toca esta
--     migracion. Las 18.399 restantes no tienen de donde copiar y se dejan
--     como estan.
--   * 8.826 filas ya tienen `whatsapp` cargado -> NO se tocan. La migracion
--     nunca pisa un WhatsApp existente, aunque difiera del celular.
--
-- CALIDAD DEL DATO QUE SE COPIA
--
-- De las 16.014 filas, 15.818 traen un `celular` de 10 digitos (numero
-- nacional valido). Las ~196 restantes tienen valores que la normalizacion
-- dejo pasar como digitos crudos: 128 con menos de 10 digitos (basura de
-- carga: '1', '54', '1145') y 68 con mas de 10 (numeros del exterior, que son
-- legitimos). Se copian igual, a proposito: el criterio es "si hay algo en
-- celular, va a whatsapp", y filtrar por largo dejaria afuera a los del
-- exterior, que son justamente los que mas se contactan por WhatsApp. La
-- basura corta ya estaba en `celular` y ahora tambien queda en `whatsapp`; si
-- se decide limpiarla, va en un pase aparte sobre las DOS columnas.
--
-- Idempotente: el WHERE exige que el destino este vacio, asi que la segunda
-- corrida matchea 0 filas. Solo DML, sin DDL — no hace falta el patron
-- information_schema + PREPARE. Compatible MySQL 8 (dev) + MariaDB 10.11 (prod).
-- Un unico UPDATE sobre ~16k filas entra comodo en el limite de 30 s del
-- Migrador DB.

UPDATE `datarocket_contactos`
   SET `whatsapp` = `celular`
 WHERE COALESCE(`whatsapp`, '') = ''
   AND COALESCE(`celular`, '') <> '';
