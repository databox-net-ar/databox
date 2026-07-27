<?php
// api/lib/datarocketdominios_whois.php
// Logica de scraping WHOIS para dominios de `datarocket_dominios`.
// La usa el endpoint HTTP `api/datarocketdominios_whois.php` (streaming
// desde el UI) y tambien el job CLI `jobs/datarocketdominios_actualizar_whois.php`
// (batch diario). Portado del monorepo `dex` (cloud/api/dominios_whois.php).
//
// Fuente segun el TLD:
//   .ar  -> https://nic.ar/es/nic-argentina/dominios/<dominio>
//   otro -> https://who.is/whois/<dominio>
//
// Guarda en la tabla: titular_dominio, fecha_registro,
// fecha_siguiente_renovacion, entidad_registrante (si venia vacio),
// costo_renovacion + moneda (si venia vacio) y `actualizado = NOW()`.

if (!function_exists('drdoActualizarWhois')) {

/**
 * Actualiza los datos WHOIS de un dominio y retorna un resumen JSON-serializable.
 *
 * @param PDO      $pdo
 * @param int      $id            Id de la fila en `datarocket_dominios`.
 * @param callable $log           Callable que recibe cada linea de log (string).
 *                                Pasar `fn($m) => null` para silenciar.
 * @return array {
 *     ok: bool,
 *     fuente?: 'nic.ar'|'who.is',
 *     cambios?: int,
 *     datos?: array,             Payload crudo del scraper.
 *     error?: string,
 *     detail?: string,
 * }
 */
function drdoActualizarWhois(PDO $pdo, int $id, callable $log): array {
    $stmt = $pdo->prepare('SELECT id, dominio, titular_dominio, entidad_registrante,
                                  costo_renovacion, moneda
                             FROM datarocket_dominios WHERE id = ? LIMIT 1');
    $stmt->execute([$id]);
    $dom = $stmt->fetch();
    if (!$dom) {
        $log("No existe el dominio #$id.");
        return ['ok' => false, 'error' => 'not_found'];
    }

    $dominio = strtolower(trim((string)$dom['dominio']));
    if ($dominio === '') {
        $log('El dominio de la fila esta vacio.');
        return ['ok' => false, 'error' => 'dominio_vacio'];
    }

    $log("Dominio a consultar: $dominio");

    $esAr   = (bool) preg_match('/\.ar$/', $dominio);
    $fuente = $esAr ? 'nic.ar' : 'who.is';
    $log("TLD detectado: " . ($esAr ? '.ar -> uso nic.ar' : 'no .ar -> uso who.is'));

    $datos = $esAr ? drdoConsultarNicAr($dominio, $log) : drdoConsultarWhoIs($dominio, $log);

    if (!$datos['ok']) {
        $log('X ' . ($datos['detail'] ?? 'Error consultando el WHOIS.'));
        // Marcamos `actualizado` igual para que el job no re-golpee cada
        // corrida a dominios que fallan de forma sostenida.
        $upd = $pdo->prepare('UPDATE datarocket_dominios SET actualizado = NOW() WHERE id = ?');
        $upd->execute([$id]);
        return [
            'ok'     => false,
            'error'  => $datos['error']  ?? 'whois_error',
            'detail' => $datos['detail'] ?? 'No se pudo consultar el WHOIS.',
            'fuente' => $fuente,
        ];
    }

    // -------- Armar el UPDATE --------
    $sets = ['actualizado = NOW()'];
    $args = [];
    $resumenCambios = [];

    if (!empty($datos['titular_dominio'])) {
        $sets[] = 'titular_dominio = ?';
        $args[] = mb_substr($datos['titular_dominio'], 0, 200);
        $resumenCambios[] = 'titular_dominio';
    }
    if (!empty($datos['fecha_registro'])) {
        $sets[] = 'fecha_registro = ?';
        $args[] = $datos['fecha_registro'];
        $resumenCambios[] = 'fecha_registro';
    }
    if (!empty($datos['fecha_siguiente_renovacion'])) {
        $sets[] = 'fecha_siguiente_renovacion = ?';
        $args[] = $datos['fecha_siguiente_renovacion'];
        $resumenCambios[] = 'fecha_siguiente_renovacion';
    }
    if (($dom['entidad_registrante'] ?? '') === '' || $dom['entidad_registrante'] === null) {
        if (!empty($datos['entidad_registrante'])) {
            $sets[] = 'entidad_registrante = ?';
            $args[] = $datos['entidad_registrante'];
            $resumenCambios[] = 'entidad_registrante';
        }
    } else {
        $log("  entidad_registrante ya cargada como \"{$dom['entidad_registrante']}\" — no la piso.");
    }

    // Costo + moneda: solo autocompletar cuando el costo actual esta vacio
    // (NULL o 0). Si el operador ya cargo un precio a mano, no lo pisamos.
    $costoActual = $dom['costo_renovacion'];
    $costoVacio  = ($costoActual === null || (float)$costoActual === 0.0);
    if ($costoVacio && isset($datos['costo_renovacion'])) {
        $sets[] = 'costo_renovacion = ?';
        $args[] = $datos['costo_renovacion'];
        $resumenCambios[] = 'costo_renovacion';
        $sets[] = 'moneda = ?';
        $args[] = $datos['moneda'];
        $resumenCambios[] = 'moneda';
    } elseif (!$costoVacio) {
        $log("  costo_renovacion ya cargado ({$dom['moneda']} $costoActual) — no lo piso.");
    }

    // sets siempre trae al menos `actualizado = NOW()`, entonces cambios reales = count-1
    $cambiosReales = count($sets) - 1;
    if ($cambiosReales === 0) {
        $log('El WHOIS no aporto campos nuevos. Solo actualizo el timestamp.');
    } else {
        $log('-> Guardando ' . $cambiosReales . ' campo(s): ' . implode(', ', $resumenCambios));
    }

    $args[] = $id;
    $sql = 'UPDATE datarocket_dominios SET ' . implode(', ', $sets) . ' WHERE id = ?';
    $upd = $pdo->prepare($sql);
    $upd->execute($args);

    $log('OK Listo. Filas afectadas: ' . $upd->rowCount());

    return [
        'ok'      => true,
        'fuente'  => $fuente,
        'cambios' => $cambiosReales,
        'datos'   => $datos,
    ];
}

// ---------- Consulta HTTP ----------

function drdoHttpRequest(string $url, ?string $cookieJar = null, ?array $post = null): array {
    $ch = curl_init($url);
    $opts = [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_MAXREDIRS      => 5,
        CURLOPT_TIMEOUT        => 25,
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_USERAGENT      => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/122.0 Safari/537.36',
        CURLOPT_HTTPHEADER     => [
            'Accept: text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8',
            'Accept-Language: es-AR,es;q=0.9,en;q=0.5',
        ],
    ];
    if ($cookieJar !== null) {
        $opts[CURLOPT_COOKIEJAR]  = $cookieJar;
        $opts[CURLOPT_COOKIEFILE] = $cookieJar;
    }
    if ($post !== null) {
        $opts[CURLOPT_POST]       = true;
        $opts[CURLOPT_POSTFIELDS] = http_build_query($post);
        $opts[CURLOPT_HTTPHEADER][] = 'Content-Type: application/x-www-form-urlencoded';
        $opts[CURLOPT_REFERER]      = $url;
    }
    curl_setopt_array($ch, $opts);
    $body     = curl_exec($ch);
    $err      = curl_error($ch);
    $code     = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $finalUrl = (string) curl_getinfo($ch, CURLINFO_EFFECTIVE_URL);
    curl_close($ch);
    return ['body' => $body, 'error' => $err, 'code' => $code, 'url' => $finalUrl];
}

// ---------- NIC.ar ----------

function drdoConsultarNicAr(string $dominio, callable $log): array {
    $log('-> Consultando nic.ar...');

    $partes = drdoDescomponerDominioAr($dominio);
    if (!$partes) {
        return ['ok' => false, 'error' => 'dominio_no_ar',
                'detail' => "No pude separar '$dominio' en nombre + extension .ar.",
                'fuente' => 'nic.ar'];
    }
    [$nombre, $ext] = $partes;
    $log("  Descompuesto: nombre='$nombre'  extension='$ext'");

    $cookieJar = tempnam(sys_get_temp_dir(), 'niccar_');

    try {
        $log('  Paso 1: GET https://nic.ar/  (cookies + form)');
        $home = drdoHttpRequest('https://nic.ar/', $cookieJar);
        $log("    HTTP {$home['code']} — " . strlen((string)$home['body']) . ' bytes');
        if ($home['body'] === false || $home['body'] === '') {
            return ['ok' => false, 'error' => 'whois_inalcanzable',
                    'detail' => 'No pude descargar la home de nic.ar.',
                    'fuente' => 'nic.ar'];
        }

        $form = drdoExtraerFormularioBuscadorNicAr($home['body']);
        if (!$form) {
            return ['ok' => false, 'error' => 'form_no_encontrado',
                    'detail' => 'No encontre el formulario de busqueda en nic.ar.',
                    'fuente' => 'nic.ar'];
        }
        $actionAbs = drdoResolverUrl($form['action'] ?: 'https://nic.ar/', $home['url']);
        $method    = strtoupper($form['method'] ?: 'POST');
        $log("    Form: {$method} {$actionAbs}");
        $log("    Campo dominio: '{$form['inputName']}'  Campo ext: '{$form['selectName']}'");

        $post = array_merge($form['hidden'], [
            $form['inputName']  => $nombre,
            $form['selectName'] => $ext,
        ]);
        $log("  Paso 2: POST del formulario con dominio='$nombre' ext='$ext'");
        $res = drdoHttpRequest($actionAbs, $cookieJar, $method === 'POST' ? $post : null);
        if ($method === 'GET') {
            $url = $actionAbs . (str_contains($actionAbs, '?') ? '&' : '?') . http_build_query($post);
            $log("    (metodo GET) GET $url");
            $res = drdoHttpRequest($url, $cookieJar);
        }
        $log("    HTTP {$res['code']} — " . strlen((string)$res['body']) . ' bytes');
        $log("    URL final: {$res['url']}");

        if ($res['body'] === false || $res['body'] === '') {
            return ['ok' => false, 'error' => 'whois_inalcanzable',
                    'detail' => 'nic.ar no devolvio respuesta al POST del formulario.',
                    'fuente' => 'nic.ar'];
        }
        $html = $res['body'];

        if (stripos($html, 'Datos del dominio') === false) {
            if (stripos($html, 'disponible para registrarlo') !== false
                || stripos($html, 'est&aacute; disponible') !== false) {
                return ['ok' => false, 'error' => 'dominio_libre',
                        'detail' => "$dominio figura como DISPONIBLE en nic.ar (todavia no esta registrado).",
                        'fuente' => 'nic.ar'];
            }
            return ['ok' => false, 'error' => 'dominio_no_encontrado',
                    'detail' => "nic.ar no devolvio la seccion 'Datos del dominio' para $dominio.",
                    'fuente' => 'nic.ar'];
        }

        $titular = drdoExtraerNicAr($html, 'Nombre y Apellido');
        $alta    = drdoExtraerNicAr($html, 'Fecha de Alta');
        $venc    = drdoExtraerNicAr($html, 'Fecha de vencimiento');

        $log('  Nombre y Apellido: '    . ($titular ?: '(sin dato)'));
        $log('  Fecha de Alta: '        . ($alta    ?: '(sin dato)'));
        $log('  Fecha de vencimiento: ' . ($venc    ?: '(sin dato)'));

        return [
            'ok'                         => true,
            'fuente'                     => 'nic.ar',
            'titular_dominio'            => drdoATitleCase(drdoNormalizarNombre($titular)),
            'fecha_registro'             => drdoParseFechaDMY($alta),
            'fecha_siguiente_renovacion' => drdoParseFechaDMY($venc),
            'entidad_registrante'        => 'Nic Argentina',
            'costo_renovacion'           => 25000,
            'moneda'                     => 'ARS',
            'crudo'                      => [
                'Nombre y Apellido'    => $titular,
                'Fecha de Alta'        => $alta,
                'Fecha de vencimiento' => $venc,
            ],
        ];
    } finally {
        @unlink($cookieJar);
    }
}

function drdoDescomponerDominioAr(string $dominio): ?array {
    $exts = [
        '.com.ar', '.net.ar', '.org.ar', '.gob.ar', '.gov.ar',
        '.edu.ar', '.mil.ar', '.int.ar', '.tur.ar', '.mus.ar', '.ar',
    ];
    $d = strtolower($dominio);
    foreach ($exts as $ext) {
        if (str_ends_with($d, $ext)) {
            $nombre = substr($d, 0, -strlen($ext));
            if ($nombre === '') return null;
            return [$nombre, $ext];
        }
    }
    return null;
}

function drdoExtraerFormularioBuscadorNicAr(string $html): ?array {
    if (!preg_match_all('/<form\b[^>]*>[\s\S]*?<\/form>/i', $html, $forms)) return null;
    foreach ($forms[0] as $formHtml) {
        if (!preg_match('/\.com\.ar|\.net\.ar|value="\.ar"/i', $formHtml)) continue;
        if (!preg_match('/<select\b([^>]*)>([\s\S]*?)<\/select>/i', $formHtml, $sel)) continue;
        if (!preg_match('/\bname\s*=\s*"([^"]+)"/i', $sel[1], $mSelName)) continue;
        if (!preg_match('/<input\b([^>]*type\s*=\s*"(?:text|search)"[^>]*)>/i', $formHtml, $inp)) {
            if (!preg_match('/<input\b((?![^>]*type\s*=\s*"(?:hidden|submit|button|checkbox|radio)")[^>]*)>/i', $formHtml, $inp)) {
                continue;
            }
        }
        if (!preg_match('/\bname\s*=\s*"([^"]+)"/i', $inp[1], $mInpName)) continue;

        preg_match('/\baction\s*=\s*"([^"]*)"/i', $formHtml, $mAction);
        preg_match('/\bmethod\s*=\s*"([^"]*)"/i', $formHtml, $mMethod);

        $hidden = [];
        if (preg_match_all('/<input\b[^>]*type\s*=\s*"hidden"[^>]*>/i', $formHtml, $hs)) {
            foreach ($hs[0] as $h) {
                if (preg_match('/\bname\s*=\s*"([^"]+)"/i', $h, $hn)) {
                    $hv = '';
                    if (preg_match('/\bvalue\s*=\s*"([^"]*)"/i', $h, $hval)) $hv = $hval[1];
                    $hidden[$hn[1]] = html_entity_decode($hv, ENT_QUOTES | ENT_HTML5, 'UTF-8');
                }
            }
        }

        return [
            'action'     => $mAction[1] ?? '',
            'method'     => $mMethod[1] ?? 'POST',
            'inputName'  => $mInpName[1],
            'selectName' => $mSelName[1],
            'hidden'     => $hidden,
        ];
    }
    return null;
}

function drdoResolverUrl(string $ref, string $base): string {
    if ($ref === '') return $base;
    if (preg_match('#^https?://#i', $ref)) return $ref;
    $p = parse_url($base);
    if (!$p) return $ref;
    $origin = ($p['scheme'] ?? 'https') . '://' . ($p['host'] ?? '');
    if (str_starts_with($ref, '/')) return $origin . $ref;
    $path = $p['path'] ?? '/';
    $path = substr($path, 0, strrpos($path, '/') + 1);
    return $origin . $path . $ref;
}

function drdoExtraerNicAr(string $html, string $label): ?string {
    $labelEsc = preg_quote($label, '/');
    $rgx = '/' . $labelEsc . '\s*:\s*<\/[a-z]+>\s*([^<]+)/i';
    if (preg_match($rgx, $html, $m)) return trim($m[1]);
    $rgx = '/' . $labelEsc . '\s*:\s*([^<]+)/i';
    if (preg_match($rgx, $html, $m)) return trim($m[1]);
    return null;
}

// ---------- who.is ----------

function drdoConsultarWhoIs(string $dominio, callable $log): array {
    $log('-> Consultando who.is...');
    $url = 'https://who.is/whois/' . rawurlencode($dominio);
    $log("  GET $url");
    $res = drdoHttpRequest($url);
    $log("  HTTP {$res['code']} — " . ($res['body'] === false ? 'sin respuesta' : (strlen($res['body']) . ' bytes')));

    if ($res['body'] === false || $res['body'] === '') {
        return ['ok' => false, 'error' => 'whois_inalcanzable',
                'detail' => $res['error'] ?: 'Sin respuesta de who.is.',
                'fuente' => 'who.is'];
    }
    $html = $res['body'];

    $registrar = drdoExtraerWhoIsCampo($html, 'Registrar');
    $creado    = drdoExtraerWhoIsCampo($html, 'Registered On')
              ?? drdoExtraerWhoIsCampo($html, 'Created On')
              ?? drdoExtraerWhoIsCampo($html, 'Created')
              ?? drdoExtraerWhoIsCampo($html, 'Creation Date');
    $expira    = drdoExtraerWhoIsCampo($html, 'Expires On')
              ?? drdoExtraerWhoIsCampo($html, 'Registry Expiry Date')
              ?? drdoExtraerWhoIsCampo($html, 'Expiration Date');
    $titular   = drdoExtraerWhoIsName($html);
    $titularNorm = drdoNormalizarNombre($titular);
    if ($titularNorm === null || $titularNorm === '') {
        $titularNorm = 'Privado';
    }

    $log('  Name: '          . ($titular   ?: '(sin dato — se guarda "Privado")'));
    $log('  Registrar: '     . ($registrar ?: '(sin dato)'));
    $log('  Registered On: ' . ($creado    ?: '(sin dato)'));
    $log('  Expires On: '    . ($expira    ?: '(sin dato)'));

    return [
        'ok'                         => true,
        'fuente'                     => 'who.is',
        'titular_dominio'            => $titularNorm,
        'fecha_registro'             => drdoParseFechaLibre($creado),
        'fecha_siguiente_renovacion' => drdoParseFechaLibre($expira),
        'entidad_registrante'        => drdoMapearEntidad($registrar),
        'costo_renovacion'           => 35,
        'moneda'                     => 'USD',
        'crudo'                      => [
            'Registrar'     => $registrar,
            'Registered On' => $creado,
            'Expires On'    => $expira,
            'Name'          => $titular,
        ],
    ];
}

function drdoExtraerWhoIsCampo(string $html, string $label): ?string {
    $labelEsc = preg_quote($label, '/');
    $rgx = '/<div[^>]*>\s*' . $labelEsc . '\s*<\/div>\s*<div[^>]*>\s*([^<]+?)\s*<\/div>/i';
    if (preg_match($rgx, $html, $m)) return trim($m[1]);
    $rgx = '/' . $labelEsc . '\s*:\s*([^\r\n<]+)/i';
    if (preg_match($rgx, $html, $m)) return trim($m[1]);
    return null;
}

function drdoExtraerWhoIsName(string $html): ?string {
    if (preg_match('/<div[^>]*>\s*Name\s*<\/div>\s*<div[^>]*>\s*([^<]+?)\s*<\/div>/i', $html, $m)) {
        return trim($m[1]);
    }
    if (preg_match('/(?:^|[\r\n>])\s*Name\s*:\s*([^\r\n<]+)/im', $html, $m)) {
        return trim($m[1]);
    }
    return null;
}

// ---------- helpers de normalizacion ----------

function drdoNormalizarNombre(?string $s): ?string {
    if ($s === null) return null;
    $s = html_entity_decode($s, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    $s = trim(preg_replace('/\s+/', ' ', $s));
    return $s === '' ? null : $s;
}

function drdoATitleCase(?string $s): ?string {
    if ($s === null || $s === '') return $s;
    return mb_convert_case($s, MB_CASE_TITLE, 'UTF-8');
}

function drdoParseFechaDMY(?string $s): ?string {
    if (!$s) return null;
    if (preg_match('/(\d{1,2})\/(\d{1,2})\/(\d{4})/', $s, $m)) {
        return sprintf('%04d-%02d-%02d', (int)$m[3], (int)$m[2], (int)$m[1]);
    }
    return null;
}

function drdoParseFechaLibre(?string $s): ?string {
    if (!$s) return null;
    $s = trim($s);
    if (preg_match('/(\d{4})-(\d{2})-(\d{2})/', $s, $m)) {
        return sprintf('%04d-%02d-%02d', (int)$m[1], (int)$m[2], (int)$m[3]);
    }
    if (preg_match('/^(\d{1,2})\/(\d{1,2})\/(\d{4})/', $s, $m)) {
        return sprintf('%04d-%02d-%02d', (int)$m[3], (int)$m[2], (int)$m[1]);
    }
    $t = strtotime($s);
    if ($t !== false) return date('Y-m-d', $t);
    return null;
}

function drdoMapearEntidad(?string $registrar): ?string {
    if (!$registrar) return null;
    $r = strtolower($registrar);
    if (str_contains($r, 'network solutions'))  return 'Networksolutions';
    if (str_contains($r, 'networksolutions'))   return 'Networksolutions';
    if (str_contains($r, 'cloudflare'))         return 'Cloudflare';
    if (str_contains($r, 'godaddy'))            return 'GoDaddy';
    if (str_contains($r, 'donweb') || str_contains($r, 'don web')) return 'Don Web';
    if (str_contains($r, 'nic.ar') || str_contains($r, 'nic argentina')) return 'Nic Argentina';
    return null;
}

} // if (!function_exists('drdoActualizarWhois'))
