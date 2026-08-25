<?php
// api/datarocket_redes_sociales.php
// Datarocket > Redes sociales (CRUD). Lee/escribe sobre la tabla
// `datarocket_redes_sociales` — cada fila es UNA CUENTA de una red social
// perteneciente a un proyecto del grupo, con sus credenciales y los datos que
// hacen falta para publicar en ella. La justificacion del modelo (una sola
// tabla, catalogo de plataformas en `estados`, secretos cifrados) esta en la
// migracion 20260824_1300_datarocket_redes_sociales_modulo.sql.
//
//   GET    api/datarocket_redes_sociales.php[?q=..&proyecto=..&plataforma=..&postiz=..&activa=..&limite=100&orden=id&dir=desc]
//                                                    -> listado + stats (secretos enmascarados)
//   GET    api/datarocket_redes_sociales.php?id=N     -> registro individual (secretos en claro)
//   GET    api/datarocket_redes_sociales.php?lookups=1-> catalogos para los <select>
//   POST   api/datarocket_redes_sociales.php          -> alta (JSON body)
//   PUT    api/datarocket_redes_sociales.php?id=N     -> modificacion (JSON body)
//   DELETE api/datarocket_redes_sociales.php?id=N     -> baja
//
// SECRETOS: `contrasena`, `app_secret`, `access_token` y `refresh_token` se
// guardan cifrados con encriptar()/desencriptar() (db.php), la cifra reversible
// legacy del grupo — mismo criterio que `accesos.contrasena`. El LISTADO los
// reemplaza por '***' (no tiene sentido volcar todos los tokens del grupo en
// cada pintada de la tabla); solo el GET por id los devuelve en claro, que es
// lo que necesita el modal de Consultar/Editar para poder copiarlos.
//
// Respuesta siempre {ok: true, data: ...} u {ok: false, error: '...'} (STACK.md sec. 10).

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/lib/auth_check.php';
require_once __DIR__ . '/lib/sucesos.php';

requireAuth();
header('Content-Type: application/json; charset=utf-8');

const DRRS_TABLA   = 'datarocket_redes_sociales';
const DRRS_ORDENES = ['id', 'nombre', 'plataforma', 'usuario', 'postiz_estado',
                      'activa', 'fecha_creacion', 'fecha_modificacion'];
const DRRS_COLS    = 'id, proyecto_id, plataforma, nombre, slug, tipo_cuenta, usuario, url,
                      cuenta_externa_id, correo, contrasena, app_id, app_secret, access_token,
                      refresh_token, token_expira, postiz_integration_id, postiz_estado,
                      postiz_sync, datos_extra, observaciones, activa,
                      fecha_creacion, fecha_modificacion';

// Columnas cifradas. Se descifran al leer y se cifran al escribir; en el
// listado se enmascaran. Todas se tratan igual, asi que van en una constante
// para no repetir la lista en cuatro lugares.
const DRRS_SECRETOS = ['contrasena', 'app_secret', 'access_token', 'refresh_token'];

// Campos de `estados` que alimentan los combos del ABM.
const DRRS_CAMPO_PLATAFORMA = 'datarocket_red_social_plataforma';
const DRRS_CAMPO_TIPO       = 'datarocket_red_social_tipo_cuenta';
const DRRS_CAMPO_POSTIZ     = 'datarocket_red_social_postiz_estado';

try {
    requirePermCrud('datarocket.redes_sociales');
    $pdo    = db();
    $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
    $id     = isset($_GET['id']) ? (int) $_GET['id'] : 0;

    if ($method === 'GET' && ($_GET['lookups'] ?? '') !== '') {
        handleLookupsRedSocial($pdo);
    } elseif ($method === 'GET' && $id > 0) {
        handleGetOneRedSocial($pdo, $id);
    } elseif ($method === 'GET') {
        handleListRedesSociales($pdo, $_GET);
    } elseif ($method === 'POST') {
        handleCreateRedSocial($pdo, readJsonBody());
    } elseif ($method === 'PUT') {
        if ($id <= 0) jsonError('Falta id', 400);
        handleUpdateRedSocial($pdo, $id, readJsonBody());
    } elseif ($method === 'DELETE') {
        if ($id <= 0) jsonError('Falta id', 400);
        handleDeleteRedSocial($pdo, $id);
    } else {
        jsonError('Método no soportado', 405);
    }
} catch (Throwable $e) {
    jsonError($e->getMessage(), 500);
}

