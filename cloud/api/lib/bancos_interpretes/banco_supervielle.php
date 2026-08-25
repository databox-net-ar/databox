<?php
// api/lib/bancos_interpretes/banco_supervielle.php
// Intérprete de extractos del Banco Supervielle.
//
// CALIBRADO contra un export real de cuenta corriente (428 movimientos,
// nov 2025 – ago 2026, cuenta 091-5402032-2). Formato observado:
//
//   ASCII puro, SIN BOM · separador ',' · importes entrecomillados
//   Columnas: Fecha | Hora | Concepto | Detalle | Débito | Crédito | Saldo
//   Fechas dd/mm/aaaa · decimal coma · miles con punto ("3.277,99")
//   Orden: fecha y hora DESCENDENTES (lo más nuevo arriba)
//
// Es el formato más distinto de los cinco bancos, por cuatro motivos:
//
//   1. SEPARADOR COMA y decimal coma a la vez. Funciona porque el banco
//      entrecomilla todos los importes; planillaDetectarDelimitador() elige
//      la coma y fgetcsv respeta las comillas.
//   2. TIENE COLUMNA HORA. Es el único que la trae, y es lo que lo distingue
//      del resto en la detección: ningún otro export tiene 'hora'.
//   3. PERDIÓ LOS ACENTOS. El archivo es ASCII puro y donde iba una vocal
//      acentuada quedó un '?': "D?bito", "Cr?dito", "OPERACI?N",
//      "Autom?tico", "MU?OZ". Sin la tolerancia de contiene() en _base.php el
//      encabezado no matcheaba y ningún intérprete reconocía el archivo. Acá
//      además se reparan las palabras para no guardar los '?' en la base.
//   4. DÉBITO Y CRÉDITO SIEMPRE TIENEN VALOR: el que no aplica viene en
//      "0,00" en vez de vacío. debitoCredito() lo resuelve bien porque
//      descarta los ceros.
//
// La columna Detalle trae información estructurada en las transferencias:
//   "CONCEPTO: Transferencia recibida TERMINAL: LINK0011100C4
//    NOMBRE: CARRIVALE,PABLO FABIAN DOCUMENTO: 23131432089
//    REFERENCIA: TRANSF LETINI: C"
// De ahí salen la contraparte, el CUIT/DNI y la referencia, que si no se
// perderían: este export no tiene columna de comprobante.

final class InterpreteBancoSupervielle extends InterpreteTabular {

    public function clave(): string  { return 'banco_supervielle'; }
    public function nombre(): string { return 'Banco Supervielle'; }

    public function calibracion(): array {
        return [
            'verificado' => true,
            'nota' => 'Calibrado contra un export real de cuenta corriente '
                    . '(428 movimientos, nov 2025 – ago 2026).',
        ];
    }

    protected function decimal(): string { return 'coma'; }

    // 'hora' es la firma: es la única columna que no comparte con los demás
    // bancos, y el archivo no menciona a Supervielle por ningún lado.
    protected function firmasBanco(): array {
        return ['supervielle', '0270', 'hora'];
    }

    protected function firmasEncabezado(): array {
        return [
            // Firma exacta del export verificado. Los acentos perdidos los
            // absorbe contiene() de _base.php.
            ['fecha', 'hora', 'concepto', 'detalle', 'debito', 'credito', 'saldo'],
            // Variantes tolerantes, por si cambian el export.
            ['fecha', 'concepto', 'debito', 'credito', 'saldo'],
            ['fecha', 'debito', 'credito', 'saldo'],
            ['fecha', 'movimiento', 'importe', 'saldo'],
        ];
    }

    protected function patronesColumnas(): array {
        return [
            'fecha_valor' => ['fecha valor', 'f. valor'],
            'fecha'       => ['fecha operacion', 'fecha mov', 'fecha'],
            'debito'      => ['debito', 'debitos', 'debe'],
            'credito'     => ['credito', 'creditos', 'haber'],
            'importe'     => ['importe', 'monto'],
            'saldo'       => ['saldo'],
            'referencia'  => ['comprobante', 'nro operacion', 'operacion', 'numero'],
            // 'detalle' antes que 'descripcion': si no, el patrón 'detalle' de
            // descripción se queda con la columna y se pierde la contraparte.
            'contraparte' => ['detalle', 'beneficiario', 'ordenante'],
            'descripcion' => ['concepto', 'descripcion', 'movimiento'],
        ];
    }

    public function interpretar(array $filas, string $formato): ResultadoInterpretacion {
        $r = parent::interpretar($filas, $formato);

        $huecos = $this->contarQuiebresDeSaldo($r->movimientos);
        if ($huecos > 0) {
            $r->avisos[] = "Hay {$huecos} salto(s) en la cadena de saldos: en esos puntos el "
                         . 'extracto de origen tiene movimientos faltantes. Los importes y las '
                         . 'fechas de lo importado son correctos.';
        }

        return $r;
    }

    protected function ajustar(MovimientoBancario $m, array $fila, array $cols): MovimientoBancario {
        $m->descripcion = $this->repararAcentos($m->descripcion);
        $detalle        = $this->repararAcentos($m->contraparte);

        // El detalle estructurado de las transferencias se desarma en sus
        // partes; lo que no tiene esa forma queda como contraparte tal cual.
        if ($detalle !== null && str_contains($detalle, 'NOMBRE:')) {
            $m->contraparte = $this->extraerCampo($detalle, 'NOMBRE') ?? $detalle;
            $m->cuit        = $this->normalizarCuit((string) $this->extraerCampo($detalle, 'DOCUMENTO'));
            // REFERENCIA en las transferencias por CBU, ID_DEBIN en los DEBIN.
            $ref = $this->extraerCampo($detalle, 'REFERENCIA')
                ?? $this->extraerCampo($detalle, 'ID_DEBIN');
            if ($ref !== null && $m->referencia === null) $m->referencia = mb_substr($ref, 0, 100);
        } else {
            $m->contraparte = $detalle;
            // Detalles sin etiquetas que igual traen el CUIT suelto, del estilo
            // "PROVEEDORES 30704467423 SAN JUAN CABLE COLOR SA".
            $m->cuit = $this->cuitSuelto($detalle);
        }

        $m->medio = $this->clasificar((string) $m->descripcion);
        return $m;
    }

