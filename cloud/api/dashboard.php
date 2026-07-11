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

function fechaRel(DateTime $base, int $minutosAtras): string {
    $d = clone $base;
    $d->modify("-{$minutosAtras} minutes");
    return $d->format(DATE_ATOM);
}

$data = [
    'stats' => [
        'correos_hoy'       => 12840,
        'whatsapp_hoy'      => 6420,
        'campanias_activas' => 7,
        'clientes'          => 38,
    ],
    'ultimas_campanias' => [
        ['nombre' => 'Newsletter mayo',           'canal' => 'email',    'estado' => 'enviada',  'enviados' => 8420, 'fecha' => fechaRel($hoy, 15)],
        ['nombre' => 'Promo invierno WhatsApp',   'canal' => 'whatsapp', 'estado' => 'enviando', 'enviados' => 2140, 'fecha' => fechaRel($hoy, 35)],
        ['nombre' => 'Recordatorio pagos',        'canal' => 'whatsapp', 'estado' => 'enviada',  'enviados' => 4280, 'fecha' => fechaRel($hoy, 120)],
        ['nombre' => 'Encuesta NPS',              'canal' => 'email',    'estado' => 'pausada',  'enviados' => 1200, 'fecha' => fechaRel($hoy, 240)],
        ['nombre' => 'Aviso de mantenimiento',    'canal' => 'email',    'estado' => 'fallida',  'enviados' => 0,    'fecha' => fechaRel($hoy, 360)],
    ],
    'ultimos_mensajes' => [
        ['contacto' => 'Juan Pérez',     'telefono' => '+54 9 11 5555-1234', 'ultimo_mensaje' => 'Listo, recibido. Gracias!',     'fecha' => fechaRel($hoy, 4)],
        ['contacto' => 'María González', 'telefono' => '+54 9 11 4444-9876', 'ultimo_mensaje' => 'A qué hora abren mañana?',       'fecha' => fechaRel($hoy, 12)],
        ['contacto' => 'Laura Méndez',   'telefono' => '+54 9 11 3333-5511', 'ultimo_mensaje' => 'Quiero darme de baja.',          'fecha' => fechaRel($hoy, 22)],
        ['contacto' => 'Carlos Ruiz',    'telefono' => '+54 9 11 2222-0099', 'ultimo_mensaje' => 'Me llegó el comprobante, ok.',   'fecha' => fechaRel($hoy, 45)],
        ['contacto' => 'Sofía Torres',   'telefono' => '+54 9 11 1111-7788', 'ultimo_mensaje' => 'Necesito hablar con un humano.', 'fecha' => fechaRel($hoy, 80)],
    ],
];

echo json_encode(['ok' => true, 'data' => $data], JSON_UNESCAPED_UNICODE);
