-- Redefine la semantica del parametro `evolution.mensajes.enviar` para
-- soportar pausa manual del motor de envio. Antes era booleano (1=hay
-- trabajo, 0=cola vacia). Ahora es tri-estado:
--
--   '0' = DETENIDO   (pausa manual desde el UI; el worker no procesa aunque
--                     haya pendientes; se sale de este estado solo con
--                     "Iniciar motor" desde el listado de mensajes).
--   '1' = ESPERANDO  (cola vacia, worker esta ocioso; el proximo encole
--                     lo lleva a '2').
--   '2' = ENVIANDO   (hay pendientes; el worker los esta procesando en
--                     los proximos ticks del cron).
--
-- Reglas nuevas de transicion:
--   * encolarEvolutionMensaje() sube el flag a '2' salvo que este en '0'
--     (respeta la pausa manual).
--   * El worker se saltea la corrida si el flag es '0' o '1'; solo procesa
--     con '2'. Al terminar, si quedan pendientes deja '2', si no baja a '1'.
--     Nunca sobreescribe '0'.
--   * El UI (listado > menu hamburguesa) permite alternar 0 <-> 2.
--
-- Realineacion al aplicar:
--   * Se actualiza el `comentario` para reflejar la nueva semantica.
--   * Se respeta '0' si el operador ya lo tenia pausado (poco probable pero
--     defensivo).
--   * Cualquier otro valor se realinea segun el estado real de la cola:
--     hay pendientes -> '2', no hay -> '1'.
--
-- Idempotente: los UPDATE son autoconsistentes (filtran por WHERE variable=X)
-- y el INSERT esta protegido con WHERE NOT EXISTS. Patron compatible con
-- MySQL 8 / MariaDB 10.11.

-- Seed defensivo por si la fila nunca se creo (fallback del wake-on-demand).
INSERT INTO `parametros` (`variable`, `valor`, `comentario`)
SELECT 'evolution.mensajes.enviar', '1',
       'Motor de envio de mensajes Evolution (tri-estado). 0 = detenido (pausa manual); 1 = esperando (cola vacia); 2 = enviando (hay pendientes). Ver cloud/jobs/evolution_mensajes_enviar.php.'
 WHERE NOT EXISTS (SELECT 1 FROM `parametros` WHERE `variable` = 'evolution.mensajes.enviar');

-- Refrescar el comentario aunque la fila ya existiera (documenta la nueva
-- semantica en el ABM de Parametros).
UPDATE `parametros`
   SET `comentario` = 'Motor de envio de mensajes Evolution (tri-estado). 0 = detenido (pausa manual); 1 = esperando (cola vacia); 2 = enviando (hay pendientes). Ver cloud/jobs/evolution_mensajes_enviar.php.'
 WHERE `variable` = 'evolution.mensajes.enviar';

-- Realinear el valor segun el estado real de la cola. Solo si el flag no
-- estaba en '0' (respeta la pausa manual). Si hay >=1 pendiente -> '2'; si no,
-- '1'. Cubre tanto rows viejas con valor '1' (que antes significaba "hay
-- trabajo") como rows con basura.
UPDATE `parametros`
   SET `valor` = CASE
     WHEN EXISTS (SELECT 1 FROM `evolution_mensajes` WHERE `estado` = 'pendiente') THEN '2'
     ELSE '1'
   END
 WHERE `variable` = 'evolution.mensajes.enviar'
   AND `valor` <> '0';
