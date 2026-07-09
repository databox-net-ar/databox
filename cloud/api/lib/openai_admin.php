<?php
// api/lib/openai_admin.php
// Cliente compartido para consultar la Admin API de OpenAI usando la
// Admin API key guardada en `parametros.variable = 'openai_admin_api_key'`.
//
// Uso:
//   require_once __DIR__ . '/lib/openai_admin.php';
//   $key = openaiAdminKey($pdo);   // lanza RuntimeException si no esta configurada
//   $resp = openaiAdminGet('https://api.openai.com/v1/organization/projects?limit=100', $key);

/**
 * Lee la Admin API key desde la tabla `parametros`. Lanza si falta o esta vacia.
 */
function openaiAdminKey(PDO $pdo): string {
    $stmt = $pdo->prepare('SELECT valor FROM parametros WHERE variable = :v LIMIT 1');
    $stmt->execute([':v' => 'openai_admin_api_key']);
    $row = $stmt->fetch();
    $k = $row ? trim((string)$row['valor']) : '';
    if ($k === '') {
        throw new RuntimeException(
            'Falta el parametro `openai_admin_api_key`. Configuralo en Herramientas > Editor de parametros.'
        );
    }
    return $k;
}

/**
 * GET autenticado a la Admin API. Devuelve el JSON decodificado.
 * Lanza RuntimeException con mensaje descriptivo ante error.
 */
function openaiAdminGet(string $url, string $apiKey): array {
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 20);
    curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 5);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Authorization: Bearer ' . $apiKey,
        'Content-Type: application/json',
    ]);
    $body = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err  = curl_error($ch);
    curl_close($ch);

    if ($body === false) {
        throw new RuntimeException('cURL: ' . ($err ?: 'sin respuesta'));
    }
    $json = json_decode($body, true);
    if (!is_array($json)) {
        throw new RuntimeException('Respuesta invalida de OpenAI (HTTP ' . $code . ')');
    }
    if ($code < 200 || $code >= 300) {
        $msg = $json['error']['message'] ?? ('HTTP ' . $code);
        throw new RuntimeException('OpenAI: ' . $msg);
    }
    return $json;
}
