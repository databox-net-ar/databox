<?php
// api/datacount_pagos_openai_extraer.php
// Extrae datos estructurados de un adjunto de orden de pago (factura) usando
// OpenAI (chat completions con vision — gpt-4o). Portado de
// databox_legacy/databox-api/v2/dataintel/facturaExtraer.php.
//
//   POST api/datacount_pagos_openai_extraer.php?adjunto=N
//
// Respuesta: {ok: true, data: { factura_fecha, factura_tipo, factura_numero,
//   empresa_razon, empresa_cuit, cliente_razon, cliente_cuit, concepto, iva,
//   total, moneda }} o {ok: false, error: '...'}.
//
// Permisos: `datacount.pagos.editar` — solo un editor de pagos puede pedir
// extraccion sobre uno de sus adjuntos.
//
// Notas:
//  - El binario se baja de S3 en el server (no confia URLs del cliente).
//  - `gpt-4o` soporta tanto imagenes (image_url) como PDFs (type: file con
//    file_data en base64). Otros formatos se rechazan con 400.
//  - Timeout de 90s en el curl porque OpenAI a veces tarda con PDFs largos.

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/lib/auth_check.php';
require_once __DIR__ . '/lib/s3.php';
require_once __DIR__ . '/lib/datacount_comprobante.php';

const DCPOAI_S3_PREFIX = 'datacount/pagos/';
const DCPOAI_MODEL     = 'gpt-4o';
const DCPOAI_TIMEOUT   = 90;

// Prompt para gpt-4o. Aprende de la experiencia con el legacy:
//   - El modelo se confundia entre EMISOR (quien vende / emite) y CLIENTE
//     (quien recibe y paga). Cargaba los datos del cliente en `empresa_*` y
//     viceversa, sobre todo en tickets fiscales chicos donde ambos aparecen
//     apilados. Para evitarlo aca damos una regla operativa que funciona en
//     TODO comprobante fiscal argentino (factura, nota de venta, ticket):
//     el CUIT del EMISOR es el que esta en el mismo bloque que el numero de
//     comprobante, la fecha de emision y la letra A/B/C — porque AFIP lo
//     exige asi. El CUIT del cliente, en cambio, aparece en una seccion
//     aparte con etiquetas explicitas ("Cliente:", "Señor/es:", "Razón
//     social:", "Facturar a:", "Información del cliente", etc.).
//   - Cualquier cambio de campos hay que coordinarlo con lo que espera el
//     frontend en el modal Magia (DCP_MAGIA_CAMPOS en app.js).
//   - 2026-08-14: las ordenes de pago salian a nombre de nuestras propias
//     empresas. La extraccion estaba bien; el bug estaba en el mapeo del
//     front, que cargaba `cliente_*` en razon/cuit de la orden. Ademas de
//     corregirlo, se reforzo el prompt con la regla de agrupacion y con la
//     lista de CUITs propios (dcpoaiBloqueEmpresasPropias), y se agrego el
//     chequeo determinista dcpoaiCorregirBloquesInvertidos() por si el
//     modelo igual invierte emisor y cliente.
//   - 2026-08-14 (bis): `factura_tipo` no llegaba al select "Tipo" del modal.
//     Dos causas encadenadas: (a) el modelo buscaba la palabra "Factura" y
//     no la letra suelta del recuadro central que manda el formato ARCA, y
//     (b) devolvia la letra ("A") cuando el select espera el codigo del
//     catalogo ("FA"), asi que la opcion nunca matcheaba y el campo quedaba
//     vacio. Se reescribio la seccion del tipo en el prompt, se inyecta el
//     catalogo real (dcpoaiBloqueTiposComprobante) y se normaliza la
//     respuesta con dcpoaiNormalizarTipo().
const DCPOAI_PROMPT = <<<'EOT'
Eres un experto en comprobantes fiscales argentinos. Analiza el archivo adjunto (factura, nota de venta o ticket) y extrae los datos en JSON.

CONTEXTO — para qué se usa esto:
El comprobante es una factura que NOS EMITIERON A NOSOTROS. Con estos datos se da de alta una ORDEN DE PAGO, y una orden de pago registra SIEMPRE a quién le tenemos que pagar, es decir al EMISOR de la factura (el proveedor). Nuestros propios datos no aportan nada: ya sabemos quiénes somos.

