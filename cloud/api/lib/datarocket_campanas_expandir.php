<?php
// api/lib/datarocket_campanas_expandir.php
// Motor de las campanas Datarocket. Es lo que convierte UNA campana
// ("esta lista x esta plantilla por este canal") en N mensajes encolados.
//
// Lo usan dos callers, y por eso vive en una lib y no adentro de un endpoint:
//   - cloud/api/datarocket_campanas_ejecutar.php  (boton "Iniciar", SSE)
//   - cloud/jobs/datarocket_campanas_expandir.php (cron, camino programado)
//
// DOS FASES
// ---------
// Fase 1 (padron): da de alta un renglon en `datarocket_campanas_mensajes` por
// cada prospecto de la lista, resolviendo el destino segun el medio. Es
// set-based: un solo INSERT ... SELECT, asi que 20.000 prospectos cuestan una
// query y no 20.000. Los que no tienen dato de contacto entran igual, en estado
// 'omitido' y con el motivo escrito — que es justamente lo que la cola del canal
// no puede registrar, porque ahi el mensaje nunca llega a existir.
//
// Fase 2 (encolado): recorre los pendientes del padron en lotes y por cada uno
// llama al encolador del canal. Esta fase SI es fila por fila: cada mensaje
// tiene su propio cuerpo renderizado y su propia fila en la cola.
//
// REANUDABLE
// ----------
// Las dos fases se pueden cortar y retomar sin duplicar nada:
//   - Fase 1 se apoya en el UNIQUE (campana_id, prospecto_id) + INSERT IGNORE.
//   - Fase 2 avanza marcando cada renglon a 'encolado' apenas encola, asi que
//     una segunda corrida solo ve lo que quedo en 'pendiente'.
// Esto no es un lujo: una campana de 20.000 no entra en una sola corrida de
// cron ni en una request HTTP comoda, y el proceso se puede morir a la mitad.
//
// NO ENVIA
// --------
// Esta lib encola. El envio real lo siguen haciendo los motores de cada canal
// (cloud/jobs/aws_mensajes_enviar.php y sus pares), que ya tienen su rate limit,
// su gate manual y sus reintentos. Duplicar eso aca seria construir un segundo
// sender que compite con el primero por las mismas credenciales.

require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/aws_mensajes.php';
require_once __DIR__ . '/evolution_mensajes.php';
require_once __DIR__ . '/telegram_mensajes.php';
// Las bajas automaticas de lista se logean con registrarSuceso(): borrar a
// alguien de una lista sin dejar rastro no es aceptable. Los dos callers ya lo
// incluian, pero la lib no puede depender de que lo hagan.
require_once __DIR__ . '/sucesos.php';
// La unica puerta de entrada y salida de las listas. El motor NO borra de
// `datarocket_prospectos_listas` por su cuenta: la baja por rebote sigue las
// mismas reglas que la que hace un operador a mano.
require_once __DIR__ . '/datarocket_listas_suscripciones.php';

// Cuantos mensajes se encolan por vuelta del loop de la fase 2. No es un limite
// de throughput sino el tamano del SELECT: mas grande = menos round-trips, pero
// mas memoria y menos granularidad para el log en vivo.
const DRCA_LOTE = 200;

// Presupuesto de tiempo por corrida, en segundos. Al agotarse la corrida termina
// limpio y deja la campana reanudable en vez de que la mate el timeout de PHP o
// del navegador a mitad de un INSERT.
const DRCA_DEADLINE_DEFAULT = 240;

// De donde sale el destino de cada mensaje segun el medio. Se evalua en SQL
// dentro del INSERT de la fase 1, por eso son expresiones y no nombres de
// columna sueltos: `whatsapp` suele estar vacio y hay que caer a `celular`.
const DRCA_DESTINO_SQL = [
    'correo'   => "NULLIF(TRIM(p.correo), '')",
    'whatsapp' => "COALESCE(NULLIF(TRIM(p.whatsapp), ''), NULLIF(TRIM(p.celular), ''))",
    'telegram' => "COALESCE(NULLIF(TRIM(p.telefono), ''), NULLIF(TRIM(p.celular), ''))",
];

// Motivo con el que se marca 'omitido' al prospecto sin dato de contacto. Se
// guarda el texto y no un codigo: el padron lo lee un humano en el ABM.
const DRCA_MOTIVO_SIN_DATO = [
    'correo'   => 'El prospecto no tiene correo cargado.',
    'whatsapp' => 'El prospecto no tiene WhatsApp ni celular cargado.',
    'telegram' => 'El prospecto no tiene teléfono ni celular cargado.',
];

// ----------------------------------------------------------------------------
// Validacion
// ----------------------------------------------------------------------------

