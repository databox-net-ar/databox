<?php
// api/lib/auth_check.php
// Helpers reutilizables de autenticacion y autorizacion.
// Todo endpoint que necesite sesion valida debe empezar con:
//
//   require_once __DIR__ . '/lib/auth_check.php';
//   requireAuth();                                    // 401 si no hay sesion
//   requirePermission('datacount.empleados.editar');  // 403 si falta el permiso
//
// Los permisos se computan contra la BD la primera vez que se piden en el
// request y se cachean por request (variable estatica en `currentPerms`).
// NO viven en el JWT para evitar cookies enormes cuando un usuario tiene
// asignados muchos permisos.

require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../jwt.php';

const AUTH_COOKIE_NAME = 'databox_token';
const AUTH_TTL         = 28800; // 8 horas

// ----------------------------------------------------------------------------
// Autenticacion
// ----------------------------------------------------------------------------

function currentAuth(): ?array {
    $token = $_COOKIE[AUTH_COOKIE_NAME] ?? '';
    if ($token === '') {
        $auth = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
        if (stripos($auth, 'Bearer ') === 0) $token = substr($auth, 7);
    }
    if ($token === '') return null;
    return jwtDecode($token);
}

function requireAuth(): array {
    $p = currentAuth();
    if (!$p) {
        http_response_code(401);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['ok' => false, 'error' => 'No autenticado'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    return $p;
}

// ----------------------------------------------------------------------------
// Autorizacion
// ----------------------------------------------------------------------------

// Permisos efectivos del usuario logueado, cacheados por request.
// Se recalculan desde la BD la primera vez que se piden en el request y luego
// se devuelven de una variable estatica: multiples `requirePermission()` en el
// mismo endpoint hacen una sola query.
function currentPerms(): array {
    static $cache = null;
    if ($cache !== null) return $cache;

    $p = currentAuth();
    if (!$p) return $cache = [];
    $cache = computePermisosUsuario(db(), (int)($p['sub'] ?? 0));
    return $cache;
}

function hasPermission(string $slug): bool {
    return in_array($slug, currentPerms(), true);
}

// Corta la ejecucion con 403 JSON si el usuario no tiene el permiso indicado.
// Uso tipico: al principio del endpoint, despues de `requireAuth()`.
function requirePermission(string $slug): void {
    requireAuth();
    if (!hasPermission($slug)) {
        http_response_code(403);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(
            ['ok' => false, 'error' => "Permiso denegado: {$slug}"],
            JSON_UNESCAPED_UNICODE
        );
        exit;
    }
}

// Atajo para endpoints REST estandar de tipo ABM: mapea el metodo HTTP al
// verbo del permiso (consultar / agregar / editar / eliminar) y verifica que
// el usuario tenga `<baseSlug>.<verbo>`.
//   GET    -> <baseSlug>.consultar
//   POST   -> <baseSlug>.agregar
//   PUT    -> <baseSlug>.editar
//   DELETE -> <baseSlug>.eliminar
//
// Uso tipico en la cabecera de un ABM:
//   requirePermCrud('datacount.empleados');
//
// Para endpoints no-ABM (tools con verbos propios como `ejecutar`,
// `sincronizar`, `aplicar`, etc.) usar `requirePermission()` con el slug
// exacto en vez de este atajo.
function requirePermCrud(string $baseSlug): void {
    $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
    $verbo  = match ($method) {
        'POST'   => 'agregar',
        'PUT'    => 'editar',
        'DELETE' => 'eliminar',
        default  => 'consultar', // GET, HEAD, OPTIONS caen aca
    };
    requirePermission("{$baseSlug}.{$verbo}");
}

// ----------------------------------------------------------------------------
// Computo de permisos efectivos
// ----------------------------------------------------------------------------

// Resuelve los slugs de permisos que un usuario tiene, dados sus roles.
// Cadena: usuarios.roles (CSV de IDs) → roles.permisos (CSV de IDs por rol)
//         → permisos.slug (uno por permiso).
// Filtra por slug NOT NULL en roles y permisos: cualquier rol/permiso del
// sistema legacy queda excluido (ver 20260711_1200_limpiar_slug_y_descripcion_legacy.sql).
// Devuelve un array de slugs unicos ordenado alfabeticamente.
function computePermisosUsuario(PDO $pdo, int $userId): array {
    if ($userId <= 0) return [];

    $stmt = $pdo->prepare('SELECT roles FROM usuarios WHERE id = :id');
    $stmt->execute([':id' => $userId]);
    $roleIds = authTokenizarIdsCsv((string)($stmt->fetchColumn() ?: ''));
    if (!$roleIds) return [];

    $inRoles = implode(',', $roleIds);
    $stmt = $pdo->query(
        "SELECT permisos FROM roles
          WHERE id IN ($inRoles) AND slug IS NOT NULL AND slug <> ''"
    );
    $permIdsSet = [];
    foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $csv) {
        foreach (authTokenizarIdsCsv((string)$csv) as $id) {
            $permIdsSet[$id] = true;
        }
    }
    if (!$permIdsSet) return [];

    $inPerms = implode(',', array_keys($permIdsSet));
    $stmt = $pdo->query(
        "SELECT DISTINCT slug FROM permisos
          WHERE id IN ($inPerms) AND slug IS NOT NULL AND slug <> ''"
    );
    $slugs = $stmt->fetchAll(PDO::FETCH_COLUMN) ?: [];
    sort($slugs);
    return $slugs;
}

// Convierte una CSV (con comas, punto y coma, espacios o parentesis) a array
// de int > 0, deduplicando. Se usa para leer `usuarios.roles` y `roles.permisos`.
// Acepta dos formatos porque las tablas se comparten con las UIs legacy del grupo:
//   - cloud  : "111,112,113"
//   - legacy : "(111)(112)(113)"
// Como todos los tokens se castean a int, es seguro interpolarlos despues en
// un IN () del SQL sin binding — la unica manera de que algo llegue alli es
// que el (int) haya devuelto un entero positivo.
function authTokenizarIdsCsv(string $csv): array {
    if ($csv === '') return [];
    $out = [];
    foreach (preg_split('/[(),;\s]+/', $csv) ?: [] as $tok) {
        $n = (int)trim($tok);
        if ($n > 0) $out[$n] = true;
    }
    return array_keys($out);
}