DISTINCIÓN CRÍTICA — EMISOR vs. CLIENTE:
El comprobante siempre tiene dos partes con datos fiscales, y hay que ubicar cada una en su lugar:

  • EMISOR (quien vende, quien emitió el comprobante — el PROVEEDOR):
      - Aparece en el encabezado, generalmente con logotipo.
      - Su CUIT figura junto al NÚMERO de comprobante, la FECHA de emisión, la LETRA (A / B / C / M) y los datos fiscales propios del comprobante como "Ingresos Brutos", "IIBB" o "Inicio de Actividades".
      - Regla práctica: si un CUIT está en el mismo bloque visual que "Nº 0001-00000123" + fecha + letra A/B/C, ese CUIT es SIEMPRE del EMISOR.

  • CLIENTE (quien recibe el comprobante y paga — NOSOTROS):
      - Sus datos están en una sección aparte, precedida por etiquetas explícitas: "Cliente", "Cliente Nº", "Razón social", "Señor/es", "Facturar a", "Información del cliente", "Comprobantes asociados", etc.
      - Los datos del cliente NO tienen alrededor los datos fiscales del comprobante (nº, fecha, letra).

REGLA DE AGRUPACIÓN (vale para cualquier diseño de factura):
La ley permite formatos muy variados y cada factura ubica los bloques donde quiere, así que NO te guíes por la posición en la hoja. Guiate por la AGRUPACIÓN: la razón social, el CUIT y el domicilio de una misma parte están siempre juntos, formando un bloque. Hay dos bloques de ese tipo — uno es el del emisor y el otro es el del cliente. Nunca mezcles un dato de un bloque con un dato del otro: si tomaste la razón social de un bloque, el CUIT tiene que salir de ESE MISMO bloque.

REGLA DE DESCARTE POR CUIT PROPIO (la más importante — es una verificación obligatoria):
Más abajo tenés la lista de NUESTRAS empresas. Nosotros somos siempre el CLIENTE, nunca el emisor.
Antes de responder, revisá el CUIT que pusiste en `empresa_cuit`:
  - Si ese CUIT (o esa razón social) coincide con una de nuestras empresas, entonces te equivocaste de bloque: leíste el bloque del cliente creyendo que era el del emisor.
  - En ese caso, volvé a mirar el comprobante, buscá el OTRO bloque de razón social + CUIT + domicilio, y ESE es el emisor. Poné ese en `empresa_*` y el nuestro en `cliente_*`.
`empresa_cuit` NUNCA puede ser el CUIT de una de nuestras empresas.

Los campos `empresa_*` corresponden SIEMPRE al EMISOR (el proveedor que nos factura).
Los campos `cliente_*` corresponden SIEMPRE al CLIENTE (una de nuestras empresas).
Jamás intercambies los dos bloques.

CÓMO DETERMINAR EL TIPO DE COMPROBANTE (campo `factura_tipo`):
Este es el dato que más se falla, así que seguí estos pasos en orden y no te saltees ninguno.
El error típico es buscar la palabra "Factura" o "Nota de crédito" y leer la letra que esté al lado. Eso NO funciona: en el formato estándar la letra está lejos de esa palabra, y muchas veces la palabra directamente no está.

PASO 1 — Buscá el RECUADRO DE LA LETRA, no la palabra.
El formato que exige ARCA/AFIP imprime la letra fiscal SOLA, dentro de un recuadro cuadrado, centrado en el borde superior de la hoja, montado sobre la línea que separa el bloque del emisor (izquierda, con el logo) del bloque del comprobante (derecha, con el número y la fecha). Dentro del recuadro hay una letra grande —A, B, C, M, E o X— y debajo, en tipografía chica, "Cód. Nº 01" / "Código Nº 01" / "COD. 01".
Esa letra suelta del recuadro central ES el tipo del comprobante, aunque la palabra "Factura" esté del otro lado de la hoja y aunque no aparezca en ningún lado.

