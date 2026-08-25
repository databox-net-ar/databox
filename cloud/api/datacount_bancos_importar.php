<?php
// api/datacount_bancos_importar.php
// Datacount > Bancos: importacion de extractos (CSV / TSV / XLSX).
//
// Flujo en dos pasos, ambos multipart y ambos con el archivo adjunto:
//
//   POST api/datacount_bancos_importar.php?action=analizar
//        campos: archivo, cuenta
//     -> {encabezados, filas_muestra, mapeo_sugerido, mapeo_guardado, total_filas}
//
//   POST api/datacount_bancos_importar.php?action=importar
//        campos: archivo, cuenta, mapeo (JSON), guardar_mapeo (0|1)
//     -> {insertados, omitidos, descartados, errores[], importacion_id, saldo_actualizado}
//
// El archivo viaja dos veces a proposito: el paso 1 no deja NADA en el server
// (ni temp, ni sesion, ni fila en la BD), asi que no hay que limpiar basura si
// el usuario abandona el modal a mitad de camino. El browser ya tiene el File
// en memoria, asi que el segundo POST no le cuesta nada al usuario.
//
// Respuesta siempre {ok: true, data: ...} u {ok: false, error: '...'} (STACK.md sec. 10).

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/lib/auth_check.php';
require_once __DIR__ . '/lib/sucesos.php';
require_once __DIR__ . '/lib/planilla.php';
require_once __DIR__ . '/lib/datacount_bancos.php';
require_once __DIR__ . '/lib/bancos_interpretes/_base.php';

requireAuth();
header('Content-Type: application/json; charset=utf-8');

// Cuantas filas devuelve el preview del paso 1. Suficiente para que el usuario
// confirme que el mapeo es correcto sin mandarle el extracto entero al front.
const DCBI_MUESTRA = 12;

// Cuantas filas del header se escanean buscando el encabezado real. Los
// homebanking meten titulo, CUIT, periodo y una linea en blanco antes de la
// tabla; 15 cubre todos los que vimos sin arriesgar falsos positivos.
const DCBI_MAX_SCAN_ENCABEZADO = 15;

// Tope de errores detallados que se devuelven. Si un extracto falla en 3000
// filas no sirve listarlas todas: el problema es el mapeo, no las filas.
const DCBI_MAX_ERRORES = 25;

// Palabras clave por campo destino, en orden de prioridad. La deteccion recorre
// los encabezados y se queda con la primera columna que matchea cada destino,
// asi que los patrones mas especificos van primero dentro de cada lista.
//
// OJO: tiene que quedar declarada ARRIBA del bloque `try`. Las funciones se
// hoistean pero las `const` de nivel de archivo no: se evaluan en orden, y si
// esta queda mas abajo el handler de ?action=analizar explota con
// "Undefined constant".
const DCBI_PATRONES = [
    'col_fecha_valor' => ['fecha valor', 'f. valor', 'fecha de valor', 'valor'],
    'col_fecha'       => ['fecha operacion', 'fecha de operacion', 'f. oper', 'fecha mov', 'fecha', 'date', 'dia'],
    'col_debito'      => ['debito', 'debe', 'cargo', 'egreso', 'salida', 'retiro', 'extraccion'],
    'col_credito'     => ['credito', 'haber', 'abono', 'ingreso', 'entrada', 'deposito', 'acreditacion'],
    'col_importe'     => ['importe', 'monto', 'amount', 'valor del movimiento'],
    'col_saldo'       => ['saldo', 'balance'],
    'col_referencia'  => ['referencia', 'nro comprobante', 'n comprobante', 'comprobante',
                          'nro operacion', 'n operacion', 'operacion', 'numero', 'nro', 'id'],
    'col_contraparte' => ['beneficiario', 'ordenante', 'destinatario', 'contraparte', 'titular', 'razon social'],
    'col_descripcion' => ['descripcion', 'concepto', 'detalle', 'movimiento', 'glosa', 'leyenda', 'observacion'],
];

