-- Suma el formato `contacto` (tarjeta de contacto / vCard) al catalogo de
-- formatos de `evolution_mensajes`.
--
-- El sender ya sabe despacharlo: `evolutionApiEnviar()` en
-- cloud/api/lib/mensajes_enviar.php tiene el case que llama a
-- `/message/sendContact/` de Evolution API. Esta migracion solo agrega la
-- fila del catalogo `estados` para que el ABM de cloud lo ofrezca en el
-- desplegable de formato y lo muestre con su etiqueta en el listado.
--
-- No hace falta tocar la columna: `evolution_mensajes.formato` es un
-- VARCHAR(20) sin CHECK ni ENUM. La migracion 20260724_2300 normalizo los
-- valores existentes una sola vez, pero no restringe los futuros.
--
-- Los datos de la tarjeta viajan en `adjunto` como JSON:
--   {"fullName":"...", "wuid":"5491133445566",
--    "phoneNumber":"+54 9 11 3344-5566",
--    "organization":"...", "email":"...", "url":"..."}
-- Obligatorios `fullName` y `wuid`; el resto es opcional.
--
-- `orden` 6: va detras de los cinco formatos ya sembrados (texto, imagen,
-- video, audio, ubicacion).
--
-- Idempotente: INSERT ... WHERE NOT EXISTS contra (campo, valor).

INSERT INTO `estados` (`campo`, `texto`, `valor`, `orden`)
SELECT 'evolution_mensaje_formato', 'Contacto', 'contacto', 6
 WHERE NOT EXISTS (
   SELECT 1 FROM `estados`
    WHERE `campo` = 'evolution_mensaje_formato'
      AND `valor` = 'contacto'
 );
