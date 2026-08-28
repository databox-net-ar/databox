<?php
// api/lib/datarocket_listas_suscripciones.php
// LA UNICA PUERTA de entrada y de salida de las listas Datarocket.
//
// Nadie mas escribe `datarocket_prospectos_listas`. Ni los ABMs ni el motor de
// campanas: todos pasan por drListaSuscribir() / drListaDesuscribir().
//
// POR QUE
// -------
// Habia tres escritores de la puente, cada uno con sus propias reglas:
//
//   - `datarocketlistas.php` (editor de suscriptos): registraba historial y
//     recontaba el denormalizado.
//   - `datarocketprospectos.php` (combo de listas de la ficha): full replace,
//     sin recontar el denormalizado.
//   - `lib/datarocket_campanas_expandir.php` (baja por rebote): registraba,
//     recontaba, y ademas estampaba el padron.
//
// Tres implementaciones de la misma operacion es garantia de que se separen: la
// del medio ya se habia separado (no recontaba, y `datarocket_listas.suscriptos`
// quedaba mintiendo hasta que alguien corriera el recalculo global). Con una
// sola puerta, arreglar una regla la arregla para todos.
//
// LAS REGLAS, QUE AHORA SON UNA SOLA VEZ
// --------------------------------------
//   1. Solo se registra lo que CAMBIA de verdad. Suscribir a quien ya estaba no
//      es un alta; desuscribir a quien no estaba no es una baja. Sin esto el log
//      cuenta guardados en vez de suscripciones.
//   2. El historial se escribe ANTES de tocar la puente. Despues del DELETE ya
//      no hay de donde sacar quien estaba ni con que dato.
//   3. `destino` se denormaliza al momento del cambio. Si despues se corrige el
//      correo, el historial sigue diciendo con que dato entro o salio.
//   4. El denormalizado `datarocket_listas.suscriptos` se recalcula siempre, en
//      la misma transaccion.
//   5. Los ids inexistentes se descartan en silencio, sin depender de que
//      reviente una FK.
//
// TRANSACCIONES
// -------------
// Las dos funciones se enganchan a la transaccion del caller si hay una, y solo
// abren la propia si no. PDO no anida: llamar a beginTransaction() con una
// activa tira "There is already an active transaction".

require_once __DIR__ . '/../db.php';

// Vocabulario de `estados`. Los valores viven en la base bajo estos campos; las
// constantes existen para que los callers no escriban el string a mano.
const DR_LISTA_ALTA_MOTIVO_MANUAL = 'manual';
const DR_LISTA_BAJA_MOTIVO_MANUAL = 'manual';

// Cuantas filas de historial entran en un INSERT multi-row. El editor admite
// hasta 5000 ids por llamada; mandarlas de a una serian 5000 round-trips y de
// una sola vez, un paquete que puede pasarse de `max_allowed_packet`.
const DR_LISTA_LOTE_LOG = 500;

/**
 * Suscribe prospectos a una lista. Devuelve cuantos se suscribieron REALMENTE
 * (los que ya estaban no cuentan).
 *
 * $ctx admite:
 *   motivo        string  Valor de `datarocket_lista_alta_motivo`. Default 'manual'.
 *   origen        string  Quien lo ejecuta ('abm/datarocketlistas', ...).
 *   usuario_id    ?int    Usuario de la sesion. NULL si es un automatismo.
 *   detalle       ?string Texto libre para todo el lote.
 *   por_prospecto array   [prospecto_id => ['motivo'=>..,'detalle'=>..,'destino'=>..]]
 *                         Pisa los defaults fila por fila.
 */
function drListaSuscribir(PDO $pdo, int $listaId, array $prospectoIds, array $ctx = []): int {
    return drListaMover($pdo, $listaId, $prospectoIds, $ctx, true);
}

/**
 * Desuscribe prospectos de una lista. Devuelve cuantos se dieron de baja
 * REALMENTE (los que no estaban no cuentan).
 *
 * Mismo $ctx que drListaSuscribir(), mas dos claves que solo tienen sentido en
 * la salida — la baja automatica la origina un envio concreto y hay que poder
 * responder "que mensaje la causo":
 *   campana_id ?int
 *   mensaje_id ?int   (tambien aceptado dentro de `por_prospecto`)
 */
