<?php
// api/lib/bancos_interpretes/brubank.php
// Intérprete de extractos de Brubank.
//
// FORMATO
// -------
// Brubank es banco digital y no exporta CSV: lo único que entrega es el
// "Estado de cuenta" en PDF que se descarga desde la app. Lo lee pdf.php, que
// reconstruye la tabla por coordenadas y la entrega como matriz de celdas
// (ver ahí por qué no alcanza con pdftotext). Lo que llega acá es:
//
//     Fecha     | #Ref    | Descripción       | Débito    | Crédito | Saldo
//     31-10-19  | 2246171 | Venta de dólares  | -         | $ 58,71 | $ 58,71
//     31-10-19  | 2246490 | Fiambreria Coll   | $ 58,71   | -       | $ 0,00
//
// Débito y crédito van en columnas separadas y la celda vacía se imprime como
// "-", no en blanco. Fecha dd-mm-aa, decimal con coma, importes con símbolo.
//
// UN PDF, DOS CUENTAS
// -------------------
// El estado de cuenta trae TODAS las cuentas del titular una atrás de la otra:
// primero la caja de ahorro en pesos (con su bloque "Mi cuenta" + "Resumen" y
// su tabla de movimientos, que puede seguir en la página siguiente) y después
// la caja de ahorro en dólares con la suya. Peor: los números de referencia se
// repiten entre las dos — el #2246171 existe en pesos Y en dólares, porque es
// la misma operación de cambio vista desde cada lado.
//
// Por eso este intérprete no puede devolver todo lo que lee: filtra por la
// moneda de la cuenta destino, que le llega por conCuenta(). Sin eso, importar
// este PDF a la cuenta en pesos cargaba también los 17 movimientos en dólares
// como si fueran pesos.
//
// POR QUE LAS FIRMAS EXIGEN "#Ref"
// --------------------------------
// La tabla de Brubank (fecha / descripción / débito / crédito / saldo) es
// idéntica en columnas a la del Banco San Juan y a la del Supervielle, y las
// firmas tolerantes de esos dos (['fecha','debito','credito','saldo']) matchean
// este archivo. Si acá se declararan firmas igual de amplias, cada intérprete
// reclamaría los archivos del otro.
//
// "#Ref" es el nombre de columna que sólo usa Brubank: ni San Juan ni
// Supervielle lo tienen (ambos la llaman "Comprobante"). Anclando TODAS las
// firmas en él, detectar() devuelve 0 sobre cualquier archivo de esos bancos —
// que es lo que antes garantizaba excluyeDebitoCredito(), y que ya no sirve
// porque el formato real de Brubank sí trae débito y crédito separados.

final class InterpreteBrubank extends InterpreteTabular {

    /** Moneda de la cuenta destino ('P' | 'D'), o null si no se informó. */
    private ?string $monedaCuenta = null;

    public function clave(): string  { return 'brubank'; }
    public function nombre(): string { return 'Brubank'; }

    public function calibracion(): array {
        return [
            'verificado' => true,
            'nota' => 'Calibrado contra un estado de cuenta real en PDF '
                    . '(ago–dic 2019, 53 movimientos en 2 cuentas: pesos y dólares). '
                    . 'La cadena de saldos cierra en las dos.',
        ];
    }

    public function conCuenta(array $cuenta): void {
        $m = strtoupper(trim((string) ($cuenta['moneda'] ?? '')));
        $this->monedaCuenta = $m !== '' ? $m : null;
    }

    protected function firmasBanco(): array {
        // '1430' es el prefijo de CBU de Brubank (entidad 143) y aparece en el
        // bloque de cabecera de cada cuenta.
        return ['brubank', '1430'];
    }

    // El PDF trae bastante preámbulo antes de la tabla (titular, domicilio,
    // "Mi cuenta", "Resumen", CUIT, número y CBU): 12 filas en el archivo real.
    protected function maxScan(): int { return 40; }

