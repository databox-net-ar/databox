<?php
// api/lib/openai_snapshot.php
// Captura un snapshot completo del estado de cuenta OpenAI que se guarda
// como JSON en `openai_consumos.datos`. Reune:
//   - KPIs globales: costo mes actual, costo mes anterior, tokens y requests
//     del mes en curso.
//   - Tabla por API key: nombre, tracking id, proyecto, created_at, last_used,
//     tokens, requests y spend estimado del mes en curso.
//
// Los errores parciales (una API que falla) se loguean en `sucesos` pero no
// abortan el snapshot: la vista igual muestra lo que se pudo obtener.
//
// Uso:
//   require_once __DIR__ . '/lib/openai_snapshot.php';
//   $snap = openaiCapturarSnapshot($pdo, $apiKey);
//   $pdo->prepare('INSERT INTO openai_consumos (fecha, datos) VALUES (NOW(), :d)')
//       ->execute([':d' => json_encode($snap, JSON_UNESCAPED_UNICODE)]);

require_once __DIR__ . '/openai_admin.php';
require_once __DIR__ . '/openai_pricing.php';
require_once __DIR__ . '/sucesos.php';

/**
 * Toma un snapshot completo consultando la Admin API de OpenAI.
 * Devuelve un array serializable a JSON con la forma final que consume el
 * frontend (KPIs globales + tabla por API key + rangos + moneda).
 */
