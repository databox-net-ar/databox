<?php
/**
 * Cliente minimo para la API Query de EC2 (SigV4, respuesta XML).
 *
 * A diferencia de lib/awssig.php:aws_json_rpc() y aws_rest_json() —que hablan
 * con servicios "modernos" JSON— EC2 sigue expuesta como "AWS Query API":
 *
 *   - POST https://ec2.<region>.amazonaws.com/
 *   - Content-Type: application/x-www-form-urlencoded
 *   - Body con `Action=<X>&Version=2016-11-15&...` parametros planos.
 *   - Respuesta XML (namespaced, ej: <DescribeInstancesResponse xmlns=...>).
 *
 * La firma SigV4 aplica igual: firmamos el body form-encoded como payload.
 *
 * Este helper solo cubre el subset que necesita el boton "Obtener" del ABM
 * de servidores AWS: DescribeRegions (para descubrir regiones habilitadas
 * por cuenta) y DescribeInstances (para listar las EC2 de cada region).
 */

/**
 * Ejecuta un POST firmado contra la Query API de EC2 en una region dada y
 * devuelve la respuesta parseada como SimpleXMLElement.
 *
 * @return array { status:int, xml:?SimpleXMLElement, error:?string }
 *   - status  HTTP code (200 si OK).
 *   - xml     SimpleXMLElement del body (o null si status != 200).
 *   - error   Mensaje AWS parseado del XML de error (o null si no hubo).
 */
function aws_ec2_query(
    string $accessKey,
    string $secretKey,
    string $region,
    string $action,
    array  $params = [],
    string $apiVersion = '2016-11-15'
): array {
    $host   = "ec2.{$region}.amazonaws.com";
    $method = 'POST';
    $uri    = '/';

    // Body form-encoded: Action + Version primero, resto de params despues.
    // AWS acepta orden arbitrario, pero por prolijidad los ponemos primero.
    $form = array_merge(['Action' => $action, 'Version' => $apiVersion], $params);
    $body = http_build_query($form, '', '&', PHP_QUERY_RFC3986);

    $ts          = time();
    $now         = gmdate('Ymd\THis\Z', $ts);
    $dateStmp    = gmdate('Ymd', $ts);
    $payloadHash = hash('sha256', $body);
    $contentType = 'application/x-www-form-urlencoded';

    $headers = [
        'content-type' => $contentType,
        'host'         => $host,
        'x-amz-date'   => $now,
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
        . $uri . "\n"
        . "" . "\n"                  // query string vacio (todo va en el body)
        . $canonicalHeaders . "\n"
        . $signedHeadersStr . "\n"
        . $payloadHash;

    $service      = 'ec2';
    $scope        = $dateStmp . '/' . $region . '/' . $service . '/aws4_request';
    $stringToSign = "AWS4-HMAC-SHA256\n" . $now . "\n" . $scope . "\n"
                  . hash('sha256', $canonicalRequest);

    $kDate    = hash_hmac('sha256', $dateStmp,     'AWS4' . $secretKey, true);
    $kRegion  = hash_hmac('sha256', $region,       $kDate,   true);
    $kService = hash_hmac('sha256', $service,      $kRegion, true);
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

    $ch = curl_init('https://' . $host . $uri);
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
        return ['status' => 0, 'xml' => null, 'error' => 'Error cURL: ' . $err];
    }
    $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    // SimpleXML no digiere bien los defaults de namespace; para que XPath y
    // el acceso ->Tag funcionen, borramos el xmlns default del root antes
    // de parsear (workaround estandar para respuestas AWS Query API).
    $clean = preg_replace('/\sxmlns="[^"]+"/', '', $resp, 1);

    libxml_use_internal_errors(true);
    $xml = simplexml_load_string($clean ?: '');
    libxml_clear_errors();

    if ($status >= 400 || $xml === false) {
        // Parsear el mensaje de error de AWS si vino en el XML.
        // Formato tipico:
        //   <Response><Errors><Error><Code>X</Code><Message>Y</Message>...
        //   o <ErrorResponse><Error><Code>...
        $msg = null;
        if ($xml !== false) {
            if (isset($xml->Errors->Error->Message)) {
                $msg = (string)$xml->Errors->Error->Code . ': '
                     . (string)$xml->Errors->Error->Message;
            } elseif (isset($xml->Error->Message)) {
                $msg = (string)$xml->Error->Code . ': '
                     . (string)$xml->Error->Message;
            }
        }
        if ($msg === null) {
            $snippet = trim(substr((string)$resp, 0, 200));
            $msg = "HTTP {$status}" . ($snippet !== '' ? " — {$snippet}" : '');
        }
        return ['status' => $status, 'xml' => null, 'error' => $msg];
    }

    return ['status' => $status, 'xml' => $xml, 'error' => null];
}

