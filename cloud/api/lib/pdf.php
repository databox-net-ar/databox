<?php
// api/lib/pdf.php
// Extractor de texto tabular de PDFs, en PHP puro y con coordenadas.
//
// POR QUE UN EXTRACTOR PROPIO
// ---------------------------
// Mismo motivo que el lector de .xlsx de planilla.php: el contenedor `databox`
// no tiene composer ni extensiones de mas (ver docker/Dockerfile: solo
// pdo_mysql, pcntl y soap), asi que no hay smalot/pdfparser; y tampoco hay
// poppler-utils, asi que no se puede delegar en `pdftotext`. Los streams de un
// PDF estan comprimidos con deflate, que zlib SI expone via gzuncompress(), y
// con eso alcanza para leerlo entero sin rebuild del contenedor.
//
// POR QUE CON COORDENADAS Y NO TEXTO PLANO
// ----------------------------------------
// Un PDF no guarda filas ni columnas: guarda glifos con su posicion. El orden
// en que estan escritos en el content stream NO es el orden de lectura. El
// resumen de Brubank es el caso extremo: dibuja la tabla POR COLUMNA (primero
// las 22 fechas, despues las 22 referencias, despues las descripciones, y
// recien al final los importes), asi que cualquier lector que respete el orden
// del stream devuelve las columnas apiladas y no las filas. `pdftotext` sin
// -layout produce exactamente esa basura, y con -layout la produce igual
// porque ademas colapsa las celdas vacias y desalinea el resto.
//
// La unica lectura confiable es geometrica: se calcula la posicion final de
// cada glifo (matriz de texto x matriz del CTM), se agrupan por coordenada Y
// para formar las filas y dentro de cada fila se corta en celdas por los
// huecos horizontales. Eso reconstruye la tabla tal como se ve impresa, que es
// lo que el usuario cree estar subiendo.
//
// ALCANCE
// -------
// Cubre lo que necesita un resumen bancario y nada mas: PDFs sin cifrar, con
// streams sin filtro o con FlateDecode, y fuentes con /ToUnicode (que es lo que
// emite cualquier generador moderno — wkhtmltopdf, Chrome, iText, ReportLab).
// NO cubre: PDFs escaneados (son imagenes, harian falta OCR), cifrados, ni
// fuentes sin ToUnicode con encoding propietario.
//
// La salida es la MISMA forma que planillaLeer(): una matriz de strings. De ahi
// para arriba (interpretes, mapeo, importador) nadie sabe que el origen fue un
// PDF.

// Limites defensivos: un resumen bancario real no pasa de unas decenas de
// paginas. Estan para que un PDF corrupto o malicioso no se coma la memoria del
// worker de Apache antes de que PHP corte por max_execution_time.
const PDF_MAX_PAGINAS = 300;
const PDF_MAX_GLIFOS  = 400000;
const PDF_MAX_OBJETOS = 50000;

// Ancho medio de un glifo como fraccion del cuerpo de la fuente. Se usa para
// estimar donde TERMINA un texto y asi medir el hueco hasta el siguiente.
//
// No se leen los anchos reales (/Widths, /W): habria que resolver la fuente
// descendiente de cada Type0 y su formato de anchos, que es mucho parseo para
// una cifra que solo decide cortes de celda. 0.6 em es el promedio de Arial /
// Helvetica y esta elegido a proposito por el lado ANCHO: sobreestimar el
// ancho junta celdas (peor caso: dos columnas pegadas, que el interprete nota),
// subestimarlo las parte al medio (peor caso: una descripcion rota en 3
// columnas fantasma, que corre los indices de TODAS las columnas siguientes).
const PDF_ANCHO_GLIFO = 0.6;

// Hueco horizontal, en múltiplos del cuerpo de la fuente, a partir del cual se
// considera que empieza otra celda. Un espacio entre palabras mide ~0.3 em y la
// separacion entre columnas de una tabla arranca en ~1.5 em, asi que 1.2 corta
// limpio en el medio de esos dos mundos.
const PDF_HUECO_CELDA = 1.2;