function openaiCapturarSnapshot(PDO $pdo, string $apiKey): array {
    $tz         = new DateTimeZone('UTC');
    $ahora      = new DateTimeImmutable('now', $tz);
    $inicioActu = $ahora->modify('first day of this month')->setTime(0, 0, 0);
    $inicioAnte = $ahora->modify('first day of last month')->setTime(0, 0, 0);
    $finAnte    = $ahora->modify('first day of this month')->setTime(0, 0, 0);
    $finActu    = $ahora;

    $moneda        = 'usd';
    $costoActual   = null;
    $costoAnterior = null;
    $tokensMes     = null;
    $requestsMes   = null;

    try {
        $r = openaiSnapCosts($apiKey, $inicioActu->getTimestamp(), $finActu->getTimestamp());
        $costoActual = $r['total'];
        if (!empty($r['currency'])) $moneda = $r['currency'];
    } catch (Throwable $e) {
        registrarSuceso($pdo, 'openai_snapshot', 'error',
            'Costs (actual) fallo: ' . $e->getMessage());
    }

    try {
        $r = openaiSnapCosts($apiKey, $inicioAnte->getTimestamp(), $finAnte->getTimestamp());
        $costoAnterior = $r['total'];
        if (!empty($r['currency'])) $moneda = $r['currency'];
    } catch (Throwable $e) {
        registrarSuceso($pdo, 'openai_snapshot', 'error',
            'Costs (anterior) fallo: ' . $e->getMessage());
    }

    try {
        $r = openaiSnapUsageCompletions($apiKey, $inicioActu->getTimestamp(), $finActu->getTimestamp());
        $tokensMes   = $r['tokens'];
        $requestsMes = $r['requests'];
    } catch (Throwable $e) {
        registrarSuceso($pdo, 'openai_snapshot', 'error',
            'Usage completions global fallo: ' . $e->getMessage());
    }

    // ---- Tabla por API key ----
    $keys      = [];
    $proyectos = [];
    try {
        $proyectos = iterator_to_array(openaiSnapListarProyectos($apiKey), false);
    } catch (Throwable $e) {
        registrarSuceso($pdo, 'openai_snapshot', 'error',
            'Listar proyectos fallo: ' . $e->getMessage());
    }

    foreach ($proyectos as $proyecto) {
        $projId   = (string)($proyecto['id']   ?? '');
        $projName = (string)($proyecto['name'] ?? $projId);
        if ($projId === '') continue;
        try {
            foreach (openaiSnapListarKeysDeProyecto($apiKey, $projId) as $k) {
                $id = (string)($k['id'] ?? '');
                if ($id === '') continue;
                $created = $k['created_at'] ?? null;
                $keys[$id] = [
                    'id'           => $id,
                    'name'         => (string)($k['name'] ?? $id),
                    'project_id'   => $projId,
                    'project_name' => $projName,
                    'created_at'   => is_numeric($created)
                        ? (new DateTimeImmutable('@' . (int)$created))->format(DATE_ATOM)
                        : null,
                ];
            }
        } catch (Throwable $e) {
            registrarSuceso($pdo, 'openai_snapshot', 'alerta',
                "Listar keys de proyecto '{$projName}' ({$projId}) fallo: " . $e->getMessage());
        }
    }

    // Agregacion por (api_key_id, model): tokens + spend estimado + last_used.
    $agg = [];
    foreach ($keys as $id => $_) {
        $agg[$id] = [
            'tokens_input'  => 0,
            'tokens_output' => 0,
            'requests'      => 0,
            'spend'         => 0.0,
            'last_used_ts'  => null,
            'sin_precio'    => false,
        ];
    }

    try {
        foreach (openaiSnapUsageBucketsPorKey($apiKey, $inicioActu->getTimestamp(), $finActu->getTimestamp()) as $b) {
            foreach ($b['results'] as $r) {
                $kid = (string)($r['api_key_id'] ?? '');
                if ($kid === '' || !isset($agg[$kid])) continue;
                $model = (string)($r['model'] ?? '');
                $ti    = (int)($r['input_tokens']       ?? 0);
                $to    = (int)($r['output_tokens']      ?? 0);
                $rq    = (int)($r['num_model_requests'] ?? 0);

                $agg[$kid]['tokens_input']  += $ti;
                $agg[$kid]['tokens_output'] += $to;
                $agg[$kid]['requests']      += $rq;

                if ($model !== '') {
                    $precio = openaiPricingFor($model);
                    if ($precio) {
                        $agg[$kid]['spend'] += openaiEstimarCosto($model, $ti, $to);
                    } elseif ($ti > 0 || $to > 0) {
                        $agg[$kid]['sin_precio'] = true;
                    }
                }
                if (($ti + $to + $rq) > 0) {
                    $ts = (int)($b['start_time'] ?? 0);
                    if ($ts > 0 && ($agg[$kid]['last_used_ts'] === null || $ts > $agg[$kid]['last_used_ts'])) {
                        $agg[$kid]['last_used_ts'] = $ts;
                    }
                }
            }
        }
    } catch (Throwable $e) {
        registrarSuceso($pdo, 'openai_snapshot', 'error',
            'Usage por key fallo: ' . $e->getMessage());
    }

    $apikeys = [];
    foreach ($keys as $id => $meta) {
        $a = $agg[$id];
        $apikeys[] = [
            'id'                       => $meta['id'],
            'name'                     => $meta['name'],
            'project_id'               => $meta['project_id'],
            'project_name'             => $meta['project_name'],
            'created_at'               => $meta['created_at'],
            'last_used'                => $a['last_used_ts']
                ? (new DateTimeImmutable('@' . $a['last_used_ts']))->format(DATE_ATOM)
                : null,
            'tokens_input'             => $a['tokens_input'],
            'tokens_output'            => $a['tokens_output'],
            'tokens_total'             => $a['tokens_input'] + $a['tokens_output'],
            'requests'                 => $a['requests'],
            'spend_estimado'           => round($a['spend'], 4),
            'tiene_modelos_sin_precio' => $a['sin_precio'],
        ];
    }
    usort($apikeys, function ($x, $y) {
        if ($y['spend_estimado'] !== $x['spend_estimado']) {
            return $y['spend_estimado'] <=> $x['spend_estimado'];
        }
        return $y['tokens_total'] <=> $x['tokens_total'];
    });

    return [
        'moneda' => $moneda,
        'kpis'   => [
            'costo_mes_actual'   => $costoActual,
            'costo_mes_anterior' => $costoAnterior,
            'tokens_mes'         => $tokensMes,
            'requests_mes'       => $requestsMes,
        ],
        'apikeys' => $apikeys,
        'rango'   => [
            'inicio_actual'   => $inicioActu->format(DATE_ATOM),
            'fin_actual'      => $finActu->format(DATE_ATOM),
            'inicio_anterior' => $inicioAnte->format(DATE_ATOM),
            'fin_anterior'    => $finAnte->format(DATE_ATOM),
        ],
    ];
}

// -----------------------------------------------------------------------------
// Helpers de la Admin API (paginacion).
// -----------------------------------------------------------------------------