/**
 * Devuelve las regiones habilitadas para la cuenta (opt-in incluidas si el
 * IAM user las tiene habilitadas). Fallback a una lista fija si la llamada
 * falla (por ejemplo, permisos IAM sin ec2:DescribeRegions).
 *
 * @return array{ regiones: string[], error: ?string }
 */
function aws_ec2_describe_regions(string $accessKey, string $secretKey): array {
    $res = aws_ec2_query($accessKey, $secretKey, 'us-east-1', 'DescribeRegions');
    if ($res['error'] !== null || $res['xml'] === null) {
        return ['regiones' => [], 'error' => $res['error'] ?? 'Respuesta vacia'];
    }
    $out = [];
    foreach ($res['xml']->regionInfo->item ?? [] as $item) {
        $name = (string)$item->regionName;
        if ($name !== '') $out[] = $name;
    }
    sort($out);
    return ['regiones' => $out, 'error' => null];
}

/**
 * Lista todas las instancias EC2 de una region para la cuenta dada, paginando
 * automaticamente con NextToken. Devuelve un array asociativo por instancia
 * con los campos que nos interesan para el catalogo aws_servidores.
 *
 * @return array{ items: array<int,array>, error: ?string }
 */
function aws_ec2_describe_instances(
    string $accessKey,
    string $secretKey,
    string $region
): array {
    $items = [];
    $nextToken = null;
    do {
        $params = ['MaxResults' => '1000'];
        if ($nextToken !== null && $nextToken !== '') {
            $params['NextToken'] = $nextToken;
        }
        $res = aws_ec2_query($accessKey, $secretKey, $region, 'DescribeInstances', $params);
        if ($res['error'] !== null || $res['xml'] === null) {
            return ['items' => $items, 'error' => $res['error'] ?? 'Respuesta vacia'];
        }

        $reservationSet = $res['xml']->reservationSet ?? null;
        if ($reservationSet) {
            foreach ($reservationSet->item as $reserv) {
                $instSet = $reserv->instancesSet ?? null;
                if (!$instSet) continue;
                foreach ($instSet->item as $inst) {
                    $instanceId = (string)$inst->instanceId;
                    if ($instanceId === '') continue;

                    // Buscar el tag "Name" si existe.
                    $nameTag = '';
                    if (isset($inst->tagSet->item)) {
                        foreach ($inst->tagSet->item as $tag) {
                            if ((string)$tag->key === 'Name') {
                                $nameTag = (string)$tag->value;
                                break;
                            }
                        }
                    }

                    // `platformDetails` es lo mas informativo que devuelve
                    // DescribeInstances (ej: "Linux/UNIX", "Red Hat Enterprise
                    // Linux", "Ubuntu Pro", "Windows"). Si no viene, caemos a
                    // `platform` (que solo se llena para Windows).
                    $platformDetails = (string)($inst->platformDetails ?? '');
                    $platform        = (string)($inst->platform ?? '');
                    $so = $platformDetails !== '' ? $platformDetails
                        : ($platform !== '' ? $platform : '');

                    // Volumenes EBS attach a la instancia. Guardamos solo los
                    // IDs; los tamanios los resolvemos despues con
                    // aws_ec2_describe_volumes() en un unico batch por region.
                    $volumeIds = [];
                    if (isset($inst->blockDeviceMapping->item)) {
                        foreach ($inst->blockDeviceMapping->item as $bd) {
                            $vid = (string)($bd->ebs->volumeId ?? '');
                            if ($vid !== '') $volumeIds[] = $vid;
                        }
                    }

                    $items[] = [
                        'instance_id'    => $instanceId,
                        'name_tag'       => $nameTag,
                        'public_dns'     => (string)($inst->dnsName ?? ''),
                        'private_dns'    => (string)($inst->privateDnsName ?? ''),
                        'public_ip'      => (string)($inst->ipAddress ?? ''),
                        'private_ip'     => (string)($inst->privateIpAddress ?? ''),
                        'tipo_instancia' => (string)($inst->instanceType ?? ''),
                        'state'          => (string)($inst->instanceState->name ?? ''),
                        'so'             => $so,
                        'volume_ids'     => $volumeIds,
                    ];
                }
            }
        }

        $nextToken = isset($res['xml']->nextToken) ? (string)$res['xml']->nextToken : '';
    } while ($nextToken !== '' && $nextToken !== null);

    return ['items' => $items, 'error' => null];
}

