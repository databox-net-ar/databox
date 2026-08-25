<?php
// api/lib/bancos_interpretes/banco_san_juan.php
// Intérprete de extractos del Banco San Juan (Grupo Petersen / e-Bank).
//
// CALIBRADO contra un export real de cuenta corriente (969 movimientos,
// ene–ago 2026). Formato observado:
//
//   UTF-8 con BOM · separador ';' · todos los campos entrecomillados
//   Sin líneas de título: el encabezado es la fila 1.
//   Columnas: Fecha | Sucursal | Concepto | Referencia | Comprobante |
//             Débito | Crédito | Importe | Saldo | Moneda
//   Fechas dd/mm/aaaa · decimal coma · miles sin separador ("-100000,00")
//   Orden: fecha DESCENDENTE (lo más nuevo arriba)
//
// Detalles del formato que importan:
//
//   - `Débito` trae los importes en NEGATIVO ("-600,00") y `Crédito` en
//     positivo. debitoCredito() toma valor absoluto, así que sale bien igual.
//   - `Importe` duplica el débito o el crédito con signo. Se ignora: al haber
//     débito y crédito separados, resolverColumnas() anula `importe`.
//   - `Referencia` viene SIEMPRE vacía; el número de operación real está en
//     `Comprobante`. Por eso 'comprobante' va primero en los patrones.
//   - `Sucursal` (siempre "500") es lo que distingue este export del de
//     Supervielle, que comparte los nombres de las otras columnas.
//
// BUG DEL EXPORT DEL BANCO — ver repararSaldosPaginados() en _tabular.php:
// el archivo viene paginado de a 20 filas y el banco REINICIA el saldo corrido
// en cada página, escribiendo el saldo actual de la cuenta en la primera fila
// de cada una. Resultado: en el archivo real, 48 quiebres de cadena a
// intervalos exactos de 20 y el 97,9% de los saldos mal. Sólo la primera
// página queda bien. Se reconstruye el corrido desde el movimiento más
// reciente, cuyo saldo sí es correcto.

final class InterpreteBancoSanJuan extends InterpreteTabular {

    public function clave(): string  { return 'banco_san_juan'; }
    public function nombre(): string { return 'Banco San Juan'; }

    public function calibracion(): array {
        return [
            'verificado' => true,
            'nota' => 'Calibrado contra dos exports reales: homebanking 2026 '
                    . '(969 movimientos) y extracción de PDFs 2016–2019 (10.414 movimientos).',
        ];
    }

    // 'sucursal' y 'archivo fuente' no son el nombre del banco, pero son las
    // firmas que identifican sus dos exports: ninguno menciona al banco por
    // ningún lado, y sin ellas San Juan empataba en 70 puntos con Supervielle.
    protected function firmasBanco(): array {
        return ['banco san juan', 'bancosanjuan', 'grupo petersen', '0450',
                'sucursal', 'archivo fuente', 'fecha del extracto'];
    }

    protected function firmasEncabezado(): array {
        return [
            // Export del homebanking (verificado, 2026).
            ['fecha', 'sucursal', 'concepto', 'comprobante', 'debito', 'credito', 'saldo', 'moneda'],
            // Export armado desde los PDF de estado de cuenta (verificado, 2016–2019).
            ['fecha', 'concepto', 'detalle', 'comprobante', 'debito', 'credito', 'saldo'],
            // Variantes tolerantes, por si cambian el export.
            ['fecha', 'concepto', 'debito', 'credito', 'saldo'],
            ['fecha', 'debito', 'credito', 'saldo'],
            ['fecha', 'debitos', 'creditos', 'saldo'],
            ['fecha', 'descripcion', 'debito', 'credito'],
        ];
    }

