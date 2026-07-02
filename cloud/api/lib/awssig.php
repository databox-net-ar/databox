<?php
/**
 * Firmador SigV4 generico para APIs AWS de tipo JSON-RPC.
 *
 * A diferencia de lib/s3.php (que lee credenciales de constantes del entorno
 * y sabe solo firmar contra S3), esta lib recibe las credenciales por
 * parametro: se usa para hablar con cuentas AWS de terceros (registros de
 * la tabla `awscuentas`).
 *
 * Solo cubre el caso "JSON RPC 1.0": POST /, body JSON, X-Amz-Target.
 * Es el patron de las APIs de billing/invoicing/ce/etc.
 */

/**
 * Firma y ejecuta un POST JSON contra un endpoint AWS.
 *
 * @param string $accessKey Access Key ID del IAM user o rol.
 * @param string $secretKey Secret Access Key.
 * @param string $region    Ej: 'us-east-1'.
 * @param string $service   Signing name (ej: 'invoicing', 'ce').
 * @param string $host      Ej: 'invoicing.us-east-1.amazonaws.com'.
 * @param string $target    Valor de X-Amz-Target (ej: 'Invoicing.ListInvoiceSummaries').
 * @param array  $payload   Body como array; se serializa a JSON.
 * @param string $jsonVer   '1.0' o '1.1' — determina el Content-Type.
 *
 * @return array { status:int, body:string, decoded:array|null }
 */
function aws_json_rpc(
    string $accessKey,
    string $secretKey,
    string $region,
    string $service,
    string $host,
    string $target,
    array  $payload,
    string $jsonVer = '1.0'
): array {
    $method       = 'POST';
    $canonicalUri = '/';
    $body         = $payload ? json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) : '{}';
    $ts           = time();
    $now          = gmdate('Ymd\THis\Z', $ts);
    $dateStmp     = gmdate('Ymd', $ts);
    $payloadHash  = hash('sha256', $body);
    $contentType  = 'application/x-amz-json-' . $jsonVer;

    // NOTA: los SDKs oficiales de AWS NO envian x-amz-content-sha256 para
    // servicios JSON-RPC (solo S3). Incluirlo hace que algunos servicios
    // devuelvan UnknownOperationException o InvalidSignatureException.
    $headers = [
        'content-type' => $contentType,
        'host'         => $host,
        'x-amz-date'   => $now,
        'x-amz-target' => $target,
    ];
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
        . "" . "\n"                  // query string vacio
        . $canonicalHeaders . "\n"
        . $signedHeadersStr . "\n"
        . $payloadHash;

    $scope        = $dateStmp . '/' . $region . '/' . $service . '/aws4_request';
    $stringToSign = "AWS4-HMAC-SHA256\n" . $now . "\n" . $scope . "\n" . hash('sha256', $canonicalRequest);

    $kDate    = hash_hmac('sha256', $dateStmp, 'AWS4' . $secretKey, true);
    $kRegion  = hash_hmac('sha256', $region,   $kDate, true);
    $kService = hash_hmac('sha256', $service,  $kRegion, true);
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

    $ch = curl_init('https://' . $host . $canonicalUri);
    curl_setopt_array($ch, [
        CURLOPT_CUSTOMREQUEST  => $method,
        CURLOPT_POSTFIELDS     => $body,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER     => $curlHeaders,
        CURLOPT_TIMEOUT        => 30,
    ]);
    $resp = curl_exec($ch);
    if ($resp === false) {
        $err = curl_error($ch);
        curl_close($ch);
        throw new RuntimeException('Error cURL AWS: ' . $err);
    }
    $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    $decoded = json_decode($resp, true);
    return [
        'status'  => $status,
        'body'    => $resp,
        'decoded' => is_array($decoded) ? $decoded : null,
    ];
}