/**
 * DescribeInstanceTypes por lote de nombres. Devuelve un mapa asociativo
 * [instanceType => ['vcpus' => int, 'memory_mib' => int]] con las specs de
 * hardware de cada tipo de instancia EC2.
 *
 * Batches de 100 tipos por request (EC2 acepta hasta 100 por llamada segun
 * la doc del Query API).
 *
 * @return array{ specs: array<string,array{vcpus:int,memory_mib:int}>, error: ?string }
 */
function aws_ec2_describe_instance_types(
    string $accessKey,
    string $secretKey,
    string $region,
    array  $instanceTypes
): array {
    $specs = [];
    if (!$instanceTypes) return ['specs' => $specs, 'error' => null];

    $uniq = array_values(array_unique(array_filter($instanceTypes, fn($t) => $t !== '')));
    if (!$uniq) return ['specs' => $specs, 'error' => null];

    $chunks = array_chunk($uniq, 100);
    foreach ($chunks as $chunk) {
        $params = [];
        foreach ($chunk as $i => $t) {
            $params['InstanceType.' . ($i + 1)] = $t;
        }
        $res = aws_ec2_query($accessKey, $secretKey, $region, 'DescribeInstanceTypes', $params);
        if ($res['error'] !== null || $res['xml'] === null) {
            return ['specs' => $specs, 'error' => $res['error'] ?? 'Respuesta vacia'];
        }
        $set = $res['xml']->instanceTypeSet ?? null;
        if (!$set) continue;
        foreach ($set->item as $it) {
            $name   = (string)$it->instanceType;
            $vcpus  = (int)($it->vCpuInfo->defaultVCpus ?? 0);
            $memMib = (int)($it->memoryInfo->sizeInMiB  ?? 0);
            if ($name !== '') {
                $specs[$name] = ['vcpus' => $vcpus, 'memory_mib' => $memMib];
            }
        }
    }
    return ['specs' => $specs, 'error' => null];
}

/**
 * DescribeVolumes por lote de IDs. Devuelve un mapa asociativo
 * [volumeId => sizeGB] con los tamanios en GiB de cada volumen consultado.
 *
 * Batches de 200 IDs por request (limite conservador — EC2 acepta hasta
 * 500). Los IDs no encontrados simplemente no aparecen en el mapa.
 *
 * @return array{ sizes: array<string,int>, error: ?string }
 */
function aws_ec2_describe_volumes(
    string $accessKey,
    string $secretKey,
    string $region,
    array  $volumeIds
): array {
    $sizes = [];
    if (!$volumeIds) return ['sizes' => $sizes, 'error' => null];

    $uniq = array_values(array_unique($volumeIds));
    $chunks = array_chunk($uniq, 200);
    foreach ($chunks as $chunk) {
        $params = [];
        foreach ($chunk as $i => $vid) {
            $params['VolumeId.' . ($i + 1)] = $vid;
        }
        $res = aws_ec2_query($accessKey, $secretKey, $region, 'DescribeVolumes', $params);
        if ($res['error'] !== null || $res['xml'] === null) {
            return ['sizes' => $sizes, 'error' => $res['error'] ?? 'Respuesta vacia'];
        }
        $set = $res['xml']->volumeSet ?? null;
        if (!$set) continue;
        foreach ($set->item as $v) {
            $vid  = (string)$v->volumeId;
            $size = (int)$v->size;
            if ($vid !== '') $sizes[$vid] = $size;
        }
    }
    return ['sizes' => $sizes, 'error' => null];
}