try {
    $accion = $_GET['action'] ?? '';
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
        jsonError('Método no soportado', 405);
    }

    // Analizar es solo lectura del archivo (no toca la BD) pero se pide
    // `consultar`; importar escribe movimientos, asi que pide `agregar`.
    if ($accion === 'analizar') {
        requirePermission('datacount.bancos.movimientos.consultar');
    } elseif ($accion === 'importar') {
        requirePermission('datacount.bancos.movimientos.agregar');
    } else {
        jsonError('Acción no soportada. Usá ?action=analizar o ?action=importar.', 400);
    }

    $pdo    = db();
    $cuenta = dcbiCuentaDeRequest($pdo);
    $datos  = dcbiLeerArchivoSubido();

    if ($accion === 'analizar') {
        handleAnalizarExtracto($pdo, $cuenta, $datos);
    } else {
        handleImportarExtracto($pdo, $cuenta, $datos);
    }
} catch (RuntimeException $e) {
    // Las RuntimeException de planilla.php son errores de formato del archivo:
    // culpa del input, no del server. 400 y el texto tal cual, que ya esta
    // redactado para que lo lea un humano.
    jsonError($e->getMessage(), 400);
} catch (Throwable $e) {
    jsonError($e->getMessage(), 500);
}

// ----------------------------------------------------------------------------
// Entrada
// ----------------------------------------------------------------------------

function dcbiCuentaDeRequest(PDO $pdo): array {
    $id = (int) ($_POST['cuenta'] ?? 0);
    if ($id <= 0) jsonError('Falta la cuenta destino.', 400);

    $st = $pdo->prepare(
        'SELECT id, nombre, tipo, moneda, banco_id, import_config
           FROM datacount_bancos_cuentas WHERE id = :id LIMIT 1'
    );
    $st->execute([':id' => $id]);
    $c = $st->fetch();
    if (!$c) jsonError('La cuenta indicada no existe.', 404);
    return $c;
}

