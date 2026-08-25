<?php
// api/lib/planilla.php
// Lector de planillas (CSV / TSV / XLSX) para el importador de extractos de
// Datacount > Bancos. Devuelve siempre una matriz de strings — la
// interpretacion de cada columna (que es fecha, que es importe) la hace el
// mapeo por cuenta, no este archivo.
//
// POR QUE UN LECTOR PROPIO
// ------------------------
// El contenedor `databox` no tiene ext-zip (ver docker/Dockerfile: solo se
// compilan pdo_mysql, pcntl y soap) y el proyecto no usa composer, asi que no
// hay PhpSpreadsheet ni ZipArchive. Un .xlsx es un ZIP de XMLs, y el ZIP usa
// deflate crudo — que zlib SI expone via gzinflate(). Con eso alcanza para
// leerlo entero en PHP puro y sin rebuild del contenedor.
//
// Si algun dia se agrega ext-zip al Dockerfile, leerXlsx() puede reemplazarse
// por ZipArchive sin tocar el resto: la unica salida publica es planillaLeer().

// Limites defensivos: un extracto mensual real no pasa de unos miles de filas.
// Estan para que un archivo corrupto o malicioso no se coma la memoria del
// worker de Apache antes de que PHP corte por max_execution_time.
const PLANILLA_MAX_FILAS    = 20000;
const PLANILLA_MAX_COLUMNAS = 100;
const PLANILLA_MAX_BYTES    = 20 * 1024 * 1024;   // 20 MB

// ----------------------------------------------------------------------------
// API publica
// ----------------------------------------------------------------------------

/**
 * Lee una planilla y devuelve sus filas como matriz de strings.
 *
 * @return array{filas: array<int, array<int, string>>, formato: string}
 * @throws RuntimeException si el archivo no se puede interpretar.
 */
function planillaLeer(string $ruta, string $nombreOriginal): array {
    if (!is_readable($ruta)) {
        throw new RuntimeException('No se pudo leer el archivo subido.');
    }
    $bytes = filesize($ruta);
    if ($bytes === false || $bytes === 0) {
        throw new RuntimeException('El archivo esta vacio.');
    }
    if ($bytes > PLANILLA_MAX_BYTES) {
        throw new RuntimeException('El archivo supera los 20 MB.');
    }

    $ext = strtolower(pathinfo($nombreOriginal, PATHINFO_EXTENSION));
    $raw = (string) file_get_contents($ruta);

    // La extension orienta, pero manda la firma: un .csv renombrado a .xlsx (o
    // al reves) es un error de usuario habitual y no vale la pena rebotarlo.
    $esZip = str_starts_with($raw, "PK\x03\x04");

    if ($esZip) {
        return ['filas' => planillaLeerXlsx($raw), 'formato' => 'xlsx'];
    }
    if ($ext === 'xlsx' || $ext === 'xls') {
        // .xls binario (BIFF, Excel 97-2003) no es ZIP y no lo soportamos.
        throw new RuntimeException(
            'El archivo no es un .xlsx valido. Si es un .xls viejo de Excel, '
            . 'abrilo y guardalo como .xlsx o .csv.'
        );
    }
    return ['filas' => planillaLeerCsv($raw), 'formato' => 'csv'];
}

/**
 * Huella de deduplicacion de un movimiento.
 *
 * Es lo que hace idempotente al importador: reimportar el mismo archivo, o un
 * extracto que se solapa con el mes anterior, choca contra el UNIQUE
 * (cuenta_id, huella) y las filas repetidas se cuentan como omitidas.
 *
 * La descripcion se normaliza (minusculas, sin acentos, espacios colapsados)
 * porque algunos bancos cambian el espaciado o el case del mismo concepto
 * entre exportaciones y si no la misma operacion entraria dos veces.
 *
 * NO entra `saldo` a proposito: si el extracto se reexporta despues de un
 * ajuste retroactivo, el saldo de una fila vieja puede cambiar sin que el
 * movimiento sea otro.
 */
function planillaHuella(string $fecha, string $tipo, string $importe, ?string $referencia, ?string $descripcion): string {
    $desc = planillaNormalizarTexto((string) $descripcion);
    $ref  = trim((string) $referencia);
    return hash('sha256', implode('|', [$fecha, $tipo, $importe, $ref, $desc]));
}

