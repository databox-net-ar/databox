<?php
/**
 * Cliente S3 mínimo (GET / PUT / HEAD / DELETE / LIST) con firma AWS
 * Signature V4. Sin AWS SDK.
 *
 * Lee credenciales y bucket de las constantes AWS_ACCESS_KEY_ID,
 * AWS_SECRET_ACCESS_KEY, AWS_REGION, AWS_S3_BUCKET definidas por env.php
 * a partir del .env del entorno.
 *
 * Funciones públicas:
 *   s3_bucket_name(): string
 *   s3_public_url(string $key): string
 *   s3_put_object(string $key, string $body, string $contentType): array
 *   s3_get_object(string $key): array              // GET firmado
 *   s3_delete_object(string $key): array
 *   s3_head_public(string $key): array             // HEAD anónimo (incógnito)
 *   s3_list_objects(string $prefix = '', ?string $continuationToken = null, string $delimiter = ''): array
 */

require_once dirname(__DIR__, 3) . '/env.php';

foreach (['AWS_ACCESS_KEY_ID', 'AWS_SECRET_ACCESS_KEY', 'AWS_REGION', 'AWS_S3_BUCKET'] as $k) {
    if (!defined($k) || (string) constant($k) === '') {
        throw new RuntimeException('Falta configurar la constante ' . $k . ' en el .env.');
    }
}

function s3_bucket_name(): string {
    return AWS_S3_BUCKET;
}

function s3_region(): string {
    return AWS_REGION;
}

function s3_access_key(): string {
    return AWS_ACCESS_KEY_ID;
}

function s3_secret_key(): string {
    return AWS_SECRET_ACCESS_KEY;
}

function s3_public_url(string $key): string {
    $bucket = s3_bucket_name();
    $region = s3_region();
    $key    = ltrim($key, '/');
    // Endpoint directo de S3 (path-style). No depende de DNS del subdominio custom.
    return 'https://s3.' . $region . '.amazonaws.com/'
        . rawurlencode($bucket) . '/' . s3_uri_encode($key, true);
}

/**
 * URI-encode siguiendo AWS: cada segmento se codifica, preservando '/' si
 * $preserveSlashes = true.
 */
function s3_uri_encode(string $input, bool $preserveSlashes = true): string {
    if ($preserveSlashes) {
        $parts = explode('/', $input);
        $parts = array_map(fn($p) => rawurlencode($p), $parts);
        return implode('/', $parts);
    }
    return rawurlencode($input);
}

/**
 * Construye el canonical query string ordenado por nombre, con valores
 * URL-encoded (rawurlencode). Necesario para Sig V4.
 */
function s3_canonical_query(array $params): string {
    if (!$params) return '';
    ksort($params);
    $parts = [];
    foreach ($params as $k => $v) {
        $parts[] = rawurlencode((string)$k) . '=' . rawurlencode((string)$v);
    }
    return implode('&', $parts);
}

/**
 * Firma y ejecuta una petición S3. Devuelve:
 *   [ 'status' => int, 'headers' => string, 'body' => string ]
 */
function s3_request(string $method, string $key, string $body = '', array $extraHeaders = [], array $queryParams = []): array {
    $accessKey = s3_access_key();
    $secretKey = s3_secret_key();
    $bucket    = s3_bucket_name();
    $region    = s3_region();
    $key       = ltrim($key, '/');

    // Path-style: imprescindible cuando el bucket tiene puntos en el nombre.
    $host = 's3.' . $region . '.amazonaws.com';
    $canonicalUri = '/' . rawurlencode($bucket);
    if ($key !== '') {
        $canonicalUri .= '/' . s3_uri_encode($key, true);
    }
    $canonicalQuery = s3_canonical_query($queryParams);

    $now      = gmdate('Ymd\THis\Z');
    $dateStmp = gmdate('Ymd');
    $payloadHash = hash('sha256', $body);

    $headers = array_change_key_case(array_merge([
        'Host'                 => $host,
        'X-Amz-Content-Sha256' => $payloadHash,
        'X-Amz-Date'           => $now,
    ], $extraHeaders), CASE_LOWER);

    ksort($headers);
    $canonicalHeaders = '';
    $signedHeaders    = [];
    foreach ($headers as $name => $value) {
        $canonicalHeaders .= $name . ':' . trim($value) . "\n";
        $signedHeaders[]   = $name;
    }
    $signedHeadersStr = implode(';', $signedHeaders);

    $canonicalRequest = $method . "\n"
        . $canonicalUri . "\n"
        . $canonicalQuery . "\n"
        . $canonicalHeaders . "\n"
        . $signedHeadersStr . "\n"
        . $payloadHash;

    $scope = $dateStmp . '/' . $region . '/s3/aws4_request';
    $stringToSign = "AWS4-HMAC-SHA256\n" . $now . "\n" . $scope . "\n" . hash('sha256', $canonicalRequest);

    $kDate    = hash_hmac('sha256', $dateStmp, 'AWS4' . $secretKey, true);
    $kRegion  = hash_hmac('sha256', $region, $kDate, true);
    $kService = hash_hmac('sha256', 's3', $kRegion, true);
    $kSigning = hash_hmac('sha256', 'aws4_request', $kService, true);
    $signature = hash_hmac('sha256', $stringToSign, $kSigning);

    $authorization = 'AWS4-HMAC-SHA256 '
        . 'Credential=' . $accessKey . '/' . $scope . ', '
        . 'SignedHeaders=' . $signedHeadersStr . ', '
        . 'Signature=' . $signature;

    $curlHeaders = ['Authorization: ' . $authorization];
    foreach ($headers as $name => $value) {
        $curlHeaders[] = ucwords($name, '-') . ': ' . $value;
    }

    $url = 'https://' . $host . $canonicalUri;
    if ($canonicalQuery !== '') {
        $url .= '?' . $canonicalQuery;
    }

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_CUSTOMREQUEST  => $method,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER     => $curlHeaders,
        CURLOPT_HEADER         => true,
        CURLOPT_TIMEOUT        => 60,
    ]);
    if ($method === 'PUT') {
        curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
    }
    if ($method === 'HEAD') {
        curl_setopt($ch, CURLOPT_NOBODY, true);
    }

    $response = curl_exec($ch);
    if ($response === false) {
        $err = curl_error($ch);
        curl_close($ch);
        throw new RuntimeException('Error cURL S3: ' . $err);
    }
    $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $hdrLen = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
    curl_close($ch);

    return [
        'status'  => (int)$status,
        'headers' => substr($response, 0, $hdrLen),
        'body'    => substr($response, $hdrLen),
    ];
}

