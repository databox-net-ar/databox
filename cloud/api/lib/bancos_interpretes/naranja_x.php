<?php
// api/lib/bancos_interpretes/naranja_x.php
// Intérprete de extractos de Naranja X.
//
// Naranja X es billetera con CBU de entidad financiera real (453...), así que
// mezcla lo de los dos mundos: exporta como una billetera (monto firmado,
// concepto libre) pero la cuenta tiene CBU y admite transferencias como un
// banco. En `datacount_bancos_cuentas` está tipada como 'billetera', así que
// los medios que puede llevar son los de esa whitelist.
//
// SIN CALIBRAR: falta verificar contra un export real. Confirmar sobre todo si
// el monto viene firmado (asumido) o en columnas separadas.

final class InterpreteNaranjaX extends InterpreteTabular {

    public function clave(): string  { return 'naranja_x'; }
    public function nombre(): string { return 'Naranja X'; }

    public function calibracion(): array {
        return [
            'verificado' => false,
            'nota' => 'Asume monto en una sola columna firmada. '
                    . 'Falta verificar contra un export real.',
        ];
    }

    protected function firmasBanco(): array {
        return ['naranja x', 'naranjax', 'naranja', '4530'];
    }

    // Naranja X nunca separa debito de credito: si el archivo los trae, no es suyo.
    protected function excluyeDebitoCredito(): bool { return true; }

    protected function firmasEncabezado(): array {
        return [
            ['fecha', 'concepto', 'monto'],
            ['fecha', 'descripcion', 'monto', 'saldo'],
            ['fecha', 'detalle', 'importe'],
            ['fecha', 'movimiento', 'monto'],
        ];
    }

    protected function patronesColumnas(): array {
        return [
            'fecha'       => ['fecha', 'date'],
            'importe'     => ['monto', 'importe', 'amount'],
            'saldo'       => ['saldo', 'balance'],
            'referencia'  => ['operacion', 'referencia', 'comprobante', 'id'],
            'contraparte' => ['destinatario', 'origen', 'beneficiario', 'contraparte'],
            'descripcion' => ['concepto', 'descripcion', 'detalle', 'movimiento'],
        ];
    }

    // Misma lógica de clasificación que MercadoPago pero con el vocabulario de
    // Naranja X. Los valores tienen que existir en DCB_MEDIOS_POR_TIPO['billetera'].
    protected function ajustar(MovimientoBancario $m, array $fila, array $cols): MovimientoBancario {
        $d = $this->norm($m->descripcion ?? '');
        if ($d === '') return $m;

        $reglas = [
            'rendimiento'    => ['rendimiento', 'interes', 'inversion'],
            'comision'       => ['comision', 'cargo', 'mantenimiento'],
            'impuesto'       => ['impuesto', 'retencion', 'percepcion', 'iibb', 'iva', 'ley 25413'],
            'tarjeta_credito'=> ['tarjeta de credito', 'consumo tarjeta', 'cuota'],
            'tarjeta_debito' => ['tarjeta de debito', 'debito automatico'],
            'qr'             => ['qr', 'pago en comercio', 'presencial'],
            'transferencia'  => ['transferencia', 'envio de dinero', 'cvu', 'cbu'],
        ];
        foreach ($reglas as $medio => $palabras) {
            foreach ($palabras as $p) {
                if (str_contains($d, $p)) { $m->medio = $medio; return $m; }
            }
        }
        return $m;
    }
}