    /**
     * Repara las palabras que el export dejó con '?' en lugar del acento.
     *
     * Sobre los dos exports verificados hay 14 palabras afectadas, y 6 de ellas
     * son el sufijo -ción / -sión ("Percepci?n", "Comisi?n", "Acreditaci?n",
     * "Devoluci?n", "Acci?n", "OPERACI?N"). Ese caso va por regla, así que una
     * palabra nueva con ese sufijo se arregla sola.
     *
     * El resto va por diccionario porque no hay forma de saber qué vocal iba en
     * el '?' sin conocer la palabra: "D?bito" podría ser "Débito" o "Dábito".
     * Si aparece una nueva, se suma acá.
     */
    private function repararAcentos(?string $v): ?string {
        if ($v === null || !str_contains($v, '?')) return $v;

        $v = (string) preg_replace('/([cs])i\?n\b/u', '$1ión', $v);
        $v = (string) preg_replace('/([CS])I\?N\b/u', '$1IÓN', $v);

        // 'D?bito' sin la s final alcanza para 'D?bitos': strtr reemplaza la
        // subcadena y el plural queda bien igual.
        return strtr($v, [
            'D?bito'     => 'Débito',
            'Cr?dito'    => 'Crédito',
            'Autom?tico' => 'Automático',
            'N?mero'     => 'Número',
            'MU?OZ'      => 'MUÑOZ',
            'd?bito'     => 'débito',
            'cr?dito'    => 'crédito',
        ]);
    }

    /** Saca "CAMPO: valor" del detalle estructurado, hasta la próxima etiqueta. */
    private function extraerCampo(string $detalle, string $campo): ?string {
        // Las etiquetas conocidas marcan donde termina el valor; sin eso
        // "NOMBRE: X DOCUMENTO: 123" devolveria el documento pegado al nombre.
        // Hay dos gramaticas: la de transferencias por CBU (CONCEPTO / TERMINAL
        // / REFERENCIA / LETINI) y la de los DEBIN (CONCEPTO_QB / LEYENDA /
        // TIPO_DEBIN / ID_DEBIN). Van todas.
        $etiquetas = 'CONCEPTO_QB|CONCEPTO|TERMINAL|NOMBRE|DOCUMENTO|REFERENCIA'
                   . '|LETINI|CBU|LEYENDA|TIPO_DEBIN|ID_DEBIN';
        // `\b` no sirve de borde izquierdo con CONCEPTO vs CONCEPTO_QB: se pide
        // explicitamente que antes haya inicio o espacio.
        $rx = '/(?:^|\s)' . $campo . ':\s*(.*?)(?=\s+(?:' . $etiquetas . '):|$)/u';
        if (!preg_match($rx, $detalle, $m)) return null;
        $v = trim($m[1]);
        return $v === '' ? null : $v;
    }

    /**
     * CUIT suelto en un detalle sin etiquetas ("PROVEEDORES 30704467423 SAN
     * JUAN CABLE COLOR SA"). Se exige que sea un token de 11 digitos exactos
     * para no confundirlo con un CBU (22) ni con un id de operacion.
     */
    private function cuitSuelto(?string $detalle): ?string {
        if ($detalle === null) return null;
        if (!preg_match('/(?<!\d)(\d{11})(?!\d)/', $detalle, $m)) return null;
        return $m[1];
    }

    /**
     * Clasifica el medio a partir del concepto. Este export tiene sólo 15
     * conceptos distintos, así que se cubren todos.
     * Los valores tienen que existir en DCB_MEDIOS_POR_TIPO['banco'].
     */
    private function clasificar(string $concepto): ?string {
        $c = $this->norm($concepto);
        if ($c === '') return null;

        $reglas = [
            // Van primero: "Impuesto Débitos y Créditos" contiene 'debito' y
            // 'credito', y caería en transferencia si el orden fuera al revés.
            // 'i.v.a.' y 'percep' aparte de 'iva' y 'percepcion': el banco
            // escribe "I.V.A. Percep. Resp. Inscripto" con puntos y abreviado.
            'impuesto'        => ['impuesto', 'iibb', 'iva', 'i.v.a.', 'retencion',
                                  'percepcion', 'percep', 'sellos', 'rg 5617', 'rg. 3337'],
            'comision'        => ['comis', 'cargo', 'mantenimiento', 'permanencia'],
            // 'sobreg' cubre "Intereses de Sobregiro" y "Contras.Ints.Sobreg.",
            // que no tiene la palabra completa.
            'interes'         => ['interes', 'sobreg', 'ints.'],
            'cheque'          => ['cheque'],
            'tarjeta_debito'  => ['visa debito', 'compra visa', 'compra debito',
                                  'devolucion de compras'],
            'debito_auto'     => ['debito automatico', 'debito autom', 'lote hogar',
                                  'accion social'],
            'transferencia'   => ['transferencia', 'transf', 'trf', 'debin', 'cbu',
                                  'mep', 'interbanc', 'pago.prov', 'terceros',
                                  'acreditacion', 'proveedores'],
        ];
        foreach ($reglas as $medio => $palabras) {
            foreach ($palabras as $p) {
                if ($this->contiene($c, $p)) return $medio;
            }
        }
        return null;
    }
}
