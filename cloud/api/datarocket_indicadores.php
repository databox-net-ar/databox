<?php
// api/datarocket_indicadores.php
// Indicadores del landing de Datarocket (#/datarocket): los numeros que van en
// la barra de tarjetas arriba de la grilla de modulos. Solo lectura — no tiene
// ABM ni escribe nada.
//
//   GET api/datarocket_indicadores.php -> {ok:true, data:{interacciones, oportunidades}}
//
// Cada bloque va gateado por el permiso de consulta del modulo del que sale el
// numero y viaja como `null` cuando el usuario no lo tiene: mismo criterio que
// api/dashboard.php — si no puede entrar al ABM tampoco ve su indicador, y el
// front directamente no pinta esa tarjeta.
//
// El foco son las interacciones ENTRANTES sin responder. Una saliente sin
// `respondida` no esta esperando nada, asi que no cuenta como pendiente: es la
// misma regla del filtro `?pendiente=1` de api/datarocketinteracciones.php.
//
// Los contadores son GLOBALES (sin filtro de proyecto ni de asignado): son
// indicadores del modulo, igual que las stats de los ABM.

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/lib/auth_check.php';

requireAuth();
header('Content-Type: application/json; charset=utf-8');

$pdo = db();

// ----------------------------------------------------------------------------
// Interacciones: pendientes de respuesta
// ----------------------------------------------------------------------------
// `pendientes_24h` usa una ventana movil de 24 horas reales (NOW() - 24h) y no
// "de ayer para atras": lo que importa es cuanto lleva esperando la consulta,
// no en que dia del calendario entro.
//
// `respuesta_promedio` promedia solo lo YA respondido (misma cuenta que la
// tarjeta homonima del ABM de interacciones) y es NULL mientras no haya
// ninguna marcada — un "0 min" ahi seria mentira.
$interacciones = null;
if (hasPermission('datarocket.interacciones.consultar')) {
    // `pendientes_sin_asignar` sale del LEFT JOIN con la oportunidad: el
    // responsable de una interaccion es el `asignado` de su oportunidad, asi
    // que cuenta tanto la pendiente sin oportunidad como la que cuelga de una
    // oportunidad sin dueño. Son las que NO le llegan a nadie — el cron
    // datarocket_interacciones_avisar_pendientes.php las agrupa igual y las
    // reporta como suceso 'alerta' porque no tiene a quien avisarle.
    // `descartada IS NULL` en los tres contadores de pendientes: una consulta
    // descartada (spam, mensaje sin pregunta) no esta esperando a nadie —
    // migracion 20260828_1900. Sin esto, la tarjeta "Sin responder" seguiria
    // contando basura que ya se decidio no contestar.
    $agg = $pdo->query("
        SELECT
            SUM(CASE WHEN i.sentido = 'entrante' AND i.respondida IS NULL
                          AND i.descartada IS NULL
                     THEN 1 ELSE 0 END)                                      AS pendientes,
            SUM(CASE WHEN i.sentido = 'entrante' AND i.respondida IS NULL
                          AND i.descartada IS NULL
                          AND (o.asignado IS NULL OR o.asignado = 0)
                     THEN 1 ELSE 0 END)                                      AS pendientes_sin_asignar,
            SUM(CASE WHEN i.sentido = 'entrante' AND i.respondida IS NULL
                          AND i.descartada IS NULL
                          AND i.fecha < DATE_SUB(NOW(), INTERVAL 24 HOUR)
                     THEN 1 ELSE 0 END)                                      AS pendientes_24h,
            SUM(CASE WHEN i.sentido = 'entrante' AND DATE(i.fecha) = CURDATE()
                     THEN 1 ELSE 0 END)                                      AS entrantes_hoy,
            AVG(CASE WHEN i.respondida IS NOT NULL
                     THEN TIMESTAMPDIFF(MINUTE, i.fecha, i.respondida) END)  AS respuesta_promedio
          FROM datarocket_interacciones i
          LEFT JOIN datarocket_oportunidades o ON o.id = i.oportunidad_id
    ")->fetch();

    // La pendiente mas vieja, para la tarjeta "Espera mas antigua". Se pide
    // aparte (y solo si hay alguna) porque ademas del tiempo queremos el
    // prospecto y la fecha para el tooltip: un "6 d" sin decir de quien no
    // sirve para actuar.
    $vieja = null;
    if ((int)($agg['pendientes'] ?? 0) > 0) {
        $vieja = $pdo->query("
            SELECT i.id, i.fecha,
                   TIMESTAMPDIFF(MINUTE, i.fecha, NOW()) AS espera_minutos,
                   p.nombre AS prospecto_nombre
              FROM datarocket_interacciones i
              LEFT JOIN datarocket_prospectos p ON p.id = i.prospecto_id
             WHERE i.sentido = 'entrante' AND i.respondida IS NULL
                                          AND i.descartada IS NULL
             ORDER BY i.fecha ASC
             LIMIT 1
        ")->fetch();
    }

    $interacciones = [
        'pendientes'             => (int)($agg['pendientes']             ?? 0),
        'pendientes_sin_asignar' => (int)($agg['pendientes_sin_asignar'] ?? 0),
        'pendientes_24h'     => (int)($agg['pendientes_24h'] ?? 0),
        'entrantes_hoy'      => (int)($agg['entrantes_hoy']  ?? 0),
        'respuesta_promedio' => $agg['respuesta_promedio'] !== null
                                ? (int)round((float)$agg['respuesta_promedio'])
                                : null,
        'espera_maxima'      => $vieja ? [
            'id'               => (int)$vieja['id'],
            'fecha'            => (string)$vieja['fecha'],
            'minutos'          => (int)$vieja['espera_minutos'],
            'prospecto_nombre' => $vieja['prospecto_nombre'] !== null
                                  ? (string)$vieja['prospecto_nombre'] : null,
        ] : null,
        // Dia del servidor, para que el link "Entrantes hoy" filtre por el
        // MISMO dia que se conto aca (el navegador puede estar en otra zona).
        'hoy' => (string)$pdo->query('SELECT CURDATE()')->fetchColumn(),
    ];
}

// ----------------------------------------------------------------------------
// Oportunidades: sin atender
// ----------------------------------------------------------------------------
// Mismo criterio que la stat homonima de api/datarocket_oportunidades.php:
// `atendido` NULL o 0 = nadie la tomo todavia.
$oportunidades = null;
if (hasPermission('datarocket.oportunidades.consultar')) {
    $agg = $pdo->query('
        SELECT COUNT(*)                                                          AS total,
               SUM(CASE WHEN atendido IS NULL OR atendido = 0 THEN 1 ELSE 0 END) AS sin_atender
          FROM datarocket_oportunidades
    ')->fetch();

    $oportunidades = [
        'total'       => (int)($agg['total']       ?? 0),
        'sin_atender' => (int)($agg['sin_atender'] ?? 0),
    ];
}

jsonOk([
    'interacciones' => $interacciones,
    'oportunidades' => $oportunidades,
]);