// Tolerancia vertical para considerar que dos glifos estan en la misma fila,
// en multiplos del cuerpo. Los subindices y el kerning vertical mueven la linea
// base unas decimas; 0.5 em las absorbe sin fusionar dos renglones seguidos.
const PDF_TOLERANCIA_FILA = 0.5;

/**
 * Lee un PDF y devuelve su texto como matriz de filas/celdas.
 *
 * @return array<int, array<int, string>>
 * @throws RuntimeException si el PDF no se puede interpretar.
 */
function pdfLeerFilas(string $raw): array {
    if (!str_starts_with($raw, '%PDF-')) {
        throw new RuntimeException('El archivo no es un PDF válido.');
    }

    $objetos = pdfIndexarObjetos($raw);
    if (!$objetos) {
        throw new RuntimeException('No se pudo leer la estructura del PDF.');
    }

    // Un PDF cifrado desencripta cada stream con una clave derivada de la
    // contraseña; no lo soportamos. Se detecta por el /Encrypt del trailer y no
    // por "aparece la palabra en el archivo": un stream comprimido puede
    // contener esos bytes por casualidad.
    if (preg_match('/trailer.*?\/Encrypt\b/s', $raw)
        || preg_match('/\/Type\s*\/XRef.*?\/Encrypt\b/s', $raw)) {
        throw new RuntimeException(
            'El PDF está protegido con contraseña. Quitale la protección '
            . '(abrilo e imprimilo a PDF) y volvé a subirlo.'
        );
    }

    $paginas = pdfOrdenDePaginas($raw, $objetos);
    if (!$paginas) {
        throw new RuntimeException('El PDF no tiene páginas legibles.');
    }

    $filas   = [];
    $glifos  = 0;
    $conTexto = 0;

    foreach ($paginas as $numPagina => $pagina) {
        if ($numPagina >= PDF_MAX_PAGINAS) break;

        $fuentes   = pdfFuentesDePagina($pagina, $objetos);
        $contenido = pdfContenidoDePagina($pagina, $objetos);
        if ($contenido === '') continue;

        $runs = pdfCorridasDeTexto($contenido, $fuentes, $glifos);
        if (!$runs) continue;
        $conTexto++;

        foreach (pdfArmarFilas($runs) as $fila) {
            $filas[] = $fila;
        }
    }

    if (!$filas) {
        throw new RuntimeException(
            $conTexto === 0
                ? 'El PDF no tiene texto seleccionable: probablemente sea un escaneo o una '
                . 'foto. Pedile al banco el resumen en PDF digital, o subí el CSV/XLSX.'
                : 'No se pudo extraer texto del PDF.'
        );
    }
    return $filas;
}

// ----------------------------------------------------------------------------
// Estructura del PDF
// ----------------------------------------------------------------------------

/**
 * Indice `numero de objeto => cuerpo` recorriendo el archivo entero.
 *
 * Se barre a fuerza bruta en vez de seguir la tabla xref a proposito: la xref
 * se rompe apenas alguien toca el archivo con una herramienta que no la
 * reescribe bien (y los homebanking generan PDFs con toda clase de utilitarios),
 * mientras que los `N 0 obj ... endobj` siguen ahi. Como ademas nos da igual el
 * orden, no hay nada que ganar leyendo la xref.
 *
 * Si un objeto esta definido dos veces (actualizaciones incrementales), gana la
 * ultima definicion, que es justamente la vigente.
 *
 * @return array<int, string>
 */
function pdfIndexarObjetos(string $raw): array {
    $out = [];
    $n   = preg_match_all('/(\d+)\s+(\d+)\s+obj\b(.*?)endobj/s', $raw, $m, PREG_SET_ORDER);
    if (!$n) return $out;

    foreach ($m as $i => $g) {
        if ($i >= PDF_MAX_OBJETOS) break;
        $out[(int) $g[1]] = $g[3];
    }
    return $out;
}

