<?php
/**
 * api/lib/contactos_normalizar.php
 *
 * Normalizacion de los datos de contacto de `datarocket_contactos`:
 * telefono / celular / whatsapp a 10 digitos argentinos, correo a minuscula
 * validada, y web a host+path sin esquema.
 *
 * Lo consumen los dos endpoints que escriben la tabla:
 *   - cloud/api/datarocketcontactos.php  (ABM del panel, auth por sesion)
 *   - api/v4/datarocket/contactos.php    (microservicio, auth por apikey)
 *
 * Dos migraciones aplican exactamente estas mismas reglas sobre lo que ya
 * estaba cargado, con funciones SQL temporales que espejan una a una las
 * funciones de este archivo:
 *   - 20260816_1700_datarocket_contactos_normalizar_telefonos_correos.sql
 *   - 20260816_1800_datarocket_contactos_normalizar_web.sql
 * Si se toca una regla aca, hay que tocarla alla (o escribir otra migracion).
 *
 * ---------------------------------------------------------------------------
 * TELEFONOS
 * ---------------------------------------------------------------------------
 * En Argentina todo numero nacional son 10 digitos: codigo de area (2 a 4) +
 * abonado, sin el 0 de larga distancia y sin el 15 de movil. Lo que llega de
 * los formularios y de los scrapings viene de todas las formas posibles, asi
 * que se aplican estos pasos en orden:
 *
 *   1. se descarta todo lo que no sea digito (espacios, guiones, parentesis, +)
 *   2. prefijo internacional `00`            -> se saca
 *   3. prefijo nacional `0` (larga distancia)-> se saca
 *   4. codigo de pais `54`                   -> se saca
 *   5. marcador de movil `9` (el de +54 9)   -> se saca
 *   6. `15` intercalado entre area y abonado -> se saca
 *   7. numero de 10 que arranca con `15`     -> es un movil de CABA escrito en
 *      formato local (15-6780-5502): pasa a `11` + los 8 digitos del abonado.
 *      Ningun codigo de area del pais arranca con 15, asi que no hay ambiguedad.
 *
 * Si el campo trae dos numeros ("4769-4037 1556-144420", tipico de las fichas
 * viejas con fijo + movil en la misma celda) se prueba token por token y se
 * queda con el primero que de un numero valido.
 *
 * Cuando nada de eso llega a 10 digitos validos NO se descarta el dato: se
 * guardan los digitos crudos. Son numeros extranjeros reales (+52, +1, +58),
 * fijos de CABA cargados sin el area, o fragmentos incompletos; anularlos
 * seria perder informacion que alguien puede querer corregir a mano.
 *
 * ---------------------------------------------------------------------------
 * CORREOS
 * ---------------------------------------------------------------------------
 * Siempre minuscula y sin espacios alrededor. Si el campo trae una lista
 * ("ventas@x.com.ar, soporte@x.com.ar" — comun en los contactos scrapeados de
 * sitios institucionales) se rescata la primera direccion valida. Si no hay
 * ninguna direccion parseable, el resultado es null y el llamador decide si
 * eso es un error de validacion o simplemente un campo vacio.
 *
 * ---------------------------------------------------------------------------
 * WEB
 * ---------------------------------------------------------------------------
 * La columna guarda host + path SIN esquema (`bna.com.ar/sucursales`, no
 * `https://bna.com.ar/sucursales`). El esquema no aporta informacion — el ABM
 * lo antepone al armar el link — y guardarlo obligaba a elegir entre respetar
 * el `http://` historico de la mitad de las filas o forzar `https://` y romper
 * los sitios viejos que no sirven TLS.
 *
 * Pasos, en orden:
 *
 *   1. se recorta y se saca el ruido de copy/paste del principio (": ", "- ")
 *   2. se saca el esquema (`http://`, `https://`, `//` protocol-relative)
 *   3. se sacan los espacios internos: en este campo son siempre tipeos
 *      ("www. pampasat.com", "www . jriseguridad.com.ar"), nunca separadores
 *   4. se saca la puntuacion del final (`/`, `.`, `,`), que es lo que deja el
 *      copiado desde el navegador — cubre las ~7.200 filas terminadas en `/`
 *   5. se baja a minuscula SOLO el host. El path es case sensitive y bajarlo
 *      rompe los acortadores (`bit.ly/3SSePnt` no es `bit.ly/3ssepnt`).
 *
 * El `www.` se respeta tal cual venga: no se agrega ni se saca.
 *
 * A diferencia de los telefonos, lo que no queda como host valido NO se guarda
 * crudo: un "no posee" o un "en construccion" en `web` no es un dato que
 * alguien pueda corregir despues, es ruido de scraping. Va a null.
 *
 * Caso aparte: los correos cargados por error en `web`
 * ("electrorubenrodriguez@gmail.com"). contactoWebComoCorreo() los devuelve
 * normalizados para que el llamador los mueva a `correo` cuando ese campo esta
 * libre; si el contacto ya tiene correo, el valor se descarta igual que
 * cualquier otro no-URL.
 */