// ----------------------------------------------------------------------------
// Helpers
// ----------------------------------------------------------------------------

// `$enClaro = false` (listado) enmascara los secretos con '***'; `true`
// (GET por id) los descifra. Un secreto vacio queda en null en los dos casos,
// para que el front pueda distinguir "no hay token cargado" de "hay uno y no te
// lo muestro en la tabla".
function normalizarFilaRedSocial(array $r, bool $enClaro): array {
    $out = [
        'id'                    => (int) ($r['id'] ?? 0),
        'proyecto_id'           => $r['proyecto_id'] !== null ? (int) $r['proyecto_id'] : null,
        'proyecto_nombre'       => isset($r['proyecto_nombre']) && $r['proyecto_nombre'] !== null
                                     ? (string) $r['proyecto_nombre'] : null,
        'plataforma'            => (string) ($r['plataforma'] ?? ''),
        'plataforma_texto'      => isset($r['plataforma_texto']) && $r['plataforma_texto'] !== null
                                     ? (string) $r['plataforma_texto'] : null,
        'nombre'                => (string) ($r['nombre'] ?? ''),
        'slug'                  => (string) ($r['slug'] ?? ''),
        'tipo_cuenta'           => $r['tipo_cuenta']           !== null ? (string) $r['tipo_cuenta']           : null,
        'usuario'               => $r['usuario']               !== null ? (string) $r['usuario']               : null,
        'url'                   => $r['url']                   !== null ? (string) $r['url']                   : null,
        'cuenta_externa_id'     => $r['cuenta_externa_id']     !== null ? (string) $r['cuenta_externa_id']     : null,
        'correo'                => $r['correo']                !== null ? (string) $r['correo']                : null,
        'app_id'                => $r['app_id']                !== null ? (string) $r['app_id']                : null,
        'token_expira'          => $r['token_expira']          ?? null,
        'postiz_integration_id' => $r['postiz_integration_id'] !== null ? (string) $r['postiz_integration_id'] : null,
        'postiz_estado'         => (string) ($r['postiz_estado'] ?? 'pendiente'),
        'postiz_sync'           => $r['postiz_sync']           ?? null,
        'datos_extra'           => $r['datos_extra']           !== null ? (string) $r['datos_extra']           : null,
        'observaciones'         => $r['observaciones']         !== null ? (string) $r['observaciones']         : null,
        'activa'                => (int) ($r['activa'] ?? 1),
        'fecha_creacion'        => $r['fecha_creacion']        ?? null,
        'fecha_modificacion'    => $r['fecha_modificacion']    ?? null,
    ];

    foreach (DRRS_SECRETOS as $campo) {
        $val = (string) ($r[$campo] ?? '');
        if ($val === '') {
            $out[$campo] = null;
        } else {
            $out[$campo] = $enClaro ? desencriptar($val) : '***';
        }
    }

    return $out;
}

// Normaliza un string a kebab-case: [a-z0-9-]+, sin acentos, sin guiones al
// borde, colapsando corridas de separadores. Espejo JS en app.js (`drrsSlugify`).
// Mismo criterio que datarocket_listas / _embudos / _etiquetas.
function drrsSlugify(string $s): string {
    $s = trim($s);
    if ($s === '') return '';
    $pares = [
        'á'=>'a','é'=>'e','í'=>'i','ó'=>'o','ú'=>'u',
        'à'=>'a','è'=>'e','ì'=>'i','ò'=>'o','ù'=>'u',
        'ä'=>'a','ë'=>'e','ï'=>'i','ö'=>'o','ü'=>'u',
        'Á'=>'a','É'=>'e','Í'=>'i','Ó'=>'o','Ú'=>'u',
        'ñ'=>'n','Ñ'=>'n','ç'=>'c','Ç'=>'c',
    ];
    $s = strtr($s, $pares);
    $s = mb_strtolower($s);
    $s = preg_replace('/[^a-z0-9]+/', '-', $s);
    $s = trim($s, '-');
    return substr($s, 0, 60);
}

function drrsIntOpt(mixed $v): ?int {
    if ($v === '' || $v === null || $v === false) return null;
    $n = (int) $v;
    return $n === 0 ? null : $n;
}