PASO 2 — Si ves el CÓDIGO NUMÉRICO, ese manda.
El número que acompaña a la letra ("Cód. Nº NN") identifica el comprobante sin ambigüedad y tiene PRIORIDAD sobre cualquier otra lectura:
  Letra A → 01 (factura) · 04 (recibo) · 05 (nota de venta al contado) · 81 y 111 (tique factura) · 201 (FCE MiPyME)
  Letra B → 06 (factura) · 09 (recibo) · 10 (nota de venta al contado) · 82 y 118 (tique factura) · 206 (FCE MiPyME)
  Letra C → 11 (factura) · 15 (recibo) · 16 (nota de venta al contado) · 211 (FCE MiPyME)
  Letra M → 51 (factura)          Letra E → 19 (factura de exportación)
  Notas de crédito y de débito (tratamiento aparte, ver la lista del final): 02 y 03 (A) · 07 y 08 (B) · 12 y 13 (C) · 20 y 21 (E) · 52 y 53 (M)

PASO 3 — La CLASE del documento casi nunca cambia el resultado: lo que decide es la LETRA.
"Factura", "Nota de Venta al Contado", "Tique Factura", "Recibo", "Liquidación", "Comprobante de Venta" son todos comprobantes fiscales corrientes y se cargan según su letra: una "Nota de Venta al Contado A" es una A, exactamente igual que una factura A.
La ÚNICA excepción son las NOTAS DE CRÉDITO y las NOTAS DE DÉBITO, que tienen tratamiento aparte (ver la lista del final).
Y no confundas un comprobante fiscal con letra con uno no fiscal: "no fiscal" es únicamente el que lleva letra X o dice "Documento no válido como factura", "Presupuesto", "Remito" u "Orden de compra". Si tiene letra A, B o C en el recuadro, es fiscal — no importa cómo se llame el documento.

PASO 4 — Diseños que no siguen el formato estándar.
  • Tickets de controlador fiscal: imprimen todo junto en un renglón — "FACTURA B", "TIQUE FACTURA B", "TICKET FACTURA A", "COMPROBANTE C". Ahí sí la letra está pegada a la palabra y alcanza con leer ese renglón.
  • Facturas que ponen la letra en el encabezado sin recuadro, o escrita como "TIPO: A", "CLASE A", "COMPROBANTE CLASE A".
  • Comprobantes del exterior (Amazon, Google, Microsoft, OpenAI, proveedores extranjeros): no tienen letra ni CUIT de emisor argentino. Dicen "Invoice", "Tax Invoice", "Receipt", suelen estar en inglés y expresados en USD o EUR.
  • Comprobantes no fiscales: llevan letra X o la leyenda "Documento no válido como factura", "Presupuesto", "Remito", "Orden de compra".
  • VEP / Volante Electrónico de Pago / boletas de pago de ARCA-AFIP: no tienen letra ni recuadro; dicen "VEP", "Volante Electrónico de Pago" o "Volante de pago".

PASO 5 — Último recurso, sólo si no encontraste ni letra ni código.
Si el IVA está discriminado en un renglón aparte con su alícuota, el comprobante es A. Si los precios son finales con el IVA incluido y no hay renglón de IVA, es B o C. Usá esto únicamente como desempate: nunca por encima de los pasos 1 y 2.

PASO 6 — Traducí lo que encontraste al código del catálogo.
La lista de códigos admitidos está más abajo, en "TIPOS DE COMPROBANTE". Devolvé el código exacto de esa lista, nunca la letra suelta ni el texto. Si ninguno aplica o no estás seguro, devolvé "" — un campo vacío se completa a mano en dos segundos, un tipo equivocado se guarda mal y nadie lo nota.

FORMATO DE SALIDA — devolvé únicamente este JSON plano, sin Markdown, sin texto antes o después, sin comentarios. Todos los valores son string; usá "" cuando un dato no aparezca. Los importes van sin separador de miles y con punto como separador decimal.

