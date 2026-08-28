-- Renombra el estado de campana 'expandiendo' -> 'encolando'.
--
-- POR QUE
-- -------
-- 'expandiendo' era jerga de implementacion (el fan-out de la lista al padron)
-- y no describia lo que el operador ve. El resto del modelo ya habla de colas:
-- los renglones del padron pasan a 'encolado' (`datarocket_campana_mensaje_estado`)
-- y la columna que estampa la fecha se llama `encolado`. Con el nombre nuevo la
-- lectura es directa: campana "Encolando" -> mensajes "Encolados".
--
-- El estado sigue significando exactamente lo mismo: es el candado que toma la
-- corrida en curso (fase 1 padron + fase 2 encolado). No cambia el ciclo de
-- vida ni las transiciones, solo como se llama.
--
-- Ciclo de vida resultante:
--   borrador   -> se esta armando; el encolador la ignora
--   programada -> tiene fecha; el encolador la levanta cuando programada <= NOW()
--   encolando  -> hay una corrida en curso dando de alta el padron y encolando
--   enviando   -> el padron esta completo; el motor del canal drena la cola
--   pausada    -> gate manual del operador
--   completada -> no queda nada por despachar
--   cancelada  -> se corto a proposito
--
-- ORDEN DE DEPLOY
-- ---------------
-- Primero el codigo, despues esta migracion. Al reves, una corrida que arranque
-- entre medio escribe 'expandiendo' otra vez (codigo viejo) sobre una tabla ya
-- migrada, y el paso 3 de abajo ya paso. No es grave -- esa campana queda con un
-- estado que ningun combo muestra y se arregla corriendo la migracion de nuevo
-- (es idempotente) -- pero conviene evitarlo.
--
-- La columna es varchar(20) en las dos tablas involucradas, asi que 'encolando'
-- (9 caracteres) entra sin ALTER. No hay DDL en esta migracion.

-- 1) Si una corrida anterior ya dejo el valor nuevo, se borra el viejo en vez de
--    terminar con los dos. `estados` no tiene UNIQUE sobre (campo, valor), asi
--    que nada impediria el duplicado y el combo del ABM mostraria dos opciones.
--    El SELECT anidado se envuelve en una derivada para esquivar el
--    "You can't specify target table for DELETE" de MySQL; MariaDB lo acepta
--    igual.
DELETE FROM `estados`
 WHERE `campo` = 'datarocket_campana_estado'
   AND `valor` = 'expandiendo'
   AND EXISTS (
        SELECT 1 FROM (SELECT `campo`, `valor` FROM `estados`) x
         WHERE x.`campo` = 'datarocket_campana_estado'
           AND x.`valor` = 'encolando'
       );

-- 2) Renombrar la fila del catalogo. `orden` no se toca: el estado ocupa el
--    mismo lugar en el ciclo de vida que ocupaba antes.
UPDATE `estados`
   SET `texto` = 'Encolando',
       `valor` = 'encolando'
 WHERE `campo` = 'datarocket_campana_estado'
   AND `valor` = 'expandiendo';

-- 3) Migrar las campanas que tengan el valor viejo guardado.
--
--    Son dos casos y a los dos les sirve el UPDATE:
--      a) candado vivo -- hay una corrida procesandolas ahora mismo. Cuando
--         termine llamara a drcaCampanaReconciliar(), cuyo $enMarcha ya espera
--         'encolando', asi que la encuentra y le avanza el estado normalmente.
--      b) candado huerfano -- el proceso se murio sin liberar. Sigue clavada
--         igual que antes, y se destraba igual que antes (Pausar y Reanudar
--         desde el panel). Esta migracion no intenta arreglar eso: seria
--         adivinar si el proceso todavia esta vivo.
UPDATE `datarocket_campanas`
   SET `estado` = 'encolando'
 WHERE `estado` = 'expandiendo';

-- 4) Alinear el titulo de la tarea en el Programador, que es texto visible.
--
--    Solo el `nombre`. El `script` NO se toca: es la identidad real de la tarea
--    (el guard de 20260828_1200 va por esa columna, y el scheduler resuelve el
--    archivo por ahi). El archivo en disco sigue llamandose
--    jobs/datarocket_campanas_expandir.php y esta bien que asi sea: "expandir"
--    describe correctamente lo que hace la fase 1 con la lista.
--
--    El WHERE incluye el nombre viejo para no pisar un titulo que alguien haya
--    editado a mano desde el panel -- la migracion 20260828_1200 ya declara que
--    el nombre es cosmetico y editable.
UPDATE `tareas`
   SET `nombre` = 'datarocket > campanas > encolar'
 WHERE `script` = 'jobs/datarocket_campanas_expandir.php'
   AND `nombre` = 'datarocket > campanas > expandir';
