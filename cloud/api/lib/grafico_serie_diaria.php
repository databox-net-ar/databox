<?php
// api/lib/grafico_serie_diaria.php
// Serie diaria de eventos agrupada por una dimension, para los graficos que van
// arriba de la grilla de modulos en los landings de plataforma.
//
// La cuenta es siempre la misma —"cuantas filas por dia, abiertas por grupo, en
// los ultimos N dias"— y lo unico que cambia entre pantallas es de que tabla
// salen las filas, que columna las fecha, que las filtra y contra que catalogo
// se resuelve el nombre del grupo. Todo eso vive en GRAF_SERIE_FUENTES; el
// endpoint elige una clave y valida su permiso, nada mas.
//
// Endpoints que delegan aca:
//   api/evolutionmensajes_grafico.php        mensajes enviados / canal
//   api/awsmensajes_grafico.php              correos enviados  / canal
//   api/telegrammensajes_grafico.php         mensajes enviados / canal
//   api/datarocket_interacciones_grafico.php interacciones     / proyecto
//
// En las tres de mensajeria "enviado" es estado='enviado' con `enviado` no
// nulo, y el dia que cuenta es el de la SALIDA real (el NOW() que estampan los
// senders de lib/mensajes_enviar.php y lib/telegram_mensajes_enviar.php), no el
// de encolado: un mensaje encolado el lunes y despachado el martes cuenta el
// martes. Los pendientes, anulados y con error quedan afuera a proposito — el
// grafico responde "cuanto salio", no "cuanto se cargo".
//
// El corte por dia lo hace MySQL (DATE(...) contra CURDATE()), nunca el
// navegador: el cliente puede estar en otra zona horaria y "hoy" tiene que ser
// el mismo dia que ve el ABM.
//
// La serie viene CONTINUA: los dias sin actividad viajan en 0. Sin eso el
// frontend tendria que adivinar los huecos y la linea uniria dos dias no
// consecutivos como si fueran vecinos.

// Tope de lineas del grafico. Es el largo de la paleta categorica del panel
// (ocho tonos validados sobre el fondo oscuro): mas lineas no se distinguen
// entre si. Si hay mas grupos con actividad, entran los
// GRAF_SERIE_MAX_SERIES - 1 de mayor volumen y el resto se pliega en 'Otros'.
const GRAF_SERIE_MAX_SERIES = 8;

// Fuentes habilitadas. Los fragmentos de SQL de cada entrada se interpolan en
// la consulta — son constantes de este archivo y NUNCA pueden salir de un
// parametro del request: el endpoint elige una clave de este mapa.
//
//   tabla      tabla de hechos (alias fijo `t` en el SQL)
//   fecha      columna que fecha la fila (la que se agrupa por dia)
//   where      filtro extra, sin la condicion de rango
//   grupo      expresion que devuelve el id del grupo (canal, proyecto, …)
//   join       joins extra que necesite `grupo` (o '')
//   catalogo   tabla con `id` + `nombre` para resolver el nombre del grupo
//   sin_grupo  etiqueta de las filas cuyo grupo es NULL
//   plural     como llamar al grupo en el mensaje de "Otros (N …)"
const GRAF_SERIE_FUENTES = [
    'evolution' => [
        'tabla'     => 'evolution_mensajes',
        'fecha'     => 'enviado',
        'where'     => "t.estado = 'enviado' AND t.enviado IS NOT NULL",
        'grupo'     => 't.canal_id',
        'join'      => '',
        'catalogo'  => 'evolution_canales',
        'sin_grupo' => 'Sin canal',
        'plural'    => 'canales',
    ],
    'aws' => [
        'tabla'     => 'aws_mensajes',
        'fecha'     => 'enviado',
        'where'     => "t.estado = 'enviado' AND t.enviado IS NOT NULL",
        'grupo'     => 't.canal_id',
        'join'      => '',
        'catalogo'  => 'aws_canales',
        'sin_grupo' => 'Sin canal',
        'plural'    => 'canales',
    ],
    'telegram' => [
        'tabla'     => 'telegram_mensajes',
        'fecha'     => 'enviado',
        'where'     => "t.estado = 'enviado' AND t.enviado IS NOT NULL",
        'grupo'     => 't.canal_id',
        'join'      => '',
        'catalogo'  => 'telegram_canales',
        'sin_grupo' => 'Sin canal',
        'plural'    => 'canales',
    ],
    // Interacciones: cuentan TODAS (entrantes y salientes, respondidas o no).
    // No es una cola de envio como las de arriba — la interaccion ya ocurrio, y
    // lo que muestra el grafico es el movimiento diario de cada proyecto.
    //
    // El proyecto sale de COALESCE(propio, el de la oportunidad), en ese orden:
    // es la misma lectura que hace el ABM (api/datarocketinteracciones.php) y
    // la que dejo escrita la migracion 20260828_2300 al agregar la columna. Las
    // interacciones viejas ligadas a una oportunidad siguen contando bajo el
    // proyecto de esa oportunidad.
    'datarocket_interacciones' => [
        'tabla'     => 'datarocket_interacciones',
        'fecha'     => 'fecha',
        'where'     => '',
        'grupo'     => 'COALESCE(t.proyecto_id, o.proyecto_id)',
        'join'      => 'LEFT JOIN datarocket_oportunidades o ON o.id = t.oportunidad_id',
        'catalogo'  => 'proyectos',
        'sin_grupo' => 'Sin proyecto',
        'plural'    => 'proyectos',
    ],
];

/**
 * Arma la serie diaria por grupo para una de las fuentes de GRAF_SERIE_FUENTES.
 *
 * @param  string $fuente  clave de GRAF_SERIE_FUENTES
 * @param  int    $dias    ventana en dias contando hoy (se acota a 1..90)
 * @return array           {dias, desde, hasta, total, grupos, fechas[], series[]}
 */