function drrsTexto(mixed $v, int $max, string $etiqueta): ?string {
    $s = trim((string) ($v ?? ''));
    if ($s === '') return null;
    if (mb_strlen($s) > $max) jsonError("{$etiqueta} no puede superar los {$max} caracteres.", 400);
    return $s;
}

// Valores validos de un `campo` del catalogo `estados`, cacheados por request.
// Se consulta en vez de hardcodear un array porque el catalogo es editable
// desde Herramientas > Editor de estados: agregar una red nueva no tiene que
// requerir tocar este archivo.
function drrsValoresEstado(PDO $pdo, string $campo): array {
    static $cache = [];
    if (isset($cache[$campo])) return $cache[$campo];
    $st = $pdo->prepare('SELECT valor FROM estados WHERE campo = :c ORDER BY orden ASC, id ASC');
    $st->execute([':c' => $campo]);
    return $cache[$campo] = array_map('strval', $st->fetchAll(PDO::FETCH_COLUMN) ?: []);
}

// Los inputs `datetime-local` del navegador mandan 'YYYY-MM-DDTHH:MM'. La DB
// quiere 'YYYY-MM-DD HH:MM:SS'. Se aceptan las dos formas y tambien la fecha
// sola (se completa a medianoche).
function drrsFechaHora(mixed $v, string $etiqueta): ?string {
    $s = trim((string) ($v ?? ''));
    if ($s === '') return null;
    $s = str_replace('T', ' ', $s);
    if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $s))          return $s . ' 00:00:00';
    if (preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}$/', $s)) return $s . ':00';
    if (preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $s)) return $s;
    jsonError("{$etiqueta} debe tener formato YYYY-MM-DD HH:MM.", 400);
    return null; // inalcanzable: jsonError() hace exit. Sirve al analizador estatico.
}

// Chequeo amigable del UNIQUE de `slug` antes de que MySQL tire el error crudo.
// El alcance es global (la tabla no agrupa por proyecto): dos proyectos no
// pueden compartir slug, que es justamente lo que hace del slug una referencia
// utilizable desde los jobs de publicacion.
function drrsValidarSlugUnico(PDO $pdo, string $slug, int $excluirId = 0): void {
    $st = $pdo->prepare('SELECT id FROM ' . DRRS_TABLA . ' WHERE slug = :s AND id <> :id LIMIT 1');
    $st->execute([':s' => $slug, ':id' => $excluirId]);
    if ($st->fetch()) jsonError('Ya existe otra red social con ese slug.', 409);
}

