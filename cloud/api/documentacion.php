<?php
/**
 * API cloud — Administracion: Documentacion.
 *
 * Descubre los microservicios del arbol `api/` y los documentos del panel, y
 * sirve el contenido CRUDO de cada `.md`. El render a HTML lo hace el front
 * (`mdRender()` en assets/js/app.js) — aca no se arma markup.
 *
 *   GET api/documentacion.php              -> indice de servicios y documentos
 *   GET api/documentacion.php?doc=<ruta>   -> contenido markdown de un archivo
 *
 * Permiso: `administracion.documentacion.consultar` (solo lectura, no hay
 * escritura por ninguna via — los `.md` se editan en el repo y viajan por el
 * deploy).
 *
 * ---------------------------------------------------------------------------
 * DE DONDE SALE EL LISTADO
 * ---------------------------------------------------------------------------
 * No hay un catalogo en la base ni un JSON que haya que mantener a mano: el
 * indice se arma escaneando el FILESYSTEM en cada request. Un microservicio
 * nuevo aparece en el navegador apenas se deploya, y uno que se borra
 * desaparece solo. Un catalogo manual se desincroniza el primer dia que
 * alguien agrega un endpoint y se olvida de anotarlo — y un navegador de
 * documentacion que miente es peor que no tenerlo.
 *
 * El escaneo es barato (dos carpetas, ~40 archivos) y no se cachea.
 *
 * ---------------------------------------------------------------------------
 * QUE ENTRA Y QUE NO
 * ---------------------------------------------------------------------------
 * Solo las dos fuentes de DOC_FUENTES:
 *
 *   `api/v4/<carpeta>/*.php`  -> un item por microservicio, con su `.md` hermano
 *                               si lo tiene.
 *   `cloud/*.md`              -> los documentos del panel (DESIGN, STACK, ABM,
 *                               CLAUDE), sin recursion.
 *
 * Queda AFUERA a proposito:
 *
 *   * `www/templates/` — son plantillas de terceros (AdminLTE, CKEditor,
 *     Chart.js, PHPMailer) y traen ~120 `.md` de vendor. Un buscador que los
 *     mezcle con los nuestros es inservible: la documentacion de nuestros
 *     servicios queda enterrada entre READMEs de librerias que nadie mantiene.
 *   * Carpetas y archivos que empiezan con `_` (`api/v4/_lib`, `arca/_afip`):
 *     son librerias internas, no servicios publicados.
 *   * `api/index.php`, `api/supervisor.php` y `api/v4/test.php`: sondas de
 *     salud que devuelven `.` / `OK` / `databox_new`. No son servicios y no
 *     estan dentro de ninguna carpeta de v4, asi que el escaneo por carpeta
 *     ya no los ve.
 *   * El README y el CLAUDE.md de la raiz del repo: NO estan montados en el
 *     contenedor (docker-compose monta `cloud`, `www`, `robot`, `api`, `env.php`
 *     y `certs`, no la raiz), asi que desde el panel son ilegibles. Listarlos
 *     seria ofrecer un link que siempre da 404.
 *
 * ---------------------------------------------------------------------------
 * `estado`: LA COLUMNA QUE HACE UTIL EL LISTADO
 * ---------------------------------------------------------------------------
 * El listado no muestra "los que tienen documentacion": muestra TODOS los
 * servicios y dice cual es la situacion de cada uno. Un endpoint publicado y
 * sin documentar es justamente lo que hay que poder ver de un vistazo —
 * filtrar esos de la lista los volveria invisibles y nadie los escribiria nunca.
 *
 *   `documentado` -> tiene `.md` con contenido.
 *   `sin_doc`     -> el `.php` existe y tiene codigo, pero no hay `.md` (o esta vacio).
 *   `placeholder` -> el `.php` esta vacio: el servicio esta reservado, no escrito.
 *
 * ---------------------------------------------------------------------------
 * SEGURIDAD DEL PARAMETRO `doc`
 * ---------------------------------------------------------------------------
 * `?doc=` es una ruta que manda el cliente, o sea la superficie clasica de un
 * path traversal (`?doc=../../../../etc/passwd`, o un symlink apuntando afuera).
 * docResolverRuta() la valida en cuatro pasos y cualquiera que falle devuelve
 * null — nunca una ruta a medias:
 *
 *   1. Forma: solo `[A-Za-z0-9._/-]` y terminada en `.md`. Corta las rutas
 *      absolutas, los bytes nulos y todo lo que no parezca un documento.
 *   2. Sin `..` en ningun segmento.
 *   3. `realpath()`, que resuelve symlinks y `.` — la comparacion se hace
 *      sobre la ruta REAL, no sobre el texto que mando el cliente.
 *   4. La ruta real tiene que caer DENTRO de una de las raices declaradas.
 *
 * El paso 4 es el que importa: sin el, los tres anteriores se esquivan con un
 * symlink que pase el regex y apunte afuera del arbol.
 */