function graficoSerieDiaria(PDO $pdo, string $fuente, int $dias): array {
    if (!isset(GRAF_SERIE_FUENTES[$fuente])) {
        throw new InvalidArgumentException("Fuente de grafico desconocida: {$fuente}");
    }
    $f = GRAF_SERIE_FUENTES[$fuente];

    if ($dias < 1)  $dias = 1;
    if ($dias > 90) $dias = 90;

    // Eje X: los ultimos $dias dias segun el reloj del SERVIDOR, mismo criterio
    // que el WHERE de abajo. Se pide CURDATE() en vez de usar date() de PHP por
    // si el contenedor y el motor discrepan de zona horaria.
    $hoy    = (string)$pdo->query('SELECT CURDATE()')->fetchColumn();
    $fechas = [];
    $cursor = new DateTimeImmutable($hoy);
    for ($i = $dias - 1; $i >= 0; $i--) {
        $fechas[] = $cursor->sub(new DateInterval('P' . $i . 'D'))->format('Y-m-d');
    }

    $filtro = $f['where'] !== '' ? "({$f['where']}) AND" : '';
    $stmt = $pdo->prepare("
        SELECT {$f['grupo']}          AS grupo_id,
               DATE(t.`{$f['fecha']}`) AS fecha,
               COUNT(*)               AS cantidad
          FROM `{$f['tabla']}` t
          {$f['join']}
         WHERE {$filtro} t.`{$f['fecha']}` >= DATE_SUB(CURDATE(), INTERVAL :dias DAY)
         GROUP BY {$f['grupo']}, DATE(t.`{$f['fecha']}`)
    ");
    $stmt->bindValue(':dias', $dias - 1, PDO::PARAM_INT);
    $stmt->execute();

    // Acumulador: [grupo_id => ['YYYY-MM-DD' => cantidad]]. El grupo NULL entra
    // con la clave 0 y se muestra como "Sin canal" / "Sin proyecto" — es
    // informacion real, no un error, y esconderla haria que la suma de las
    // lineas no diera el total.
    $porGrupo = [];
    $total    = 0;
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $grupo = (int)($row['grupo_id'] ?? 0);
        $cant  = (int)$row['cantidad'];
        $porGrupo[$grupo][$row['fecha']] = $cant;
        $total += $cant;
    }

    // Nombres de los grupos que aparecen en la ventana. Se piden todos juntos
    // (una query) y no con un JOIN en el GROUP BY: el nombre no participa de la
    // agrupacion y meterlo ahi obliga al motor a arrastrarlo por cada fila.
    $nombres = [];
    $ids     = array_values(array_filter(array_keys($porGrupo), fn($id) => $id > 0));
    if ($ids) {
        $in  = implode(',', array_fill(0, count($ids), '?'));
        $st2 = $pdo->prepare("SELECT id, nombre FROM `{$f['catalogo']}` WHERE id IN ($in)");
        $st2->execute($ids);
        foreach ($st2->fetchAll(PDO::FETCH_ASSOC) as $c) {
            $nombres[(int)$c['id']] = trim((string)$c['nombre']);
        }
    }

    // Una serie por grupo, ordenadas por volumen descendente: el frontend
    // asigna los colores en ese orden y las lineas que importan se llevan los
    // tonos de mas contraste.
    $series = [];
    foreach ($porGrupo as $grupoId => $porFecha) {
        $valores = [];
        $subtot  = 0;
        foreach ($fechas as $fec) {
            $v = (int)($porFecha[$fec] ?? 0);
            $valores[] = $v;
            $subtot   += $v;
        }
        $series[] = [
            'grupo_id' => $grupoId > 0 ? $grupoId : null,
            'nombre'   => $grupoId > 0
                          ? (($nombres[$grupoId] ?? '') !== '' ? $nombres[$grupoId] : "#$grupoId")
                          : $f['sin_grupo'],
            'total'    => $subtot,
            'valores'  => $valores,
        ];
    }
    usort($series, fn($a, $b) => $b['total'] <=> $a['total']);

    $grupos = count($series);

    // Cola plegada en 'Otros': se suman dia a dia los grupos que quedan fuera
    // del tope. La serie 'Otros' va siempre ultima aunque su total supere al de
    // la ultima serie individual — no es un grupo, es el resto.
    if ($grupos > GRAF_SERIE_MAX_SERIES) {
        $cabeza = array_slice($series, 0, GRAF_SERIE_MAX_SERIES - 1);
        $cola   = array_slice($series, GRAF_SERIE_MAX_SERIES - 1);

        $valores = array_fill(0, count($fechas), 0);
        $subtot  = 0;
        foreach ($cola as $s) {
            foreach ($s['valores'] as $i => $v) $valores[$i] += $v;
            $subtot += $s['total'];
        }

        $cabeza[] = [
            'grupo_id' => null,
            'nombre'   => 'Otros (' . count($cola) . ' ' . $f['plural'] . ')',
            'total'    => $subtot,
            'valores'  => $valores,
            'agrupada' => true,
        ];
        $series = $cabeza;
    }

    return [
        'dias'   => $dias,
        'desde'  => $fechas[0],
        'hasta'  => $fechas[count($fechas) - 1],
        'total'  => $total,
        'grupos' => $grupos,
        'fechas' => $fechas,
        'series' => $series,
    ];
}

/**
 * Lee `?dias=` de la query string con el default de los landings (30 dias).
 * El acotado 1..90 lo hace graficoSerieDiaria().
 */
function graficoSerieDias(): int {
    return isset($_GET['dias']) ? (int)$_GET['dias'] : 30;
}
