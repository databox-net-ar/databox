<?php
// api/lib/datacount_bancos.php
// Reglas compartidas del submodulo Datacount > Bancos. Vive aparte porque la
// consumen tanto el ABM de movimientos como el importador de extractos, y
// tenerla duplicada garantizaba que un dia se desincronizaran.

/**
 * Whitelist de medios de pago por tipo de cuenta.
 *
 * ACA VIVE la unica diferencia real entre un banco y una billetera: un banco
 * mueve plata por transferencia, debito automatico, cheque y los cargos que
 * genera el propio banco; una billetera suma QR, tarjeta y rendimientos de la
 * cuenta remunerada. Modelarlo como una lista de valores validos —y no como
 * dos tablas separadas— es lo que permite que ambas compartan extracto,
 * conciliacion y reportes de disponibilidades.
 *
 * Es el mismo enfoque que `account.payment.method` habilitados por journal en
 * Odoo. El catalogo completo de medios vive en `estados`
 * (campo `datacount_bancos_movimiento_medio`) y se edita desde
 * Herramientas > Editor de estados; este mapa solo dice cual aplica a que.
 *
 * Al agregar un medio nuevo al catalogo hay que sumarlo aca tambien, si no el
 * combo del ABM no lo va a ofrecer para ninguna cuenta.
 */
const DCB_MEDIOS_POR_TIPO = [
    // OJO: la lista de 'banco' arranco siendo solo transferencia + cargos, con
    // el supuesto de que una cuenta bancaria mueve plata unicamente por
    // transferencia. El extracto real del Banco San Juan 2016-2019 (10.414
    // movimientos) lo desmintio: tiene 404 depositos en efectivo por terminal,
    // 340 compras con tarjeta de debito, 166 extracciones por cajero y 94
    // liquidaciones de tarjeta de credito. Una cuenta bancaria mueve plata por
    // todos esos medios, asi que estan habilitados.
    //
    // Lo que sigue siendo exclusivo de billetera es 'qr' y 'rendimiento'.
    'banco' => [
        'transferencia', 'debito_auto', 'tarjeta_debito', 'tarjeta_credito',
        'efectivo', 'cheque', 'comision', 'impuesto', 'interes', 'ajuste', 'otro',
    ],
    'billetera' => [
        'transferencia', 'debito_auto', 'qr', 'tarjeta_debito', 'tarjeta_credito',
        'comision', 'impuesto', 'rendimiento', 'ajuste', 'otro',
    ],
    'efectivo' => ['efectivo', 'ajuste', 'otro'],
    'tarjeta'  => ['tarjeta_credito', 'comision', 'impuesto', 'interes', 'ajuste', 'otro'],
    'cripto'   => ['transferencia', 'comision', 'rendimiento', 'ajuste', 'otro'],
];

/** Tipos de cuenta admitidos (espeja el ENUM de datacount_bancos_cuentas.tipo). */
const DCB_TIPOS_CUENTA = ['banco', 'billetera', 'efectivo', 'tarjeta', 'cripto'];

/** Medios validos para un tipo de cuenta. Fallback: los de 'banco'. */
function dcbMediosValidos(string $tipo): array {
    return DCB_MEDIOS_POR_TIPO[$tipo] ?? DCB_MEDIOS_POR_TIPO['banco'];
}

/**
 * Deriva la huella definitiva agregando un indice de ocurrencia.
 *
 * POR QUE HACE FALTA
 * ------------------
 * Un extracto puede traer el MISMO movimiento repetido y ser correcto. En el
 * export real del Banco San Juan hay 7 casos sobre 969 filas: el 30/04 entran
 * tres acreditaciones de "CA FV LOTE HOGAR" de 200,00 con el mismo
 * comprobante, cada una con su reversa de impuesto de 1,20; y el 04/02 y el
 * 06/01 el impuesto de 1,20 aparece dos veces por comprobante (uno por ACCION
 * SOCIAL y otro por LOTE HOGAR). Son movimientos distintos que coinciden en
 * fecha, tipo, importe, referencia y descripcion — todo lo que entra en la
 * huella.
 *
 * Sin indice de ocurrencia, el UNIQUE (cuenta_id, huella) los tomaba por
 * duplicados y el importador descartaba 7 movimientos reales en silencio.
 *
 * La ocurrencia 1 conserva la huella base a proposito: asi las filas ya
 * importadas antes de este cambio siguen matcheando y una reimportacion no
 * duplica nada.
 *
 * Es estable entre exports distintos del mismo periodo: si los dos archivos
 * traen las tres acreditaciones del 30/04, en los dos son las ocurrencias
 * 1, 2 y 3.
 */
function dcbHuellaOcurrencia(string $base, int $ocurrencia): string {
    return $ocurrencia <= 1 ? $base : hash('sha256', $base . '#' . $ocurrencia);
}

/** Medios validos para una cuenta concreta, resolviendo su tipo en la BD. */
function dcbMediosDeCuenta(PDO $pdo, int $cuentaId): array {
    $st = $pdo->prepare('SELECT tipo FROM datacount_bancos_cuentas WHERE id = :id LIMIT 1');
    $st->execute([':id' => $cuentaId]);
    return dcbMediosValidos((string) ($st->fetchColumn() ?: 'banco'));
}
