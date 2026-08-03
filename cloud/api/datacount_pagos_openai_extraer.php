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
const DCPOAI_PROMPT = <<<'EOT'
Eres un experto en comprobantes fiscales argentinos. Analiza el archivo adjunto (factura, nota de venta o ticket) y extrae los datos en JSON.

DISTINCIÓN CRÍTICA — EMISOR vs. CLIENTE:
El comprobante siempre tiene dos partes con datos fiscales, y hay que ubicar cada una en su lugar:

  • EMISOR (quien vende, quien emitió el comprobante):
      - Aparece en el encabezado, generalmente con logotipo.
      - Su CUIT figura junto al NÚMERO de comprobante, la FECHA de emisión, la LETRA (A / B / C / M) y los datos fiscales propios del comprobante como "Ingresos Brutos", "IIBB" o "Inicio de Actividades".
      - Regla práctica: si un CUIT está en el mismo bloque visual que "Nº 0001-00000123" + fecha + letra A/B/C, ese CUIT es SIEMPRE del EMISOR.

  • CLIENTE (quien recibe el comprobante y paga):
      - Sus datos están en una sección aparte, precedida por etiquetas explícitas: "Cliente", "Cliente Nº", "Razón social", "Señor/es", "Facturar a", "Información del cliente", "Comprobantes asociados", etc.
      - Los datos del cliente NO tienen alrededor los datos fiscales del comprobante (nº, fecha, letra).

Los campos `empresa_*` corresponden SIEMPRE al EMISOR.
Los campos `cliente_*` corresponden SIEMPRE al CLIENTE.
Jamás intercambies los dos bloques.

FORMATO DE SALIDA — devolvé únicamente este JSON plano, sin Markdown, sin texto antes o después, sin comentarios. Todos los valores son string; usá "" cuando un dato no aparezca. Los importes van sin separador de miles y con punto como separador decimal.

{
  "factura_fecha": "fecha de emisión en formato YYYY-MM-DD",
  "factura_tipo": "letra del comprobante: A, B, C, M, E o \"\" si no tiene",
  "factura_numero": "prefijo-sufijo (ej: 0001-00032005)",
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

    $payload = [
        'model'       => DCPOAI_MODEL,
        'messages'    => [
            ['role' => 'system', 'content' => DCPOAI_PROMPT],
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

    jsonOk($extracted);
} catch (Throwable $e) {
    jsonError($e->getMessage(), 500);
}