// Codigos de area de 3 digitos. `11` es el unico de 2; todo lo demas que
// arranca con 2 o 3 y no esta en esta lista es de 4 digitos.
const CONTACTO_AREAS_3 = [
    '220', '221', '223', '230', '236', '237', '249', '260', '261', '263',
    '264', '266', '280', '291', '297', '299', '341', '342', '343', '345',
    '348', '351', '353', '358', '362', '364', '370', '376', '379', '381',
    '383', '385', '387', '388',
];

// Prefijos validos de un numero nacional ya normalizado a 10 digitos:
// `11` (CABA), cualquier area que arranque con 2 o 3, y los servicios
// especiales 0600 / 0800 / 0810 (que sin el 0 tambien son de 10).
const CONTACTO_PREFIJOS_VALIDOS = '/^(11|[23]|600|800|810)/';

function contactoSoloDigitos(mixed $v): string {
    return preg_replace('/\D+/', '', (string)($v ?? ''));
}

// Largo del codigo de area de un numero nacional ya sin prefijos.
function contactoAreaLen(string $d): int {
    if (str_starts_with($d, '11'))                          return 2;
    if (in_array(substr($d, 0, 3), CONTACTO_AREAS_3, true)) return 3;
    return 4;
}

// Aplica los pasos 2 a 7 sobre una cadena de digitos. No valida: eso es
// trabajo de contactoTelefonoEsValido().
function contactoDespejarPrefijos(string $d): string {
    // 00 internacional
    if (str_starts_with($d, '00')) $d = substr($d, 2);
    // 0 de larga distancia nacional (puede venir junto al 00 ya sacado)
    while (strlen($d) > 10 && str_starts_with($d, '0')) $d = substr($d, 1);
    // 54 codigo de pais
    if (strlen($d) > 10 && str_starts_with($d, '54')) $d = substr($d, 2);
    // 9 de movil. Largo 11 = 9 + los 10 del numero nacional; largo 13 = 9 +
    // area + 15 + abonado, que es como queda "+54 9 341 15-307-4305" (hay
    // quien escribe los dos marcadores de movil, el 9 y el 15).
    if (in_array(strlen($d), [11, 13], true) && str_starts_with($d, '9')) $d = substr($d, 1);
    // 15 entre area y abonado
    if (strlen($d) === 12) {
        $a = contactoAreaLen($d);
        if (substr($d, $a, 2) !== '15') {
            // El area no matcheo la lista: se prueban los tres largos posibles
            // antes de darse por vencido.
            $a = 0;
            foreach ([2, 3, 4] as $try) {
                if (substr($d, $try, 2) === '15') { $a = $try; break; }
            }
        }
        if ($a > 0) $d = substr($d, 0, $a) . substr($d, $a + 2);
    }
    // Movil de CABA en formato local: 15-6780-5502 -> 11-6780-5502
    if (strlen($d) === 10 && str_starts_with($d, '15')) $d = '11' . substr($d, 2);
    return $d;
}

function contactoTelefonoEsValido(string $d): bool {
    return strlen($d) === 10 && preg_match(CONTACTO_PREFIJOS_VALIDOS, $d) === 1;
}

// Normaliza telefono / celular / whatsapp. Devuelve los 10 digitos cuando las
// reglas llegan a un numero nacional valido; si no, los digitos crudos; null
// si el campo no tiene ningun digito.
function contactoNormalizarTelefono(mixed $v): ?string {
    $raw = trim((string)($v ?? ''));
    if ($raw === '') return null;

    $entero = contactoDespejarPrefijos(contactoSoloDigitos($raw));
    if (contactoTelefonoEsValido($entero)) return $entero;

    // El campo puede traer dos numeros separados por espacios ("fijo movil").
    foreach (preg_split('/\s+/', $raw, -1, PREG_SPLIT_NO_EMPTY) as $token) {
        $cand = contactoDespejarPrefijos(contactoSoloDigitos($token));
        if (contactoTelefonoEsValido($cand)) return $cand;
    }

    $digitos = contactoSoloDigitos($raw);
    return $digitos === '' ? null : substr($digitos, 0, 255);
}