/**
 * Normaliza texto para comparaciones: minusculas, sin acentos, sin espacios
 * repetidos. Los contenedores no tienen ext-intl (ver la nota de
 * `feedback_busquedas_insensibles`), asi que el plegado se hace a mano con una
 * tabla de reemplazo en vez de Normalizer/transliterator.
 */
function planillaNormalizarTexto(string $s): string {
    $s = mb_strtolower(trim($s), 'UTF-8');
    $s = strtr($s, [
        'á' => 'a', 'é' => 'e', 'í' => 'i', 'ó' => 'o', 'ú' => 'u', 'ü' => 'u', 'ñ' => 'n',
        'à' => 'a', 'è' => 'e', 'ì' => 'i', 'ò' => 'o', 'ù' => 'u',
        'â' => 'a', 'ê' => 'e', 'î' => 'i', 'ô' => 'o', 'û' => 'u',
        'ä' => 'a', 'ë' => 'e', 'ï' => 'i', 'ö' => 'o',
    ]);
    return (string) preg_replace('/\s+/u', ' ', $s);
}

/**
 * Convierte el texto de una celda a decimal con 2 posiciones.
 *
 * Maneja los formatos que exportan los bancos argentinos:
 *   "1.234,56"  (miles '.', decimal ',')  -> 1234.56
 *   "1,234.56"  (miles ',', decimal '.')  -> 1234.56
 *   "$ 1.234,56"                          -> 1234.56
 *   "(1.234,56)" contabilidad, negativo   -> -1234.56
 *   "1.234,56-"  signo al final            -> -1234.56
 *
 * `$decimal` fuerza el separador cuando el mapeo de la cuenta lo declara
 * ('coma' | 'punto'); en 'auto' se infiere del ultimo separador que aparezca,
 * que es la heuristica correcta salvo para miles sin decimales ("1.234"), caso
 * en que se trata como entero.
 *
 * Devuelve null si la celda no contiene ningun digito (celda vacia, guion,
 * encabezado repetido en el medio del archivo).
 */
function planillaNumero(string $raw, string $decimal = 'auto'): ?float {
    $s = trim($raw);
    if ($s === '') return null;

    $negativo = false;

    // Notacion contable: (1.234,56) es negativo.
    if (preg_match('/^\((.*)\)$/', $s, $m)) {
        $negativo = true;
        $s = $m[1];
    }
    // Signo al final (algunos exports de core bancario).
    if (str_ends_with($s, '-')) {
        $negativo = true;
        $s = rtrim($s, '-');
    }
    if (str_starts_with($s, '-')) {
        $negativo = true;
        $s = ltrim($s, '-');
    }

    // Fuera todo lo que no sea digito o separador (simbolo de moneda, espacios,
    // NBSP, codigo ISO pegado tipo "ARS 1.234,56").
    $s = (string) preg_replace('/[^0-9.,]/u', '', $s);
    if ($s === '' || !preg_match('/[0-9]/', $s)) return null;

    $ultimaComa  = strrpos($s, ',');
    $ultimoPunto = strrpos($s, '.');

    if ($decimal === 'coma') {
        $s = str_replace('.', '', $s);
        $s = str_replace(',', '.', $s);
    } elseif ($decimal === 'punto') {
        $s = str_replace(',', '', $s);
    } else {
        // auto: el separador que aparece mas a la derecha es el decimal, pero
        // solo si le siguen 1 o 2 digitos. "1.234" son miles, no 1 con 234.
        if ($ultimaComa !== false && ($ultimoPunto === false || $ultimaComa > $ultimoPunto)) {
            $cola = substr($s, $ultimaComa + 1);
            if (preg_match('/^[0-9]{1,2}$/', $cola)) {
                // Coma decimal. Se saca primero el punto de miles y recien
                // despues se convierte la coma: hacerlo por indice (el
                // $ultimaComa calculado arriba) romperia, porque quitar los
                // puntos corre todas las posiciones a la izquierda.
                $s = str_replace('.', '', $s);
                $s = str_replace(',', '.', $s);
            } else {
                $s = str_replace([',', '.'], '', $s);
            }
        } elseif ($ultimoPunto !== false) {
            $cola = substr($s, $ultimoPunto + 1);
            if (preg_match('/^[0-9]{1,2}$/', $cola)) {
                $s = str_replace(',', '', $s);
            } else {
                $s = str_replace([',', '.'], '', $s);
            }
        }
    }

    if (!is_numeric($s)) return null;
    $n = (float) $s;
    return $negativo ? -$n : $n;
}