function sanitizePayloadRedSocial(PDO $pdo, array $in, bool $esAlta): array {
    $nombre = trim((string) ($in['nombre'] ?? ''));
    if ($esAlta && $nombre === '') jsonError('El nombre es obligatorio.', 400);
    if ($nombre !== '' && mb_strlen($nombre) > 150) {
        jsonError('El nombre no puede superar los 150 caracteres.', 400);
    }

    $plataforma = trim((string) ($in['plataforma'] ?? ''));
    if ($esAlta && $plataforma === '') jsonError('La plataforma es obligatoria.', 400);
    if ($plataforma !== '') {
        $validas = drrsValoresEstado($pdo, DRRS_CAMPO_PLATAFORMA);
        if ($validas && !in_array($plataforma, $validas, true)) {
            jsonError('Plataforma inválida. Cargala primero en Herramientas > Editor de estados.', 400);
        }
    }

    $tipoCuenta = trim((string) ($in['tipo_cuenta'] ?? ''));
    if ($tipoCuenta !== '') {
        $validos = drrsValoresEstado($pdo, DRRS_CAMPO_TIPO);
        if ($validos && !in_array($tipoCuenta, $validos, true)) {
            jsonError('Tipo de cuenta inválido.', 400);
        }
    }

    $postizEstado = trim((string) ($in['postiz_estado'] ?? ''));
    if ($postizEstado !== '') {
        $validos = drrsValoresEstado($pdo, DRRS_CAMPO_POSTIZ);
        if ($validos && !in_array($postizEstado, $validos, true)) {
            jsonError('Estado de Postiz inválido.', 400);
        }
    }
    if ($esAlta && $postizEstado === '') $postizEstado = 'pendiente';

    // `slug` es NOT NULL. Si el operador no lo carga, se deriva del nombre —
    // mismo criterio que listas, embudos y etiquetas.
    $slug = strtolower(trim((string) ($in['slug'] ?? '')));
    if ($slug === '' && $nombre !== '') $slug = drrsSlugify($nombre);
    if ($slug !== '' && !preg_match('/^[a-z0-9]+(?:-[a-z0-9]+)*$/', $slug)) {
        jsonError('El slug solo admite minúsculas, dígitos y guiones (kebab-case).', 400);
    }
    if (strlen($slug) > 60) jsonError('El slug no puede superar los 60 caracteres.', 400);

    $correo = drrsTexto($in['correo'] ?? null, 150, 'El correo');
    if ($correo !== null && !filter_var($correo, FILTER_VALIDATE_EMAIL)) {
        jsonError('El correo no es válido.', 400);
    }

    $url = drrsTexto($in['url'] ?? null, 500, 'La URL');
    if ($url !== null && !filter_var($url, FILTER_VALIDATE_URL)) {
        jsonError('La URL del perfil no es válida (tiene que incluir http:// o https://).', 400);
    }

    // `datos_extra` guarda lo que cada plataforma pide de mas (subreddit,
    // instancia de Mastodon, chat_id de Telegram, page token de larga duracion,
    // etc). Se acepta como objeto JSON o como texto, pero si es texto tiene que
    // parsear: guardar JSON roto convierte el campo en basura silenciosa.
    $datosExtra = null;
    if (array_key_exists('datos_extra', $in) && $in['datos_extra'] !== null) {
        if (is_array($in['datos_extra'])) {
            $datosExtra = json_encode($in['datos_extra'], JSON_UNESCAPED_UNICODE);
        } elseif (trim((string) $in['datos_extra']) !== '') {
            $raw = trim((string) $in['datos_extra']);
            if (json_decode($raw, true) === null && strtolower($raw) !== 'null') {
                jsonError('Los datos extra tienen que ser un JSON válido.', 400);
            }
            $datosExtra = $raw;
        }
    }

    $out = [
        'proyecto_id'           => drrsIntOpt($in['proyecto_id'] ?? null),
        'plataforma'            => $plataforma   === '' ? null : $plataforma,
        'nombre'                => $nombre       === '' ? null : $nombre,
        'slug'                  => $slug,
        'tipo_cuenta'           => $tipoCuenta   === '' ? null : $tipoCuenta,
        'usuario'               => drrsTexto($in['usuario']               ?? null, 150, 'El usuario'),
        'url'                   => $url,
        'cuenta_externa_id'     => drrsTexto($in['cuenta_externa_id']     ?? null, 120, 'El ID de cuenta'),
        'correo'                => $correo,
        'app_id'                => drrsTexto($in['app_id']                ?? null, 255, 'El App ID'),
        'token_expira'          => drrsFechaHora($in['token_expira'] ?? null, 'El vencimiento del token'),
        'postiz_integration_id' => drrsTexto($in['postiz_integration_id'] ?? null, 64, 'El ID de integración de Postiz'),
        'postiz_estado'         => $postizEstado === '' ? null : $postizEstado,
        'postiz_sync'           => drrsFechaHora($in['postiz_sync'] ?? null, 'La fecha de sincronización'),
        'datos_extra'           => $datosExtra,
        'observaciones'         => drrsTexto($in['observaciones']         ?? null, 5000, 'Las observaciones'),
        'activa'                => array_key_exists('activa', $in) ? (int) (bool) $in['activa'] : null,
    ];

    // Los secretos llegan en claro desde el ABM y se guardan cifrados. Vacio =>
    // null, que en el UPDATE se interpreta como "no lo toques" (ver
    // handleUpdateRedSocial): si no, guardar el formulario sin retipear el
    // token lo borraria.
    foreach (DRRS_SECRETOS as $campo) {
        $raw = (string) ($in[$campo] ?? '');
        $out[$campo] = trim($raw) === '' ? null : encriptar($raw);
    }

    return $out;
}

// ----------------------------------------------------------------------------
// Handlers
// ----------------------------------------------------------------------------