    protected function firmasEncabezado(): array {
        // Todas ancladas en '#ref' — ver el encabezado del archivo.
        return [
            ['fecha', '#ref', 'descripcion', 'debito', 'credito', 'saldo'],
            ['fecha', '#ref', 'descripcion', 'saldo'],
            ['fecha', '#ref', 'descripcion'],
        ];
    }

    protected function patronesColumnas(): array {
        return [
            'fecha'       => ['fecha'],
            'debito'      => ['debito'],
            'credito'     => ['credito'],
            'saldo'       => ['saldo'],
            'referencia'  => ['#ref', 'ref'],
            'descripcion' => ['descripcion', 'detalle', 'concepto'],
        ];
    }

    protected function formatoFecha(): string { return 'dmy'; }

    // Coma decimal explícita: 'auto' leería bien igual, pero el extracto mezcla
    // "$ 1.190,00" con "$ 0,38" y no hay motivo para dejarlo a la heurística.
    protected function decimal(): string { return 'coma'; }

    /**
     * Lectura completa, en vez de la de InterpreteTabular.
     *
     * Se toma el control porque el archivo tiene VARIAS tablas (una por cuenta,
     * más las continuaciones de página) y hay que saber a qué cuenta pertenece
     * cada una. El esqueleto tabular asume una sola tabla y un solo encabezado.
     */
    public function interpretar(array $filas, string $formato): ResultadoInterpretacion {
        $res = new ResultadoInterpretacion();

        $cols     = null;   // columnas de la tabla en curso
        $canonico = false;  // el encabezado tiene el layout de 6 columnas conocido
        $moneda   = null;   // moneda de la cuenta en curso ('P' | 'D')
        $numero   = null;   // número de la cuenta en curso
        $porMoneda = [];    // moneda => cantidad leída
        $numeros   = [];    // moneda => número de cuenta
        // Moneda de cada movimiento, en el mismo orden que $res->movimientos.
        $monedas   = [];

        $fmt = $this->formatoFecha();
        $dec = $this->decimal();

        foreach ($filas as $fila) {
            if ($this->filaVacia($fila)) continue;

            // --- Cabecera de cuenta -------------------------------------
            // "Moneda | Pesos (ARS)" abre la sección de una cuenta nueva;
            // "Número | 1300070075001" la identifica. Las continuaciones de
            // página no repiten el bloque, así que el estado se arrastra.
            $etiqueta = $this->norm($fila[0] ?? '');
            if ($etiqueta === 'moneda') {
                $m = $this->monedaDeTexto($this->norm($fila[1] ?? ''));
                if ($m !== null) $moneda = $m;
                continue;
            }
            if ($etiqueta === 'numero' && ($fila[1] ?? '') !== '') {
                $numero = trim((string) $fila[1]);
                if ($moneda !== null) $numeros[$moneda] = $numero;
                continue;
            }

            // --- Encabezado de tabla ------------------------------------
            if ($this->esEncabezado($fila)) {
                $cols     = $this->resolverColumnas($fila);
                $canonico = $this->esLayoutCanonico($fila, $cols);
                continue;
            }
            if ($cols === null) continue;   // todavía no empezó ninguna tabla

            // --- Movimiento ---------------------------------------------
            // Se realinea la fila antes de leerla: ver alinearFila().
            $fila  = $canonico ? $this->alinearFila($fila) : $fila;
            $fecha = planillaFecha($this->celda($fila, $cols['fecha']), $fmt);
            if ($fecha === null) {
                // Sin fecha no es un movimiento: es el pie legal, el bloque de
                // otra cuenta o una línea suelta. No se corta la lectura (abajo
                // puede venir otra tabla), simplemente se saltea.
                continue;
            }

            $par = $this->debitoCredito($fila, $cols['debito'], $cols['credito'], $dec);
            if ($par === null) { $res->descartadas++; continue; }
            [$tipo, $importe] = $par;
            if ($importe <= 0) { $res->descartadas++; continue; }

            // La moneda del movimiento sale del propio importe cuando se puede
            // ("U$S 166,62" sólo aparece en la cuenta en dólares). Es más
            // confiable que arrastrar el estado de la sección: si un resumen
            // futuro no trae el bloque "Mi cuenta", esto sigue acertando.
            $monedaMov = $this->monedaDeImportes($fila, $cols) ?? $moneda ?? 'P';

            $descripcion = $this->texto($fila, $cols['descripcion'], 500);

            $mov = new MovimientoBancario(
                fecha:       $fecha,
                tipo:        $tipo,
                importe:     $importe,
                descripcion: $descripcion,
                referencia:  $this->texto($fila, $cols['referencia'], 100),
                saldo:       $cols['saldo'] !== null
                                ? planillaNumero($this->celda($fila, $cols['saldo']), $dec)
                                : null,
                contraparte: $this->contraparteDe($descripcion),
                medio:       $this->medioDe($descripcion),
            );

            $porMoneda[$monedaMov] = ($porMoneda[$monedaMov] ?? 0) + 1;
            if ($numero !== null && !isset($numeros[$monedaMov])) {
                $numeros[$monedaMov] = $numero;
            }

            // Se guarda la moneda al costado para poder filtrar después:
            // MovimientoBancario no tiene campo de moneda (la pone el
            // importador desde la cuenta) y no vale la pena agregárselo para
            // un caso que sólo tiene este banco.
            $res->movimientos[] = $mov;
            $monedas[] = $monedaMov;
        }

        if (!$res->movimientos) {
            $res->avisos[] = 'Se reconoció el formato de Brubank pero no se pudo leer ningún movimiento.';
            return $res;
        }

        // --- Filtrado por moneda de la cuenta destino ---------------------
        $destino = $this->monedaCuenta;
        if ($destino !== null && count($porMoneda) > 0) {
            $quedan  = [];
            $fuera   = 0;
            foreach ($res->movimientos as $i => $mov) {
                if (($monedas[$i] ?? 'P') === $destino) $quedan[] = $mov;
                else                                    $fuera++;
            }

            if ($fuera > 0 && $quedan) {
                $res->movimientos = $quedan;
                $otras = [];
                foreach ($porMoneda as $m => $c) {
                    if ($m === $destino) continue;
                    $otras[] = $c . ' en ' . $this->nombreMoneda($m)
                             . (isset($numeros[$m]) ? ' (cuenta N.º ' . $numeros[$m] . ')' : '');
                }
                $res->avisos[] = 'El estado de cuenta trae más de una cuenta. Se importan los '
                               . count($quedan) . ' movimientos en ' . $this->nombreMoneda($destino)
                               . ', que es la moneda de esta cuenta; quedan afuera '
                               . implode(' y ', $otras) . '.';
            } elseif (!$quedan) {
                // Ningún movimiento de la moneda de la cuenta: el archivo es de
                // otra cuenta del mismo titular. Devolver los que hay sería
                // cargar dólares como pesos.
                $res->movimientos = [];
                $hay = [];
                foreach ($porMoneda as $m => $c) $hay[] = $c . ' en ' . $this->nombreMoneda($m);
                $res->avisos[] = 'Este resumen no tiene movimientos en '
                               . $this->nombreMoneda($destino) . ', que es la moneda de la cuenta '
                               . 'elegida: trae ' . implode(' y ', $hay) . '. '
                               . 'Elegí la cuenta que corresponda y volvé a analizarlo.';
                return $res;
            }
        }

        // La cadena de saldos es la mejor verificación de que la tabla se leyó
        // bien: si una columna se corrió, deja de cerrar en casi todas las
        // filas. Se avisa en vez de fallar — el extracto puede tener un hueco
        // real (un período que el banco no incluyó) y eso no es culpa del parseo.
        $rotos = $this->contarQuiebresDeSaldo($res->movimientos);
        if ($rotos > 0) {
            $res->avisos[] = 'La cadena de saldos no cierra en ' . $rotos . ' de '
                           . count($res->movimientos) . ' movimientos. Revisá el detalle '
                           . 'antes de confirmar: puede faltar un período en el resumen.';
        }
        return $res;
    }