/**
 * Convierte el texto de una celda a 'YYYY-MM-DD', o null si no parsea.
 *
 * `$formato` viene del mapeo de la cuenta: 'dmy' | 'mdy' | 'ymd' | 'auto'.
 * El default es 'dmy' porque es lo que exportan los bancos argentinos; 'auto'
 * prueba ISO primero (inequivoco) y despues cae a dmy.
 *
 * Los .xlsx suelen traer la fecha como serial de Excel (numero de dias desde
 * 1899-12-30). Se detecta por rango: 20000 = 1954, 80000 = 2119. Fuera de esa
 * ventana un numero suelto casi seguro no es una fecha.
 */
function planillaFecha(string $raw, string $formato = 'dmy'): ?string {
    $s = trim($raw);
    if ($s === '') return null;

    // Serial de Excel.
    if (preg_match('/^[0-9]+(\.[0-9]+)?$/', $s)) {
        $n = (float) $s;
        if ($n >= 20000 && $n <= 80000) {
            $ts = (int) floor(($n - 25569) * 86400);   // 25569 = 1970-01-01
            return gmdate('Y-m-d', $ts);
        }
    }

    // Descartar la hora si la celda trae fecha+hora.
    $s = (string) preg_replace('/[T ][0-9]{1,2}:[0-9]{2}(:[0-9]{2})?.*$/', '', $s);

    // ISO es inequivoco — se intenta siempre primero.
    if (preg_match('/^([0-9]{4})[-\/]([0-9]{1,2})[-\/]([0-9]{1,2})$/', $s, $m)) {
        return planillaArmarFecha((int) $m[1], (int) $m[2], (int) $m[3]);
    }

    if (preg_match('/^([0-9]{1,2})[-\/.]([0-9]{1,2})[-\/.]([0-9]{2,4})$/', $s, $m)) {
        $a = (int) $m[1];
        $b = (int) $m[2];
        $anio = (int) $m[3];
        if ($anio < 100) $anio += ($anio <= 69 ? 2000 : 1900);

        if ($formato === 'mdy') return planillaArmarFecha($anio, $a, $b);
        if ($formato === 'dmy') return planillaArmarFecha($anio, $b, $a);
        // auto: si el primer numero no puede ser mes, es dia.
        if ($a > 12) return planillaArmarFecha($anio, $b, $a);
        if ($b > 12) return planillaArmarFecha($anio, $a, $b);
        return planillaArmarFecha($anio, $b, $a);   // empate -> dmy
    }

    // "15-ene-2026" / "15 Ene 26" — algunos resumenes lo usan.
    $meses = [
        'ene' => 1, 'jan' => 1, 'feb' => 2, 'mar' => 3, 'abr' => 4, 'apr' => 4,
        'may' => 5, 'jun' => 6, 'jul' => 7, 'ago' => 8, 'aug' => 8, 'sep' => 9,
        'set' => 9, 'oct' => 10, 'nov' => 11, 'dic' => 12, 'dec' => 12,
    ];
    if (preg_match('/^([0-9]{1,2})[-\/ ]([a-zA-ZáéíóúÁÉÍÓÚ]{3,})[-\/ ]([0-9]{2,4})$/u', $s, $m)) {
        $mes = $meses[substr(planillaNormalizarTexto($m[2]), 0, 3)] ?? null;
        if ($mes !== null) {
            $anio = (int) $m[3];
            if ($anio < 100) $anio += ($anio <= 69 ? 2000 : 1900);
            return planillaArmarFecha($anio, $mes, (int) $m[1]);
        }
    }

    return null;
}

function planillaArmarFecha(int $anio, int $mes, int $dia): ?string {
    if ($mes < 1 || $mes > 12 || $dia < 1 || $dia > 31) return null;
    if (!checkdate($mes, $dia, $anio)) return null;
    return sprintf('%04d-%02d-%02d', $anio, $mes, $dia);
}