// El LEFT JOIN con `proyectos` trae el nombre del proyecto, para que el listado
// no tenga que pedir el catalogo aparte ni el front resolverlo fila por fila.
function drrsFrom(): string {
    return DRRS_TABLA . ' r LEFT JOIN proyectos pr ON pr.id = r.proyecto_id';
}

// El texto amigable de la plataforma sale de `estados` como subquery escalar y
// NO como JOIN: `estados` no tiene UNIQUE(campo, valor), asi que un duplicado
// cargado a mano desde el Editor de estados multiplicaria las filas del listado.
// La subquery devuelve una sola fila pase lo que pase.
function drrsSelectCols(): string {
    $cols = preg_replace('/\s+/', ' ', DRRS_COLS);
    $cols = implode(', ', array_map(fn($c) => 'r.' . trim($c), explode(',', $cols)));
    return $cols
         . ', pr.nombre AS proyecto_nombre'
         . ", (SELECT e2.texto FROM estados e2
                WHERE e2.campo = '" . DRRS_CAMPO_PLATAFORMA . "' AND e2.valor = r.plataforma
                ORDER BY e2.orden ASC, e2.id ASC LIMIT 1) AS plataforma_texto";
}

function handleListRedesSociales(PDO $pdo, array $q): void {
    $search     = trim((string) ($q['q']          ?? ''));
    $proyecto   = trim((string) ($q['proyecto']   ?? ''));
    $plataforma = trim((string) ($q['plataforma'] ?? ''));
    $postiz     = trim((string) ($q['postiz']     ?? ''));
    $activa     = trim((string) ($q['activa']     ?? ''));
    $limite     = max(1, min(1000, (int) ($q['limite'] ?? 100)));
    $orden      = in_array(($q['orden'] ?? ''), DRRS_ORDENES, true) ? $q['orden'] : 'id';
    $dir        = strtolower((string) ($q['dir'] ?? 'desc')) === 'asc' ? 'ASC' : 'DESC';

    $where  = [];
    $params = [];

    // El LIKE va sobre columnas utf8mb4_general_ci, que pliega mayusculas y
    // acentos: "databox" matchea "Databox" y "grafico" matchea "Gráfico".
    if ($search !== '') {
        $where[] = '(r.nombre LIKE :s1 OR r.slug LIKE :s2 OR r.usuario LIKE :s3
                     OR r.url LIKE :s4 OR r.correo LIKE :s5 OR r.cuenta_externa_id LIKE :s6)';
        foreach (['s1', 's2', 's3', 's4', 's5', 's6'] as $k) $params[":{$k}"] = "%{$search}%";
    }
    if ($proyecto !== '' && ctype_digit($proyecto)) {
        $where[] = 'r.proyecto_id = :proyecto';
        $params[':proyecto'] = (int) $proyecto;
    }
    if ($plataforma !== '') {
        $where[] = 'r.plataforma = :plataforma';
        $params[':plataforma'] = $plataforma;
    }
    if ($postiz !== '') {
        $where[] = 'r.postiz_estado = :postiz';
        $params[':postiz'] = $postiz;
    }
    if ($activa !== '' && ctype_digit($activa)) {
        $where[] = 'r.activa = :activa';
        $params[':activa'] = (int) $activa;
    }

    $sqlWhere = $where ? ('WHERE ' . implode(' AND ', $where)) : '';

    // Desempate por `nombre` para que el orden sea determinista cuando la
    // columna elegida empata (pasa siempre con `plataforma` y con `activa`).
    $desempate = $orden === 'nombre' ? '' : ', r.nombre ASC';

    $sql = 'SELECT ' . drrsSelectCols() . ' FROM ' . drrsFrom()
         . " {$sqlWhere} ORDER BY r.{$orden} {$dir}{$desempate} LIMIT {$limite}";
    $st = $pdo->prepare($sql);
    $st->execute($params);
    $rows = array_map(fn($r) => normalizarFilaRedSocial($r, false), $st->fetchAll());

    $stats = [
        'total'       => (int) $pdo->query('SELECT COUNT(*) FROM ' . DRRS_TABLA)->fetchColumn(),
        'activas'     => (int) $pdo->query('SELECT COUNT(*) FROM ' . DRRS_TABLA . ' WHERE activa = 1')->fetchColumn(),
        'vinculadas'  => (int) $pdo->query('SELECT COUNT(*) FROM ' . DRRS_TABLA . " WHERE postiz_estado = 'vinculada'")->fetchColumn(),
        'plataformas' => (int) $pdo->query('SELECT COUNT(DISTINCT plataforma) FROM ' . DRRS_TABLA)->fetchColumn(),
    ];

    jsonOk(['items' => $rows, 'stats' => $stats]);
}