    /**
     * True si el encabezado es el layout clásico de 6 columnas en el orden
     * conocido: Fecha | #Ref | Descripción | Débito | Crédito | Saldo.
     *
     * Es la precondición de alinearFila(): sólo se puede anclar por el final si
     * se sabe que las tres últimas columnas son los importes. Si Brubank algún
     * día reordena o agrega columnas, esto da false y se lee por posición como
     * cualquier otro banco.
     */
    private function esLayoutCanonico(array $fila, array $cols): bool {
        return count($fila) === 6
            && $cols['fecha']       === 0
            && $cols['referencia']  === 1
            && $cols['descripcion'] === 2
            && $cols['debito']      === 3
            && $cols['credito']     === 4
            && $cols['saldo']       === 5;
    }

    /**
     * Realinea una fila de movimiento anclando los importes por el FINAL.
     *
     * POR QUE HACE FALTA
     * ------------------
     * Un PDF no guarda celdas vacías: guarda glifos. Cuando Brubank imprime un
     * movimiento SIN descripción — pasa con los ajustes automáticos, p. ej. los
     * tres del 13-07-20 refs 20549201/2/3 —, en el papel queda un hueco, pero
     * en el texto extraído la celda no existe y la fila viene con 5 celdas en
     * vez de 6:
     *
     *     13-07-20 | 20549201 | -      | $ 1,00  | $ 2.778,87
     *     ^fecha     ^#ref      ^débito  ^crédito  ^saldo
     *
     * Leída por posición, "-" cae en Descripción, el crédito en Débito y el
     * saldo en Crédito: la fila queda con débito Y crédito con valor, y
     * debitoCredito() la descarta. Eso perdía 5 movimientos en el resumen 2020
     * ($ 9,73 de créditos) y 2 en el de 2021 ($ 61,73), y rompía la cadena de
     * saldos justo después de cada uno.
     *
     * Anclar por el final lo arregla porque las tres últimas columnas SIEMPRE
     * están: Brubank imprime "-" en el importe que no aplica y el saldo en
     * todas las filas. De paso cubre el caso simétrico —una descripción con un
     * espacio ancho que el extractor parte en dos celdas, dejando 7— que por
     * posición correría los importes para el otro lado.
     */
    private function alinearFila(array $fila): array {
        $fila = array_values($fila);
        $n    = count($fila);
        if ($n === 6) return $fila;          // fila completa, nada que hacer
        if ($n < 5)   return $fila;          // no es un movimiento; se descarta después

        // Las 3 últimas son débito, crédito y saldo. Sólo se realinea si de
        // verdad lo parecen: un número, o el "-" con que el banco marca el
        // importe que no aplica. Si no, se devuelve tal cual y la fila cae por
        // el camino normal.
        $ultimas = array_slice($fila, -3);
        foreach ($ultimas as $c) {
            $c = trim((string) $c);
            if ($c !== '-' && planillaNumero($c, $this->decimal()) === null) return $fila;
        }
        // El saldo nunca falta; si viniera "-" la fila no es un movimiento.
        if (trim((string) $ultimas[2]) === '-') return $fila;

        return [
            $fila[0],                                              // fecha
            $fila[1],                                              // #ref
            trim(implode(' ', array_slice($fila, 2, $n - 5))),     // descripción (puede quedar vacía)
            $ultimas[0],                                           // débito
            $ultimas[1],                                           // crédito
            $ultimas[2],                                           // saldo
        ];
    }

