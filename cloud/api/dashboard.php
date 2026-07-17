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

// Datarocket dominios: bloque "dominios por vencer en los proximos 30 dias".
// Incluye tambien los ya vencidos (fecha_siguiente_renovacion < hoy). Se muestra
// solo si el usuario tiene permiso de ver el modulo Dominios. Si no hay ninguno
// por vencer ni vencido, `items` viene vacio y el UI renderiza "Todo bien".
$datarocketDominios = null;
if (hasPermission('datarocket.dominios.consultar')) {
    $pdo = db();

    $total = (int)$pdo->query('SELECT COUNT(*) FROM datarocket_dominios')->fetchColumn();

    $porVencer = (int)$pdo->query('
        SELECT COUNT(*) FROM datarocket_dominios
         WHERE fecha_siguiente_renovacion IS NOT NULL
           AND fecha_siguiente_renovacion <= DATE_ADD(CURDATE(), INTERVAL 30 DAY)
           AND fecha_siguiente_renovacion >= CURDATE()
    ')->fetchColumn();

    $vencidos = (int)$pdo->query('
        SELECT COUNT(*) FROM datarocket_dominios
         WHERE fecha_siguiente_renovacion IS NOT NULL
           AND fecha_siguiente_renovacion < CURDATE()
    ')->fetchColumn();

    $items = [];
    if (($porVencer + $vencidos) > 0) {
        $stmt = $pdo->query('
            SELECT id, dominio, titular_dominio, responsable,
                   fecha_siguiente_renovacion, costo_renovacion, moneda,
                   DATEDIFF(fecha_siguiente_renovacion, CURDATE()) AS dias
              FROM datarocket_dominios
             WHERE fecha_siguiente_renovacion IS NOT NULL
               AND fecha_siguiente_renovacion <= DATE_ADD(CURDATE(), INTERVAL 30 DAY)
             ORDER BY fecha_siguiente_renovacion ASC
             LIMIT 20
        ');
        $items = $stmt->fetchAll();
    }

    $datarocketDominios = [
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

    $total    = (int)$pdo->query('SELECT COUNT(*) FROM awscuentas')->fetchColumn();
    $criticas = $activo
        ? (int)$pdo->query(
            'SELECT COUNT(*) FROM awscuentas
              WHERE facturas_cantidad >= 2 AND actualizada IS NOT NULL'
          )->fetchColumn()
        : 0;

    $items = [];
    if ($activo && $criticas > 0) {
        $stmt = $pdo->query('
            SELECT id, nombre, numero, facturas_cantidad, facturas_total, facturas_moneda, actualizada
              FROM awscuentas
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

    $total   = (int)$pdo->query('SELECT COUNT(*) FROM evolutioncanales')->fetchColumn();
    $offline = (int)$pdo->query(
        "SELECT COUNT(*) FROM evolutioncanales
          WHERE habilitado = '1' AND online = '0'"
    )->fetchColumn();

    $items = [];
    if ($offline > 0) {
        $stmt = $pdo->query("
            SELECT id, nombre, prefijo, numero, celular, actualizado
              FROM evolutioncanales
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

$data = [
    'stats' => [
        'correos_hoy'       => 12840,
        'whatsapp_hoy'      => 6420,
        'campanias_activas' => 7,
        'clientes'          => 38,
    ],
    'datarocket_dominios' => $datarocketDominios,
    'aws_cuentas'         => $awsCuentas,
    'evolution_canales'   => $evolutionCanales,
];

echo json_encode(['ok' => true, 'data' => $data], JSON_UNESCAPED_UNICODE);