function handleGetOneRedSocial(PDO $pdo, int $id): void {
    $st = $pdo->prepare('SELECT ' . drrsSelectCols() . ' FROM ' . drrsFrom() . ' WHERE r.id = :id LIMIT 1');
    $st->execute([':id' => $id]);
    $row = $st->fetch();
    if (!$row) jsonError('Red social no encontrada', 404);
    jsonOk(normalizarFilaRedSocial($row, true));
}

// Catalogos para los <select> del formulario y del modal de filtros.
// Los proyectos se filtran por `tipo = 'I'` (internos): las redes sociales que
// administra el grupo son las de sus propios productos, no las de los clientes
// — mismo recorte que usan los embudos Datarocket.
function handleLookupsRedSocial(PDO $pdo): void {
    $proyectos = $pdo->query(
        "SELECT id, COALESCE(NULLIF(TRIM(nombre), ''), CONCAT('#', id)) AS nombre
           FROM proyectos WHERE tipo = 'I' ORDER BY nombre ASC, id ASC"
    )->fetchAll();

    $catalogo = function (string $campo) use ($pdo): array {
        $st = $pdo->prepare('SELECT valor, texto FROM estados WHERE campo = :c ORDER BY orden ASC, id ASC');
        $st->execute([':c' => $campo]);
        return array_map(fn($r) => [
            'valor' => (string) ($r['valor'] ?? ''),
            'texto' => (string) ($r['texto'] ?? ''),
        ], $st->fetchAll());
    };

    jsonOk([
        'proyectos'      => array_map(fn($r) => [
            'id'     => (int) $r['id'],
            'nombre' => (string) $r['nombre'],
        ], $proyectos),
        'plataformas'    => $catalogo(DRRS_CAMPO_PLATAFORMA),
        'tipos_cuenta'   => $catalogo(DRRS_CAMPO_TIPO),
        'postiz_estados' => $catalogo(DRRS_CAMPO_POSTIZ),
    ]);
}

function handleCreateRedSocial(PDO $pdo, array $body): void {
    $p = sanitizePayloadRedSocial($pdo, $body, true);

    if ($p['slug'] === '') {
        jsonError('No se pudo derivar un slug a partir del nombre. Cargalo manualmente.', 400);
    }
    drrsValidarSlugUnico($pdo, $p['slug']);

    $st = $pdo->prepare(
        'INSERT INTO ' . DRRS_TABLA . '
            (proyecto_id, plataforma, nombre, slug, tipo_cuenta, usuario, url, cuenta_externa_id,
             correo, contrasena, app_id, app_secret, access_token, refresh_token, token_expira,
             postiz_integration_id, postiz_estado, postiz_sync, datos_extra, observaciones, activa)
         VALUES
            (:proyecto_id, :plataforma, :nombre, :slug, :tipo_cuenta, :usuario, :url, :cuenta_externa_id,
             :correo, :contrasena, :app_id, :app_secret, :access_token, :refresh_token, :token_expira,
             :postiz_integration_id, :postiz_estado, :postiz_sync, :datos_extra, :observaciones, :activa)'
    );
    $st->execute([
        ':proyecto_id'           => $p['proyecto_id'],
        ':plataforma'            => $p['plataforma'],
        ':nombre'                => $p['nombre'],
        ':slug'                  => $p['slug'],
        ':tipo_cuenta'           => $p['tipo_cuenta'],
        ':usuario'               => $p['usuario'],
        ':url'                   => $p['url'],
        ':cuenta_externa_id'     => $p['cuenta_externa_id'],
        ':correo'                => $p['correo'],
        ':contrasena'            => $p['contrasena'],
        ':app_id'                => $p['app_id'],
        ':app_secret'            => $p['app_secret'],
        ':access_token'          => $p['access_token'],
        ':refresh_token'         => $p['refresh_token'],
        ':token_expira'          => $p['token_expira'],
        ':postiz_integration_id' => $p['postiz_integration_id'],
        ':postiz_estado'         => $p['postiz_estado'] ?? 'pendiente',
        ':postiz_sync'           => $p['postiz_sync'],
        ':datos_extra'           => $p['datos_extra'],
        ':observaciones'         => $p['observaciones'],
        ':activa'                => $p['activa'] ?? 1,
    ]);

    $id = (int) $pdo->lastInsertId();
    registrarSuceso($pdo, DRRS_TABLA, 'info',
        "Alta red social #{$id} — \"{$p['nombre']}\" ({$p['plataforma']})");

    handleGetOneRedSocial($pdo, $id);
}

