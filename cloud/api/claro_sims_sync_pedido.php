<?php
// api/claro_sims_sync_pedido.php
// Coordinador del sync manual de SIMs Claro. Como el portal
// https://iotgestion.claro.com.ar/ esta detras de un WAF con fingerprint dinamico
// que corta cualquier scraper HTTP puro, el sync lo dispara el agente externo
// `openclaw`. Este endpoint es el rendezvous entre el panel (que marca "hay que
// sincronizar") y openclaw (que pollea cada 5 min y arranca el trabajo).
//
// Flujo end-to-end:
//   1. Operador aprieta "Sincronizar" en el ABM de SIMs Claro
//        PUT api/claro_sims_sync_pedido.php
//        -> setea parametro `pedido_clarosims_sincronizar` = "1"
//   2. openclaw pollea cada 5 min
//        POST api/claro_sims_sync_pedido.php
//        -> lee la bandera y la deja en "0" atomicamente
//        -> si respondio {pedido:true}, openclaw:
//             a) scrapea el portal de Claro (login + paginacion + export CSV)
//             b) POSTea el CSV a api/claro_sims_sync.php con el mismo apikey
//
// Auth:
//   - POST -> Bearer apikey contra `aplicaciones` (habilitada='1'). Consume el flag.
//   - PUT  -> Sesion de panel con permiso `plataformas.claro.sims.sincronizar`.
//            Marca el flag.
//
// El proceso completo (desde el click hasta ver las filas nuevas en claro_sims)
// tarda hasta ~15 min: 5 de espera al proximo poll + ~10 de scraping / upload.
//
// Respuestas:
//   POST 200 {ok:true, data:{pedido:bool, marcado_en:datetime|null, aplicacion:{id,nombre}}}
//   PUT  200 {ok:true, data:{pedido:true, marcado_en:datetime, ya_pendiente:bool}}
//
// El nucleo (marcar/consumir el flag) vive en api/lib/claro_sims_sync_pedido.php
// y es reutilizado por el job cloud/jobs/claro_sims_actualizar.php.

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/lib/apikey_auth.php';
require_once __DIR__ . '/lib/auth_check.php';
require_once __DIR__ . '/lib/claro_sims_sync_pedido.php';

header('Content-Type: application/json; charset=utf-8');

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

try {
    if ($method === 'POST') {
        $app = requireAppApikey();
        $r   = consumirPedidoSyncClaro(db());
        $r['aplicacion'] = ['id' => (int)$app['id'], 'nombre' => (string)$app['nombre']];
        jsonOk($r);
    } elseif ($method === 'PUT') {
        requirePermission('plataformas.claro.sims.sincronizar');
        jsonOk(marcarPedidoSyncClaro(db()));
    } else {
        jsonError('Metodo no soportado', 405);
    }
} catch (Throwable $e) {
    jsonError($e->getMessage(), 500);
}
