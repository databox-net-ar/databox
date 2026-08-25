<?php
// api/lib/bancos_interpretes/brubank.php
// Intérprete de extractos de Brubank.
//
// Brubank es banco digital: exporta desde la app un resumen simple, con una
// sola columna de monto firmada (negativo = salida) en vez de débito/crédito
// separados. Por eso acá no se declaran patrones de débito/crédito — si se
// declararan, el esqueleto tabular los preferiría y leería mal el archivo.
//
// SIN CALIBRAR: falta verificar contra un export real. Lo primero a confirmar
// es el signo: si el export marca las salidas en positivo y las entradas en
// negativo, alcanza con poner invertirSigno() => true.

final class InterpreteBrubank extends InterpreteTabular {

    public function clave(): string  { return 'brubank'; }
    public function nombre(): string { return 'Brubank'; }

    public function calibracion(): array {
        return [
            'verificado' => false,
            'nota' => 'Asume monto en una sola columna firmada (negativo = egreso). '
                    . 'Verificar el signo contra un export real.',
        ];
    }

    protected function firmasBanco(): array {
        return ['brubank', '1430'];
    }

    // Brubank nunca separa debito de credito: si el archivo los trae, no es suyo.
    protected function excluyeDebitoCredito(): bool { return true; }

    protected function firmasEncabezado(): array {
        return [
            ['fecha', 'concepto', 'monto'],
            ['fecha', 'descripcion', 'monto'],
            ['fecha', 'detalle', 'importe'],
            ['fecha', 'movimiento', 'importe', 'saldo'],
        ];
    }

    protected function patronesColumnas(): array {
        // Sin 'debito' ni 'credito' a propósito: Brubank usa monto firmado.
        return [
            'fecha'       => ['fecha', 'date'],
            'importe'     => ['monto', 'importe', 'amount'],
            'saldo'       => ['saldo', 'balance'],
            'referencia'  => ['operacion', 'referencia', 'comprobante', 'id'],
            'contraparte' => ['destinatario', 'origen', 'beneficiario', 'contraparte'],
            'descripcion' => ['concepto', 'descripcion', 'detalle', 'movimiento'],
        ];
    }
}