/** Cuerpo de un objeto, resolviendo `12 0 R` si `$ref` es una referencia. */
function pdfResolver(string $ref, array $objetos): ?string {
    if (preg_match('/^\s*(\d+)\s+\d+\s+R\s*$/', $ref, $m)) {
        return $objetos[(int) $m[1]] ?? null;
    }
    return $ref;
}

/**
 * Contenido descomprimido del stream de un objeto.
 *
 * `/Length` puede ser una referencia indirecta, asi que no se puede confiar en
 * el numero para cortar: se busca `endstream` y se recorta ahi.
 */
function pdfStream(string $cuerpo, array $objetos): string {
    $i = strpos($cuerpo, 'stream');
    if ($i === false) return '';
    $j = strrpos($cuerpo, 'endstream');
    if ($j === false || $j <= $i) return '';

    $inicio = $i + 6;
    // Tras 'stream' va CRLF o LF (nunca CR solo, segun el spec).
    if (substr($cuerpo, $inicio, 2) === "\r\n")      $inicio += 2;
    elseif (substr($cuerpo, $inicio, 1) === "\n")    $inicio += 1;
    elseif (substr($cuerpo, $inicio, 1) === "\r")    $inicio += 1;

    $datos = substr($cuerpo, $inicio, $j - $inicio);
    $cab   = substr($cuerpo, 0, $i);

    if (!preg_match('/\/Filter\s*(\/\w+|\[[^\]]*\])/', $cab, $mf)) {
        return $datos;   // sin filtro: texto plano
    }
    $filtro = $mf[1];

    // Solo FlateDecode. LZW/RunLength/ASCII85 no los emite ningun generador
    // actual para contenido de pagina; si aparecen, la pagina se saltea y el
    // llamador termina avisando que no se pudo extraer texto.
    if (!str_contains($filtro, 'FlateDecode')) return '';

    $plano = @gzuncompress($datos);
    if ($plano === false) $plano = @gzinflate($datos);        // sin cabecera zlib
    if ($plano === false) $plano = @gzinflate(substr($datos, 1));
    return $plano === false ? '' : $plano;
}

/**
 * Cuerpos de las paginas EN ORDEN de lectura.
 *
 * Se sigue el arbol /Pages -> /Kids porque es el unico lugar donde el orden es
 * explicito: el orden de aparicion de los objetos en el archivo no tiene por
 * que coincidir (y en los PDFs con actualizaciones incrementales no coincide).
 * Si el arbol no se puede seguir, se cae al barrido por /Type /Page.
 *
 * @return array<int, string>
 */
function pdfOrdenDePaginas(string $raw, array $objetos): array {
    $orden = [];

    // El catalogo dice cual es el nodo raiz del arbol de paginas.
    $raiz = null;
    foreach ($objetos as $cuerpo) {
        if (preg_match('/\/Type\s*\/Catalog/', $cuerpo)
            && preg_match('/\/Pages\s+(\d+)\s+\d+\s+R/', $cuerpo, $m)) {
            $raiz = (int) $m[1];
            break;
        }
    }

    if ($raiz !== null) {
        $vistos = [];
        pdfRecorrerPaginas($raiz, $objetos, $orden, $vistos);
    }

    if (!$orden) {
        // Fallback: cualquier objeto que se declare pagina, en orden de numero.
        $nums = [];
        foreach ($objetos as $num => $cuerpo) {
            if (preg_match('/\/Type\s*\/Page\b/', $cuerpo)) $nums[] = $num;
        }
        sort($nums);
        foreach ($nums as $num) $orden[] = $objetos[$num];
    }
    return $orden;
}