/**
 * Chequea que la campana se pueda largar y devuelve la cantidad real de
 * suscriptos de su lista. Tira InvalidArgumentException con un mensaje
 * mostrable si algo falta — los dos callers lo traducen a su formato.
 *
 * Los suscriptos se cuentan sobre la tabla puente y NO sobre el contador
 * denormalizado `datarocket_listas.suscriptos`, que puede estar atrasado.
 * Bloquear un envio por un contador viejo seria peor que la consulta de mas.
 */
function drcaValidarLanzable(PDO $pdo, array $c): int {
    $estado = (string) $c['estado'];
    // 'enviando' entra a proposito: es el estado en el que queda una campana que
    // no termino de encolar su padron en una sola corrida (se agoto el deadline
    // con pendientes). Retomarla es exactamente el caso reanudable que las dos
    // fases estan disenadas para soportar, y sin esto quedaba clavada para
    // siempre: el cron solo levantaba 'programada' y el boton del panel solo
    // ofrecia 'borrador'/'programada', asi que nadie volvia a encolar sus
    // pendientes nunca.
    //
    // 'encolando' NO entra: es el candado que toma una corrida en curso. Dejar
    // pasar ese estado seria permitir que dos procesos encolen el mismo padron a
    // la vez. 'pausada' y 'cancelada' tampoco: son decisiones del operador que
    // no se pisan solas -- para volver de ahi esta el boton Reanudar.
    if (!in_array($estado, ['borrador', 'programada', 'enviando'], true)) {
        throw new InvalidArgumentException(
            "La campaña está en estado \"{$estado}\": solo se pueden lanzar las que están en borrador, programadas o a medio enviar."
        );
    }

    $faltan = [];
    if ($c['lista_id']     === null) $faltan[] = 'la lista de distribución';
    if ($c['canal_id']     === null) $faltan[] = 'el canal de salida';
    if ($c['plantilla_id'] === null) $faltan[] = 'la plantilla';
    if ($faltan) {
        throw new InvalidArgumentException('Falta ' . implode(', ', $faltan) . '.');
    }

    if (!isset(DRCA_DESTINO_SQL[(string) $c['medio']])) {
        throw new InvalidArgumentException("El medio \"{$c['medio']}\" no tiene encolador asociado.");
    }

    // El proyecto es obligatorio para los tres encoladores (lo exigen sus
    // REQUERIDOS_CREATE): sin el, cada mensaje fallaria de a uno en la fase 2.
    // Mejor frenar entera la campana aca que dejar 20.000 errores identicos.
    if ($c['proyecto_id'] === null) {
        throw new InvalidArgumentException('Falta el proyecto: los tres canales lo exigen para poder encolar.');
    }

    // El asunto tiene que quedar resuelto ANTES de largar. Las plantillas
    // "transaccionales" guardan literalmente `{asunto}` esperando recibirlo del
    // caller (ver aplicarPlantillaAws en lib/aws_mensajes.php); si la campana no
    // trae el suyo, los N mensajes salen con asunto vacio. Solo aplica a correo:
    // WhatsApp y Telegram no tienen subject.
    if ((string) $c['medio'] === 'correo') {
        $st = $pdo->prepare('SELECT asunto FROM datarocket_plantillas WHERE id = :id LIMIT 1');
        $st->execute([':id' => (int) $c['plantilla_id']]);
        $tplAsunto = (string) ($st->fetchColumn() ?: '');
        $resuelto  = trim(str_replace('{asunto}', (string) ($c['asunto'] ?? ''), $tplAsunto));
        if ($resuelto === '') {
            throw new InvalidArgumentException(
                'El asunto queda vacío: la plantilla espera recibirlo de la campaña. Cargá el asunto en la campaña.'
            );
        }
    }

    $st = $pdo->prepare('SELECT COUNT(*) FROM datarocket_prospectos_listas WHERE lista_id = :l');
    $st->execute([':l' => (int) $c['lista_id']]);
    $n = (int) $st->fetchColumn();
    if ($n === 0) {
        throw new InvalidArgumentException('La lista no tiene prospectos suscriptos.');
    }
    return $n;
}

function drcaCargarCampana(PDO $pdo, int $id): array {
    $st = $pdo->prepare('SELECT * FROM datarocket_campanas WHERE id = :id LIMIT 1');
    $st->execute([':id' => $id]);
    $c = $st->fetch();
    if (!$c) throw new InvalidArgumentException('Campaña no encontrada.');
    return $c;
}

// ----------------------------------------------------------------------------
// Fase 1 — padron
// ----------------------------------------------------------------------------

/**
 * Da de alta el padron de la campana desde la tabla puente de la lista.
 * Idempotente: INSERT IGNORE contra el UNIQUE (campana_id, prospecto_id), asi
 * que correrlo dos veces no duplica ni pisa el estado de lo ya procesado.
 *
 * Devuelve ['altas' => n, 'omitidos_sin_dato' => n, 'omitidos_duplicados' => n].
 */
