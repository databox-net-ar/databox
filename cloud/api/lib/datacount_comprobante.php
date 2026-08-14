<?php
/**
 * api/lib/datacount_comprobante.php
 *
 * Forma canonica del numero de comprobante de una orden de pago, y la clave con
 * la que se decide si dos comprobantes son el mismo.
 *
 * Vive en una lib porque lo usan dos endpoints y TIENEN que coincidir:
 *   - datacount_pagos_openai_extraer.php normaliza lo que devuelve la IA, para
 *     que el modal muestre exactamente el numero que se va a guardar;
 *   - datacount_pagos.php lo vuelve a aplicar al guardar (defensa en
 *     profundidad: por ahi entran tambien las cargas manuales) y lo usa para
 *     comparar contra lo que ya hay en la base.
 * Si la regla viviera duplicada en los dos archivos, cualquier divergencia
 * haria que el numero mostrado, el guardado y el comparado dejaran de ser el
 * mismo, y el control de duplicados empezaria a fallar en silencio.
 */

/**
 * Normaliza el numero de comprobante a la forma canonica `punto-numero`: los
 * bloques tal como los imprimio el emisor, unidos por un unico guion.
 *
 *   "  0280 01678510  "  -> "0280-01678510"
 *   "0280 - 01678510"    -> "0280-01678510"
 *   "0280/01678510"      -> "0280-01678510"
 *   "0003-00000879"      -> "0003-00000879"   (sin cambios)
 *
 * REGLA CENTRAL: los ceros a la izquierda NO se tocan, ni se sacan ni se
 * agregan. El relleno lo decide cada emisor y no esta normado, asi que el unico
 * valor correcto es el que figura impreso en el comprobante. Guardarlo textual
 * es ademas lo que hace confiable el control de duplicados: si alguien vuelve a
 * subir el mismo PDF, la IA lee otra vez los mismos ceros y el numero coincide
 * caracter por caracter.
 *
 * Lo unico que se elimina son los espacios y los caracteres que no forman parte
 * del numero (barras, puntos, guiones repetidos): cada corrida de esos pasa a
 * ser un guion. Letras y acentos se respetan — hay numeraciones alfanumericas
 * legitimas ("BC09EE63-1491") y hasta descriptivas ("Poliza004180067"), por eso
 * el corte es por `\p{L}\p{N}` y no por "todo lo que no sea digito".
 *
 * Los numeros cargados sin separador ("000500001234") quedan como un unico
 * bloque: no hay forma de saber donde terminaba el punto de venta y partirlos a
 * ciegas seria inventar datos.
 *
 * Es idempotente: aplicarla sobre un valor ya normalizado devuelve lo mismo.
 */
function dcpNormalizarNumero(?string $v): ?string {
    if ($v === null) return null;
    $bloques = preg_split('/[^\p{L}\p{N}]+/u', trim($v), -1, PREG_SPLIT_NO_EMPTY) ?: [];
    $s = implode('-', $bloques);
    return $s === '' ? null : $s;
}

/**
 * Clave de comparacion de un numero de comprobante. NO se guarda: solo sirve
 * para decidir si dos filas son el mismo comprobante.
 *
 * Es la forma canonica en mayusculas — es decir, el numero completo, CON sus
 * ceros. El relleno no se normaliza a proposito: el mismo comprobante leido dos
 * veces del mismo PDF trae siempre los mismos ceros, asi que la igualdad exacta
 * alcanza, y en cambio recortar ceros arriesga fusionar comprobantes distintos
 * de emisores que numeran con rellenos diferentes.
 *
 *   "0280 01678510" -> "0280-01678510"
 *   "0280/01678510" -> "0280-01678510"   (mismo comprobante)
 *   "0003-00000879" -> "0003-00000879"
 *   "3-879"         -> "3-879"           (distinto: no es el mismo numero)
 */
function dcpClaveComprobante(?string $numero): string {
    return strtoupper((string)dcpNormalizarNumero($numero));
}