function dcbiLeerArchivoSubido(): array {
    if (!isset($_FILES['archivo'])) jsonError('No se recibió ningún archivo.', 400);

    $f = $_FILES['archivo'];
    if (($f['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
        jsonError(match ((int) $f['error']) {
            UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE => 'El archivo supera el tamaño máximo permitido por el servidor.',
            UPLOAD_ERR_PARTIAL                        => 'La subida se interrumpió. Probá de nuevo.',
            UPLOAD_ERR_NO_FILE                        => 'No se recibió ningún archivo.',
            default                                   => 'Error al subir el archivo.',
        }, 400);
    }
    if (!is_uploaded_file($f['tmp_name'])) jsonError('Archivo inválido.', 400);

    return planillaLeer($f['tmp_name'], (string) ($f['name'] ?? ''));
}

// ----------------------------------------------------------------------------
// Deteccion del encabezado y del mapeo
// ----------------------------------------------------------------------------

// Elige la fila que hace de encabezado: la de las primeras DCBI_MAX_SCAN_ENCABEZADO
// que mas patrones distintos matchea. Se exige un minimo de 2 para no confundir
// un titulo suelto ("Movimientos de la cuenta") con la tabla real.
function dcbiDetectarFilaEncabezado(array $filas): int {
    $mejorFila  = 0;
    $mejorScore = 0;
    $tope       = min(count($filas), DCBI_MAX_SCAN_ENCABEZADO);

    for ($i = 0; $i < $tope; $i++) {
        $celdas = array_map('planillaNormalizarTexto', $filas[$i]);
        $score  = 0;
        foreach (DCBI_PATRONES as $patrones) {
            foreach ($celdas as $c) {
                if ($c === '') continue;
                foreach ($patrones as $p) {
                    if (str_contains($c, $p)) { $score++; break 2; }
                }
            }
        }
        if ($score > $mejorScore) { $mejorScore = $score; $mejorFila = $i; }
    }
    return $mejorScore >= 2 ? $mejorFila : -1;   // -1 = el archivo no tiene encabezado
}

function dcbiSugerirMapeo(array $encabezados): array {
    $norm  = array_map('planillaNormalizarTexto', $encabezados);
    $mapeo = [];
    $usadas = [];

    foreach (DCBI_PATRONES as $destino => $patrones) {
        foreach ($patrones as $p) {
            foreach ($norm as $idx => $texto) {
                if ($texto === '' || in_array($idx, $usadas, true)) continue;
                if (str_contains($texto, $p)) {
                    $mapeo[$destino] = $idx;
                    $usadas[]        = $idx;
                    continue 3;
                }
            }
        }
        $mapeo[$destino] = null;
    }

    // Debito/credito e importe unico son excluyentes. Si se detectaron las dos
    // columnas separadas, esa es la lectura correcta y un "importe" suelto
    // sobra (suele ser una columna de totales).
    if ($mapeo['col_debito'] !== null && $mapeo['col_credito'] !== null) {
        $mapeo['col_importe'] = null;
    }

    return $mapeo + [
        'fila_encabezado' => 1,
        'formato_fecha'   => 'dmy',   // default argentino
        'decimal'         => 'auto',
        'medio_default'   => null,
        'invertir_signo'  => false,
    ];
}

function dcbiNormalizarMapeo(array $m): array {
    $col = function ($v) {
        if ($v === null || $v === '' || $v === false) return null;
        $n = (int) $v;
        return $n >= 0 ? $n : null;
    };

    $formato = (string) ($m['formato_fecha'] ?? 'dmy');
    if (!in_array($formato, ['dmy', 'mdy', 'ymd', 'auto'], true)) $formato = 'dmy';

    $decimal = (string) ($m['decimal'] ?? 'auto');
    if (!in_array($decimal, ['auto', 'coma', 'punto'], true)) $decimal = 'auto';

    return [
        'fila_encabezado' => (int) ($m['fila_encabezado'] ?? 1),
        'col_fecha'       => $col($m['col_fecha']       ?? null),
        'col_fecha_valor' => $col($m['col_fecha_valor'] ?? null),
        'col_descripcion' => $col($m['col_descripcion'] ?? null),
        'col_referencia'  => $col($m['col_referencia']  ?? null),
        'col_importe'     => $col($m['col_importe']     ?? null),
        'col_debito'      => $col($m['col_debito']      ?? null),
        'col_credito'     => $col($m['col_credito']     ?? null),
        'col_saldo'       => $col($m['col_saldo']       ?? null),
        'col_contraparte' => $col($m['col_contraparte'] ?? null),
        'formato_fecha'   => $formato,
        'decimal'         => $decimal,
        'medio_default'   => ($m['medio_default'] ?? '') !== '' ? (string) $m['medio_default'] : null,
        'invertir_signo'  => !empty($m['invertir_signo']),
    ];
}

// ----------------------------------------------------------------------------
// Paso 1: analizar
// ----------------------------------------------------------------------------

function handleAnalizarExtracto(PDO $pdo, array $cuenta, array $datos): void {
    $filas = $datos['filas'];
    if (!$filas) jsonError('El archivo no tiene filas.', 400);

    // --- Intérprete del banco -------------------------------------------
    // Se resuelve el intérprete configurado para el banco de la cuenta y, por
    // separado, se corre la detección de TODOS. Comparar las dos cosas es lo
    // que permite avisar "esto parece un extracto de otro banco" en vez de
    // importar mal en silencio.
    $claveBanco = dcbInterpreteDeBanco($pdo, $cuenta['banco_id'] !== null ? (int) $cuenta['banco_id'] : null);
    $ranking    = dcbDetectarTodos($filas, $datos['formato']);
    $mejor      = $ranking[0] ?? null;

    $interprete = null;
    $aviso      = null;

    $delBanco = null;
    foreach ($ranking as $r) {
        if ($r['clave'] === $claveBanco) { $delBanco = $r; break; }
    }

    // No alcanza con que el intérprete del banco supere el umbral: hay que
    // exigir además que ningún otro le saque una ventaja clara.
    //
    // Los intérpretes tienen firmas tolerantes para sobrevivir a que el banco
    // cambie el export, y eso hace que varios reconozcan a medias el archivo de
    // otro. Un extracto del Banco San Juan puntúa 100 en San Juan y 70 en
    // Supervielle — los dos por encima del umbral. Sin este margen, subirlo a
    // una cuenta de Supervielle lo aceptaba con el intérprete de Supervielle y
    // sin decir nada, leyendo mal la contraparte y clasificando cualquier cosa.
    $ganaOtro = $claveBanco !== null && $delBanco !== null && $mejor !== null
             && $mejor['clave'] !== $claveBanco
             && $mejor['puntaje'] >= $delBanco['puntaje'] + DCB_MARGEN_DETECCION;

    if ($claveBanco !== null && $delBanco !== null
        && $delBanco['puntaje'] >= DCB_UMBRAL_DETECCION && !$ganaOtro) {
        // Caso feliz: el intérprete del banco reconoce el archivo y nadie lo
        // reconoce claramente mejor.
        $interprete = $delBanco;
    } elseif ($claveBanco !== null && $mejor !== null && $mejor['puntaje'] >= DCB_UMBRAL_DETECCION) {
        // Otro intérprete reconoce el archivo pero no el del banco de la cuenta.
        // Casi siempre es un archivo subido a la cuenta equivocada.
        $interprete = $mejor;
        $obj        = dcbInterprete($claveBanco);
        $aviso      = 'El archivo parece un extracto de ' . $mejor['nombre']
                    . ', pero esta cuenta está asociada a '
                    . ($obj ? $obj->nombre() : 'otro banco')
                    . '. Verificá que sea la cuenta correcta antes de importar.';
    } elseif ($claveBanco === null && $mejor !== null && $mejor['puntaje'] >= DCB_UMBRAL_DETECCION) {
        // El banco de la cuenta no tiene intérprete configurado, pero el archivo
        // se reconoce igual. Se ofrece y se avisa.
        $interprete = $mejor;
        $aviso      = 'El banco de esta cuenta no tiene intérprete configurado, '
                    . 'pero el archivo se reconoció como ' . $mejor['nombre'] . '.';
    } elseif ($claveBanco !== null) {
        $obj   = dcbInterprete($claveBanco);
        $aviso = 'El intérprete de ' . ($obj ? $obj->nombre() : $claveBanco)
               . ' no reconoció este archivo. Puede que el banco haya cambiado el formato '
               . 'del export. Mapeá las columnas a mano o avisá para actualizar el intérprete.';
    } else {
        // Banco sin interprete configurado. No es un error, pero conviene
        // decirlo: si no, el usuario no entiende por que a esta cuenta le pide
        // mapear columnas y a las otras no.
        $aviso = 'El banco de esta cuenta todavía no tiene intérprete propio, '
               . 'así que hay que indicarle qué columna es cada cosa. '
               . 'El mapeo queda guardado para la próxima importación.';
    }

    // Preview leído por el intérprete: el usuario confirma sobre los
    // movimientos ya interpretados, no sobre columnas crudas.
    $preview = null;
    if ($interprete !== null) {
        $obj = dcbInterprete($interprete['clave']);
        if ($obj !== null) {
            $r = $obj->interpretar($filas, $datos['formato']);
            $interprete['calibracion'] = $obj->calibracion();
            $interprete['avisos']      = $r->avisos;
            $interprete['total']       = count($r->movimientos);
            $preview = array_map(
                fn(MovimientoBancario $m) => $m->aArray(),
                array_slice($r->movimientos, 0, DCBI_MUESTRA)
            );
            // Reconoció el encabezado pero no pudo leer nada: mejor mandar al
            // mapeo manual que ofrecer un import vacío.
            if (!$r->movimientos) {
                $aviso      = ($aviso ? $aviso . ' ' : '')
                            . 'El intérprete reconoció el formato pero no pudo leer movimientos.';
                $interprete = null;
                $preview    = null;
            }
        }
    }

    // --- Fallback: mapeo manual de columnas ------------------------------
    $idxEnc      = dcbiDetectarFilaEncabezado($filas);
    $encabezados = $idxEnc >= 0 ? $filas[$idxEnc] : [];

    // Sin encabezado detectado igual mostramos el preview con nombres
    // posicionales, para que el usuario mapee a mano.
    if (!$encabezados) {
        $ancho = max(array_map('count', array_slice($filas, 0, 5)) ?: [0]);
        for ($i = 0; $i < $ancho; $i++) $encabezados[] = 'Columna ' . ($i + 1);
    }

    $sugerido = dcbiSugerirMapeo($encabezados);
    $sugerido['fila_encabezado'] = $idxEnc + 1;   // 1-based; 0 = sin encabezado

    $desde   = $idxEnc >= 0 ? $idxEnc + 1 : 0;
    $muestra = array_slice($filas, $desde, DCBI_MUESTRA);

    jsonOk([
        // 'interprete' = hay un intérprete que lee el archivo; el front muestra
        // los movimientos ya leídos y saltea el mapeo de columnas.
        // 'mapeo'      = ninguno lo reconoció; se cae al asistente manual.
        'modo'           => $interprete !== null ? 'interprete' : 'mapeo',
        'interprete'     => $interprete,
        'preview'        => $preview,
        'aviso'          => $aviso,
        'deteccion'      => $ranking,
        'formato'        => $datos['formato'],
        'total_filas'    => count($filas),
        'filas_datos'    => max(0, count($filas) - $desde),
        'encabezados'    => array_values($encabezados),
        'filas_muestra'  => array_values($muestra),
        'mapeo_sugerido' => $sugerido,
        'mapeo_guardado' => dcbiMapeoGuardado($cuenta),
        'cuenta'         => [
            'id'     => (int) $cuenta['id'],
            'nombre' => (string) $cuenta['nombre'],
            'tipo'   => (string) $cuenta['tipo'],
            'moneda' => (string) $cuenta['moneda'],
        ],
    ]);
}

function dcbiMapeoGuardado(array $cuenta): ?array {
    $raw = $cuenta['import_config'] ?? null;
    if ($raw === null || trim((string) $raw) === '') return null;
    $j = json_decode((string) $raw, true);
    return is_array($j) ? $j : null;
}

// ----------------------------------------------------------------------------
// Paso 2: importar
// ----------------------------------------------------------------------------

/**
 * Lee el archivo con el interprete indicado y devuelve movimientos normalizados.
 * @return array{0: array<int, array>, 1: int, 2: string[]}  [movimientos, descartadas, avisos]
 */
function dcbiLeerConInterprete(array $cuenta, array $datos): array {
    $clave = trim((string) ($_POST['interprete'] ?? ''));
    if ($clave === '') jsonError('Falta indicar el intérprete.', 400);

    $obj = dcbInterprete($clave);
    if ($obj === null) jsonError('El intérprete "' . $clave . '" no existe.', 400);

    // Se revalida la deteccion en el import: entre el analisis y la confirmacion
    // el usuario pudo cambiar de archivo en el input y reenviar otro distinto.
    $puntaje = $obj->detectar($datos['filas'], $datos['formato']);
    if ($puntaje < DCB_UMBRAL_DETECCION) {
        jsonError('El archivo enviado ya no coincide con el formato de ' . $obj->nombre()
                . '. Volvé a analizarlo.', 409);
    }

    $r = $obj->interpretar($datos['filas'], $datos['formato']);

    // El interprete puede asignar un medio que no aplique al tipo de esta
    // cuenta (el de MercadoPago clasifica como 'qr', valido en billetera pero
    // no en banco). Se limpia el medio en vez de rechazar el import entero: el
    // movimiento es correcto, lo unico que sobra es la etiqueta.
    $validos  = dcbMediosValidos((string) $cuenta['tipo']);
    $limpiados = 0;

    $out = [];
    foreach ($r->movimientos as $i => $mov) {
        $a = $mov->aArray();
        if ($a['medio'] !== null && !in_array($a['medio'], $validos, true)) {
            $a['medio'] = null;
            $limpiados++;
        }
        $a['fila'] = $i + 1;   // orden dentro de lo que leyó el intérprete
        $out[] = $a;
    }

    $avisos = $r->avisos;
    if ($limpiados > 0) {
        $avisos[] = "Se ignoró el medio de pago en {$limpiados} movimiento(s): "
                  . 'no aplica al tipo de esta cuenta.';
    }
    return [$out, $r->descartadas, $avisos];
}

/**
 * Lee el archivo con el mapeo manual de columnas.
 * @return array{0: array<int, array>, 1: int, 2: array}  [movimientos, descartadas, mapeo]
 */
function dcbiLeerConMapeo(array $cuenta, array $datos): array {
    $mapeoRaw = $_POST['mapeo'] ?? '';
    $mapeoIn  = is_string($mapeoRaw) ? json_decode($mapeoRaw, true) : null;
    if (!is_array($mapeoIn)) jsonError('Falta el mapeo de columnas o no es JSON válido.', 400);

    $m = dcbiNormalizarMapeo($mapeoIn);

    if ($m['col_fecha'] === null) {
        jsonError('El mapeo no indica cuál es la columna de fecha.', 400);
    }
    if ($m['col_importe'] === null && $m['col_debito'] === null && $m['col_credito'] === null) {
        jsonError('El mapeo no indica de dónde sale el importe (ni columna única ni débito/crédito).', 400);
    }

    // El medio por defecto tiene que ser valido para el tipo de cuenta: es la
    // misma whitelist que aplica el ABM. Aca si se rechaza (a diferencia del
    // camino por interprete) porque el valor lo eligio el usuario a mano.
    if ($m['medio_default'] !== null) {
        $validos = dcbMediosValidos((string) $cuenta['tipo']);
        if (!in_array($m['medio_default'], $validos, true)) {
            jsonError('El medio por defecto no aplica al tipo de esta cuenta.', 400);
        }
    }

    $desde = max(0, (int) $m['fila_encabezado']);   // 1-based => primera de datos
    $filas = array_slice($datos['filas'], $desde);
    if (!$filas) jsonError('El archivo no tiene filas de datos debajo del encabezado.', 400);

    $out         = [];
    $descartadas = 0;

    foreach ($filas as $i => $fila) {
        $fecha = planillaFecha((string) ($fila[$m['col_fecha']] ?? ''), $m['formato_fecha']);
        if ($fecha === null) {
            // Sin fecha no es un movimiento: es un subtotal, un pie de pagina o
            // una fila en blanco. Se descarta en silencio (no es un error).
            $descartadas++;
            continue;
        }

        $par = dcbiImporteDeFila($fila, $m);
        if ($par === null) { $descartadas++; continue; }
        [$tipo, $importe] = $par;
        // Un movimiento de importe 0 no mueve el saldo: fila informativa.
        if ($importe <= 0) { $descartadas++; continue; }

        $out[] = [
            'fila'        => $desde + $i + 1,   // 1-based, como lo ve en Excel
            'fecha'       => $fecha,
            'fecha_valor' => $m['col_fecha_valor'] !== null
                                ? planillaFecha((string) ($fila[$m['col_fecha_valor']] ?? ''), $m['formato_fecha'])
                                : null,
            'tipo'        => $tipo,
            'importe'     => $importe,
            'descripcion' => dcbiCelda($fila, $m['col_descripcion'], 500),
            'referencia'  => dcbiCelda($fila, $m['col_referencia'], 100),
            'saldo'       => $m['col_saldo'] !== null
                                ? planillaNumero((string) ($fila[$m['col_saldo']] ?? ''), $m['decimal'])
                                : null,
            'contraparte' => dcbiCelda($fila, $m['col_contraparte'], 255),
            'medio'       => $m['medio_default'],
            'cuit'        => null,
        ];
    }

    return [$out, $descartadas, $m];
}

function handleImportarExtracto(PDO $pdo, array $cuenta, array $datos): void {
    $cuentaId = (int) $cuenta['id'];
    $moneda   = (string) ($cuenta['moneda'] ?: 'P');
    $modo     = ($_POST['modo'] ?? 'mapeo') === 'interprete' ? 'interprete' : 'mapeo';

    // Las dos vías producen la MISMA forma normalizada, así que de acá para
    // abajo el dedup, el insert y la actualización de saldo son comunes.
    $mapeoParaGuardar = null;
    if ($modo === 'interprete') {
        [$movimientos, $descartadas, $avisos] = dcbiLeerConInterprete($cuenta, $datos);
    } else {
        [$movimientos, $descartadas, $mapeoParaGuardar] = dcbiLeerConMapeo($cuenta, $datos);
        $avisos = [];
    }

    // Huellas ya presentes en la cuenta. Se traen de una para no hacer un
    // SELECT por fila: un extracto de 3000 movimientos serian 3000 roundtrips.
    $prev = $pdo->prepare('SELECT huella FROM datacount_bancos_movimientos WHERE cuenta_id = :c');
    $prev->execute([':c' => $cuentaId]);
    $existentes = array_flip($prev->fetchAll(PDO::FETCH_COLUMN) ?: []);

    $importacionId = bin2hex(random_bytes(16));
    $aInsertar     = [];
    $errores       = [];
    $omitidas      = 0;
    $ocurrencias   = [];   // huella base -> veces vista en este archivo
    $repetidos     = 0;    // movimientos legitimamente repetidos (ocurrencia > 1)

    // Saldo de cierre = el del movimiento cronologicamente mas nuevo, que es el
    // que se va a copiar a la cuenta.
    //
    // No alcanza con quedarse con la fecha mas alta: el extracto trae varios
    // movimientos por dia y la fecha no los desempata. En el export del Banco
    // San Juan hay tres del 21/08 con saldos 34.434,81 / 35.034,81 / 135.034,81
    // y el bueno es el primero — quedarse con "el ultimo de la fecha maxima"
    // dejaba la cuenta en 135.034,81, 100 mil pesos de mas.
    //
    // Lo que si desempata es el orden del archivo, que es la secuencia real de
    // los movimientos. Segun el banco exporte ascendente o descendente, el mas
    // nuevo es el ultimo o el primero.
    $ultimoSaldo = null;
    $ultimaFecha = null;
    $conSaldo    = array_values(array_filter($movimientos, fn($m) => $m['saldo'] !== null));
    if ($conSaldo) {
        $ultimo    = $conSaldo[count($conSaldo) - 1];
        $desc      = $conSaldo[0]['fecha'] >= $ultimo['fecha'];
        $cierre    = $desc ? $conSaldo[0] : $ultimo;
        $ultimaFecha = $cierre['fecha'];
        $ultimoSaldo = (float) $cierre['saldo'];
    }

    foreach ($movimientos as $mov) {
        $importeStr = number_format((float) $mov['importe'], 2, '.', '');
        $base       = planillaHuella(
            $mov['fecha'], $mov['tipo'], $importeStr,
            $mov['referencia'], $mov['descripcion']
        );

        // El mismo movimiento puede venir repetido y ser correcto (ver
        // dcbHuellaOcurrencia). Se le da una huella propia a cada repeticion en
        // vez de descartarla, que es lo que hacia perder movimientos reales.
        $n      = ($ocurrencias[$base] = ($ocurrencias[$base] ?? 0) + 1);
        $huella = dcbHuellaOcurrencia($base, $n);
        if ($n > 1) $repetidos++;

        if (isset($existentes[$huella])) { $omitidas++; continue; }

        // Se guarda el numero de fila junto al payload para que, si el INSERT
        // falla, el error apunte a la linea del Excel que el usuario tiene
        // abierta y no a un dato del registro.
        $aInsertar[] = ['fila' => $mov['fila'], 'params' => [
            ':cuenta_id'      => $cuentaId,
            ':fecha'          => $mov['fecha'],
            ':fecha_valor'    => $mov['fecha_valor'],
            ':tipo'           => $mov['tipo'],
            ':medio'          => $mov['medio'],
            ':descripcion'    => $mov['descripcion'],
            ':referencia'     => $mov['referencia'],
            ':importe'        => $importeStr,
            ':saldo'          => $mov['saldo'],
            ':moneda'         => $moneda,
            ':contraparte'    => $mov['contraparte'],
            ':cuit'           => $mov['cuit'],
            ':importacion_id' => $importacionId,
            ':huella'         => $huella,
        ]];
    }

    if (!$aInsertar) {
        jsonOk([
            'modo'             => $modo,
            'insertados'       => 0,
            'omitidos'         => $omitidas,
            'descartados'      => $descartadas,
            'errores'          => $errores,
            'avisos'           => $avisos,
            'importacion_id'   => null,
            'saldo_actualizado' => null,
            'mensaje'          => $omitidas > 0
                ? 'No se agregó nada: todos los movimientos del archivo ya estaban importados.'
                : ($modo === 'interprete'
                    ? 'El intérprete no encontró movimientos para importar en este archivo.'
                    : 'No se encontró ningún movimiento válido con el mapeo indicado.'),
        ]);
    }

    $ins = $pdo->prepare(
        'INSERT INTO datacount_bancos_movimientos
            (cuenta_id, fecha, fecha_valor, tipo, medio, descripcion, referencia, importe,
             saldo, moneda, contraparte, cuit, importacion_id, huella, origen)
         VALUES
            (:cuenta_id, :fecha, :fecha_valor, :tipo, :medio, :descripcion, :referencia, :importe,
             :saldo, :moneda, :contraparte, :cuit, :importacion_id, :huella, \'importado\')'
    );

    $insertados = 0;
    $pdo->beginTransaction();
    try {
        foreach ($aInsertar as $item) {
            try {
                $ins->execute($item['params']);
                $insertados++;
            } catch (PDOException $e) {
                // 1062 aca solo puede pasar si otra importacion de la misma
                // cuenta corrio en paralelo entre el SELECT de huellas y este
                // INSERT. Cuenta como omitida, no como error.
                if (($e->errorInfo[1] ?? 0) === 1062) { $omitidas++; continue; }
                if (count($errores) < DCBI_MAX_ERRORES) {
                    $errores[] = ['fila' => $item['fila'], 'error' => $e->getMessage()];
                }
            }
        }
        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }

    // El saldo del extracto es mas confiable que el que tuviera cargado la
    // cuenta, asi que se pisa — pero solo si el archivo traia columna de saldo
    // y solo si la fecha es igual o posterior a la del saldo ya guardado (para
    // que importar un extracto viejo no retroceda el saldo actual).
    $saldoActualizado = null;
    if ($insertados > 0 && $ultimoSaldo !== null && $ultimaFecha !== null) {
        $up = $pdo->prepare(
            'UPDATE datacount_bancos_cuentas
                SET saldo = :s, saldo_fecha = :f
              WHERE id = :id AND (saldo_fecha IS NULL OR saldo_fecha <= :f2)'
        );
        $up->execute([':s' => $ultimoSaldo, ':f' => $ultimaFecha, ':id' => $cuentaId, ':f2' => $ultimaFecha]);
        if ($up->rowCount() > 0) {
            $saldoActualizado = ['saldo' => $ultimoSaldo, 'fecha' => $ultimaFecha];
        }
    }

    // Guardar el mapeo deja la cuenta configurada para la proxima importacion.
    // Solo aplica al camino manual: cuando lee un interprete no hay columnas
    // que recordar — el conocimiento del formato vive en el PHP del banco, que
    // es justamente el punto de tenerlo.
    if ($mapeoParaGuardar !== null && !empty($_POST['guardar_mapeo'])) {
        $pdo->prepare('UPDATE datacount_bancos_cuentas SET import_config = :c WHERE id = :id')
            ->execute([':c' => json_encode($mapeoParaGuardar, JSON_UNESCAPED_UNICODE), ':id' => $cuentaId]);
    }

    if ($repetidos > 0) {
        $avisos[] = "El extracto trae {$repetidos} movimiento(s) idéntico(s) a otro del mismo "
                  . 'día (misma fecha, importe, referencia y concepto). Se importaron todos: '
                  . 'en este banco suelen ser operaciones distintas, no duplicados.';
    }

    $via = $modo === 'interprete'
        ? 'intérprete ' . (string) ($_POST['interprete'] ?? '?')
        : 'mapeo manual';
    registrarSuceso($pdo, 'datacount_bancos_movimientos', 'info',
        "Importación en cuenta #{$cuentaId} \"{$cuenta['nombre']}\" vía {$via}: {$insertados} nuevos, "
        . "{$omitidas} ya existentes, {$descartadas} descartados (lote {$importacionId})");

    jsonOk([
        'modo'              => $modo,
        'insertados'        => $insertados,
        'omitidos'          => $omitidas,
        'descartados'       => $descartadas,
        'errores'           => $errores,
        'avisos'            => $avisos,
        'importacion_id'    => $importacionId,
        'saldo_actualizado' => $saldoActualizado,
        'mensaje'           => "Se importaron {$insertados} movimiento/s.",
    ]);
}

/**
 * Resuelve tipo + importe de una fila segun el mapeo.
 * Devuelve [tipo, importe] con importe siempre positivo, o null si la fila no
 * tiene importe interpretable.
 */
function dcbiImporteDeFila(array $fila, array $m): ?array {
    // Modo A: columnas separadas de debito y credito. Es el formato mas comun
    // en extractos bancarios y el menos ambiguo — el signo lo da la columna.
    if ($m['col_debito'] !== null || $m['col_credito'] !== null) {
        $deb = $m['col_debito']  !== null ? planillaNumero((string) ($fila[$m['col_debito']]  ?? ''), $m['decimal']) : null;
        $cre = $m['col_credito'] !== null ? planillaNumero((string) ($fila[$m['col_credito']] ?? ''), $m['decimal']) : null;

        $deb = ($deb !== null && abs($deb) > 0) ? abs($deb) : null;
        $cre = ($cre !== null && abs($cre) > 0) ? abs($cre) : null;

        if ($cre !== null && $deb === null) return ['ingreso', round($cre, 2)];
        if ($deb !== null && $cre === null) return ['egreso',  round($deb, 2)];
        // Ambas con valor (o ninguna): la fila no es un movimiento simple.
        return null;
    }

    // Modo B: una sola columna firmada. El signo decide el tipo.
    $n = planillaNumero((string) ($fila[$m['col_importe']] ?? ''), $m['decimal']);
    if ($n === null) return null;
    if ($m['invertir_signo']) $n = -$n;
    if ($n === 0.0) return null;
    return $n > 0 ? ['ingreso', round($n, 2)] : ['egreso', round(abs($n), 2)];
}

function dcbiCelda(array $fila, ?int $idx, int $max): ?string {
    if ($idx === null) return null;
    $s = trim((string) ($fila[$idx] ?? ''));
    if ($s === '') return null;
    return mb_strlen($s) > $max ? mb_substr($s, 0, $max) : $s;
}
