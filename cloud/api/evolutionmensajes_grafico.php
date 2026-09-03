<?php
// api/evolutionmensajes_grafico.php
// Serie diaria de mensajes enviados por canal para el grafico del landing de
// Evolution API (#/evolution). Solo lectura — no tiene ABM ni escribe nada.
//
//   GET api/evolutionmensajes_grafico.php[?dias=30]
//     -> {ok:true, data:{dias, desde, hasta, total, grupos, fechas[], series[]}}
//
// La cuenta vive en lib/grafico_serie_diaria.php, compartida con los graficos de
// AWS y Telegram; aca solo se valida el permiso y se elige la fuente.

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/lib/auth_check.php';
require_once __DIR__ . '/lib/grafico_serie_diaria.php';

header('Content-Type: application/json; charset=utf-8');

try {
    requirePermission('plataformas.evolution.mensajes.consultar');
    jsonOk(graficoSerieDiaria(db(), 'evolution', graficoSerieDias()));
} catch (Throwable $e) {
    jsonError($e->getMessage(), 500);
}
