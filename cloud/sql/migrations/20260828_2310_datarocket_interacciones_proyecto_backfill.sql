-- Backfill de `datarocket_interacciones.proyecto_id` para las filas que ya
-- estaban antes de la migracion 20260828_2300.
--
-- COMO SE RECUPERA EL DATO
-- ------------------------
-- registrarInteraccionMensaje() recibe del encolador la MISMA `fecha` y el
-- MISMO `prospecto_id` que se insertaron en la fila de la cola. Asi que la
-- interaccion y su mensaje se pueden reunir por ese par, y de ahi sale el
-- `proyecto_id` que la interaccion nunca llego a guardar.
--
-- El `canal` de la interaccion dice contra que cola cruzar: 'correo' ->
-- aws_mensajes, 'whatsapp' -> evolution_mensajes, 'telegram' ->
-- telegram_mensajes. Por eso son tres UPDATE y no uno con UNION: cada uno usa
-- su tabla y su predicado, y si alguno falla los otros dos ya quedaron hechos.
--
-- AMBIGUEDAD ACEPTADA
-- -------------------
-- Si un mismo prospecto recibio dos mensajes en el MISMO segundo, el JOIN
-- matchea las dos filas y MySQL escribe una de ellas sin garantizar cual. En la
-- practica dos mensajes al mismo prospecto en el mismo segundo salen de la misma
-- corrida del mismo encolador, o sea de la misma campana, o sea con el mismo
-- `proyecto_id` -- el valor resultante es el mismo por cualquiera de los dos
-- caminos. Se documenta igual porque es una suposicion, no una garantia del
-- esquema.
--
-- SOBRE EL COSTO
-- --------------
-- `aws_mensajes` y sus pares no tienen mas indice que la PK, asi que este JOIN
-- escanea la cola entera por cada interaccion candidata. En desarrollo son
-- 901 x 2.100 y corre en milisegundos; en produccion las colas son mas grandes
-- y el Migrador corta a los 30 s.
--
-- Si se corta: no pasa nada grave. El WHERE exige `i.proyecto_id IS NULL`, asi
-- que la migracion es idempotente y se puede volver a aplicar. Y la migracion
-- que importa (20260828_2300, la del esquema) es otra y ya quedo aplicada: sin
-- este backfill los envios NUEVOS igual guardan su proyecto, lo unico que falta
-- es el historial viejo.
--
-- Solo toca filas `saliente`: las entrantes no las escribe ningun encolador y no
-- tienen mensaje de cola con el cual cruzarse.

-- 1) Correo.
UPDATE `datarocket_interacciones` i
  JOIN `aws_mensajes` m
    ON m.`prospecto_id` = i.`prospecto_id`
   AND m.`fecha`        = i.`fecha`
   SET i.`proyecto_id` = m.`proyecto_id`
 WHERE i.`proyecto_id` IS NULL
   AND i.`sentido`     = 'saliente'
   AND i.`canal`       = 'correo'
   AND m.`proyecto_id` IS NOT NULL;

-- 2) WhatsApp.
UPDATE `datarocket_interacciones` i
  JOIN `evolution_mensajes` m
    ON m.`prospecto_id` = i.`prospecto_id`
   AND m.`fecha`        = i.`fecha`
   SET i.`proyecto_id` = m.`proyecto_id`
 WHERE i.`proyecto_id` IS NULL
   AND i.`sentido`     = 'saliente'
   AND i.`canal`       = 'whatsapp'
   AND m.`proyecto_id` IS NOT NULL;

-- 3) Telegram.
UPDATE `datarocket_interacciones` i
  JOIN `telegram_mensajes` m
    ON m.`prospecto_id` = i.`prospecto_id`
   AND m.`fecha`        = i.`fecha`
   SET i.`proyecto_id` = m.`proyecto_id`
 WHERE i.`proyecto_id` IS NULL
   AND i.`sentido`     = 'saliente'
   AND i.`canal`       = 'telegram'
   AND m.`proyecto_id` IS NOT NULL;