function openaiSnapCosts(string $apiKey, int $start, int $end): array {
    $base   = 'https://api.openai.com/v1/organization/costs';
    $total  = 0.0;
    $moneda = '';
    $page   = null;
    do {
        $qs = http_build_query(array_filter([
            'start_time'   => $start,
            'end_time'     => $end,
            'bucket_width' => '1d',
            'limit'        => 180,
            'page'         => $page,
        ], fn($v) => $v !== null && $v !== ''));
        $resp = openaiAdminGet($base . '?' . $qs, $apiKey);
        foreach (($resp['data'] ?? []) as $bucket) {
            foreach (($bucket['results'] ?? []) as $r) {
                $amt = $r['amount'] ?? null;
                if (is_array($amt) && isset($amt['value'])) {
                    $total += (float)$amt['value'];
                    if ($moneda === '' && !empty($amt['currency'])) {
                        $moneda = (string)$amt['currency'];
                    }
                }
            }
        }
        $page = ($resp['has_more'] ?? false) ? ($resp['next_page'] ?? null) : null;
    } while ($page);
    return ['total' => $total, 'currency' => $moneda];
}

function openaiSnapUsageCompletions(string $apiKey, int $start, int $end): array {
    $base     = 'https://api.openai.com/v1/organization/usage/completions';
    $tokens   = 0;
    $requests = 0;
    $page     = null;
    do {
        $qs = http_build_query(array_filter([
            'start_time'   => $start,
            'end_time'     => $end,
            'bucket_width' => '1d',
            'limit'        => 180,
            'page'         => $page,
        ], fn($v) => $v !== null && $v !== ''));
        $resp = openaiAdminGet($base . '?' . $qs, $apiKey);
        foreach (($resp['data'] ?? []) as $bucket) {
            foreach (($bucket['results'] ?? []) as $r) {
                $tokens   += (int)($r['input_tokens']       ?? 0);
                $tokens   += (int)($r['output_tokens']      ?? 0);
                $requests += (int)($r['num_model_requests'] ?? 0);
            }
        }
        $page = ($resp['has_more'] ?? false) ? ($resp['next_page'] ?? null) : null;
    } while ($page);
    return ['tokens' => $tokens, 'requests' => $requests];
}

function openaiSnapListarProyectos(string $apiKey): Generator {
    $base  = 'https://api.openai.com/v1/organization/projects';
    $after = null;
    do {
        $qs = http_build_query(array_filter([
            'limit' => 100,
            'after' => $after,
        ], fn($v) => $v !== null && $v !== ''));
        $resp = openaiAdminGet($base . '?' . $qs, $apiKey);
        foreach (($resp['data'] ?? []) as $p) yield $p;
        $after = ($resp['has_more'] ?? false) ? ($resp['last_id'] ?? null) : null;
    } while ($after);
}

function openaiSnapListarKeysDeProyecto(string $apiKey, string $projectId): Generator {
    $base  = "https://api.openai.com/v1/organization/projects/{$projectId}/api_keys";
    $after = null;
    do {
        $qs = http_build_query(array_filter([
            'limit' => 100,
            'after' => $after,
        ], fn($v) => $v !== null && $v !== ''));
        $resp = openaiAdminGet($base . '?' . $qs, $apiKey);
        foreach (($resp['data'] ?? []) as $k) yield $k;
        $after = ($resp['has_more'] ?? false) ? ($resp['last_id'] ?? null) : null;
    } while ($after);
}

function openaiSnapUsageBucketsPorKey(string $apiKey, int $start, int $end): Generator {
    $base = 'https://api.openai.com/v1/organization/usage/completions';
    $page = null;
    do {
        $qs = http_build_query(array_filter([
            'start_time'   => $start,
            'end_time'     => $end,
            'bucket_width' => '1d',
            'limit'        => 180,
            'page'         => $page,
        ], fn($v) => $v !== null && $v !== ''));
        $qs .= '&group_by[]=api_key_id&group_by[]=model';
        $resp = openaiAdminGet($base . '?' . $qs, $apiKey);
        foreach (($resp['data'] ?? []) as $bucket) {
            yield [
                'start_time' => $bucket['start_time'] ?? null,
                'end_time'   => $bucket['end_time']   ?? null,
                'results'    => $bucket['results']    ?? [],
            ];
        }
        $page = ($resp['has_more'] ?? false) ? ($resp['next_page'] ?? null) : null;
    } while ($page);
}