function drcaExpandirPadron(PDO $pdo, array $c, callable $log): array {
    $medio      = (string) $c['medio'];
    $destinoSql = DRCA_DESTINO_SQL[$medio];
    $motivo     = DRCA_MOTIVO_SIN_DATO[$medio];

    $antes = drcaContarPadron($pdo, (int) $c['id']);

    // El CASE se repite porque MySQL no deja referenciar un alias de la propia
    // SELECT en otra columna de la misma SELECT. Es feo y es correcto.
    $sql = "
        INSERT IGNORE INTO datarocket_campanas_mensajes
            (campana_id, prospecto_id, destino, estado, motivo)
        SELECT :cid,
               p.id,
               {$destinoSql},
               CASE WHEN {$destinoSql} IS NULL THEN 'omitido' ELSE 'pendiente' END,
               CASE WHEN {$destinoSql} IS NULL THEN :motivo   ELSE NULL        END
          FROM datarocket_prospectos_listas pl
          JOIN datarocket_prospectos p ON p.id = pl.prospecto_id
         WHERE pl.lista_id = :lid
    ";
    $st = $pdo->prepare($sql);
    $st->execute([
        ':cid'    => (int) $c['id'],
        ':motivo' => $motivo,
        ':lid'    => (int) $c['lista_id'],
    ]);
    $altas = $st->rowCount();

    $log("Padrón: {$altas} destinatarios nuevos dados de alta.");
    if ($antes > 0) {
        $log("Ya había {$antes} renglones de una corrida anterior — se respetan.");
    }

    // Se cuenta por MOTIVO y no por estado: 'omitido' tambien lo usan los
    // duplicados, asi que contar el estado a secas hace que la segunda corrida
    // reporte como "sin dato de contacto" a gente que en realidad estaba
    // repetida.
    $sinDato = drcaContarPadronPorMotivo($pdo, (int) $c['id'], $motivo);
    if ($sinDato > 0) {
        $log("{$sinDato} sin dato de contacto para {$medio}: quedan omitidos.");
    }

    $dups = drcaMarcarDuplicados($pdo, (int) $c['id']);
    if ($dups > 0) {
        $log("{$dups} con destino repetido dentro de la campaña: quedan omitidos (se manda una sola vez).");
    }

    return [
        'altas'               => $altas,
        'omitidos_sin_dato'   => $sinDato,
        'omitidos_duplicados' => $dups,
    ];
}

/**
 * Marca como 'omitido' los pendientes cuyo `destino` ya aparece en un renglon
 * anterior (id menor) de la MISMA campana. Dos prospectos distintos pueden
 * compartir el correo — la lista no lo impide — y mandarle dos veces lo mismo a
 * la misma casilla es la forma mas rapida de terminar en spam.
 *
 * La comparacion la hace la collation `utf8mb4_general_ci` de la columna, que
 * pliega mayusculas Y acentos: "Juan@X.com" y "juan@x.com" son el mismo destino.
 * Solo toca 'pendiente': un renglon ya encolado no se re-juzga.
 */