function drListaDesuscribir(PDO $pdo, int $listaId, array $prospectoIds, array $ctx = []): int {
    return drListaMover($pdo, $listaId, $prospectoIds, $ctx, false);
}

/**
 * Recalcula `datarocket_listas.suscriptos` para una lista. Publica porque el
 * recalculo global (`datarocketlistas_recalcular.php`) hace lo mismo para todas.
 */
function drListaRecontar(PDO $pdo, int $listaId): void {
    $pdo->prepare('UPDATE datarocket_listas dl
                      SET dl.suscriptos = (SELECT COUNT(*)
                                             FROM datarocket_prospectos_listas dpl
                                            WHERE dpl.lista_id = dl.id)
                    WHERE dl.id = :id')->execute([':id' => $listaId]);
}

// ----------------------------------------------------------------------------
// Interno
// ----------------------------------------------------------------------------

/**
 * El cuerpo compartido de las dos direcciones. `$entrada` decide si se suscribe
 * o se desuscribe; el resto del procedimiento es identico y por eso vive en una
 * sola funcion en vez de duplicarse "por claridad".
 */
function drListaMover(PDO $pdo, int $listaId, array $prospectoIds, array $ctx, bool $entrada): int {
    $ids = drListaIdsUnicos($prospectoIds);
    if ($listaId <= 0 || !$ids) return 0;

    // Regla 5, primera mitad: una lista inexistente no es un error del caller,
    // es un id viejo. Se descarta antes de tocar nada — si no, el INSERT del
    // historial reventaria contra `fk_drla_lista`.
    $ex = $pdo->prepare('SELECT 1 FROM datarocket_listas WHERE id = :id LIMIT 1');
    $ex->execute([':id' => $listaId]);
    if (!$ex->fetchColumn()) return 0;

    $propia = !$pdo->inTransaction();
    if ($propia) $pdo->beginTransaction();
    try {
        // Regla 1 + regla 5: quienes cambian DE VERDAD, y de paso el `destino`
        // del momento (regla 3). El JOIN contra `datarocket_prospectos` descarta
        // ids inexistentes; el EXISTS/NOT EXISTS contra la puente descarta a los
        // que ya estan en el estado pedido.
        $cambian = drListaSeleccionarCambios($pdo, $listaId, $ids, $entrada);
        if (!$cambian) {
            if ($propia) $pdo->commit();
            return 0;
        }

        // Regla 2: el historial va ANTES de mover la puente.
        drListaRegistrar($pdo, $listaId, $cambian, $ctx, $entrada);

        $ph  = implode(',', array_fill(0, count($cambian), '?'));
        $efe = array_keys($cambian);
        if ($entrada) {
            // INSERT IGNORE y no INSERT a secas: entre el SELECT de arriba y
            // esta linea puede haber entrado otra request con el mismo prospecto.
            // La PK compuesta lo resuelve sin abortar la operacion entera.
            $st = $pdo->prepare("INSERT IGNORE INTO datarocket_prospectos_listas (lista_id, prospecto_id)
                                 SELECT ?, p.id FROM datarocket_prospectos p WHERE p.id IN ({$ph})");
        } else {
            $st = $pdo->prepare("DELETE FROM datarocket_prospectos_listas
                                  WHERE lista_id = ? AND prospecto_id IN ({$ph})");
        }
        $st->execute(array_merge([$listaId], $efe));
        $n = $st->rowCount();

        // Regla 4.
        drListaRecontar($pdo, $listaId);

        if ($propia) $pdo->commit();
        return $n;
    } catch (Throwable $e) {
        if ($propia) $pdo->rollBack();
        throw $e;
    }
}

/**
 * Devuelve [prospecto_id => destino] de los que efectivamente cambian de estado.
 * El `destino` sale del dato de contacto de hoy; los callers que sepan uno mejor
 * (el motor de campanas sabe a que direccion se mando de verdad) lo pisan via
 * `por_prospecto`.
 */
function drListaSeleccionarCambios(PDO $pdo, int $listaId, array $ids, bool $entrada): array {
    $ph   = implode(',', array_fill(0, count($ids), '?'));
    $cond = $entrada ? 'NOT EXISTS' : 'EXISTS';
    $st = $pdo->prepare("
        SELECT p.id, NULLIF(TRIM(p.correo), '') AS destino
          FROM datarocket_prospectos p
         WHERE p.id IN ({$ph})
           AND {$cond} (SELECT 1
                          FROM datarocket_prospectos_listas dpl
                         WHERE dpl.lista_id = ? AND dpl.prospecto_id = p.id)
    ");
    $st->execute(array_merge($ids, [$listaId]));

    $out = [];
    foreach ($st->fetchAll() as $r) $out[(int) $r['id']] = $r['destino'];
    return $out;
}

/**
 * Escribe el historial. `datarocket_listas_altas` y `datarocket_listas_bajas`
 * tienen el mismo shape salvo `campana_id`/`mensaje_id`, que solo existen en la
 * salida (un alta no se origina nunca en una campana).
 */
function drListaRegistrar(PDO $pdo, int $listaId, array $cambian, array $ctx, bool $entrada): void {
    $motivoDef = (string) ($ctx['motivo'] ?? 'manual');
    $origen    = isset($ctx['origen'])  && $ctx['origen']  !== '' ? mb_substr((string) $ctx['origen'], 0, 50)  : null;
    $detalleDef= isset($ctx['detalle']) && $ctx['detalle'] !== '' ? mb_substr((string) $ctx['detalle'], 0, 255) : null;
    $usuarioId = isset($ctx['usuario_id']) && (int) $ctx['usuario_id'] > 0 ? (int) $ctx['usuario_id'] : null;
    $campanaId = isset($ctx['campana_id']) && (int) $ctx['campana_id'] > 0 ? (int) $ctx['campana_id'] : null;
    $porProsp  = is_array($ctx['por_prospecto'] ?? null) ? $ctx['por_prospecto'] : [];

    $tabla = $entrada ? 'datarocket_listas_altas' : 'datarocket_listas_bajas';
    $cols  = ['lista_id', 'prospecto_id', 'destino', 'motivo', 'detalle', 'origen', 'usuario_id'];
    if (!$entrada) $cols = array_merge($cols, ['campana_id', 'mensaje_id']);
    $cols[] = 'fecha';

    $filas = [];
    foreach ($cambian as $pid => $destino) {
        $o = is_array($porProsp[$pid] ?? null) ? $porProsp[$pid] : [];
        $fila = [
            $listaId,
            $pid,
            array_key_exists('destino', $o) ? $o['destino'] : $destino,
            (string) ($o['motivo'] ?? $motivoDef),
            isset($o['detalle']) && $o['detalle'] !== '' ? mb_substr((string) $o['detalle'], 0, 255) : $detalleDef,
            $origen,
            $usuarioId,
        ];
        if (!$entrada) {
            $fila[] = $campanaId;
            $fila[] = isset($o['mensaje_id']) && (int) $o['mensaje_id'] > 0 ? (int) $o['mensaje_id'] : null;
        }
        $filas[] = $fila;
    }

    // Multi-row en lotes: una fila por prospecto seria un round-trip por
    // prospecto, y las 5000 de una sola vez pueden pasarse de `max_allowed_packet`.
    $nCols   = count($cols);
    $tupla   = '(' . implode(',', array_fill(0, $nCols - 1, '?')) . ',NOW())';
    $colList = '`' . implode('`,`', $cols) . '`';

    foreach (array_chunk($filas, DR_LISTA_LOTE_LOG) as $lote) {
        $sql = "INSERT INTO {$tabla} ({$colList}) VALUES "
             . implode(',', array_fill(0, count($lote), $tupla));
        $st = $pdo->prepare($sql);
        $st->execute(array_merge(...$lote));
    }
}

/** Enteros positivos, sin repetidos, sin basura. */
function drListaIdsUnicos(array $v): array {
    $out = [];
    foreach ($v as $x) {
        $n = (int) $x;
        if ($n > 0) $out[$n] = true;
    }
    return array_keys($out);
}
