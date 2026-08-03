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

// Prompt copiado literal del legacy — cualquier cambio de campos hay que
// coordinarlo con lo que espera el frontend en la UI del modal Magia.
const DCPOAI_PROMPT = <<<'EOT'
Eres un experto en facturación electrónica. Examina el archivo adjunto y extrae los datos del ejemplo de la factura. Devuelve únicamente el JSON plano, sin ningún texto adicional antes o después, sin etiquetas Markdown ni explicaciones. El JSON debe ser de un solo nivel, incluir solamente los siguientes campos con exactamente estos nombres, y respetar los formatos especificados. Es importante quitar los separadores de miles y reemplazar la coma por punto como separador decimal.:
{
  "factura_fecha": "fecha de emision en formato YYYY-MM-DD",
  "factura_tipo": "tipo de factura (una letra A, B, C)",
  "factura_numero": "prefijo y sufijo",
  "empresa_razon": "razon social del empresa",
  "empresa_cuit": "cuit del empresa, sin guiones",
  "cliente_razon": "nombre del cliente",
  "cliente_cuit": "cuit del cliente, sin guiones",
  "concepto": "extracto del producto o servicio",
  "iva": "monto iva de la factura, con punto como separador decimal",
  "total": "monto total de la factura, con punto como separador decimal",
  "moneda": "devuelve P para pesos y D para dolares"
}
Devuelve solo el JSON, sin ningún tipo de envoltorio, explicación ni comentarios.
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

    $ch = curl_init('https://api.openai.com/v1/chat/completions');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_TIMEOUT        => DCPOAI_TIMEOUT,
        CURLOPT_HTTPHEADER     => [
            'Authorization: Bearer ' . OPENAI_API_KEY,
            'Content-Type: application/json',
        ],
        CURLOPT_POSTFIELDS     => json_encode($payload, JSON_UNESCAPED_UNICODE),
    ]);
    $response = curl_exec($ch);
    if ($response === false) {
        $err = curl_error($ch);
        curl_close($ch);
        jsonError('Error cURL contra OpenAI: ' . $err, 502);
    }
    $httpcode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
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