ini_set('display_errors', 0);
error_reporting(E_ALL);
header('Content-Type: application/json; charset=utf-8');

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'OPTIONS') { exit; }

require_once __DIR__ . '/lib/auth_check.php';
requireAuth();
require_once __DIR__ . '/db.php';

// Raiz del repo. __DIR__ = cloud/api  ->  ../.. = raiz (en el contenedor,
// /var/www). Mismo calculo que db.php para llegar a env.php.
function docRaiz(): string { return dirname(__DIR__, 2); }

// Fuentes del indice. `tipo` decide como se recorre:
//   'v4'    -> subcarpetas de `base`, un item por `.php` (mas su `.md` hermano).
//   'plano' -> los `.md` que esten directamente en `base`, sin recursion.
const DOC_FUENTES = [
    ['clave' => 'v4',    'grupo' => 'Microservicios v4', 'icono' => '🛰️',
     'tipo'  => 'v4',    'base'  => 'api/v4'],
    ['clave' => 'panel', 'grupo' => 'Panel cloud',       'icono' => '📘',
     'tipo'  => 'plano', 'base'  => 'cloud'],
];

// Nombre legible de cada carpeta de `api/v4`. Lo que no este aca cae a
// ucfirst() — una carpeta nueva entra al navegador sin tocar este mapa, solo
// con un titulo menos prolijo.
const DOC_SECCIONES = [
    'arca'        => 'Arca (AFIP)',
    'aws'         => 'AWS',
    'claro'       => 'Claro',
    'databox'     => 'Databox',
    'datacount'   => 'Datacount',
    'datarocket'  => 'Datarocket',
    'dolarhoy'    => 'DolarHoy',
    'evolution'   => 'Evolution (WhatsApp)',
    'mercadopago' => 'Mercado Pago',
    'movistar'    => 'Movistar',
    'telegram'    => 'Telegram',
];

// Host publico del vhost de la API, para poder ofrecer el enlace "ver online"
// del `.md` y la URL real del endpoint. Por APP_ENV y no por el Host del
// request: el panel se sirve desde cloud.databox.net.ar, la API desde otro vhost.
const DOC_API_BASE_PROD = 'https://api.databox.net.ar';
const DOC_API_BASE_DEV  = 'http://localhost:8114';

// Techo del texto que se manda como `descripcion` en el indice. Es un resumen
// para la lista lateral, no el documento.
const DOC_DESC_MAX = 180;

try {
    requirePermission('administracion.documentacion.consultar');

    if (isset($_GET['doc'])) {
        handleDoc((string)$_GET['doc']);
    }
    handleIndice();
} catch (Throwable $e) {
    jsonError($e->getMessage(), 500);
}

// ---------------------------------------------------------------------------
// Indice
// ---------------------------------------------------------------------------

function handleIndice(): void {
    $items = [];
    foreach (DOC_FUENTES as $fuente) {
        $items = array_merge($items, $fuente['tipo'] === 'v4'
            ? docEscanearV4($fuente)
            : docEscanearPlano($fuente));
    }

    // Contadores del encabezado. Se calculan aca y no en el front para que las
    // dos vistas (el resumen y la lista) no puedan contar distinto.
    $resumen = ['total' => count($items), 'documentado' => 0, 'sin_doc' => 0, 'placeholder' => 0];
    foreach ($items as $it) $resumen[$it['estado']]++;

    jsonOk([
        'entorno'  => getenv('APP_ENV') ?: 'unknown',
        'api_base' => docApiBase(),
        'resumen'  => $resumen,
        'items'    => $items,
    ]);
}

