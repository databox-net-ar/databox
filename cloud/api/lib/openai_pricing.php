<?php
// api/lib/openai_pricing.php
// Tabla de precios por modelo de OpenAI (USD por 1M tokens).
// Fuente: https://platform.openai.com/docs/pricing (snapshot mantenido a mano).
//
// El matching es por prefijo: la Usage API devuelve el nombre con timestamp
// (ej. "gpt-4o-2024-08-06"), y buscamos la entrada mas larga que sea prefijo
// del modelo. Si no matchea, el modelo se ignora del calculo y se contabiliza
// en `desconocidos` del helper para poder mostrar un aviso en la UI.
//
// Los precios se actualizan a mano cuando OpenAI cambia la tabla. No cubre
// descuentos batch ni cached-input a rate reducido (se usa el rate estandar).

// Precios en USD por 1_000_000 tokens.
// Orden importante: los mas especificos primero (mini antes que su padre).
const OPENAI_PRICING = [
    // GPT-5 family
    'gpt-5-nano'                  => ['input' => 0.05,  'output' => 0.40],
    'gpt-5-mini'                  => ['input' => 0.25,  'output' => 2.00],
    'gpt-5'                       => ['input' => 1.25,  'output' => 10.00],

    // GPT-4.1 family
    'gpt-4.1-nano'                => ['input' => 0.10,  'output' => 0.40],
    'gpt-4.1-mini'                => ['input' => 0.40,  'output' => 1.60],
    'gpt-4.1'                     => ['input' => 2.00,  'output' => 8.00],

    // GPT-4o family
    'gpt-4o-mini'                 => ['input' => 0.15,  'output' => 0.60],
    'gpt-4o'                      => ['input' => 2.50,  'output' => 10.00],

    // GPT-4 clasico
    'gpt-4-turbo'                 => ['input' => 10.00, 'output' => 30.00],
    'gpt-4'                       => ['input' => 30.00, 'output' => 60.00],

    // GPT-3.5
    'gpt-3.5-turbo'               => ['input' => 0.50,  'output' => 1.50],

    // Razonamiento
    'o4-mini'                     => ['input' => 1.10,  'output' => 4.40],
    'o3-mini'                     => ['input' => 1.10,  'output' => 4.40],
    'o3'                          => ['input' => 2.00,  'output' => 8.00],
    'o1-mini'                     => ['input' => 3.00,  'output' => 12.00],
    'o1'                          => ['input' => 15.00, 'output' => 60.00],

    // Embeddings
    'text-embedding-3-small'      => ['input' => 0.02,  'output' => 0.00],
    'text-embedding-3-large'      => ['input' => 0.13,  'output' => 0.00],
    'text-embedding-ada-002'      => ['input' => 0.10,  'output' => 0.00],
];

/**
 * Devuelve los precios (input/output USD por 1M tokens) para un modelo.
 * Matching por el prefijo mas largo definido en OPENAI_PRICING.
 * Devuelve null si no hay match.
 */
function openaiPricingFor(string $model): ?array {
    $mejor = null;
    $largo = -1;
    foreach (OPENAI_PRICING as $prefix => $precio) {
        if (strncmp($model, $prefix, strlen($prefix)) === 0 && strlen($prefix) > $largo) {
            $mejor = $precio;
            $largo = strlen($prefix);
        }
    }
    return $mejor;
}

/**
 * Costo estimado en USD para (input_tokens, output_tokens) sobre un modelo.
 * Devuelve 0.0 si el modelo no matchea (ver `openaiPricingFor`).
 */
function openaiEstimarCosto(string $model, int $inputTokens, int $outputTokens): float {
    $p = openaiPricingFor($model);
    if (!$p) return 0.0;
    return ($inputTokens  / 1_000_000) * (float)$p['input']
         + ($outputTokens / 1_000_000) * (float)$p['output'];
}