/** Recorre el arbol de paginas en profundidad, acumulando las hojas en orden. */
function pdfRecorrerPaginas(int $num, array $objetos, array &$orden, array &$vistos): void {
    // Un /Kids que se apunte a si mismo (PDF corrupto o malicioso) colgaria la
    // recursion; el set de vistos la corta.
    if (isset($vistos[$num]) || count($orden) >= PDF_MAX_PAGINAS) return;
    $vistos[$num] = true;

    $cuerpo = $objetos[$num] ?? null;
    if ($cuerpo === null) return;

    if (preg_match('/\/Type\s*\/Page\b(?!s)/', $cuerpo)) {
        $orden[] = $cuerpo;
        return;
    }
    if (!preg_match('/\/Kids\s*\[(.*?)\]/s', $cuerpo, $m)) return;

    if (preg_match_all('/(\d+)\s+\d+\s+R/', $m[1], $hijos)) {
        foreach ($hijos[1] as $h) {
            pdfRecorrerPaginas((int) $h, $objetos, $orden, $vistos);
        }
    }
}

/**
 * Contenido concatenado de una pagina. `/Contents` puede ser un stream o un
 * array de streams que hay que pegar en orden (los generadores parten el
 * contenido en varios objetos cuando se hace grande).
 */
function pdfContenidoDePagina(string $pagina, array $objetos): string {
    if (!preg_match('/\/Contents\s*(\[[^\]]*\]|\d+\s+\d+\s+R)/', $pagina, $m)) return '';

    $refs = [];
    if (preg_match_all('/(\d+)\s+\d+\s+R/', $m[1], $g)) $refs = $g[1];

    $out = '';
    foreach ($refs as $r) {
        $cuerpo = $objetos[(int) $r] ?? null;
        if ($cuerpo === null) continue;
        $out .= pdfStream($cuerpo, $objetos) . "\n";
    }
    return $out;
}

/**
 * Mapa `nombre de fuente en el stream => info de la fuente` para una pagina.
 *
 * @return array<string, array{bytes:int, mapa:array<int,string>}>
 */
function pdfFuentesDePagina(string $pagina, array $objetos): array {
    $recursos = null;
    if (preg_match('/\/Resources\s+(\d+)\s+\d+\s+R/', $pagina, $m)) {
        $recursos = $objetos[(int) $m[1]] ?? null;
    } elseif (preg_match('/\/Resources\s*(<<.*)/s', $pagina, $m)) {
        $recursos = $m[1];
    }
    if ($recursos === null) return [];

    // El diccionario /Font puede estar inline o referenciado.
    $dicFuentes = null;
    if (preg_match('/\/Font\s+(\d+)\s+\d+\s+R/', $recursos, $m)) {
        $dicFuentes = $objetos[(int) $m[1]] ?? null;
    } elseif (preg_match('/\/Font\s*<<(.*?)>>/s', $recursos, $m)) {
        $dicFuentes = $m[1];
    }
    if ($dicFuentes === null) return [];

    $out = [];
    if (!preg_match_all('/\/([^\s\/\[\]<>]+)\s+(\d+)\s+\d+\s+R/', $dicFuentes, $g, PREG_SET_ORDER)) {
        return $out;
    }

    foreach ($g as $par) {
        $cuerpo = $objetos[(int) $par[2]] ?? null;
        if ($cuerpo === null) continue;

        // Identity-H es el encoding que usan todas las fuentes embebidas
        // modernas: los codigos del string son CIDs de 2 bytes, no bytes
        // sueltos. Partirlo mal desplaza TODO el texto de la pagina.
        $bytes = preg_match('/\/Encoding\s*\/Identity-H/', $cuerpo)
              || preg_match('/\/Subtype\s*\/Type0/', $cuerpo) ? 2 : 1;

        $mapa = [];
        if (preg_match('/\/ToUnicode\s+(\d+)\s+\d+\s+R/', $cuerpo, $mu)) {
            $cm = $objetos[(int) $mu[1]] ?? null;
            if ($cm !== null) $mapa = pdfLeerCMap(pdfStream($cm, $objetos));
        }
        $out[$par[1]] = ['bytes' => $bytes, 'mapa' => $mapa];
    }
    return $out;
}