{
  "factura_fecha": "fecha de emisión en formato YYYY-MM-DD",
  "factura_tipo": "código del catálogo de TIPOS DE COMPROBANTE (más abajo), o \"\" si ninguno aplica",
  "factura_numero": "punto de venta y número, separados por un guión (ej: 0001-00032005). COPIALO TAL CUAL ESTÁ IMPRESO, con todos los ceros a la izquierda que tenga cada bloque: si dice 0003-00000879 va \"0003-00000879\", nunca \"3-879\" ni \"0003-879\". El relleno lo elige cada emisor y forma parte del número; recortarlo hace que el mismo comprobante parezca otro. Si el comprobante no separa los dos bloques, devolvelo entero sin inventar el corte",
  "empresa_razon": "razón social del EMISOR",
  "empresa_cuit": "CUIT del EMISOR, solo dígitos, sin guiones",
  "cliente_razon": "razón social del CLIENTE",
  "cliente_cuit": "CUIT del CLIENTE, solo dígitos, sin guiones",
  "concepto": "producto o servicio principal (breve)",
  "iva": "monto IVA (decimal con punto)",
  "total": "monto total final (decimal con punto)",
  "moneda": "P para pesos, D para dólares"
}

Devolvé SOLO el JSON.
EOT;

// Bloque que se le agrega al prompt con las empresas propias del grupo — las
// mismas de `datacount_empresas` que alimentan los talonarios. Es lo que hace
// aplicable la REGLA DE DESCARTE POR CUIT PROPIO: sin la lista, el modelo no
// tiene forma de saber cuál de los dos bloques de la factura somos nosotros.
// Se arma en runtime (y no como const) para que dar de alta una empresa nueva
// alcance para que el prompt la contemple, sin tocar codigo.
function dcpoaiBloqueEmpresasPropias(PDO $pdo): string {
    $filas = $pdo->query("
        SELECT nombre, razon, cuit
          FROM datacount_empresas
         WHERE cuit IS NOT NULL AND TRIM(cuit) <> ''
         ORDER BY nombre
    ")->fetchAll();

    if (!$filas) {
        // Sin CUITs cargados la regla no se puede evaluar. Lo decimos
        // explicitamente en vez de mandar una lista vacia, que el modelo
        // podria leer como "ninguna coincidencia posible".
        return "\n\nNUESTRAS EMPRESAS:\nNo hay datos cargados. Aplicá igual el resto de las reglas.\n";
    }

    $items = '';
    foreach ($filas as $f) {
        $cuit  = preg_replace('/\D+/', '', (string)$f['cuit']);
        $razon = trim((string)($f['razon'] ?: $f['nombre']));
        $items .= "  - {$razon} (CUIT {$cuit})\n";
    }

    return "\n\nNUESTRAS EMPRESAS (somos SIEMPRE el cliente, NUNCA el emisor):\n"
         . $items
         . "Si el CUIT o la razón social que ibas a poner en `empresa_*` está en esta lista, "
         . "estás leyendo el bloque equivocado: ese es el bloque del cliente. "
         . "Buscá el otro bloque de razón social + CUIT + domicilio y usá ese como emisor.\n"
         . "Tené en cuenta que la razón social puede venir escrita con variantes de puntuación "
         . "o espaciado (por ejemplo \"ALFATEC S.R.L.\", \"ALFATEC S. R. L.\" y \"ALFATEC SRL\" "
         . "son la misma empresa): compará ignorando puntos, espacios y mayúsculas.\n";
}

// Catalogo real de tipos de comprobante: los mismos valores que ofrece el
// select "Tipo" del modal de la orden de pago (`estados.campo =
// 'datacount_pago_tipo'`). Devuelve [codigo => texto], ej. ['FA' => 'Factura A'].
function dcpoaiCatalogoTipos(PDO $pdo): array {
    $filas = $pdo->query("
        SELECT valor, texto
          FROM estados
         WHERE campo = 'datacount_pago_tipo'
         ORDER BY orden, id
    ")->fetchAll();

    $out = [];
    foreach ($filas as $f) {
        $valor = strtoupper(trim((string)$f['valor']));
        if ($valor === '') continue;
        $out[$valor] = trim((string)$f['texto']);
    }
    return $out;
}

// Bloque que se le agrega al prompt con el catalogo de tipos. Se arma en
// runtime (igual que las empresas propias) para que agregar o sacar un tipo
// desde Herramientas > Editor de estados alcance, sin tocar codigo.
//
// Es lo que cierra el circuito con el select del modal: el modelo devuelve
// directamente el CODIGO (`FA`) en vez de la letra (`A`), que es lo unico que
// el `<select>` puede seleccionar.
function dcpoaiBloqueTiposComprobante(array $catalogo): string {
    if (!$catalogo) {
        return "\n\nTIPOS DE COMPROBANTE:\nNo hay catálogo cargado. Devolvé \"\" en `factura_tipo`.\n";
    }

    $lista = '';
    foreach ($catalogo as $codigo => $texto) {
        $lista .= "  - {$codigo} = {$texto}\n";
    }

    // Guia de traduccion comprobante -> codigo. Solo se emiten las lineas
    // cuyo codigo existe hoy en el catalogo, asi el prompt nunca ofrece una
    // opcion que el select no tiene.
    $guia = [
        'FA' => 'letra A (códigos AFIP 01, 81, 111, 201)',
        'FB' => 'letra B (códigos AFIP 06, 82, 118, 206)',
        'FC' => 'letra C (códigos AFIP 11, 211)',
        'FX' => 'letra X, o comprobante no fiscal ("documento no válido como factura", presupuesto, remito, orden de compra) — nunca uno que tenga letra A, B o C',
        'FI' => 'comprobante del exterior, sin letra y sin CUIT de emisor argentino (Invoice / Tax Invoice, en inglés y/o en USD o EUR)',
        'VP' => 'VEP / Volante Electrónico de Pago / boleta de pago de ARCA-AFIP',
    ];
    $guiaTxt = '';
    foreach ($guia as $codigo => $desc) {
        if (isset($catalogo[$codigo])) $guiaTxt .= "  - {$desc} → {$codigo}\n";
    }

    return "\n\nTIPOS DE COMPROBANTE — únicos valores admitidos en `factura_tipo`:\n"
         . $lista
         . "\nCómo traducir lo que leíste en el comprobante a uno de esos códigos:\n"
         . $guiaTxt
         . "\nDevolvé el CÓDIGO (lo que está antes del \"=\"), no el texto ni la letra suelta: "
         . "para una factura A la respuesta correcta es \"FA\" — ni \"A\", ni \"Factura A\", ni \"01\".\n"
         . "Si el comprobante es una NOTA DE CRÉDITO o una NOTA DE DÉBITO, devolvé \"\": el catálogo "
         . "no las contempla y mapearlas a la factura de la misma letra sería un error.\n"
         . "Si no podés determinarlo con seguridad, devolvé \"\" en vez de adivinar.\n";
}

// Red de seguridad determinista para `factura_tipo`, en la misma linea que
// dcpoaiCorregirBloquesInvertidos(): pedirselo al prompt no alcanza.
//
// El modelo puede devolver la letra suelta ("A"), el texto ("Factura A"), el
// codigo AFIP ("01") o el codigo del catalogo ("FA"). El `<select>` del modal
// solo acepta lo ultimo — cualquier otra cosa lo deja en blanco y el operador
// tiene que completarlo a mano (el sintoma que se reporto el 2026-08-14).
//
// Devuelve siempre un codigo presente en $codigosValidos, o '' cuando no hay
// forma de decidir sin inventar.
function dcpoaiNormalizarTipo(string $bruto, array $codigosValidos): string {
    // Normalizamos acentos a mano, en ambas cajas y ANTES del strtoupper:
    // strtoupper() es byte-a-byte y no toca los acentuados UTF-8, asi que
    // "válido" quedaria como "VáLIDO" y no matchearia "NO VALIDO".
    $t = strtr(trim($bruto), [
        'á' => 'A', 'é' => 'E', 'í' => 'I', 'ó' => 'O', 'ú' => 'U', 'ñ' => 'N',
        'Á' => 'A', 'É' => 'E', 'Í' => 'I', 'Ó' => 'O', 'Ú' => 'U', 'Ñ' => 'N',
    ]);
    $t = strtoupper($t);
    if ($t === '') return '';

    // Solo devolvemos codigos que el select realmente ofrece.
    $ok = static fn (string $c): string => in_array($c, $codigosValidos, true) ? $c : '';

    // 1) Ya vino el codigo del catalogo.
    if (in_array($t, $codigosValidos, true)) return $t;

    // 2) Notas de credito / debito: el catalogo no las tiene. Cortamos aca
    //    para que el paso de la letra no las convierta en factura.
    //    Ojo: NO alcanza con buscar "NOTA" — una "Nota de Venta al Contado A"
    //    es un comprobante fiscal A comun y corriente (caso real: las duty
    //    invoices de DHL, codigo AFIP 05).
    if (preg_match('/\bN[CD]\b|CREDITO|DEBITO/', $t)) return '';

    // 3) Familias que se reconocen por palabra, no por letra.
    if (str_contains($t, 'VEP') || str_contains($t, 'VOLANTE'))       return $ok('VP');
    if (str_contains($t, 'INVOICE') || str_contains($t, 'INTERNACIONAL')
        || str_contains($t, 'EXTERIOR'))                              return $ok('FI');
    if (str_contains($t, 'PRESUPUESTO') || str_contains($t, 'REMITO')
        || str_contains($t, 'NO VALIDO'))                             return $ok('FX');

    $porLetra      = ['A' => 'FA', 'B' => 'FB', 'C' => 'FC', 'X' => 'FX'];
    $porCodigoAfip = [
        1  => 'FA', 4  => 'FA', 5  => 'FA', 81  => 'FA', 111 => 'FA', 201 => 'FA',
        6  => 'FB', 9  => 'FB', 10 => 'FB', 82  => 'FB', 118 => 'FB', 206 => 'FB',
        11 => 'FC', 15 => 'FC', 16 => 'FC', 211 => 'FC',
    ];

    // 4) Letra suelta. En vez de ir borrando las palabras que suelen
    //    acompanarla (lista siempre incompleta), buscamos tokens de UNA sola
    //    letra que ademas sean una letra fiscal valida: eso resuelve "A",
    //    "Factura A", "TIQUE FACTURA B", "TIPO: A" y "Nota de Venta Contado A"
    //    con la misma regla. Si aparece mas de una letra candidata es
    //    ambiguo y seguimos de largo en vez de elegir al azar.
    $tokens = preg_split('/[^A-Z]+/', $t, -1, PREG_SPLIT_NO_EMPTY) ?: [];
    $letras = array_values(array_unique(array_filter(
        $tokens,
        static fn (string $tk): bool => strlen($tk) === 1 && str_contains('ABCMEX', $tk)
    )));
    if (count($letras) === 1 && isset($porLetra[$letras[0]])) {
        $codigo = $ok($porLetra[$letras[0]]);
        if ($codigo !== '') return $codigo;
    }

    // 5) Codigo AFIP. Exigimos que el valor sea SOLO digitos (hasta 3) para no
    //    confundirlo con un numero de comprobante que se haya colado.
    $soloDigitos = preg_replace('/\D+/', '', $t);
    if ($soloDigitos !== '' && strlen($soloDigitos) <= 3) {
        $n = (int)$soloDigitos;
        if (isset($porCodigoAfip[$n])) return $ok($porCodigoAfip[$n]);
    }

    return '';
}

// Red de seguridad determinista, por si el modelo igual invierte los bloques.
// La regla del usuario es binaria y no depende de criterio: nosotros nunca
// emitimos estas facturas. Entonces, si `empresa_cuit` es de una empresa
// nuestra y `cliente_cuit` no lo es, los bloques vinieron al reves y se
// intercambian. Si ambos son nuestros (factura entre empresas del grupo — caso
// legitimo, ahi si emitimos nosotros) o ninguno lo es, no se toca nada: no hay
// forma de decidir sin inventar.
//
// Caso borde deliberado: si el emisor es nuestro y el cliente vino vacio, el
// intercambio deja `empresa_*` vacio. Es intencional y preferible a la
// alternativa: un `empresa_*` vacio hace fallar la validacion de campos
// obligatorios del modal y obliga a completarlo a mano, mientras que dejar
// nuestra propia empresa guardaria el dato equivocado sin que nadie lo note
// (exactamente el bug que se reporto el 2026-08-14).
function dcpoaiCorregirBloquesInvertidos(array $ex, array $cuitsPropios): array {
    $soloDigitos = static fn ($v) => preg_replace('/\D+/', '', (string)$v);
    $emisor  = $soloDigitos($ex['empresa_cuit'] ?? '');
    $cliente = $soloDigitos($ex['cliente_cuit'] ?? '');

    $emisorEsPropio  = $emisor  !== '' && in_array($emisor,  $cuitsPropios, true);
    $clienteEsPropio = $cliente !== '' && in_array($cliente, $cuitsPropios, true);

    if ($emisorEsPropio && !$clienteEsPropio) {
        [$ex['empresa_razon'], $ex['cliente_razon']] =
            [$ex['cliente_razon'] ?? '', $ex['empresa_razon'] ?? ''];
        [$ex['empresa_cuit'],  $ex['cliente_cuit']]  =
            [$ex['cliente_cuit']  ?? '', $ex['empresa_cuit']  ?? ''];
    }
    return $ex;
}

header('Content-Type: application/json; charset=utf-8');

try {
    requirePermission('datacount.pagos.editar');

    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
        jsonError('Metodo no soportado', 405);
    }
    if (!defined('OPENAI_API_KEY') || (string)OPENAI_API_KEY === '') {
        jsonError('Falta configurar OPENAI_API_KEY en el .env', 500);
    }

    $adjuntoId = isset($_GET['adjunto']) ? (int)$_GET['adjunto'] : 0;
    if ($adjuntoId <= 0) jsonError('Falta adjunto', 400);

    $pdo  = db();
    $stmt = $pdo->prepare(
        'SELECT id, pago, archivo, formato, nombre
           FROM datacount_pagos_adjuntos
          WHERE id = :id'
    );
    $stmt->execute([':id' => $adjuntoId]);
    $row = $stmt->fetch();
    if (!$row)             jsonError('Adjunto no encontrado', 404);
    if (empty($row['archivo'])) jsonError('El adjunto no tiene archivo asociado', 400);

    // Determinar el mime real via extension (mismo criterio que el legacy).
    $ext = strtolower(pathinfo($row['archivo'], PATHINFO_EXTENSION));
    if ($ext === '' && !empty($row['formato'])) {
        $ext = strtolower($row['formato']);
    }

    $key = DCPOAI_S3_PREFIX . $row['archivo'];
    $obj = s3_get_object($key);
    if ($obj['status'] < 200 || $obj['status'] >= 300) {
        jsonError('No se pudo leer el binario de S3 (HTTP ' . $obj['status'] . ')', 500);
    }
    $bytes = $obj['body'];
    if ($bytes === '' || strlen($bytes) < 100) {
        jsonError('El binario descargado esta vacio o es invalido', 500);
    }

    // Inicializado a array vacio para que el linter estatico vea que siempre
    // esta definido; en la practica la rama else llama a jsonError() que
    // termina el request antes de llegar al payload.
    $userContent = [];
    if (in_array($ext, ['jpg', 'jpeg', 'png', 'webp', 'gif'], true)) {
        $mime    = $ext === 'jpg' ? 'jpeg' : $ext;
        $dataUri = 'data:image/' . $mime . ';base64,' . base64_encode($bytes);
        $userContent = [
            ['type' => 'text', 'text' => 'Analiza la imagen adjunta y extrae todos los datos relevantes de la factura en JSON.'],
            ['type' => 'image_url', 'image_url' => ['url' => $dataUri]],
        ];
    } elseif ($ext === 'pdf') {
        $dataUri = 'data:application/pdf;base64,' . base64_encode($bytes);
        $userContent = [
            ['type' => 'text', 'text' => 'Analiza el PDF adjunto y extrae todos los datos relevantes de la factura en JSON.'],
            ['type' => 'file', 'file' => [
                'filename'  => $row['nombre'] ?: ('factura.' . $ext),
                'file_data' => $dataUri,
            ]],
        ];
    } else {
        jsonError('Formato no soportado (solo JPG, PNG, PDF): .' . $ext, 400);
    }

    // Se consulta una sola vez: alimenta el prompt (para que el modelo devuelva
    // un codigo valido) y despues la normalizacion de la respuesta.
    $catalogoTipos = dcpoaiCatalogoTipos($pdo);

    $payload = [
        'model'       => DCPOAI_MODEL,
        'messages'    => [
            ['role' => 'system', 'content' => DCPOAI_PROMPT
                . dcpoaiBloqueEmpresasPropias($pdo)
                . dcpoaiBloqueTiposComprobante($catalogoTipos)],
            ['role' => 'user',   'content' => $userContent],
        ],
        'max_tokens'  => 2048,
        'temperature' => 0.2,
    ];

    // constant() en vez de OPENAI_API_KEY directo: el analizador estatico
    // no ve las constantes definidas dinamicamente por env.php desde .env,
    // pero en runtime funciona igual y ya validamos defined() arriba.
    $ch = curl_init('https://api.openai.com/v1/chat/completions');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_TIMEOUT        => DCPOAI_TIMEOUT,
        CURLOPT_HTTPHEADER     => [
            'Authorization: Bearer ' . constant('OPENAI_API_KEY'),
            'Content-Type: application/json',
        ],
        CURLOPT_POSTFIELDS     => json_encode($payload, JSON_UNESCAPED_UNICODE),
    ]);
    $response = curl_exec($ch);
    // curl_close() es un no-op deprecado desde PHP 8.0 — el handle se libera
    // al salir de scope.
    if ($response === false) {
        jsonError('Error cURL contra OpenAI: ' . curl_error($ch), 502);
    }
    $httpcode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    if ($httpcode < 200 || $httpcode >= 300) {
        jsonError('OpenAI respondio HTTP ' . $httpcode . ': ' . $response, 502);
    }

    $data    = json_decode($response, true);
    $content = $data['choices'][0]['message']['content'] ?? '';
    if ($content === '') {
        jsonError('Respuesta vacia de OpenAI', 502);
    }

    // OpenAI a veces devuelve el JSON envuelto en ```json ... ```; lo
    // sanitizamos antes de intentar el decode.
    $content = trim($content);
    if (str_starts_with($content, '```')) {
        $content = preg_replace('/^```(?:json)?\s*/i', '', $content);
        $content = preg_replace('/\s*```\s*$/', '', $content);
    }

    $extracted = json_decode($content, true);
    if (!is_array($extracted)) {
        jsonError('OpenAI no devolvio JSON parseable: ' . $content, 502);
    }

    // Ultimo control antes de devolver: si el emisor resulto ser una empresa
    // nuestra, los bloques vinieron invertidos y se corrigen aca. No alcanza
    // con pedirselo al prompt — el modelo puede desobedecer y el dato termina
    // en la orden de pago.
    $cuitsPropios = array_values(array_filter(array_map(
        static fn ($c) => preg_replace('/\D+/', '', (string)$c),
        $pdo->query("SELECT cuit FROM datacount_empresas
                      WHERE cuit IS NOT NULL AND TRIM(cuit) <> ''")->fetchAll(PDO::FETCH_COLUMN)
    )));
    $extracted = dcpoaiCorregirBloquesInvertidos($extracted, $cuitsPropios);

    // El select "Tipo" del modal solo puede seleccionar un codigo del catalogo:
    // traducimos aca la letra / el texto / el codigo AFIP que haya devuelto el
    // modelo, y dejamos '' si no matchea nada en vez de mandar basura al form.
    $extracted['factura_tipo'] = dcpoaiNormalizarTipo(
        (string)($extracted['factura_tipo'] ?? ''),
        array_keys($catalogoTipos)
    );

    // El numero se devuelve YA en su forma canonica (espacios y separadores
    // raros -> un unico guion, ceros intactos). Asi el modal muestra
    // exactamente el string que se va a guardar y contra el que se va a
    // comparar el duplicado: si el modelo leyo "0280 01678510", el operador ve
    // "0280-01678510" y no un valor que despues cambia solo al guardar.
    // Misma funcion que aplica datacount_pagos.php — ver lib/datacount_comprobante.php.
    $extracted['factura_numero'] = dcpNormalizarNumero(
        (string)($extracted['factura_numero'] ?? '')
    ) ?? '';

    jsonOk($extracted);
} catch (Throwable $e) {
    jsonError($e->getMessage(), 500);
}