// ----------------------------------------------------------------------------
// CSV / TSV
// ----------------------------------------------------------------------------

function planillaLeerCsv(string $raw): array {
    // BOM UTF-8: Excel lo agrega al "Guardar como CSV UTF-8" y contamina el
    // nombre de la primera columna si no se saca.
    if (str_starts_with($raw, "\xEF\xBB\xBF")) $raw = substr($raw, 3);

    // Los homebanking argentinos exportan mayormente en Latin-1. Si el archivo
    // no es UTF-8 valido lo convertimos, si no los acentos entran rotos a la BD.
    if (!mb_check_encoding($raw, 'UTF-8')) {
        $raw = mb_convert_encoding($raw, 'UTF-8', 'ISO-8859-1');
    }

    $delim = planillaDetectarDelimitador($raw);

    // SplTempFileObject en vez de explode("\n") para que fgetcsv respete los
    // saltos de linea dentro de campos entrecomillados (las descripciones de
    // transferencias los traen).
    $tmp = new SplTempFileObject(-1);
    $tmp->fwrite($raw);
    $tmp->rewind();
    $tmp->setCsvControl($delim, '"', '\\');
    $tmp->setFlags(SplFileObject::READ_CSV | SplFileObject::SKIP_EMPTY | SplFileObject::READ_AHEAD);

    $filas = [];
    foreach ($tmp as $fila) {
        if ($fila === false || $fila === [null]) continue;
        if (count($filas) >= PLANILLA_MAX_FILAS) break;
        $filas[] = array_map(
            fn($c) => trim((string) $c),
            array_slice($fila, 0, PLANILLA_MAX_COLUMNAS)
        );
    }

    if (!$filas) throw new RuntimeException('El CSV no tiene filas legibles.');
    return $filas;
}

// Elige el delimitador contando ocurrencias en las primeras lineas no vacias.
// Se miran varias lineas y no solo el header porque hay bancos que ponen un
// titulo suelto arriba ("Movimientos de la cuenta 123") sin separadores.
function planillaDetectarDelimitador(string $raw): string {
    $lineas = preg_split('/\r\n|\r|\n/', $raw) ?: [];
    $conteo = [';' => 0, ',' => 0, "\t" => 0, '|' => 0];
    $vistas = 0;
    foreach ($lineas as $l) {
        if (trim($l) === '') continue;
        foreach ($conteo as $d => $_) {
            $conteo[$d] += substr_count($l, $d);
        }
        if (++$vistas >= 10) break;
    }
    arsort($conteo);
    $mejor = array_key_first($conteo);
    return $conteo[$mejor] > 0 ? (string) $mejor : ',';
}

// ----------------------------------------------------------------------------
// XLSX
// ----------------------------------------------------------------------------