function drcaMarcarDuplicados(PDO $pdo, int $campanaId): int {
    $st = $pdo->prepare("
        UPDATE datarocket_campanas_mensajes m
          JOIN (
                SELECT MIN(id) AS keep_id, destino
                  FROM datarocket_campanas_mensajes
                 WHERE campana_id = :c1 AND destino IS NOT NULL
                 GROUP BY destino
                HAVING COUNT(*) > 1
               ) g ON g.destino = m.destino
           SET m.estado = 'omitido',
               m.motivo = 'Destino repetido dentro de la campaña.'
         WHERE m.campana_id = :c2
           AND m.estado     = 'pendiente'
           AND m.id        <> g.keep_id
    ");
    $st->execute([':c1' => $campanaId, ':c2' => $campanaId]);
    return $st->rowCount();
}

function drcaContarPadron(PDO $pdo, int $campanaId): int {
    $st = $pdo->prepare('SELECT COUNT(*) FROM datarocket_campanas_mensajes WHERE campana_id = :c');
    $st->execute([':c' => $campanaId]);
    return (int) $st->fetchColumn();
}

function drcaContarPadronPorMotivo(PDO $pdo, int $campanaId, string $motivo): int {
    $st = $pdo->prepare('SELECT COUNT(*) FROM datarocket_campanas_mensajes WHERE campana_id = :c AND motivo = :m');
    $st->execute([':c' => $campanaId, ':m' => $motivo]);
    return (int) $st->fetchColumn();
}

// ----------------------------------------------------------------------------
// Fase 2 — encolado
// ----------------------------------------------------------------------------

// Variables de merge disponibles en el asunto y el cuerpo de la plantilla, como
// {nombre}, {empresa}, {ciudad}. Las resuelve aplicarPlantilla*() de la lib del
// canal via la clave `variables` del payload. Un campo vacio se reemplaza por
// string vacio y NO deja el literal {nombre} colgado en el mensaje.
// OJO con la clave del nombre: el SELECT de la fase 2 lo trae aliaseado como
// `prospecto_nombre` (para no chocar con otras columnas del JOIN), asi que leer
// `nombre` a secas devolvia siempre '' y todos los mensajes salian con el
// {nombre} en blanco. Se aceptan las dos formas para que la funcion sirva
// tambien si se la llama con una fila cruda de `datarocket_prospectos`.
function drcaVariablesDe(array $p): array {
    return [
        'nombre'   => (string) ($p['prospecto_nombre'] ?? $p['nombre'] ?? ''),
        'persona'  => (string) ($p['persona_nombre'] ?? ''),
        'empresa'  => (string) ($p['empresa_nombre'] ?? ''),
        'correo'   => (string) ($p['correo']         ?? ''),
        'celular'  => (string) ($p['celular']        ?? ''),
        'whatsapp' => (string) ($p['whatsapp']       ?? ''),
        'telefono' => (string) ($p['telefono']       ?? ''),
        'ciudad'   => (string) ($p['ciudad']         ?? ''),
    ];
}

/**
 * Encola un renglon del padron en la cola del canal que corresponda.
 * Devuelve el id de la fila creada en la cola.
 *
 * `registrar_prospecto` va en TRUE, al reves del default de los ABMs de
 * mensajes sueltos. El default apagado de esos ABMs existe para que un envio
 * manual a una direccion tipeada a mano no de de alta prospectos basura; aca el
 * prospecto YA existe y viene de la lista, asi que no hay nada que ensuciar — y
 * el rastro en `datarocket_interacciones` es medio punto de tener un CRM.
 */
function drcaEncolarUno(PDO $pdo, array $c, array $fila): int {
    $datos = [
        'proyecto_id'         => (int) $c['proyecto_id'],
        'canal_id'            => (int) $c['canal_id'],
        'plantilla_id'        => (int) $c['plantilla_id'],
        'prospecto_id'        => (int) $fila['prospecto_id'],
        'destino'             => (string) $fila['destino'],
        'destinatario'        => (string) ($fila['prospecto_nombre'] ?? ''),
        'prioridad'           => (int) $c['prioridad'],
        'variables'           => drcaVariablesDe($fila),
        'registrar_prospecto' => true,
        // Alimenta el `{asunto}` de las plantillas transaccionales. Si la
        // plantilla ya trae asunto fijo este valor se ignora — el str_replace
        // no encuentra el marcador y no cambia nada.
        'asunto'              => (string) ($c['asunto'] ?? ''),
    ];

    switch ((string) $c['medio']) {
        case 'correo':   return encolarAwsMensaje($pdo, $datos);
        case 'whatsapp': return encolarEvolutionMensaje($pdo, $datos);
        case 'telegram': return encolarTelegramMensaje($pdo, $datos);
    }
    throw new InvalidArgumentException("Medio \"{$c['medio']}\" sin encolador.");
}

/**
 * Recorre los pendientes del padron y los encola, hasta agotarlos o hasta que
 * se acabe el presupuesto de tiempo.
 *
 * Un mensaje que falla NO aborta la corrida: se marca ese renglon como
 * 'fallido' con el error en `motivo` y se sigue con el siguiente. Un destino mal
 * cargado entre 20.000 no puede frenar la campana entera.
 */
function drcaEncolarPendientes(PDO $pdo, array $c, callable $log, float $deadline): array {
    $encolados = 0;
    $fallidos  = 0;
    $upOk = $pdo->prepare("
        UPDATE datarocket_campanas_mensajes
           SET estado = 'encolado', mensaje_id = :m, encolado = NOW(), motivo = NULL
         WHERE id = :id
    ");
    $upErr = $pdo->prepare("
        UPDATE datarocket_campanas_mensajes
           SET estado = 'fallido', motivo = :motivo
         WHERE id = :id
    ");

    while (true) {
        if (microtime(true) >= $deadline) {
            $log('Se agotó el presupuesto de tiempo de esta corrida.');
            break;
        }

        $lote = DRCA_LOTE;
        $st = $pdo->prepare("
            SELECT m.id, m.prospecto_id, m.destino,
                   p.nombre AS prospecto_nombre, p.persona_nombre, p.empresa_nombre,
                   p.correo, p.celular, p.whatsapp, p.telefono, p.ciudad
              FROM datarocket_campanas_mensajes m
              JOIN datarocket_prospectos p ON p.id = m.prospecto_id
             WHERE m.campana_id = :c AND m.estado = 'pendiente'
             ORDER BY m.id ASC
             LIMIT {$lote}
        ");
        $st->execute([':c' => (int) $c['id']]);
        $filas = $st->fetchAll();
        if (!$filas) break;

        foreach ($filas as $f) {
            if (microtime(true) >= $deadline) break;
            try {
                $mid = drcaEncolarUno($pdo, $c, $f);
                $upOk->execute([':m' => $mid, ':id' => (int) $f['id']]);
                $encolados++;
            } catch (Throwable $e) {
                $upErr->execute([
                    ':motivo' => mb_substr('No se pudo encolar: ' . $e->getMessage(), 0, 255),
                    ':id'     => (int) $f['id'],
                ]);
                $fallidos++;
            }
        }

        $log("Encolados {$encolados}" . ($fallidos > 0 ? " · {$fallidos} fallidos" : '') . '…');
    }

    return ['encolados' => $encolados, 'fallidos' => $fallidos];
}

// ----------------------------------------------------------------------------
// Orquestacion
// ----------------------------------------------------------------------------

/**
 * Corre la campana entera: valida, arma el padron, encola y deja el estado
 * al dia. `$log` recibe una linea por hito para que el caller la muestre (SSE
 * en el panel, anotarLog() en el cron).
 *
 * Devuelve un resumen con los contadores de la corrida.
 */
function drcaCampanaEjecutar(PDO $pdo, int $id, callable $log, array $opts = []): array {
    $deadline = microtime(true) + (float) ($opts['deadline_seg'] ?? DRCA_DEADLINE_DEFAULT);

    $c = drcaCargarCampana($pdo, $id);
    $log("Campaña #{$id} — \"{$c['nombre']}\" ({$c['medio']}).");

    $suscriptos = drcaValidarLanzable($pdo, $c);
    $log("Lista #{$c['lista_id']}: {$suscriptos} prospectos suscriptos.");

    // 'encolando' es el candado: el cron no levanta campanas en ese estado, asi
    // que una corrida manual y una del cron no se pisan sobre el mismo padron.
    $pdo->prepare("UPDATE datarocket_campanas
                      SET estado = 'encolando', iniciada = COALESCE(iniciada, NOW())
                    WHERE id = :id")->execute([':id' => $id]);

    try {
        $log('--- Fase 1: armando el padrón ---');
        $p1 = drcaExpandirPadron($pdo, $c, $log);

        $log('--- Fase 2: encolando ---');
        $p2 = drcaEncolarPendientes($pdo, $c, $log, $deadline);
    } catch (Throwable $e) {
        // Si algo revienta a mitad, la campana no puede quedar clavada en
        // 'encolando' o el cron nunca la vuelve a mirar.
        $pdo->prepare("UPDATE datarocket_campanas SET estado = 'pausada' WHERE id = :id")
            ->execute([':id' => $id]);
        throw $e;
    }

    $resumen = drcaCampanaReconciliar($pdo, $id);
    $pendientes = $resumen['pendientes'];

    if ($pendientes > 0) {
        $log("Quedan {$pendientes} pendientes: volvé a ejecutar para continuar (la campaña retoma donde quedó).");
    }

    $log('Total del padrón: ' . $resumen['total']
       . ' · encolados: '     . $resumen['encolados']
       . ' · omitidos: '      . $resumen['omitidos']
       . ' · fallidos: '      . $resumen['fallidos']);

    return array_merge($resumen, [
        'encolados_esta_corrida' => $p2['encolados'],
        'fallidos_esta_corrida'  => $p2['fallidos'],
        'padron_altas'           => $p1['altas'],
    ]);
}

// ----------------------------------------------------------------------------
// Feedback de entrega (SES) y bajas de lista
// ----------------------------------------------------------------------------

// Resultados que provocan la baja del prospecto de la lista de la campana.
//
//   spam     -> Complaint. El destinatario apreto "esto es spam". Seguir
//               mandandole es exactamente lo que hunde la reputacion del
//               dominio, asi que la baja no admite matices.
//   rebotado -> SOLO si el bounce fue 'Permanent'. Ver drcaBajasPorRebote.
//
// 'rechazado' (Reject) queda deliberadamente AFUERA: SES rechaza por nuestro
// contenido (virus, dominio en lista negra), no por la casilla del otro. Dar de
// baja al destinatario por un problema nuestro seria castigar al equivocado.
const DRCA_RESULTADOS_BAJA = ['spam', 'rebotado'];

// Resultados que cuentan como "no le llego / no lo quiere" en el contador
// `rebotados` de la campana. Incluye 'rechazado' aunque no de de baja: el
// operador igual necesita ver que ese mensaje no llego.
const DRCA_RESULTADOS_REBOTE = ['rebotado', 'rechazado', 'spam'];

/**
 * Copia `aws_mensajes.resultado` al padron. Devuelve cuantos renglones
 * cambiaron.
 *
 * El guard `m.resultado <> q.resultado` (con su NULL-check explicito, porque en
 * SQL `NULL <> 'x'` es NULL y no TRUE) hace que la corrida sea un no-op cuando
 * no llego nada nuevo. Eso importa: esta funcion la llama el cron cada minuto
 * sobre toda campana dentro de la ventana de eventos, y sin el guard estaria
 * reescribiendo el padron entero y moviendo `resultado_fecha` cada vez, lo que
 * volveria inutil esa columna como senal de "cuando llego el ultimo evento".
 */
function drcaSincronizarResultado(PDO $pdo, int $campanaId): int {
    $st = $pdo->prepare("
        UPDATE datarocket_campanas_mensajes m
          JOIN aws_mensajes q ON q.id = m.mensaje_id
           SET m.resultado       = q.resultado,
               m.resultado_fecha = NOW()
         WHERE m.campana_id = :c
           AND m.mensaje_id IS NOT NULL
           AND q.resultado  IS NOT NULL
           AND (m.resultado IS NULL OR m.resultado <> q.resultado)
    ");
    $st->execute([':c' => $campanaId]);
    return $st->rowCount();
}

/**
 * Da de baja de la lista de la campana a los prospectos que rebotaron duro o
 * denunciaron el mensaje como spam. Devuelve cuantos dio de baja.
 *
 * REBOTE DURO vs BLANDO
 * ---------------------
 * `resultado = 'rebotado'` NO alcanza. SES manda bounceType 'Permanent' (la
 * casilla no existe) y 'Transient' (buzon lleno, servidor caido) y los dos
 * mapean al mismo 'rebotado' en aws_mensajes (ver AWS_EVT_TIPO_A_RESULTADO en
 * api/v4/aws/eventos.php). Al escribir esto prod tenia 10 Permanent y 7
 * Transient: desuscribir por los 7 seria perder la suscripcion de gente cuya
 * casilla existe y anda, solo porque estaba llena ese dia. El subtipo se cruza
 * contra `aws_eventos` por `uuid`.
 *
 * El complaint ('spam') no necesita ese cruce: cualquier denuncia da de baja.
 *
 * LA BAJA ES DESTRUCTIVA
 * ----------------------
 * Borra el renglon de `datarocket_prospectos_listas`, que no tiene columnas
 * propias donde anotar nada. Por eso antes de borrar se estampa `baja_lista` en
 * el padron: ese renglon sobrevive a la desuscripcion y conserva prospecto_id,
 * destino y resultado. Es la unica evidencia de por que alguien dejo de estar
 * en la lista.
 *
 * Se borra SOLO de la lista de esta campana. Un prospecto puede estar en varias
 * y el rebote lo probamos contra una sola direccion en un solo envio; sacarlo
 * de todas seria extrapolar mas de lo que el evento dice.
 *
 * EL BORRADO NO SE HACE ACA
 * -------------------------
 * Lo hace drListaDesuscribir(), la unica puerta de salida de las listas. Este
 * motor no tiene una forma propia de desuscribir: usa la misma que el ABM, con
 * el mismo historial y el mismo recuento del denormalizado. Lo unico que agrega
 * es lo que solo el sabe — a que direccion se mando de verdad, que subtipo de
 * evento llego y que mensaje lo causo — y eso viaja en `por_prospecto`.
 */
function drcaBajasPorRebote(PDO $pdo, array $c): int {
    $listaId = $c['lista_id'] !== null ? (int) $c['lista_id'] : 0;
    if ($listaId <= 0) return 0;

    $ph = implode(',', array_fill(0, count(DRCA_RESULTADOS_BAJA), '?'));

    // Candidatos: rebote/spam todavia sin baja estampada. El bounce 'Transient'
    // se filtra aca — el EXISTS exige un evento 'Permanent' para el uuid.
    // Se trae tambien `mensaje_id` y el subtipo del evento: los dos van al
    // historial de bajas, que tiene que poder responder "¿por que la sacamos?"
    // sin depender del padron (que se borra junto con la campana).
    $sql = "
        SELECT m.id, m.prospecto_id, m.destino, m.resultado, m.mensaje_id,
               (SELECT ev.subtipo
                  FROM aws_eventos ev
                 WHERE ev.uuid = q.uuid
                   AND ev.tipo IN ('bounce', 'complaint')
                 ORDER BY ev.id DESC LIMIT 1) AS subtipo
          FROM datarocket_campanas_mensajes m
          JOIN aws_mensajes q ON q.id = m.mensaje_id
         WHERE m.campana_id = ?
           AND m.baja_lista IS NULL
           AND m.resultado IN ({$ph})
           AND (
                m.resultado = 'spam'
             OR EXISTS (SELECT 1
                          FROM aws_eventos ev
                         WHERE ev.uuid    = q.uuid
                           AND ev.tipo    = 'bounce'
                           AND ev.subtipo = 'Permanent')
           )
    ";
    $st = $pdo->prepare($sql);
    $st->execute(array_merge([(int) $c['id']], DRCA_RESULTADOS_BAJA));
    $filas = $st->fetchAll();
    if (!$filas) return 0;

    $ids       = array_map(fn($f) => (int) $f['id'],           $filas);
    $prospecto = array_map(fn($f) => (int) $f['prospecto_id'], $filas);
    $phIds     = implode(',', array_fill(0, count($ids), '?'));

    // Estampa y baja tienen que ser atomicas, pero PDO no anida transacciones:
    // si un caller ya abrio una, beginTransaction() tira "There is already an
    // active transaction". Se toma la transaccion solo si no hay una en curso;
    // si la hay, la atomicidad ya la garantiza el caller. La puerta hace el
    // mismo chequeo y se engancha a esta.
    $propia = !$pdo->inTransaction();
    if ($propia) $pdo->beginTransaction();
    try {
        // Estampar ANTES de dar de baja: si la baja falla, el rollback deja las
        // dos cosas sin hacer; si estampáramos despues y fallara, tendriamos
        // bajas sin rastro en el padron.
        $up = $pdo->prepare("UPDATE datarocket_campanas_mensajes
                                SET baja_lista = NOW()
                              WHERE id IN ({$phIds})");
        $up->execute($ids);

        // Lo que la puerta no puede saber y este motor si: el destino REAL al
        // que se mando (que puede no ser el correo que el prospecto tiene hoy,
        // y en WhatsApp/Telegram no es un correo en absoluto), el subtipo del
        // evento de SES y el mensaje que lo causo.
        $porProspecto = [];
        foreach ($filas as $f) {
            $porProspecto[(int) $f['prospecto_id']] = [
                'destino'    => $f['destino'],
                // Vocabulario de `datarocket_lista_baja_motivo`. Para el rebote
                // dice 'rebotado' (siempre duro: el blando no llega hasta aca).
                'motivo'     => (string) $f['resultado'] === 'spam' ? 'spam' : 'rebotado',
                'detalle'    => $f['subtipo'],
                'mensaje_id' => $f['mensaje_id'],
            ];
        }

        // El historial lo escribe la puerta, ANTES de borrar de la puente, y es
        // el registro que importa: vive fuera del padron, asi que sobrevive al
        // borrado de la campana (su FK es ON DELETE SET NULL). Sin esto, borrar
        // la campana borraria la razon por la que estas personas ya no estan en
        // la lista.
        $bajas = drListaDesuscribir($pdo, $listaId, $prospecto, [
            'motivo'        => 'rebotado',
            'origen'        => 'cron/datarocket_campanas',
            'campana_id'    => (int) $c['id'],
            'por_prospecto' => $porProspecto,
        ]);

        if ($propia) $pdo->commit();
    } catch (Throwable $e) {
        if ($propia) $pdo->rollBack();
        throw $e;
    }

    // Una baja automatica cambia la lista sin que nadie la haya tocado: tiene
    // que quedar en el log o el operador ve encoger la lista sin explicacion.
    $detalle = array_map(fn($f) => "{$f['destino']} ({$f['resultado']})", $filas);
    registrarSuceso($pdo, 'datarocket_campanas', 'alerta',
        "Campaña #{$c['id']}: {$bajas} prospectos dados de baja de la lista #{$listaId}"
        . ' por rebote duro o spam — ' . implode(', ', array_slice($detalle, 0, 20))
        . (count($detalle) > 20 ? ' …' : ''));

    return $bajas;
}

/**
 * Pone al dia el padron y los contadores de la campana.
 *
 * Primero SINCRONIZA desde la cola del canal: cada renglon 'encolado' del padron
 * apunta por `mensaje_id` a una fila de `aws_mensajes` / `evolution_mensajes` /
 * `telegram_mensajes`, y es esa fila la que sabe si el motor ya la despacho. Por
 * eso el padron se entera del envio por JOIN y no por notificacion: los motores
 * de canal no conocen a las campanas, y no hace falta que las conozcan.
 *
 * Despues recomputa los 5 contadores denormalizados y cierra la campana si ya
 * no queda nada por hacer.
 */
function drcaCampanaReconciliar(PDO $pdo, int $id): array {
    $c = drcaCargarCampana($pdo, $id);

    // Tabla de cola segun el medio. Nombre de tabla desde constante local, nunca
    // desde el request.
    $colas = ['correo' => 'aws_mensajes', 'whatsapp' => 'evolution_mensajes', 'telegram' => 'telegram_mensajes'];
    $cola  = $colas[(string) $c['medio']] ?? null;

    if ($cola !== null) {
        // Los tres motores usan el mismo vocabulario en `estado`: 'pendiente',
        // 'enviando', 'enviado', 'error'. Solo nos interesan los dos terminales.
        $st = $pdo->prepare("
            UPDATE datarocket_campanas_mensajes m
              JOIN {$cola} q ON q.id = m.mensaje_id
               SET m.estado  = CASE WHEN q.estado = 'enviado' THEN 'enviado' ELSE 'fallido' END,
                   m.enviado = q.enviado,
                   m.motivo  = CASE WHEN q.estado = 'enviado' THEN NULL ELSE LEFT(COALESCE(q.error, 'El canal reportó error.'), 255) END
             WHERE m.campana_id = :c
               AND m.estado     = 'encolado'
               AND q.estado IN ('enviado', 'error')
        ");
        $st->execute([':c' => $id]);

        // Feedback de entrega de SES. Va aparte del bloque de arriba y NO se
        // limita a los renglones que acaban de transicionar: los eventos SNS
        // llegan DESPUES del envio (un open a las horas, un complaint a los
        // dias), asi que hay que refrescar tambien lo que ya estaba 'enviado'.
        // Solo correo: `resultado` es una columna de aws_mensajes, evolution y
        // telegram no la tienen (romperia el UPDATE con Unknown column).
        if ((string) $c['medio'] === 'correo') {
            drcaSincronizarResultado($pdo, $id);
            drcaBajasPorRebote($pdo, $c);
        }
    }

    // `encolados` = "cuantos llegaron a la cola del canal", asi que se mide por
    // `mensaje_id IS NOT NULL` y no por estado. Un renglon que se encolo y
    // despues reboto sigue habiendo llegado a la cola; uno que fallo ANTES de
    // encolarse (excepcion en la fase 2) no tiene mensaje_id y no cuenta. Los
    // dos casos comparten el estado 'fallido', que por si solo no distingue.
    // `rebotados` y `bajas` se miden sobre `resultado` / `baja_lista` y NO sobre
    // `estado`: un rebote de SES llega con estado='enviado' (el mensaje SALIO;
    // reboto despues), asi que contarlo por estado lo haria invisible.
    $phReb = implode(',', array_fill(0, count(DRCA_RESULTADOS_REBOTE), '?'));
    $st = $pdo->prepare("
        SELECT COUNT(*) AS total,
               SUM(CASE WHEN mensaje_id IS NOT NULL THEN 1 ELSE 0 END) AS encolados,
               SUM(CASE WHEN estado = 'enviado'   THEN 1 ELSE 0 END) AS enviados,
               SUM(CASE WHEN estado = 'fallido'   THEN 1 ELSE 0 END) AS fallidos,
               SUM(CASE WHEN estado = 'omitido'   THEN 1 ELSE 0 END) AS omitidos,
               SUM(CASE WHEN estado = 'pendiente' THEN 1 ELSE 0 END) AS pendientes,
               SUM(CASE WHEN estado = 'encolado'  THEN 1 ELSE 0 END) AS en_vuelo,
               SUM(CASE WHEN resultado IN ({$phReb}) THEN 1 ELSE 0 END) AS rebotados,
               SUM(CASE WHEN baja_lista IS NOT NULL  THEN 1 ELSE 0 END) AS bajas
          FROM datarocket_campanas_mensajes
         WHERE campana_id = ?
    ");
    $st->execute(array_merge(DRCA_RESULTADOS_REBOTE, [$id]));
    $g = $st->fetch() ?: [];

    $r = [
        'total'      => (int) ($g['total']      ?? 0),
        'encolados'  => (int) ($g['encolados']  ?? 0),
        'enviados'   => (int) ($g['enviados']   ?? 0),
        'fallidos'   => (int) ($g['fallidos']   ?? 0),
        'omitidos'   => (int) ($g['omitidos']   ?? 0),
        'pendientes' => (int) ($g['pendientes'] ?? 0),
        'en_vuelo'   => (int) ($g['en_vuelo']   ?? 0),
        'rebotados'  => (int) ($g['rebotados']  ?? 0),
        'bajas'      => (int) ($g['bajas']      ?? 0),
    ];

    // La campana esta completa cuando no queda nada por encolar ni nada en
    // vuelo. Ojo: 'completada' NO significa "todo salio bien" — una campana
    // entera de omitidos o de fallidos tambien termina completada. El detalle
    // esta en los contadores y en el padron.
    $cerrada = $r['pendientes'] === 0 && $r['en_vuelo'] === 0 && $r['total'] > 0;

    // Solo movemos el estado desde los estados "en marcha": si el operador
    // pauso o cancelo la campana mientras corriamos, no le pisamos la decision.
    $enMarcha = ['encolando', 'enviando'];
    if (in_array((string) $c['estado'], $enMarcha, true)) {
        $nuevo = $cerrada ? 'completada' : 'enviando';
        $pdo->prepare('UPDATE datarocket_campanas
                          SET estado = :e, completada = :f
                        WHERE id = :id')
            ->execute([
                ':e'  => $nuevo,
                ':f'  => $cerrada ? date('Y-m-d H:i:s') : null,
                ':id' => $id,
            ]);
        $r['estado'] = $nuevo;
    } else {
        $r['estado'] = (string) $c['estado'];
    }

    $pdo->prepare('UPDATE datarocket_campanas
                      SET total = :t, encolados = :en, enviados = :ev, fallidos = :f,
                          omitidos = :o, rebotados = :rb, bajas = :bj
                    WHERE id = :id')
        ->execute([
            ':t'  => $r['total'],
            ':en' => $r['encolados'],
            ':ev' => $r['enviados'],
            ':f'  => $r['fallidos'],
            ':o'  => $r['omitidos'],
            ':rb' => $r['rebotados'],
            ':bj' => $r['bajas'],
            ':id' => $id,
        ]);

    return $r;
}