// Normaliza `correo` a minuscula. Devuelve null si el campo esta vacio o si no
// contiene ninguna direccion parseable.
function contactoNormalizarCorreo(mixed $v): ?string {
    $s = strtolower(trim((string)($v ?? '')));
    if ($s === '') return null;

    // Una direccion sola y bien formada es el caso normal: se valida con el
    // filtro de PHP y se pide ademas un TLD alfabetico (filter_var acepta
    // `a@b`, que en esta tabla nunca es un correo real).
    if (filter_var($s, FILTER_VALIDATE_EMAIL) !== false
        && preg_match('/\.[a-z]{2,}$/', $s) === 1) {
        return substr($s, 0, 255);
    }

    // Listas separadas por coma o barra, direcciones con basura pegada
    // ("info@estudiocontax.com."): se rescata la primera que matchee.
    $m = [];
    if (preg_match('/[a-z0-9._%+-]+@[a-z0-9.-]+\.[a-z]{2,}/', $s, $m) === 1) {
        return substr($m[0], 0, 255);
    }
    return null;
}

// Host valido: una o mas etiquetas terminadas en punto, un TLD alfabetico y un
// puerto opcional. Los caracteres permitidos se declaran por exclusion en vez
// de con una lista blanca `[a-z0-9-]` para no dejar afuera los dominios con eñe
// que hay en el padron ("serdueño.com.ar", "cañuelas.gob.ar"); el TLD ademas no
// admite digitos ni guion, que es lo que descarta los restos de prosa.
const CONTACTO_WEB_HOST = '~^(?:[^\s./?#@:]+\.)+[^\s./?#@:0-9-]{2,}(?::\d{1,5})?$~u';

// El TLD sin digitos deja afuera las IPv4, que en esta tabla son direcciones
// reales ("http://209.154.192.80/"), asi que se aceptan por separado.
const CONTACTO_WEB_IPV4 = '~^(?:\d{1,3}\.){3}\d{1,3}(?::\d{1,5})?$~';

// Saca el esquema, los blancos y la puntuacion de borde. Deja el valor listo
// para partirlo en host + resto; no valida nada.
function contactoWebLimpiar(mixed $v): string {
    $s = trim((string)($v ?? ''));
    // Ruido de copy/paste al principio: ": http://...", "- www...".
    $s = preg_replace('~^[\s:;,.\-]+~u', '', $s);
    // Esquema, y la forma protocol-relative "//host/path".
    $s = preg_replace('~^[a-z][a-z0-9+.\-]*://~i', '', $s);
    $s = preg_replace('~^//~', '', $s);
    // Espacios internos: siempre tipeos en este campo.
    $s = preg_replace('~\s+~u', '', $s);
    // Puntuacion final, incluida la barra que deja el copiado del navegador.
    return rtrim($s, "/.,;:-");
}

// Parte el valor limpio en [host, resto] por el primer `/`, `?` o `#`.
function contactoWebPartir(string $s): array {
    $i = strcspn($s, '/?#');
    return [substr($s, 0, $i), substr($s, $i)];
}

// Normaliza `web`. Devuelve host+path sin esquema, o null si el valor no es una
// URL (incluidos los correos, que resuelve contactoWebComoCorreo()).
function contactoNormalizarWeb(mixed $v): ?string {
    $s = contactoWebLimpiar($v);
    if ($s === '') return null;

    [$host, $resto] = contactoWebPartir($s);
    // Un `@` en el host es un correo mal cargado, no una URL. El `@` del path
    // si es legitimo ("youtube.com/@itscontrolseguridad").
    if ($host === '' || str_contains($host, '@')) return null;

    $host = mb_strtolower($host, 'UTF-8');
    if (preg_match(CONTACTO_WEB_HOST, $host) !== 1
        && preg_match(CONTACTO_WEB_IPV4, $host) !== 1) return null;

    return mb_substr($host . $resto, 0, 255, 'UTF-8');
}

// Devuelve el correo que estaba cargado por error en `web`, o null si el valor
// no es un correo. El `www.` pegado adelante ("www.tecnicatotal@hotmail.com")
// es un tipeo frecuente y se descarta antes de parsear.
function contactoWebComoCorreo(mixed $v): ?string {
    $s = contactoWebLimpiar($v);
    if ($s === '') return null;

    [$host] = contactoWebPartir($s);
    if (!str_contains($host, '@')) return null;

    return contactoNormalizarCorreo(preg_replace('~^www\.~i', '', $s));
}