function s3_put_object(string $key, string $body, string $contentType): array {
    return s3_request('PUT', $key, $body, [
        'Content-Type' => $contentType,
    ]);
}

function s3_get_object(string $key): array {
    return s3_request('GET', $key);
}

function s3_delete_object(string $key): array {
    return s3_request('DELETE', $key);
}

/**
 * HEAD anónimo (sin firmar) al URL público del objeto. Simula un browser
 * en incógnito: si responde 200 el objeto es realmente público.
 */
function s3_head_public(string $key): array {
    $url = s3_public_url($key);

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_NOBODY         => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_TIMEOUT        => 15,
        CURLOPT_HTTPHEADER     => ['Cache-Control: no-cache'],
    ]);
    $ok        = curl_exec($ch);
    $status    = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $sizeHdr   = curl_getinfo($ch, CURLINFO_CONTENT_LENGTH_DOWNLOAD);
    $typeHdr   = curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
    $err       = $ok === false ? curl_error($ch) : '';
    curl_close($ch);

    return [
        'status'         => (int)$status,
        'content_length' => $sizeHdr >= 0 ? (int)$sizeHdr : null,
        'content_type'   => $typeHdr ?: null,
        'url'            => $url,
        'error'          => $err,
    ];
}

/**
 * Lista objetos del bucket con un prefix. Maneja paginación devolviendo
 * el continuation token si hay más resultados.
 */
function s3_list_objects(string $prefix = '', ?string $continuationToken = null, string $delimiter = ''): array {
    $params = [
        'list-type' => '2',
    ];
    if ($prefix !== '') $params['prefix'] = $prefix;
    if ($delimiter !== '') $params['delimiter'] = $delimiter;
    if ($continuationToken !== null && $continuationToken !== '') {
        $params['continuation-token'] = $continuationToken;
    }

    $res = s3_request('GET', '', '', [], $params);

    if ($res['status'] < 200 || $res['status'] >= 300) {
        return [
            'ok'         => false,
            'status'     => $res['status'],
            'objects'    => [],
            'folders'    => [],
            'truncated'  => false,
            'next_token' => null,
            'error'      => 'S3 ListObjectsV2 HTTP ' . $res['status'],
            'detail'     => $res['body'],
        ];
    }

    $objects   = [];
    $folders   = [];
    $truncated = false;
    $nextToken = null;
    libxml_use_internal_errors(true);
    $xml = simplexml_load_string($res['body']);
    if ($xml !== false) {
        foreach ($xml->Contents as $c) {
            $objects[] = [
                'key'           => (string)$c->Key,
                'size'          => (int)$c->Size,
                'last_modified' => (string)$c->LastModified,
            ];
        }
        foreach ($xml->CommonPrefixes as $p) {
            $folders[] = (string)$p->Prefix;
        }
        $truncated = ((string)$xml->IsTruncated) === 'true';
        $nextToken = $truncated ? (string)$xml->NextContinuationToken : null;
    }

    return [
        'ok'         => true,
        'status'     => $res['status'],
        'objects'    => $objects,
        'folders'    => $folders,
        'truncated'  => $truncated,
        'next_token' => $nextToken,
    ];
}

/**
 * Helper: lista todos los objetos bajo un prefix paginando hasta el final.
 */
function s3_list_all_objects(string $prefix = ''): array {
    $all   = [];
    $token = null;
    do {
        $page = s3_list_objects($prefix, $token);
        if (!$page['ok']) {
            throw new RuntimeException(($page['error'] ?? 'Error listando S3') . ($page['detail'] ?? ''));
        }
        $all = array_merge($all, $page['objects']);
        $token = $page['next_token'];
    } while ($token !== null);
    return $all;
}
