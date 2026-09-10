<?php
// api/dashboard.php
// Datos de resumen para la pantalla de dashboard.
// TODO: cuando exista el esquema de BD (scripts/migrate.php) reemplazar
// la data hardcodeada por consultas reales y agregar requireAuth().

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/lib/auth_check.php';

requirePermission('inicio.dashboard.consultar');
header('Content-Type: application/json; charset=utf-8');

$hoy = new DateTime('now', new DateTimeZone('America/Argentina/Buenos_Aires'));

// Datainfra endpoints: bloque "endpoints activos con problemas". Muestra los
// endpoints con `activo = 1` cuyo ultimo health-check dio `error` o `timeout`.
// Ignora los inactivos (paused por el operador) y los que aun no fueron
// chequeados (`nunca`). Se muestra solo si el usuario tiene permiso de ver el
// modulo Endpoints. Si no hay ninguno con problemas, `items` viene vacio y el
// UI renderiza "Todo bien".
$datainfraEndpoints = null;
if (hasPermission('datainfra.endpoints.consultar')) {
    $pdo = $pdo ?? db();

    $total = (int)$pdo->query(
        'SELECT COUNT(*) FROM datainfra_endpoints WHERE activo = 1'
    )->fetchColumn();

    $conProblemas = (int)$pdo->query(
        "SELECT COUNT(*) FROM datainfra_endpoints
          WHERE activo = 1 AND ultimo_estado IN ('error','timeout')"
    )->fetchColumn();

    $items = [];
    if ($conProblemas > 0) {
        $stmt = $pdo->query("
            SELECT id, nombre, url, metodo,
                   ultimo_estado, ultimo_codigo, ultimo_tiempo_ms,
                   ultimo_check, ultimo_error
              FROM datainfra_endpoints
             WHERE activo = 1 AND ultimo_estado IN ('error','timeout')
             ORDER BY ultimo_check DESC, id DESC
             LIMIT 20
        ");
        $items = $stmt->fetchAll();
    }

    $datainfraEndpoints = [
        'total'         => $total,
        'con_problemas' => $conProblemas,
        'items'         => $items,
    ];
}

// Datainfra dominios: bloque "dominios por vencer en los proximos 30 dias".
// Incluye tambien los ya vencidos (fecha_vencimiento < hoy). Solo
// considera dominios cuyo responsable operativo es Databox — los de responsable
// 'Cliente' se ignoran porque no los renueva Databox y no son un problema
// nuestro. Se muestra solo si el usuario tiene permiso de ver el modulo
// Dominios. Si no hay ninguno por vencer ni vencido, `items` viene vacio y el
// UI renderiza "Todo bien".
$datainfraDominios = null;
if (hasPermission('datainfra.dominios.consultar')) {
    $pdo = $pdo ?? db();

    $total = (int)$pdo->query(
        "SELECT COUNT(*) FROM datainfra_dominios WHERE responsable = 'Databox'"
    )->fetchColumn();

    $porVencer = (int)$pdo->query("
        SELECT COUNT(*) FROM datainfra_dominios
         WHERE responsable = 'Databox'
           AND fecha_vencimiento IS NOT NULL
           AND fecha_vencimiento <= DATE_ADD(CURDATE(), INTERVAL 30 DAY)
           AND fecha_vencimiento >= CURDATE()
    ")->fetchColumn();

    $vencidos = (int)$pdo->query("
        SELECT COUNT(*) FROM datainfra_dominios
         WHERE responsable = 'Databox'
           AND fecha_vencimiento IS NOT NULL
           AND fecha_vencimiento < CURDATE()
    ")->fetchColumn();

    $items = [];
    if (($porVencer + $vencidos) > 0) {
        $stmt = $pdo->query("
            SELECT id, dominio, titular_dominio, responsable,
                   fecha_vencimiento, fecha_suspension, costo_renovacion, moneda,
                   DATEDIFF(fecha_vencimiento, CURDATE()) AS dias
              FROM datainfra_dominios
             WHERE responsable = 'Databox'
               AND fecha_vencimiento IS NOT NULL
               AND fecha_vencimiento <= DATE_ADD(CURDATE(), INTERVAL 30 DAY)
             ORDER BY fecha_vencimiento ASC
             LIMIT 20
        ");
        $items = $stmt->fetchAll();
    }

    $datainfraDominios = [
        'total'      => $total,
        'por_vencer' => $porVencer,
        'vencidos'   => $vencidos,
        'items'      => $items,
    ];
}

// AWS Cuentas: bloque de estado (total + criticas + listado de criticas).
// Se muestra solo si el usuario tiene permiso de ver el modulo AWS Cuentas;
// sino se omite silenciosamente para no filtrar la existencia del recurso.
// Criterio de "critica": mismo que api/awscuentas.php — dia del mes >= 5 y
// facturas_cantidad >= 2 (arrastra al menos la factura del mes anterior).
$awsCuentas = null;
if (hasPermission('plataformas.aws.cuentas.consultar')) {
    $pdo    = $pdo ?? db();
    $diaMes = (int)$hoy->format('j');
    $activo = $diaMes >= 5;

    $total    = (int)$pdo->query('SELECT COUNT(*) FROM aws_cuentas')->fetchColumn();
    $criticas = $activo
        ? (int)$pdo->query(
            'SELECT COUNT(*) FROM aws_cuentas
              WHERE facturas_cantidad >= 2 AND actualizada IS NOT NULL'
          )->fetchColumn()
        : 0;

    $items = [];
    if ($activo && $criticas > 0) {
        $stmt = $pdo->query('
            SELECT id, nombre, numero, facturas_cantidad, facturas_total, facturas_moneda, actualizada
              FROM aws_cuentas
             WHERE facturas_cantidad >= 2 AND actualizada IS NOT NULL
             ORDER BY facturas_cantidad DESC, facturas_total DESC
             LIMIT 20
        ');
        $items = $stmt->fetchAll();
    }

    $awsCuentas = [
        'activo'   => $activo, // false = todavia estamos en dia 1-5 del mes
        'total'    => $total,
        'criticas' => $criticas,
        'items'    => $items,
    ];
}

// Evolution canales: bloque "canales offline pero habilitados". Mismo criterio
// que el badge del ABM: online='0' es un estado explicito (Evolution nos
// contesto que la instancia esta desconectada); online='' / NULL no cuentan
// porque son "desconocido" (el job aun no corrio contra ese canal). Se
// muestra solo si el usuario tiene permiso de ver el modulo.
$evolutionCanales = null;
if (hasPermission('plataformas.evolution.canales.consultar')) {
    $pdo = $pdo ?? db();

    $total   = (int)$pdo->query('SELECT COUNT(*) FROM evolution_canales')->fetchColumn();
    $offline = (int)$pdo->query(
        "SELECT COUNT(*) FROM evolution_canales
          WHERE habilitado = '1' AND online = '0'"
    )->fetchColumn();

    $items = [];
    if ($offline > 0) {
        $stmt = $pdo->query("
            SELECT id, nombre, prefijo, numero, celular, latido, actualizado
              FROM evolution_canales
             WHERE habilitado = '1' AND online = '0'
             ORDER BY actualizado DESC, id DESC
             LIMIT 20
        ");
        $items = $stmt->fetchAll();
    }

    $evolutionCanales = [
        'total'   => $total,
        'offline' => $offline,
        'items'   => $items,
    ];
}

// Datacount cheques: bloque "cheques por entrar en los proximos 7 dias".
// `fecha_pago` es la fecha desde la cual el cheque se puede presentar, o sea
// cuando entra al banco y golpea la cuenta. Solo cuentan los que todavia estan
// en la calle (emitido / entregado / depositado): un cheque pagado ya se
// debito, y uno rechazado o anulado no va a entrar nunca, asi que ninguno de
// los tres necesita que se lo avise.
//
// Incluye tambien los ya vencidos (fecha_pago < hoy y sin debitar): esos son
// justamente los mas urgentes, porque el cheque esta habilitado para
// presentarse y la cuenta todavia no lo acuso. El UI los pinta en rojo y a los
// proximos en amarillo. Se muestra solo si el usuario tiene permiso de ver el
// modulo Cheques; si no hay ninguno, `items` viene vacio y se renderiza
// "Todo bien".
$datacountCheques = null;
if (hasPermission('datacount.bancos.cheques.consultar')) {
    $pdo = $pdo ?? db();

    $pendientes = (int)$pdo->query(
        "SELECT COUNT(*) FROM datacount_bancos_cheques
          WHERE estado IN ('emitido','entregado','depositado')"
    )->fetchColumn();

    $porEntrar = (int)$pdo->query("
        SELECT COUNT(*) FROM datacount_bancos_cheques
         WHERE estado IN ('emitido','entregado','depositado')
           AND fecha_pago >= CURDATE()
           AND fecha_pago <= DATE_ADD(CURDATE(), INTERVAL 7 DAY)
    ")->fetchColumn();

    $vencidos = (int)$pdo->query("
        SELECT COUNT(*) FROM datacount_bancos_cheques
         WHERE estado IN ('emitido','entregado','depositado')
           AND fecha_pago < CURDATE()
    ")->fetchColumn();

    $items = [];
    if (($porEntrar + $vencidos) > 0) {
        // Misma cadena de JOINs que api/datacount_bancos_cheques.php: la moneda
        // es de la cuenta y la clase (papel / electronico) es de la chequera —
        // el cheque no las guarda.
        $stmt = $pdo->query("
            SELECT q.id, q.numero, q.fecha_pago, q.importe, q.estado,
                   q.beneficiario_razon,
                   ch.clase  AS chequera_clase,
                   cu.moneda AS moneda,
                   NULLIF(TRIM(COALESCE(e.nombre, '')), '') AS empresa_nombre,
                   DATEDIFF(q.fecha_pago, CURDATE()) AS dias
              FROM datacount_bancos_cheques          q
              INNER JOIN datacount_bancos_chequeras ch ON ch.id = q.chequera_id
              INNER JOIN datacount_bancos_cuentas   cu ON cu.id = ch.cuenta_id
              LEFT  JOIN datacount_empresas         e  ON e.id  = q.empresa_id
             WHERE q.estado IN ('emitido','entregado','depositado')
               AND q.fecha_pago <= DATE_ADD(CURDATE(), INTERVAL 7 DAY)
             ORDER BY q.fecha_pago ASC, q.id ASC
             LIMIT 20
        ");
        $items = $stmt->fetchAll();
    }

    $datacountCheques = [
        'pendientes' => $pendientes,
        'por_entrar' => $porEntrar,
        'vencidos'   => $vencidos,
        'items'      => $items,
    ];
}

$data = [
    'stats' => [
        'correos_hoy'       => 12840,
        'whatsapp_hoy'      => 6420,
        'campanias_activas' => 7,
        'clientes'          => 38,
    ],
    'datainfra_endpoints'           => $datainfraEndpoints,
    'datainfra_dominios'            => $datainfraDominios,
    'aws_cuentas'                   => $awsCuentas,
    'evolution_canales'             => $evolutionCanales,
    'datacount_cheques'             => $datacountCheques,
];

echo json_encode(['ok' => true, 'data' => $data], JSON_UNESCAPED_UNICODE);