    /** True si la fila es el encabezado de la tabla de movimientos. */
    private function esEncabezado(array $fila): bool {
        $linea = $this->norm(implode(' | ', $fila));
        return $this->contiene($linea, 'fecha') && $this->contiene($linea, '#ref');
    }

    /** "Pesos (ARS)" => 'P', "Dólar (USD)" => 'D'. */
    private function monedaDeTexto(string $norm): ?string {
        if ($norm === '') return null;
        if (str_contains($norm, 'dolar') || str_contains($norm, 'usd')) return 'D';
        if (str_contains($norm, 'peso')  || str_contains($norm, 'ars')) return 'P';
        return null;
    }

    /**
     * Moneda deducida de cómo vienen escritos los importes de la fila.
     * Brubank antepone "U$S" en la cuenta en dólares y "$" en la de pesos.
     */
    private function monedaDeImportes(array $fila, array $cols): ?string {
        foreach (['debito', 'credito', 'saldo'] as $c) {
            if ($cols[$c] === null) continue;
            $txt = $this->norm($this->celda($fila, $cols[$c]));
            if ($txt === '') continue;
            if (str_contains($txt, 'u$s') || str_contains($txt, 'usd')) return 'D';
        }
        return null;
    }

    private function nombreMoneda(string $m): string {
        return $m === 'D' ? 'dólares' : 'pesos';
    }