// `api/v4/<carpeta>/*.php` -> un item por microservicio.
//
// El recorrido es por `.php` y no por `.md` a proposito: el objetivo del
// navegador es mostrar los SERVICIOS, y los que no tienen documentacion son
// justamente los que interesa ver. Un `.md` huerfano (sin `.php` hermano)
// tambien entra, para que un documento no desaparezca del indice por un
// renombre a medias.
function docEscanearV4(array $fuente): array {
    $baseAbs = docRaiz() . '/' . $fuente['base'];
    if (!is_dir($baseAbs)) return [];

    $carpetas = array_filter(scandir($baseAbs) ?: [], static fn($n) =>
        $n[0] !== '.' && $n[0] !== '_' && is_dir($baseAbs . '/' . $n));
    sort($carpetas, SORT_STRING);

    $items = [];
    foreach ($carpetas as $carpeta) {
        $dirAbs  = $baseAbs . '/' . $carpeta;
        $seccion = DOC_SECCIONES[$carpeta] ?? ucfirst($carpeta);

        // Union de los basenames con `.php` y con `.md`: asi un servicio sin
        // doc y un doc sin servicio caen los dos en la misma pasada.
        $nombres = [];
        foreach (glob($dirAbs . '/*.{php,md}', GLOB_BRACE) ?: [] as $ruta) {
            $base = pathinfo($ruta, PATHINFO_FILENAME);
            if ($base === '' || $base[0] === '_') continue;   // libs internas
            $nombres[$base] = true;
        }
        $nombres = array_keys($nombres);
        sort($nombres, SORT_STRING);

        foreach ($nombres as $nombre) {
            $relPhp = $fuente['base'] . '/' . $carpeta . '/' . $nombre . '.php';
            $relMd  = $fuente['base'] . '/' . $carpeta . '/' . $nombre . '.md';
            $absPhp = docRaiz() . '/' . $relPhp;
            $absMd  = docRaiz() . '/' . $relMd;

            $hayPhp   = is_file($absPhp);
            $bytesPhp = $hayPhp ? (int)filesize($absPhp) : 0;
            $hayMd    = is_file($absMd) && filesize($absMd) > 0;

            // La ruta publica va SIN `.php`: es como se documenta y como la
            // llaman los integradores (lo resuelve el .htaccess de `api/`).
            $endpoint = '/v4/' . $carpeta . '/' . $nombre;

            $items[] = docItem([
                'id'       => $relPhp,
                'grupo'    => $fuente['grupo'],
                'icono'    => $fuente['icono'],
                'seccion'  => $seccion,
                'nombre'   => $nombre,
                'doc'      => $hayMd ? $relMd : null,
                'abs_doc'  => $hayMd ? $absMd : null,
                'endpoint' => $hayPhp ? $endpoint : null,
                'estado'   => $hayMd ? 'documentado' : ($bytesPhp > 0 ? 'sin_doc' : 'placeholder'),
            ]);
        }
    }
    return $items;
}

// `cloud/*.md` -> los documentos del panel. Sin recursion: lo que esta suelto
// en la raiz de `cloud/` son los cuatro documentos de referencia del proyecto;
// mas abajo solo hay codigo, assets y migraciones.
function docEscanearPlano(array $fuente): array {
    $baseAbs = docRaiz() . '/' . $fuente['base'];
    if (!is_dir($baseAbs)) return [];

    $archivos = glob($baseAbs . '/*.md') ?: [];
    sort($archivos, SORT_STRING);

    $items = [];
    foreach ($archivos as $absMd) {
        if (filesize($absMd) === 0) continue;   // un doc vacio no es un doc
        $nombre = pathinfo($absMd, PATHINFO_FILENAME);
        $relMd  = $fuente['base'] . '/' . $nombre . '.md';

        $items[] = docItem([
            'id'       => $relMd,
            'grupo'    => $fuente['grupo'],
            'icono'    => $fuente['icono'],
            'seccion'  => 'Referencia del proyecto',
            'nombre'   => $nombre,
            'doc'      => $relMd,
            'abs_doc'  => $absMd,
            'endpoint' => null,
            'estado'   => 'documentado',
        ]);
    }
    return $items;
}

// Completa un item del indice con lo que hay que leer del archivo: titulo,
// descripcion, tamaño y fecha. `abs_doc` es interno y no viaja en el JSON.
function docItem(array $it): array {
    $titulo = $it['nombre'];
    $desc   = null;
    $bytes  = null;
    $modif  = null;

    if ($it['abs_doc'] !== null) {
        [$titulo, $desc] = docResumen($it['abs_doc'], $it['nombre']);
        $bytes = (int)filesize($it['abs_doc']);
        $modif = date('Y-m-d H:i:s', (int)filemtime($it['abs_doc']));
    }

    unset($it['abs_doc']);
    return $it + ['titulo' => $titulo, 'descripcion' => $desc,
                  'bytes' => $bytes, 'modificado' => $modif];
}

