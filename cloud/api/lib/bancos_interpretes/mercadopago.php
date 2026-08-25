<?php
// api/lib/bancos_interpretes/mercadopago.php
// Intérprete de reportes de MercadoPago.
//
// MercadoPago es el caso más distinto de los cinco, por dos motivos:
//
//   1. Exporta en inglés. El "Reporte de saldo" (el que sirve como extracto)
//      trae DATE / SOURCE_ID / DESCRIPTION / NET_CREDIT_AMOUNT /
//      NET_DEBIT_AMOUNT / BALANCE_AMOUNT, y el "Detalle de operaciones" trae
//      otras. Hay además una versión en español desde la web nueva.
//   2. Sus fechas son ISO (YYYY-MM-DD, a veces con hora y offset) y su decimal
//      es punto, al revés que los bancos locales.
//
// Por eso este archivo declara formatoFecha() = 'auto' y decimal() = 'punto',
// y firmasEncabezado() cubre las tres variantes.
//
// SIN CALIBRAR: falta verificar contra un export real.

final class InterpreteMercadoPago extends InterpreteTabular {

    public function clave(): string  { return 'mercadopago'; }
    public function nombre(): string { return 'MercadoPago'; }

    public function calibracion(): array {
        return [
            'verificado' => false,
            'nota' => 'Cubre reporte de saldo (EN), detalle de operaciones y export en español. '
                    . 'Falta verificar contra un export real.',
        ];
    }

    // MercadoPago escribe en ISO y con punto decimal, no como los bancos locales.
    protected function formatoFecha(): string { return 'auto'; }
    protected function decimal(): string      { return 'punto'; }

    protected function firmasBanco(): array {
        return ['mercadopago', 'mercado pago', 'source_id', 'net_credit_amount',
                'balance_amount', '0000003100'];
    }

    protected function firmasEncabezado(): array {
        return [
            // Reporte de saldo (inglés) — el que hace de extracto.
            ['date', 'description', 'net_credit_amount', 'net_debit_amount'],
            ['date', 'source_id', 'balance_amount'],
            // Export en español de la web nueva.
            ['fecha', 'descripcion', 'valor', 'saldo'],
            ['fecha', 'descripcion', 'id de operacion'],
        ];
    }

    protected function patronesColumnas(): array {
        return [
            'fecha_valor' => ['money_release_date', 'fecha de liberacion'],
            'fecha'       => ['date', 'fecha'],
            'debito'      => ['net_debit_amount', 'debito'],
            'credito'     => ['net_credit_amount', 'credito'],
            // Sin 'amount' pelado: matchearia NET_CREDIT_AMOUNT, NET_DEBIT_AMOUNT
            // y BALANCE_AMOUNT. Los tres tienen su propio destino.
            'importe'     => ['gross_amount', 'valor', 'importe', 'monto'],
            'saldo'       => ['balance_amount', 'saldo', 'balance'],
            'referencia'  => ['source_id', 'external_reference', 'id de operacion',
                              'operation_id', 'id'],
            'contraparte' => ['payer_name', 'counterpart', 'nombre', 'contraparte'],
            'descripcion' => ['description', 'descripcion', 'record_type', 'detalle'],
        ];
    }

    // Clasifica el medio a partir del concepto: en una billetera esto es lo que
    // distingue una comisión de un rendimiento de una transferencia, y es
    // justamente la información que el ABM usa para filtrar.
    // Los valores tienen que existir en DCB_MEDIOS_POR_TIPO['billetera'].
    protected function ajustar(MovimientoBancario $m, array $fila, array $cols): MovimientoBancario {
        $d = $this->norm($m->descripcion ?? '');
        if ($d === '') return $m;

        $reglas = [
            'rendimiento'   => ['rendimiento', 'interes', 'investment', 'asset_management'],
            'comision'      => ['comision', 'fee', 'cargo', 'mediation'],
            'impuesto'      => ['impuesto', 'tax', 'retencion', 'percepcion', 'iibb', 'iva'],
            'transferencia' => ['transferencia', 'transfer', 'money_transfer', 'cvu', 'cbu'],
            'qr'            => ['qr', 'point', 'presencial'],
            'tarjeta_debito'=> ['debito automatico', 'tarjeta de debito'],
        ];
        foreach ($reglas as $medio => $palabras) {
            foreach ($palabras as $p) {
                if (str_contains($d, $p)) { $m->medio = $medio; return $m; }
            }
        }
        return $m;
    }
}
