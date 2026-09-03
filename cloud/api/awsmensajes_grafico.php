<?php
// api/awsmensajes_grafico.php
// Serie diaria de correos enviados por canal para el grafico del landing de
// AWS (#/aws). Solo lectura — no tiene ABM ni escribe nada.
//
//   GET api/awsmensajes_grafico.php[?dias=30]
//     -> {ok:true, data:{dias, desde, hasta, total, grupos, fechas[], series[]}}
//
// La cuenta vive en lib/grafico_serie_diaria.php, compartida con los graficos de
// Evolution y Telegram; aca solo se valida el permiso y se elige la fuente.

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/lib/auth_check.php';
require_once __DIR__ . '/lib/grafico_serie_diaria.php';

header('Content-Type: application/json; charset=utf-8');

try {
    requirePermission('plataformas.aws.mensajes.consultar');
    jsonOk(graficoSerieDiaria(db(), 'aws', graficoSerieDias()));
} catch (Throwable $e) {
    jsonError($e->getMessage(), 500);
}