function planillaLeerXlsx(string $raw): array {
    $zip = planillaZipLeer($raw);

    $sheetXml = planillaXlsxPrimeraHoja($zip);
    if ($sheetXml === null) {
        throw new RuntimeException('El .xlsx no tiene hojas legibles.');
    }

    $compartidas = planillaXlsxSharedStrings($zip);

    $prev = libxml_use_internal_errors(true);
    $xml  = simplexml_load_string($sheetXml);
    libxml_use_internal_errors($prev);
    if ($xml === false) {
        throw new RuntimeException('No se pudo interpretar la hoja del .xlsx.');
    }

    $filas    = [];
    $maxCol   = 0;
    $sheetData = $xml->sheetData ?? null;
    if ($sheetData === null) return [];

    foreach ($sheetData->row as $row) {
        if (count($filas) >= PLANILLA_MAX_FILAS) break;

        $celdas = [];
        foreach ($row->c as $c) {
            $ref  = (string) ($c['r'] ?? '');
            $col  = $ref !== '' ? planillaXlsxColIndice($ref) : count($celdas);
            if ($col < 0 || $col >= PLANILLA_MAX_COLUMNAS) continue;

            $tipo  = (string) ($c['t'] ?? '');
            $valor = '';

            if ($tipo === 's') {
                // Shared string: <v> es el indice en sharedStrings.xml.
                $idx   = (int) ((string) ($c->v ?? '-1'));
                $valor = $compartidas[$idx] ?? '';
            } elseif ($tipo === 'inlineStr') {
                // Texto embebido en la celda (lo usan varios exportadores web).
                $valor = planillaXlsxTextoDe($c->is ?? null);
            } elseif ($tipo === 'str') {
                // Resultado de formula ya evaluado.
                $valor = (string) ($c->v ?? '');
            } elseif (isset($c->v)) {
                $valor = (string) $c->v;
            }

            $celdas[$col] = trim($valor);
            if ($col > $maxCol) $maxCol = $col;
        }

        if (!$celdas) { $filas[] = []; continue; }

        // Las celdas vienen sparse (Excel omite las vacias): rellenar los
        // huecos para que los indices de columna del mapeo sean estables.
        $plana = [];
        for ($i = 0, $n = max(array_keys($celdas)) + 1; $i < $n; $i++) {
            $plana[] = $celdas[$i] ?? '';
        }
        $filas[] = $plana;
    }

    // Normalizar el ancho de todas las filas al maximo real de la hoja.
    $ancho = $maxCol + 1;
    foreach ($filas as &$f) {
        for ($i = count($f); $i < $ancho; $i++) $f[] = '';
    }
    unset($f);

    // Descartar las filas totalmente vacias del final (Excel arrastra cientos).
    while ($filas && trim(implode('', end($filas))) === '') array_pop($filas);

    if (!$filas) throw new RuntimeException('El .xlsx no tiene filas con datos.');
    return $filas;
}

// "BC12" -> 54 (indice 0-based de la columna).
function planillaXlsxColIndice(string $ref): int {
    if (!preg_match('/^([A-Za-z]+)/', $ref, $m)) return -1;
    $letras = strtoupper($m[1]);
    $n = 0;
    for ($i = 0, $len = strlen($letras); $i < $len; $i++) {
        $n = $n * 26 + (ord($letras[$i]) - 64);
    }
    return $n - 1;
}

// Concatena los <t> de un nodo <si>/<is>, incluidos los de runs con formato
// (<r><t>Parte</t></r><r><t>2</t></r>), que Excel genera al mezclar estilos
// dentro de una misma celda.
function planillaXlsxTextoDe(?SimpleXMLElement $nodo): string {
    if ($nodo === null) return '';
    $out = '';
    foreach ($nodo->t as $t) $out .= (string) $t;
    foreach ($nodo->r as $r) {
        foreach ($r->t as $t) $out .= (string) $t;
    }
    return $out;
}

function planillaXlsxSharedStrings(array $zip): array {
    $xmlRaw = $zip['xl/sharedStrings.xml'] ?? null;
    if ($xmlRaw === null) return [];

    $prev = libxml_use_internal_errors(true);
    $xml  = simplexml_load_string($xmlRaw);
    libxml_use_internal_errors($prev);
    if ($xml === false) return [];

    $out = [];
    foreach ($xml->si as $si) $out[] = planillaXlsxTextoDe($si);
    return $out;
}

// Devuelve el XML de la primera hoja del libro. Se resuelve por workbook.xml +
// sus rels (y no asumiendo 'sheet1.xml') porque el orden de los archivos no
// tiene por que coincidir con el orden de las pestañas: la "primera hoja" del
// usuario puede ser sheet3.xml.
function planillaXlsxPrimeraHoja(array $zip): ?string {
    $wb = $zip['xl/workbook.xml'] ?? null;
    if ($wb !== null) {
        $prev = libxml_use_internal_errors(true);
        $xml  = simplexml_load_string($wb);
        libxml_use_internal_errors($prev);

        if ($xml !== false && isset($xml->sheets->sheet[0])) {
            $rid = '';
            foreach ($xml->sheets->sheet[0]->attributes('r', true) as $k => $v) {
                if ((string) $k === 'id') $rid = (string) $v;
            }
            $rels = $zip['xl/_rels/workbook.xml.rels'] ?? null;
            if ($rid !== '' && $rels !== null) {
                $prev = libxml_use_internal_errors(true);
                $rx   = simplexml_load_string($rels);
                libxml_use_internal_errors($prev);
                if ($rx !== false) {
                    foreach ($rx->Relationship as $rel) {
                        if ((string) $rel['Id'] !== $rid) continue;
                        $target = ltrim((string) $rel['Target'], '/');
                        if (!str_starts_with($target, 'xl/')) $target = 'xl/' . $target;
                        if (isset($zip[$target])) return $zip[$target];
                    }
                }
            }
        }
    }

    // Fallback: primera worksheet que aparezca en el ZIP.
    foreach ($zip as $nombre => $contenido) {
        if (str_starts_with($nombre, 'xl/worksheets/') && str_ends_with($nombre, '.xml')) {
            return $contenido;
        }
    }
    return null;
}

