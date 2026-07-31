<?php
// api/v4/arca/_lib/afip_factory.php
// Fabrica de ArcaAfip a partir de un slug de empresa. Junta 3 cosas:
//
//   1. RESOLVER: slug -> {empresa, cert_pem, key_pem}
//      Lee `datacount_empresas` + JOIN `arca_certificados`. Falla si la
//      empresa no existe, no tiene certificado_id, o el certificado no tiene
//      los PEM cargados.
//
//   2. TA CACHE: closures que leen y escriben en `arca_ta_cache` la dupla
//      (certificado_id, service). El TA de AFIP dura 12h; sin cache
//      chocariamos contra el error 25 -- Ya existe TA valido para
//      CUIT/servicio. Persistimos el timestamp de expiracion para no
//      renovar prematuramente.
//
//   3. INSTANCIA: construye ArcaAfip con el CUIT de la empresa, cert/key
//      recuperados y los callbacks del cache.
//
// El endpoint SOLO importa este archivo y llama arcaFor($slug); todo el
// wiring queda encapsulado aca.

declare(strict_types=1);

require_once __DIR__ . '/../_afip/Afip.php';

/**
 * Resuelve un slug de empresa a los datos que necesita ArcaAfip.
 * Retorna: ['empresa' => row datacount_empresas, 'cert_pem' => string, 'key_pem' => string]
 * o hace jsonError si no se puede facturar con ese slug.
 */
function arcaResolveEmpresa(string $slug): array {
    if ($slug === '') jsonError('Falta el parametro `empresa` (slug).', 400);

    $pdo = db();
    $st  = $pdo->prepare(
        'SELECT e.id, e.slug, e.nombre, e.razon, e.cuit, e.condicion,
                e.certificado_id,
                c.nombre AS certificado_nombre, c.llave, c.certificado
           FROM datacount_empresas e
      LEFT JOIN arca_certificados c ON c.id = e.certificado_id
          WHERE e.slug = :slug
          LIMIT 1'
    );
    $st->execute([':slug' => $slug]);
    $row = $st->fetch();
    if (!$row) jsonError("La empresa '{$slug}' no existe.", 404);

    if (empty($row['certificado_id'])) {
        jsonError("La empresa '{$slug}' no tiene certificado de ARCA asignado.", 400);
    }
    if (empty($row['cuit'])) {
        jsonError("La empresa '{$slug}' no tiene CUIT cargado.", 400);
    }
    $cert = trim((string)$row['certificado']);
    $key  = trim((string)$row['llave']);
    if ($cert === '' || $key === '') {
        jsonError("El certificado #{$row['certificado_id']} de la empresa '{$slug}' no tiene cert/llave cargados.", 500);
    }

    return [
        'empresa'  => $row,
        'cert_pem' => $cert,
        'key_pem'  => $key,
    ];
}

/**
 * Devuelve el par de closures (taLoad, taSave) que ArcaAfip usa para
 * cachear el TA de un certificado dado en la tabla `arca_ta_cache`.
 */
function arcaMakeTaCallbacks(int $certificadoId): array {
    $load = function (string $service) use ($certificadoId): ?array {
        $pdo = db();
        $st  = $pdo->prepare(
            'SELECT token, sign, UNIX_TIMESTAMP(expira_en) AS expira_en
               FROM arca_ta_cache
              WHERE certificado_id = :c AND service = :s
              LIMIT 1'
        );
        $st->execute([':c' => $certificadoId, ':s' => $service]);
        $row = $st->fetch();
        if (!$row) return null;
        return [
            'token'     => (string)$row['token'],
            'sign'      => (string)$row['sign'],
            'expira_en' => (int)$row['expira_en'],
        ];
    };

    $save = function (string $service, string $token, string $sign, int $expiraEn) use ($certificadoId): void {
        $pdo = db();
        // UPSERT: mismo (certificado_id, service) siempre; si ya existia lo
        // pisamos con el TA nuevo.
        $st = $pdo->prepare(
            'INSERT INTO arca_ta_cache (certificado_id, service, token, sign, expira_en, actualizado)
             VALUES (:c, :s, :t, :g, FROM_UNIXTIME(:e), NOW())
             ON DUPLICATE KEY UPDATE
                token       = VALUES(token),
                sign        = VALUES(sign),
                expira_en   = VALUES(expira_en),
                actualizado = NOW()'
        );
        $st->execute([
            ':c' => $certificadoId,
            ':s' => $service,
            ':t' => $token,
            ':g' => $sign,
            ':e' => $expiraEn,
        ]);
    };

    return [$load, $save];
}

/**
 * Punto de entrada unico. Dado un slug de empresa, devuelve una instancia
 * de ArcaAfip lista para usar (accedes a $arca->ElectronicBilling->...).
 *
 * @return array{arca: ArcaAfip, empresa: array}
 */
function arcaFor(string $slug): array {
    $resolved = arcaResolveEmpresa($slug);
    $empresa  = $resolved['empresa'];

    [$load, $save] = arcaMakeTaCallbacks((int)$empresa['certificado_id']);

    // CUIT sin guiones ni espacios (AFIP quiere un entero de 11 digitos).
    $cuit = preg_replace('/\D+/', '', (string)$empresa['cuit']);

    $arca = new ArcaAfip([
        'cuit'   => (int)$cuit,
        'cert'   => $resolved['cert_pem'],
        'key'    => $resolved['key_pem'],
        'taLoad' => $load,
        'taSave' => $save,
    ]);

    return ['arca' => $arca, 'empresa' => $empresa];
}