/**
 * Parsea un CMap /ToUnicode y devuelve `codigo => texto`.
 *
 * OJO con el orden de los patrones: un bloque `beginbfrange` admite DOS formas,
 *
 *     <0041> <0043> <0061>              (rango: 41->61, 42->62, 43->63)
 *     <0001> <0003> [<0046> <0065> ...] (lista explicita, uno por codigo)
 *
 * y si se buscan por separado, el patron de la primera forma matchea de a tres
 * los `<...>` de ADENTRO del corchete de la segunda y pisa el mapa con basura.
 * Por eso las dos alternativas van en un unico preg_match_all, que consume cada
 * entrada entera antes de seguir.
 *
 * @return array<int, string>
 */
function pdfLeerCMap(string $cmap): array {
    $out = [];
    if ($cmap === '') return $out;

    // Forma directa, codigo por codigo.
    if (preg_match_all('/beginbfchar(.*?)endbfchar/s', $cmap, $bloques)) {
        foreach ($bloques[1] as $b) {
            if (preg_match_all('/<([0-9A-Fa-f]+)>\s*<([0-9A-Fa-f]+)>/', $b, $g, PREG_SET_ORDER)) {
                foreach ($g as $par) $out[hexdec($par[1])] = pdfUtf16beATexto($par[2]);
            }
        }
    }

    // Rangos.
    if (preg_match_all('/beginbfrange(.*?)endbfrange/s', $cmap, $bloques)) {
        foreach ($bloques[1] as $b) {
            $re = '/<([0-9A-Fa-f]+)>\s*<([0-9A-Fa-f]+)>\s*(?:\[(.*?)\]|<([0-9A-Fa-f]+)>)/s';
            if (!preg_match_all($re, $b, $g, PREG_SET_ORDER)) continue;

            foreach ($g as $ent) {
                $lo = hexdec($ent[1]);
                $hi = hexdec($ent[2]);
                if (($ent[3] ?? '') !== '') {
                    if (preg_match_all('/<([0-9A-Fa-f]+)>/', $ent[3], $items)) {
                        foreach ($items[1] as $k => $u) $out[$lo + $k] = pdfUtf16beATexto($u);
                    }
                } elseif (($ent[4] ?? '') !== '') {
                    $base = hexdec($ent[4]);
                    // Un rango disparatado (archivo corrupto) reventaria la
                    // memoria; 65536 es el maximo real de un codespace de 2 bytes.
                    if ($hi < $lo || $hi - $lo > 65535) continue;
                    for ($k = 0; $k <= $hi - $lo; $k++) {
                        $out[$lo + $k] = pdfPuntoATexto($base + $k);
                    }
                }
            }
        }
    }
    return $out;
}

/** "00480065" (UTF-16BE en hexa) => "He". */
function pdfUtf16beATexto(string $hex): string {
    $s = '';
    $n = strlen($hex);
    for ($i = 0; $i + 3 < $n; $i += 4) {
        $s .= pdfPuntoATexto((int) hexdec(substr($hex, $i, 4)));
    }
    return $s;
}

/** Codepoint => UTF-8, saltando los sustitutos y el nulo. */
function pdfPuntoATexto(int $cp): string {
    if ($cp <= 0 || ($cp >= 0xD800 && $cp <= 0xDFFF) || $cp > 0x10FFFF) return '';
    return mb_chr($cp, 'UTF-8');
}

// ----------------------------------------------------------------------------
// Content stream -> corridas de texto posicionadas
// ----------------------------------------------------------------------------

/** Multiplica dos matrices [a b c d e f] del PDF. */
function pdfMatMul(array $m1, array $m2): array {
    return [
        $m1[0] * $m2[0] + $m1[1] * $m2[2],
        $m1[0] * $m2[1] + $m1[1] * $m2[3],
        $m1[2] * $m2[0] + $m1[3] * $m2[2],
        $m1[2] * $m2[1] + $m1[3] * $m2[3],
        $m1[4] * $m2[0] + $m1[5] * $m2[2] + $m2[4],
        $m1[4] * $m2[1] + $m1[5] * $m2[3] + $m2[5],
    ];
}