/**
 * Titulo y descripcion de un `.md`, para pintar la lista lateral sin tener que
 * abrir el documento.
 *
 * El titulo sale del primer `# `. Se le sacan los backticks porque todos los
 * `.md` de v4 titulan con la ruta del endpoint entre comillas invertidas
 * (`` # `/v4/datarocket/listas` ``) y en una lista eso es ruido.
 *
 * La descripcion es la primera linea de prosa: se saltean encabezados, citas,
 * tablas, vallas de codigo y lineas de metadata (el `> Documentacion online:`
 * con el que arrancan todos). Solo se leen los primeros 4 KB — la prosa inicial
 * siempre esta ahi y leer 80 KB por item para quedarse con dos renglones seria
 * tirar el escaneo a la basura.
 */
function docResumen(string $abs, string $fallback): array {
    $cabeza = (string)@file_get_contents($abs, false, null, 0, 4096);
    if ($cabeza === '') return [$fallback, null];

    $titulo = $fallback;
    $desc   = null;
    $enValla = false;

    foreach (preg_split('/\R/', $cabeza) ?: [] as $linea) {
        $l = trim($linea);
        if ($l === '') continue;

        // Las vallas de codigo se saltean enteras: adentro puede haber
        // cualquier cosa que parezca prosa (un `curl`, un JSON) y no lo es.
        if (str_starts_with($l, '```')) { $enValla = !$enValla; continue; }
        if ($enValla) continue;

        if (str_starts_with($l, '# ')) {
            if ($titulo === $fallback) $titulo = trim(str_replace('`', '', substr($l, 2)));
            continue;
        }
        // Sub-encabezados, citas, tablas, listas, separadores y comentarios
        // HTML no son la descripcion.
        if (preg_match('/^(#{2,}\s|>|\||[-*+]\s|-{3,}|={3,}|<!--)/', $l)) continue;

        $desc = mb_substr($l, 0, DOC_DESC_MAX);
        if (mb_strlen($l) > DOC_DESC_MAX) $desc .= '…';
        break;
    }

    return [$titulo, $desc];
}

// ---------------------------------------------------------------------------
// Contenido de un documento
// ---------------------------------------------------------------------------

function handleDoc(string $rel): void {
    $abs = docResolverRuta($rel);
    if ($abs === null) {
        // El mismo error para "ruta invalida" y para "no existe": distinguirlos
        // convierte el endpoint en un oraculo para mapear el filesystem.
        jsonError('Documento no encontrado', 404);
    }

    $contenido = @file_get_contents($abs);
    if ($contenido === false) jsonError('No se pudo leer el documento', 500);

    [$titulo] = docResumen($abs, pathinfo($abs, PATHINFO_FILENAME));

    jsonOk([
        'doc'         => $rel,
        'titulo'      => $titulo,
        // Markdown CRUDO. El render a HTML lo hace el front (mdRender()), que
        // escapa todo antes de aplicar formato: si el HTML se armara aca,
        // habria que escapar en los dos lados y el dia que uno de los dos se
        // relaje queda un XSS almacenado a un `git push` de distancia.
        'contenido'   => $contenido,
        'bytes'       => strlen($contenido),
        'modificado'  => date('Y-m-d H:i:s', (int)filemtime($abs)),
        // Enlace al `.md` publicado por el vhost de la API. Solo para los
        // documentos que viven bajo `api/` — los del panel no se publican.
        'url_publica' => str_starts_with($rel, 'api/')
            ? docApiBase() . substr($rel, strlen('api'))
            : null,
    ]);
}

/**
 * Valida y resuelve una ruta relativa a la raiz del repo. Devuelve la ruta
 * absoluta real, o null si no es un documento legitimo del arbol permitido.
 * Ver "SEGURIDAD DEL PARAMETRO `doc`" en el encabezado.
 */
function docResolverRuta(string $rel): ?string {
    if ($rel === '' || strlen($rel) > 300)          return null;
    if (!preg_match('~^[A-Za-z0-9._/-]+\.md$~', $rel)) return null;
    // El regex ya no deja pasar `\` ni bytes nulos; falta el `..`, que si
    // matchea `[.]`. Se chequea por segmento y no con str_contains para no
    // rechazar un nombre legitimo como `v1..v2.md`.
    foreach (explode('/', $rel) as $seg) {
        if ($seg === '' || $seg === '.' || $seg === '..') return null;
    }

    $abs = realpath(docRaiz() . '/' . $rel);
    if ($abs === false || !is_file($abs)) return null;

    foreach (DOC_FUENTES as $fuente) {
        $raiz = realpath(docRaiz() . '/' . $fuente['base']);
        if ($raiz !== false && str_starts_with($abs, $raiz . DIRECTORY_SEPARATOR)) {
            return $abs;
        }
    }
    return null;
}

function docApiBase(): string {
    return (getenv('APP_ENV') === 'production') ? DOC_API_BASE_PROD : DOC_API_BASE_DEV;
}
