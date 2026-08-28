<?php
// api/lib/datarocket_campanas_expandir.php
// Motor de las campanas Datarocket. Es lo que convierte UNA campana
// ("esta lista x esta plantilla por este canal") en N mensajes encolados.
//
// Lo usan dos callers, y por eso vive en una lib y no adentro de un endpoint:
//   - cloud/api/datarocket_campanas_ejecutar.php  (boton "Ejecutar ahora", SSE)
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
    if (!in_array($estado, ['borrador', 'programada'], true)) {
        throw new InvalidArgumentException(
            "La campaña está en estado \"{$estado}\": solo se pueden lanzar las que están en borrador o programadas."
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
 * Corre la campana entera: valida, expande el padron, encola y deja el estado
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

    // 'expandiendo' es el candado: el cron no levanta campanas en ese estado, asi
    // que una corrida manual y una del cron no se pisan sobre el mismo padron.
    $pdo->prepare("UPDATE datarocket_campanas
                      SET estado = 'expandiendo', iniciada = COALESCE(iniciada, NOW())
                    WHERE id = :id")->execute([':id' => $id]);

    try {
        $log('--- Fase 1: armando el padrón ---');
        $p1 = drcaExpandirPadron($pdo, $c, $log);

        $log('--- Fase 2: encolando ---');
        $p2 = drcaEncolarPendientes($pdo, $c, $log, $deadline);
    } catch (Throwable $e) {
        // Si algo revienta a mitad, la campana no puede quedar clavada en
        // 'expandiendo' o el cron nunca la vuelve a mirar.
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
    }

    // `encolados` = "cuantos llegaron a la cola del canal", asi que se mide por
    // `mensaje_id IS NOT NULL` y no por estado. Un renglon que se encolo y
    // despues reboto sigue habiendo llegado a la cola; uno que fallo ANTES de
    // encolarse (excepcion en la fase 2) no tiene mensaje_id y no cuenta. Los
    // dos casos comparten el estado 'fallido', que por si solo no distingue.
    $st = $pdo->prepare("
        SELECT COUNT(*) AS total,
               SUM(CASE WHEN mensaje_id IS NOT NULL THEN 1 ELSE 0 END) AS encolados,
               SUM(CASE WHEN estado = 'enviado'   THEN 1 ELSE 0 END) AS enviados,
               SUM(CASE WHEN estado = 'fallido'   THEN 1 ELSE 0 END) AS fallidos,
               SUM(CASE WHEN estado = 'omitido'   THEN 1 ELSE 0 END) AS omitidos,
               SUM(CASE WHEN estado = 'pendiente' THEN 1 ELSE 0 END) AS pendientes,
               SUM(CASE WHEN estado = 'encolado'  THEN 1 ELSE 0 END) AS en_vuelo
          FROM datarocket_campanas_mensajes
         WHERE campana_id = :c
    ");
    $st->execute([':c' => $id]);
    $g = $st->fetch() ?: [];

    $r = [
        'total'      => (int) ($g['total']      ?? 0),
        'encolados'  => (int) ($g['encolados']  ?? 0),
        'enviados'   => (int) ($g['enviados']   ?? 0),
        'fallidos'   => (int) ($g['fallidos']   ?? 0),
        'omitidos'   => (int) ($g['omitidos']   ?? 0),
        'pendientes' => (int) ($g['pendientes'] ?? 0),
        'en_vuelo'   => (int) ($g['en_vuelo']   ?? 0),
    ];

    // La campana esta completa cuando no queda nada por encolar ni nada en
    // vuelo. Ojo: 'completada' NO significa "todo salio bien" — una campana
    // entera de omitidos o de fallidos tambien termina completada. El detalle
    // esta en los contadores y en el padron.
    $cerrada = $r['pendientes'] === 0 && $r['en_vuelo'] === 0 && $r['total'] > 0;

    // Solo movemos el estado desde los estados "en marcha": si el operador
    // pauso o cancelo la campana mientras corriamos, no le pisamos la decision.
    $enMarcha = ['expandiendo', 'enviando'];
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
                      SET total = :t, encolados = :en, enviados = :ev, fallidos = :f, omitidos = :o
                    WHERE id = :id')
        ->execute([
            ':t'  => $r['total'],
            ':en' => $r['encolados'],
            ':ev' => $r['enviados'],
            ':f'  => $r['fallidos'],
            ':o'  => $r['omitidos'],
            ':id' => $id,
        ]);

    return $r;
}