    protected function patronesColumnas(): array {
        return [
            'fecha_valor' => ['fecha valor', 'f. valor', 'fecha de valor'],
            // 'fecha' a secas matchearía "Fecha del extracto"; como la columna
            // de fecha real va antes en el archivo, el barrido por posición la
            // encuentra primero igual.
            'fecha'       => ['fecha movimiento', 'fecha operacion', 'fecha', 'f. oper'],
            'debito'      => ['debitos', 'debito', 'debe'],
            'credito'     => ['creditos', 'credito', 'haber'],
            'importe'     => ['importe', 'monto'],
            'saldo'       => ['saldo'],
            // 'comprobante' primero: la columna 'Referencia' del export del
            // homebanking viene siempre vacía.
            'referencia'  => ['comprobante', 'nro comprobante', 'nro operacion',
                              'referencia', 'nro mov', 'numero'],
            // El export de PDFs trae 'Detalle' con la contraparte real
            // ("SAN JUAN CABLE", "BANCO CREDICOOP", "MUNICIPALIDAD DE LA
            // CAPITAL"). Se resuelve antes que 'descripcion' — si no, el patrón
            // 'detalle' de descripción se quedaba con la columna y el dato se
            // perdía.
            'contraparte' => ['detalle', 'beneficiario', 'ordenante', 'contraparte'],
            'descripcion' => ['descripcion', 'concepto', 'movimiento', 'leyenda'],
        ];
    }

    public function interpretar(array $filas, string $formato): ResultadoInterpretacion {
        $r = parent::interpretar($filas, $formato);

        $paginados = $this->repararSaldosPaginados($r->movimientos);
        if ($paginados > 0) {
            $r->avisos[] = "Se recalcularon {$paginados} saldos: el export del banco viene "
                         . 'paginado y reinicia el saldo corrido en cada página. Los importes '
                         . 'y las fechas no se tocaron.';
        }

        // Los saltos que quedan NO se tocan. Se probó corregirlos asumiendo que
        // eran signos perdidos por la extracción del PDF — la magnitud coincidía
        // exacta con lo que predice la cadena, así que parecía eso. No lo era:
        // el archivo continúa correctamente desde el valor tal cual está
        // (7.575,33 → 656.689,18 con un débito de 664.264,51, y el movimiento
        // siguiente cierra contra 656.689,18), y "corregir" el signo arreglaba
        // un eslabón rompiendo el próximo, dejando la misma cantidad de saltos.
        //
        // Lo que falta es un movimiento en el origen: en ese caso se ve la
        // acreditación grande ausente pero sí aparece el impuesto que la grava.
        // Recalcular taparía el hueco en vez de mostrarlo, así que sólo se avisa.
        $huecos = $this->contarQuiebresDeSaldo($r->movimientos);
        if ($huecos > 0) {
            $r->avisos[] = "Hay {$huecos} salto(s) en la cadena de saldos: en esos puntos el "
                         . 'extracto de origen tiene movimientos faltantes. Los importes y las '
                         . 'fechas de lo importado son correctos; sólo el saldo arrastra la '
                         . 'diferencia a partir de cada salto.';
        }

        return $r;
    }

