<?php
// api/datarocket_interacciones_grafico.php
// Serie diaria de interacciones por proyecto para el grafico del landing de
// Datarocket (#/datarocket). Solo lectura — no tiene ABM ni escribe nada.
//
//   GET api/datarocket_interacciones_grafico.php[?dias=30]
//     -> {ok:true, data:{dias, desde, hasta, total, grupos, fechas[], series[]}}
//
// Cuenta TODAS las interacciones de `datarocket_interacciones` (entrantes y
// salientes, respondidas o no, descartadas o no) fechadas por `fecha`: el
// grafico muestra el movimiento diario de cada proyecto, no una cola de envio.
// Para "que quedo sin responder" estan los indicadores de la misma pantalla
// (api/datarocket_indicadores.php).
//
// El proyecto sale de COALESCE(propio, el de la oportunidad) — misma lectura
// que el ABM. Los detalles estan en lib/grafico_serie_diaria.php, que ademas
// alimenta los graficos de Evolution, AWS y Telegram; aca solo se valida el
// permiso y se elige la fuente.

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/lib/auth_check.php';
require_once __DIR__ . '/lib/grafico_serie_diaria.php';

header('Content-Type: application/json; charset=utf-8');

try {
    requirePermission('datarocket.interacciones.consultar');
    jsonOk(graficoSerieDiaria(db(), 'datarocket_interacciones', graficoSerieDias()));
} catch (Throwable $e) {
    jsonError($e->getMessage(), 500);
}