    /**
     * Conceptos que genera el propio banco. Se listan para NO tomarlos como
     * contraparte: en Brubank la descripción es el comercio o la persona del
     * otro lado ("Fiambreria Coll", "ALVAREZ, LEONARDO JAVIER") salvo cuando es
     * una operación interna, y ahí no hay contraparte que registrar.
     */
    private const CONCEPTOS = [
        'venta de dolares', 'compra de dolares', 'interes mensual ganado',
        'cajero automatico', 'ing. brutos', 'ing brutos', 'imp. trans', 'imp trans',
        'impuesto', 'a una cuenta tuya', 'pago a prov', 'transferencia',
        'debito automatico', 'comision', 'iva', 'saldo',
    ];

    private function esConcepto(?string $descripcion): bool {
        if ($descripcion === null) return true;
        $n = $this->norm($descripcion);
        foreach (self::CONCEPTOS as $c) {
            if (str_contains($n, $c)) return true;
        }
        return false;
    }

    private function contraparteDe(?string $descripcion): ?string {
        if ($descripcion === null || $this->esConcepto($descripcion)) return null;
        return mb_strlen($descripcion) > 255 ? mb_substr($descripcion, 0, 255) : $descripcion;
    }

    /**
     * Medio de pago deducido del concepto.
     *
     * Sólo se clasifica lo inequívoco. Las compras en comercios se dejan en
     * null a propósito: son con tarjeta de débito, pero no hay nada en la fila
     * que lo diga — se deducirían de "no es ninguno de los conceptos
     * conocidos", que es exactamente el tipo de suposición que después mete
     * ruido en los reportes.
     */
    private function medioDe(?string $descripcion): ?string {
        if ($descripcion === null) return null;
        $n = $this->norm($descripcion);

        if (str_contains($n, 'interes'))                                  return 'interes';
        if (str_contains($n, 'ing. brutos') || str_contains($n, 'ing brutos')
            || str_contains($n, 'impuesto') || str_contains($n, 'imp. trans')
            || str_contains($n, 'imp trans') || str_contains($n, 'iva'))  return 'impuesto';
        if (str_contains($n, 'comision'))                                 return 'comision';
        if (str_contains($n, 'cajero automatico'))                        return 'efectivo';
        if (str_contains($n, 'debito automatico'))                        return 'debito_auto';
        if (str_contains($n, 'transferencia') || str_contains($n, 'a una cuenta tuya')
            || str_contains($n, 'pago a prov') || str_contains($n, 'interbanking')) return 'transferencia';

        return null;
    }
}