    // Clasifica el medio a partir del concepto. El extracto de esta cuenta está
    // dominado por impuestos (ley 25.413 en casi una de cada dos filas) y por
    // débitos DEBIN, así que distinguirlos ahorra la clasificación manual.
    // Los valores tienen que existir en DCB_MEDIOS_POR_TIPO['banco'].
    protected function ajustar(MovimientoBancario $m, array $fila, array $cols): MovimientoBancario {
        // El e-Bank exporta el comprobante como número y pierde los ceros a la
        // izquierda; se re-pad para que la referencia sea estable entre exports
        // y la huella de dedup no cambie.
        if ($m->referencia !== null && ctype_digit($m->referencia) && strlen($m->referencia) < 8) {
            $m->referencia = str_pad($m->referencia, 8, '0', STR_PAD_LEFT);
        }

        $m->contraparte = $this->limpiarSubtotal($m->contraparte);

        $d = $this->norm($m->descripcion ?? '');
        if ($d === '') return $m;

        // El orden manda: se toma la primera regla que matchea. Los grupos mas
        // especificos van antes que los mas amplios — por ejemplo
        // 'compra con debito' (tarjeta) antes que 'debito' a secas, y los
        // impuestos primero porque son el 64% del extracto y muchos conceptos
        // arrancan con "IMPUESTO DEBITO ...", que si no caeria en debito_auto.
        $reglas = [
            'impuesto'        => ['impuesto', 'ley 25413', 'ley25413', 'percepcion',
                                  'sircreb', 'i.v.a.', 'iibb', 'rg 4815', 'rg3583',
                                  'rg 3583', 'ajuste iva', 'ajus. iva', 'res3337',
                                  'sellos', 'sellado', 'afip dgi',
                                  'munic de la capital', 'municipalidad'],
            // 'comis' cubre comision, comisiones y "COMIS CH RECHAZA".
            // 'deb com' va aca y no en debito_auto: "FV DEB COM DEB DIR ORIG" es
            // la comision que cobra el banco por el debito directo, no el
            // debito en si.
            'comision'        => ['comis', 'com gest', 'com pag', 'com ss', 'com sal',
                                  'com reimpresion', 'com recaud', 'deb com',
                                  'multa', 'mantenimiento', 'cargo'],
            'interes'         => ['interes', 'sobregiro', 'ajuste de intereses'],
            'cheque'          => ['cheque', 'cheq.'],
            'tarjeta_credito' => ['tarjeta credito', 'tc master', 'tc visa', 'socio tc',
                                  'argencard'],
            'tarjeta_debito'  => ['compra con debito', 'debito en cta', 'debito en cuenta'],
            'efectivo'        => ['efectivo', 'depefect', 'extraccion cajero',
                                  'cajero automat', 'caj.auto', 'caj auto', 'atm'],
            'transferencia'   => ['trans inm', 'tran inm', 'transferencia', 'transf',
                                  'trans mismo titular', 'debin', 'interbanking',
                                  'pagos link', 'invertir on line', 'acr. pre cpra',
                                  'pagos a terceros', 'sinapa'],
            // La cuenta de Alfatec es de recaudacion por debito automatico, asi
            // que este grupo cubre todo su vocabulario: el debito, su reversion
            // y la acreditacion de lo recaudado. 'ddo' = debito directo
            // originante.
            'debito_auto'     => ['debito directo', 'deb directo', 'deb dir', 'ddo',
                                  'recaudacion deb', 'deb autom', 'db automatico',
                                  'deb vs', 'seg b rivadavia', 'accion social',
                                  'lote hogar', 'debito automatico'],
        ];
        foreach ($reglas as $medio => $palabras) {
            foreach ($palabras as $p) {
                if (str_contains($d, $p)) { $m->medio = $medio; return $m; }
            }
        }
        return $m;
    }

    /**
     * Saca el marcador "SUBTOTAL <n>" que la extracción de los PDF deja pegado
     * en la columna Detalle.
     *
     * Es el subtotal de página del estado de cuenta, no un dato del movimiento:
     * en el archivo 2016–2019 aparece en 326 filas y en 274 de ellas el número
     * es exactamente el Saldo de esa misma fila. A veces viene solo
     * ("SUBTOTAL 29.536,61") y a veces prefijando el detalle real
     * ("SUBTOTAL 2.546,86 | VO TERMINALES AUTOGESTION DEPOSITO EN EF"), asi que
     * se corta el marcador y el separador y se conserva lo que sigue.
     *
     * La fila en si es un movimiento legitimo — solo se limpia el texto.
     */
    private function limpiarSubtotal(?string $v): ?string {
        if ($v === null) return null;
        if (stripos($v, 'subtotal') === false) return $v;

        // El marcador aparece en cualquier posicion, no solo al principio:
        //   "SUBTOTAL 29.536,61"
        //   "SUBTOTAL 2.546,86 | VO TERMINALES AUTOGESTION..."
        //   "MUNICIPALIDAD DE LA CAPIT | SUBTOTAL 3.448,53"
        //   "27324468213 ANDRADA LORENA | SUBTOTAL 115.729,80 | 27324468213 ANDRADA LORENA"
        // Por eso se parte por '|' y se descartan los tramos que son el
        // marcador, en vez de recortar por los extremos.
        $partes = array_map('trim', explode('|', $v));
        $utiles = [];
        foreach ($partes as $p) {
            if ($p === '') continue;
            // El signo es obligatorio contemplarlo: cuando la cuenta esta en
            // rojo el subtotal de pagina viene negativo ("SUBTOTAL -613,94").
            if (preg_match('/^subtotal\s+-?[\d.,]+$/iu', $p)) continue;
            // El ultimo ejemplo repite el mismo tramo a los dos lados del
            // marcador; una vez sacado el marcador queda duplicado.
            if (in_array($p, $utiles, true)) continue;
            $utiles[] = $p;
        }

        $limpio = implode(' | ', $utiles);
        return $limpio === '' ? null : $limpio;
    }
}
