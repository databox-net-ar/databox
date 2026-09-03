<?php
// api/telegrammensajes_grafico.php
// Serie diaria de mensajes enviados por canal para el grafico del landing de
// Telegram (#/telegram). Solo lectura — no tiene ABM ni escribe nada.
//
//   GET api/telegrammensajes_grafico.php[?dias=30]
//     -> {ok:true, data:{dias, desde, hasta, total, grupos, fechas[], series[]}}
//
// El canal de un mensaje de Telegram es la cuenta usuario (MTProto) de
// `telegram_canales` — la misma que resuelve el JOIN del ABM
// (api/telegrammensajes.php), NO el bot de `telegram_bots`.
//
// La cuenta vive en lib/grafico_serie_diaria.php, compartida con los graficos de
// Evolution y AWS; aca solo se valida el permiso y se elige la fuente.

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/lib/auth_check.php';
require_once __DIR__ . '/lib/grafico_serie_diaria.php';

header('Content-Type: application/json; charset=utf-8');

try {
    requirePermission('plataformas.telegram.mensajes.consultar');
    jsonOk(graficoSerieDiaria(db(), 'telegram', graficoSerieDias()));
} catch (Throwable $e) {
    jsonError($e->getMessage(), 500);
}