function handleUpdateRedSocial(PDO $pdo, int $id, array $body): void {
    $st = $pdo->prepare('SELECT id, nombre, slug FROM ' . DRRS_TABLA . ' WHERE id = :id LIMIT 1');
    $st->execute([':id' => $id]);
    $prev = $st->fetch();
    if (!$prev) jsonError('Red social no encontrada', 404);

    // El update es parcial: solo se tocan las claves presentes en el body. El
    // ABM manda el formulario completo, pero un futuro sincronizador con Postiz
    // va a mandar unicamente `postiz_*` y no debe pisar el resto con nulls.
    $p = sanitizePayloadRedSocial($pdo, $body, false);

    // El `slug` es un identificador estable: si el cliente no lo manda, se
    // conserva el actual en vez de re-derivarlo del nombre (re-derivarlo
    // romperia las referencias externas, que es justo lo que el slug evita).
    if (array_key_exists('slug', $body)) {
        if ($p['slug'] === '') jsonError('El slug no puede quedar vacío.', 400);
        if ($p['slug'] !== (string) $prev['slug']) drrsValidarSlugUnico($pdo, $p['slug'], $id);
    }

    $campos = [
        'proyecto_id', 'plataforma', 'nombre', 'slug', 'tipo_cuenta', 'usuario', 'url',
        'cuenta_externa_id', 'correo', 'app_id', 'token_expira', 'postiz_integration_id',
        'postiz_estado', 'postiz_sync', 'datos_extra', 'observaciones', 'activa',
    ];

    $sets   = [];
    $params = [':id' => $id];
    foreach ($campos as $c) {
        if (!array_key_exists($c, $body)) continue;
        // Columnas NOT NULL: si vienen vacías se ignoran en vez de romper.
        if (in_array($c, ['nombre', 'plataforma', 'slug', 'postiz_estado'], true) && $p[$c] === null) continue;
        $sets[]          = "{$c} = :{$c}";
        $params[":{$c}"] = $p[$c];
    }

    // Los secretos solo se pisan si vino algo: un PUT sin el campo (o con el
    // campo vacío) conserva el guardado. Si no, editar cualquier otro dato del
    // formulario borraría los tokens.
    foreach (DRRS_SECRETOS as $c) {
        if (!array_key_exists($c, $body) || $p[$c] === null) continue;
        $sets[]          = "{$c} = :{$c}";
        $params[":{$c}"] = $p[$c];
    }

    if (empty($sets)) jsonError('No hay campos para actualizar.', 400);

    $sql = 'UPDATE ' . DRRS_TABLA . ' SET ' . implode(', ', $sets) . ' WHERE id = :id';
    $pdo->prepare($sql)->execute($params);

    registrarSuceso($pdo, DRRS_TABLA, 'info',
        "Modificación red social #{$id} — \"{$prev['nombre']}\"");

    handleGetOneRedSocial($pdo, $id);
}

function handleDeleteRedSocial(PDO $pdo, int $id): void {
    $st = $pdo->prepare('SELECT nombre, plataforma FROM ' . DRRS_TABLA . ' WHERE id = :id LIMIT 1');
    $st->execute([':id' => $id]);
    $prev = $st->fetch();
    if (!$prev) jsonError('Red social no encontrada', 404);

    $pdo->prepare('DELETE FROM ' . DRRS_TABLA . ' WHERE id = :id')->execute([':id' => $id]);

    registrarSuceso($pdo, DRRS_TABLA, 'info',
        "Baja red social #{$id} — \"{$prev['nombre']}\" ({$prev['plataforma']})");

    jsonOk(['id' => $id]);
}
