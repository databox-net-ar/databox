<?php
// api/datainfra_indicadores.php
// Puntos de falla del landing de Datainfra (#/datainfra): lo que esta roto o a
// punto de romperse en cada sub-modulo, para pintarlo arriba de la grilla de
// sub-modulos. Solo lectura — no tiene ABM ni escribe nada.
//
//   GET api/datainfra_indicadores.php
//     -> {ok:true, data:{servidores, bases_datos, dominios, endpoints}}
//
// Cada bloque va gateado por el permiso de consulta del sub-modulo del que sale
// y viaja como `null` cuando el usuario no lo tiene: mismo criterio que
// api/dashboard.php y api/datarocket_indicadores.php — si no puede entrar al
// ABM tampoco ve sus puntos de falla, y el front directamente no pinta esa
// tarjeta.
//
// `servidores` y `bases_datos` viajan siempre `null`: sus sub-modulos son
// placeholders (ver route('/datainfraservidores') en app.js), no hay tabla de
// donde sacar un estado. Se dejan declarados para que el dia que exista el ABM
// alcance con llenar el bloque aca — el front ya sabe pintarlos.
//
// Criterio de "problema" por sub-modulo:
//   * endpoints: `activo = 1` y el ultimo health-check dio `error` o `timeout`.
//     Los inactivos (pausados a mano) y los `nunca` (recien creados, sin corrida
//     todavia) NO son un problema — mismo criterio que el bloque homonimo del
//     dashboard.
//   * dominios: responsable operativo Databox y `fecha_siguiente_renovacion`
//     dentro de los proximos 30 dias o ya pasada. Los de responsable 'Cliente'
//     se ignoran porque no los renueva Databox.

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/lib/auth_check.php';

requireAuth();

// Tope de items por bloque. El front muestra el listado completo dentro de la
// tarjeta, asi que hace falta un techo para no mandar miles de filas si algun
// dia se cae todo junto. `con_problemas` viaja aparte con el total real: si
// `items` quedo corto, la tarjeta avisa "y N mas".
const DINF_MAX_ITEMS = 100;

$pdo = db();

// ----------------------------------------------------------------------------
// Servidores / Bases de datos: sin fuente de datos todavia
// ----------------------------------------------------------------------------
$servidores  = null;
$basesDatos  = null;

// ----------------------------------------------------------------------------
// Dominios: vencidos + por vencer dentro de 30 dias
// ----------------------------------------------------------------------------
// `dias` sale negativo para los ya vencidos y el front lo usa para separar
// severidad (vencido = rojo, por vencer = amarillo). Se ordenan por fecha
// ascendente: primero lo mas vencido, que es lo que hay que atender ya.
$dominios = null;
if (hasPermission('datainfra.dominios.consultar')) {
    $agg = $pdo->query("
        SELECT COUNT(*)                                                       AS total,
               SUM(CASE WHEN fecha_siguiente_renovacion < CURDATE()
                        THEN 1 ELSE 0 END)                                    AS vencidos,
               SUM(CASE WHEN fecha_siguiente_renovacion >= CURDATE()
                         AND fecha_siguiente_renovacion <= DATE_ADD(CURDATE(), INTERVAL 30 DAY)
                        THEN 1 ELSE 0 END)                                    AS por_vencer
          FROM datainfra_dominios
         WHERE responsable = 'Databox'
    ")->fetch();

    $vencidos  = (int)($agg['vencidos']   ?? 0);
    $porVencer = (int)($agg['por_vencer'] ?? 0);

    $items = [];
    if (($vencidos + $porVencer) > 0) {
        $items = $pdo->query("
            SELECT id, dominio, titular_dominio,
                   fecha_siguiente_renovacion,
                   costo_renovacion, moneda,
                   DATEDIFF(fecha_siguiente_renovacion, CURDATE()) AS dias
              FROM datainfra_dominios
             WHERE responsable = 'Databox'
               AND fecha_siguiente_renovacion IS NOT NULL
               AND fecha_siguiente_renovacion <= DATE_ADD(CURDATE(), INTERVAL 30 DAY)
             ORDER BY fecha_siguiente_renovacion ASC, id ASC
             LIMIT " . DINF_MAX_ITEMS . "
        ")->fetchAll();
    }

    $dominios = [
        'total'         => (int)($agg['total'] ?? 0),
        'vencidos'      => $vencidos,
        'por_vencer'    => $porVencer,
        'con_problemas' => $vencidos + $porVencer,
        'items'         => $items,
    ];
}

// ----------------------------------------------------------------------------
// Endpoints: activos cuyo ultimo health-check fallo
// ----------------------------------------------------------------------------
$endpoints = null;
if (hasPermission('datainfra.endpoints.consultar')) {
    $agg = $pdo->query("
        SELECT COUNT(*)                                                       AS total,
               SUM(CASE WHEN ultimo_estado = 'error'   THEN 1 ELSE 0 END)     AS errores,
               SUM(CASE WHEN ultimo_estado = 'timeout' THEN 1 ELSE 0 END)     AS timeouts
          FROM datainfra_endpoints
         WHERE activo = 1
    ")->fetch();

    $errores  = (int)($agg['errores']  ?? 0);
    $timeouts = (int)($agg['timeouts'] ?? 0);

    $items = [];
    if (($errores + $timeouts) > 0) {
        $items = $pdo->query("
            SELECT id, nombre, url, metodo,
                   ultimo_estado, ultimo_codigo, ultimo_tiempo_ms,
                   ultimo_check, ultimo_error
              FROM datainfra_endpoints
             WHERE activo = 1 AND ultimo_estado IN ('error','timeout')
             ORDER BY ultimo_check DESC, id DESC
             LIMIT " . DINF_MAX_ITEMS . "
        ")->fetchAll();
    }

    $endpoints = [
        'total'         => (int)($agg['total'] ?? 0),
        'errores'       => $errores,
        'timeouts'      => $timeouts,
        'con_problemas' => $errores + $timeouts,
        'items'         => $items,
    ];
}

jsonOk([
    'servidores'  => $servidores,
    'bases_datos' => $basesDatos,
    'dominios'    => $dominios,
    'endpoints'   => $endpoints,
]);
