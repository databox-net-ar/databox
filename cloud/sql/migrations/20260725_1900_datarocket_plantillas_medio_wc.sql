-- Normaliza `datarocket_plantillas.medio` para que solo acepte dos codigos
-- validos: `C` (Correo) y `W` (WhatsApp). Historicamente la columna era
-- varchar(1) y podia contener letras heredadas como `S` (SMS) o `P` (Push);
-- el ABM cloud ahora solo ofrece Correo/WhatsApp en el <select>.
--
-- Cualquier valor fuera de la whitelist (S, P, cadena vacia, letras raras)
-- queda en NULL para que no aparezca como opcion invalida en el listado.
--
-- Idempotente: el UPDATE filtra por "no esta en la whitelist final", asi
-- re-correr la migracion no toca filas ya normalizadas.
-- Compatible con MySQL 8 (dev) y MariaDB 10.11 (prod).

UPDATE `datarocket_plantillas`
   SET `medio` = NULL
 WHERE `medio` IS NOT NULL
   AND `medio` NOT IN ('C', 'W');