// ----------------------------------------------------------------------------
// ZIP minimo (solo lectura, solo lo que necesita un .xlsx)
// ----------------------------------------------------------------------------
//
// Recorre el Central Directory y descomprime cada entrada. Soporta los dos
// metodos que usa Excel: 0 (stored) y 8 (deflate). Cualquier otro se omite en
// silencio — si la entrada omitida era una que hacia falta, el llamador falla
// despues con un mensaje entendible.
//
// No soporta ZIP64 ni entradas cifradas. Un .xlsx llega a ZIP64 recien pasadas
// las 65535 entradas o los 4 GB, muy lejos de cualquier extracto bancario.

function planillaZipLeer(string $raw): array {
    $len = strlen($raw);

    // EOCD: firma PK\x05\x06. Se busca desde el final porque puede haber
    // comentario despues (hasta 64 KB).
    $eocd = -1;
    $desde = max(0, $len - 65557);
    for ($i = $len - 22; $i >= $desde; $i--) {
        if (substr($raw, $i, 4) === "PK\x05\x06") { $eocd = $i; break; }
    }
    if ($eocd < 0) throw new RuntimeException('El archivo no es un .xlsx valido (ZIP sin directorio).');

    $cd     = unpack('vdisco/vdiscoCd/ventradasDisco/ventradas/Vtam/Voffset', substr($raw, $eocd + 4, 16));
    $cursor = $cd['offset'];
    $total  = $cd['entradas'];

    $out = [];
    for ($n = 0; $n < $total; $n++) {
        if ($cursor + 46 > $len || substr($raw, $cursor, 4) !== "PK\x01\x02") break;

        $h = unpack(
            'vversion/vversionNec/vflags/vmetodo/vhora/vfecha/Vcrc/Vcomp/VsinComp'
            . '/vlenNombre/vlenExtra/vlenComentario/vdisco/vattrInt/VattrExt/Vlocal',
            substr($raw, $cursor + 4, 42)
        );
        $nombre = substr($raw, $cursor + 46, $h['lenNombre']);
        $cursor += 46 + $h['lenNombre'] + $h['lenExtra'] + $h['lenComentario'];

        // El spec ZIP manda '/' como separador y Excel lo respeta, pero
        // .NET Framework (ZipFile.CreateFromDirectory) y algunas libs Java
        // escriben '\'. Normalizamos para que las rutas del OPC ('xl/...')
        // resuelvan igual vengan de donde vengan.
        $nombre = str_replace('\\', '/', $nombre);

        // Solo nos interesan los XML del libro; saltear el resto (thumbnails,
        // media, printerSettings) ahorra descomprimir de mas.
        if (!str_ends_with($nombre, '.xml') && !str_ends_with($nombre, '.rels')) continue;

        $lh = $h['local'];
        if ($lh + 30 > $len || substr($raw, $lh, 4) !== "PK\x03\x04") continue;
        $lhd  = unpack('vlenNombre/vlenExtra', substr($raw, $lh + 26, 4));
        $dato = $lh + 30 + $lhd['lenNombre'] + $lhd['lenExtra'];

        $comprimido = substr($raw, $dato, $h['comp']);
        if ($h['metodo'] === 0) {
            $out[$nombre] = $comprimido;
        } elseif ($h['metodo'] === 8) {
            $plano = @gzinflate($comprimido);
            if ($plano !== false) $out[$nombre] = $plano;
        }
    }

    if (!$out) throw new RuntimeException('El .xlsx esta vacio o comprimido en un formato no soportado.');
    return $out;
}