/**
 * Interpreta el content stream y devuelve las corridas de texto con su
 * posicion final en la pagina.
 *
 * Se sigue solo el subconjunto de operadores que mueve texto: el estado grafico
 * (q/Q/cm) porque el CTM escala y ESPEJA la pagina — wkhtmltopdf arranca con
 * `0.72 0 0 -0.72 ... cm`, o sea Y invertida, y sin aplicarlo las filas salen
 * al reves —, y el estado de texto (BT, ET, Tm, Td, TD, T*, TL, Tf) porque es
 * el que dice donde cae cada string.
 *
 * @return array<int, array{x:float, y:float, s:string, size:float}>
 */
function pdfCorridasDeTexto(string $contenido, array $fuentes, int &$glifos): array {
    // Un unico preg_match_all sobre todo el stream: recorrerlo caracter por
    // caracter en PHP es un orden de magnitud mas lento y estos streams pueden
    // pesar varios MB. El grupo `str` es recursivo porque un string literal
    // admite parentesis balanceados adentro.
    $re = '~
        (?P<str>\((?:[^\\\\()]|\\\\.|(?P>str))*\))
      | (?P<hex><(?!<)[0-9A-Fa-f\s]*>)
      | (?P<delim><<|>>|\[|\])
      | (?P<name>/[^\s/\[\]<>(){}%]*)
      | (?P<num>[+-]?(?:\d+\.?\d*|\.\d+))
      | (?P<op>[A-Za-z\x27"*]+)
      | %[^\r\n]*
    ~xs';

    if (!preg_match_all($re, $contenido, $tokens, PREG_SET_ORDER)) return [];

    $ctm   = [1, 0, 0, 1, 0, 0];
    $pila  = [];
    $tm    = null;   // matriz de texto
    $tlm   = null;   // matriz de la linea de texto
    $tl    = 0.0;    // interlineado (TL)
    $fuente = null;
    $tf    = 0.0;    // cuerpo declarado en Tf
    $ops   = [];
    $out   = [];

    foreach ($tokens as $t) {
        if (($t['op'] ?? '') === '' ) {
            // Operando: se apila y se sigue.
            if (isset($t['delim']) && $t['delim'] !== '') {
                // Los corchetes de un TJ se ignoran: el contenido ya quedo en
                // los operandos y se reconstruye del texto crudo mas abajo.
                continue;
            }
            $ops[] = $t[0];
            if (count($ops) > 64) array_shift($ops);   // cota anti-basura
            continue;
        }

        $op = $t['op'];
        $n  = count($ops);

        switch ($op) {
            case 'q':
                $pila[] = $ctm;
                break;

            case 'Q':
                if ($pila) $ctm = array_pop($pila);
                break;

            case 'cm':
                if ($n >= 6) {
                    $ctm = pdfMatMul(array_map('floatval', array_slice($ops, -6)), $ctm);
                }
                break;

            case 'BT':
                $tm = $tlm = [1, 0, 0, 1, 0, 0];
                break;

            case 'ET':
                $tm = $tlm = null;
                break;

            case 'Tf':
                if ($n >= 2) {
                    $fuente = ltrim($ops[$n - 2], '/');
                    $tf     = (float) $ops[$n - 1];
                }
                break;

            case 'TL':
                if ($n >= 1) $tl = (float) $ops[$n - 1];
                break;

            case 'Tm':
                if ($n >= 6) $tm = $tlm = array_map('floatval', array_slice($ops, -6));
                break;

            case 'Td':
                if ($n >= 2 && $tlm !== null) {
                    $tlm = pdfMatMul([1, 0, 0, 1, (float) $ops[$n - 2], (float) $ops[$n - 1]], $tlm);
                    $tm  = $tlm;
                }
                break;

            case 'TD':
                if ($n >= 2 && $tlm !== null) {
                    $tl  = -((float) $ops[$n - 1]);
                    $tlm = pdfMatMul([1, 0, 0, 1, (float) $ops[$n - 2], (float) $ops[$n - 1]], $tlm);
                    $tm  = $tlm;
                }
                break;

            case 'T*':
                if ($tlm !== null) {
                    $tlm = pdfMatMul([1, 0, 0, 1, 0, -$tl], $tlm);
                    $tm  = $tlm;
                }
                break;

            case 'Tj':
            case 'TJ':
            case "'":
            case '"':
                // Las comillas bajan de linea antes de escribir.
                if (($op === "'" || $op === '"') && $tlm !== null) {
                    $tlm = pdfMatMul([1, 0, 0, 1, 0, -$tl], $tlm);
                    $tm  = $tlm;
                }
                if ($tm === null || !$ops) break;

                $info = $fuentes[$fuente] ?? ['bytes' => 1, 'mapa' => []];

                // En un TJ los operandos son varios strings + los kernings; se
                // concatenan todos los strings del operando crudo, que es lo
                // que forma la celda.
                $texto = '';
                foreach ($ops as $o) {
                    if ($o === '' ) continue;
                    if ($o[0] === '<') {
                        $texto .= pdfDecodificarHex($o, $info);
                    } elseif ($o[0] === '(') {
                        $texto .= pdfDecodificarLiteral($o, $info);
                    }
                }
                // Se conservan las corridas que son SOLO un espacio: los
                // generadores que posicionan glifo por glifo (wkhtmltopdf)
                // emiten el espacio como una corrida propia, y descartarla
                // pegotea las palabras ("Ventadedolares").
                if ($texto !== '') {
                    $m    = pdfMatMul($tm, $ctm);
                    // Cuerpo real = escala vertical de la matriz combinada.
                    $size = sqrt($m[2] * $m[2] + $m[3] * $m[3]) * ($tf ?: 1.0);
                    if ($size <= 0.01) $size = abs($tf) ?: 1.0;

                    $out[] = ['x' => $m[4], 'y' => $m[5], 's' => $texto, 'size' => $size];

                    $glifos += mb_strlen($texto, 'UTF-8');
                    if ($glifos > PDF_MAX_GLIFOS) return $out;

                    // Avance estimado. Solo importa cuando el generador escribe
                    // varios Tj seguidos sin reposicionar; los que emiten un Tm
                    // por glifo (wkhtmltopdf, que es el caso de Brubank) lo
                    // pisan en el token siguiente.
                    $av = mb_strlen($texto, 'UTF-8') * PDF_ANCHO_GLIFO * ($tf ?: 1.0);
                    $tm = pdfMatMul([1, 0, 0, 1, $av, 0], $tm);
                }
                break;
        }
        $ops = [];
    }
    return $out;
}

/** "<00480065>" => texto, segun el ancho de codigo de la fuente. */
function pdfDecodificarHex(string $tok, array $info): string {
    $hex = preg_replace('/[^0-9A-Fa-f]/', '', $tok);
    if ($hex === '' || $hex === null) return '';

    $paso = $info['bytes'] === 2 ? 4 : 2;
    // Un hex de largo impar se completa con 0 segun el spec.
    if (strlen($hex) % $paso !== 0) $hex = str_pad($hex, (int) (ceil(strlen($hex) / $paso) * $paso), '0');

    $s = '';
    for ($i = 0, $n = strlen($hex); $i + $paso <= $n; $i += $paso) {
        $s .= pdfCodigoATexto((int) hexdec(substr($hex, $i, $paso)), $info);
    }
    return $s;
}

/** "(Hola\)mundo)" => texto, resolviendo los escapes del spec. */
function pdfDecodificarLiteral(string $tok, array $info): string {
    $crudo = substr($tok, 1, -1);

    // Escapes: \n \r \t \b \f \( \) \\ y \ddd octal.
    $crudo = preg_replace_callback(
        '/\\\\(?:([nrtbf])|([0-7]{1,3})|(.))/s',
        function ($m) {
            if (($m[1] ?? '') !== '') {
                return ['n' => "\n", 'r' => "\r", 't' => "\t", 'b' => "\x08", 'f' => "\x0C"][$m[1]];
            }
            if (($m[2] ?? '') !== '') return chr(octdec($m[2]) & 0xFF);
            return $m[3] ?? '';
        },
        $crudo
    );

    $s = '';
    if ($info['bytes'] === 2) {
        for ($i = 0, $n = strlen($crudo); $i + 1 < $n; $i += 2) {
            $s .= pdfCodigoATexto((ord($crudo[$i]) << 8) | ord($crudo[$i + 1]), $info);
        }
    } else {
        for ($i = 0, $n = strlen($crudo); $i < $n; $i++) {
            $s .= pdfCodigoATexto(ord($crudo[$i]), $info);
        }
    }
    return $s;
}

/**
 * Un codigo de caracter a texto.
 *
 * Sin ToUnicode se cae a interpretar el codigo como Latin-1, que es lo correcto
 * para las fuentes estandar (WinAnsi) y lo unico razonable para el resto: peor
 * es devolver vacio y perder la celda entera.
 */
function pdfCodigoATexto(int $codigo, array $info): string {
    if (isset($info['mapa'][$codigo])) return $info['mapa'][$codigo];
    if ($info['bytes'] === 2)          return $codigo > 0 && $codigo < 0x2500 ? pdfPuntoATexto($codigo) : '';
    return $codigo >= 32 ? pdfPuntoATexto($codigo) : '';
}

// ----------------------------------------------------------------------------
// Corridas -> filas y celdas
// ----------------------------------------------------------------------------

/**
 * Agrupa las corridas en filas (por Y) y celdas (por huecos en X).
 *
 * En coordenadas PDF la Y crece hacia ARRIBA, asi que las filas salen ordenadas
 * de mayor a menor Y para leer la pagina de arriba hacia abajo.
 *
 * @param  array<int, array{x:float,y:float,s:string,size:float}> $runs
 * @return array<int, array<int, string>>
 */
function pdfArmarFilas(array $runs): array {
    if (!$runs) return [];

    // Agrupar por linea base. Se ordena por Y descendente y se abre fila nueva
    // cuando el salto supera la tolerancia.
    usort($runs, fn($a, $b) => $b['y'] <=> $a['y']);

    $lineas = [];
    $actual = [];
    $refY   = null;
    $refSz  = 1.0;

    foreach ($runs as $r) {
        $tol = max(0.75, $r['size'] * PDF_TOLERANCIA_FILA);
        if ($refY === null || abs($r['y'] - $refY) <= max($tol, $refSz * PDF_TOLERANCIA_FILA)) {
            if ($refY === null) { $refY = $r['y']; $refSz = $r['size']; }
            $actual[] = $r;
        } else {
            $lineas[] = $actual;
            $actual   = [$r];
            $refY     = $r['y'];
            $refSz    = $r['size'];
        }
    }
    if ($actual) $lineas[] = $actual;

    $filas = [];
    foreach ($lineas as $linea) {
        usort($linea, fn($a, $b) => $a['x'] <=> $b['x']);

        $celdas = [];
        $buffer = '';
        $finPrev = null;

        foreach ($linea as $r) {
            $ancho = mb_strlen($r['s'], 'UTF-8') * $r['size'] * PDF_ANCHO_GLIFO;
            $hueco = $finPrev === null ? 0.0 : $r['x'] - $finPrev;

            if ($finPrev !== null && $hueco > $r['size'] * PDF_HUECO_CELDA) {
                $celdas[] = $buffer;
                $buffer   = '';
            }
            $buffer .= $r['s'];
            $finPrev = $r['x'] + $ancho;
        }
        if ($buffer !== '') $celdas[] = $buffer;

        // Se normalizan los espacios de cada celda: el PDF puede traer el mismo
        // texto partido en varias corridas y con espacios duplicados.
        $celdas = array_map(
            fn($c) => trim((string) preg_replace('/\s+/u', ' ', $c)),
            $celdas
        );
        if (implode('', $celdas) !== '') $filas[] = $celdas;
    }
    return $filas;
}
