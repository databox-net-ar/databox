<?php
/**
 * api/lib/prospectos_normalizar.php
 *
 * Normalizacion de los datos de prospecto de `datarocket_prospectos`:
 * telefono / celular / whatsapp a 10 digitos argentinos, correo a minuscula
 * validada, y web a host+path sin esquema.
 *
 * Lo consumen los dos endpoints que escriben la tabla:
 *   - cloud/api/datarocketprospectos.php  (ABM del panel, auth por sesion)
 *   - api/v4/datarocket/prospectos.php    (microservicio, auth por apikey)
 *
 * Dos migraciones aplican exactamente estas mismas reglas sobre lo que ya
 * estaba cargado, con funciones SQL temporales que espejan una a una las
 * funciones de este archivo:
 *   - 20260816_1700_datarocket_prospectos_normalizar_telefonos_correos.sql
 *   - 20260816_1800_datarocket_prospectos_normalizar_web.sql
 * Si se toca una regla aca, hay que tocarla alla (o escribir otra migracion).
 *
 * DESALINEADO A PROPOSITO desde el 2026-08-18: las dos reglas que se agregaron
 * ese dia (sacar los espacios internos del correo, bajar a minuscula el path de
 * `web`) valen solo para lo que ENTRA. No se escribio backfill — el de `web`
 * habria roto las 569 filas historicas con mayusculas legitimas en el path. Las
 * filas viejas quedan como estaban; se re-normalizan al editarlas.
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
 * Siempre minuscula. Si el campo trae una lista ("ventas@x.com.ar,
 * soporte@x.com.ar" — comun en los prospectos scrapeados de sitios
 * institucionales) se rescata la primera direccion valida. Si no hay ninguna
 * direccion parseable, el resultado es null y el llamador decide si eso es un
 * error de validacion o simplemente un campo vacio.
 *
 * LO QUE VIENE CORREGIBLE SE CORRIGE, NO SE RECHAZA (decision del 2026-08-18).
 * La correccion es DETERMINISTA: solo lo que no obliga a adivinar cual era la
 * direccion. Sobre los 259 valores rotos distintos que hay en la tabla legacy
 * `datarocketcontactos`, esto recupera 122 y rechaza 137.
 *
 * Se corrige:
 *   - acentos y diacriticos      "german@" <- "germán@"  (ver el mapa abajo)
 *   - `@` escrito con palabras   "informes(a)windnet.com.ar", "[at]", "(arroba)"
 *   - envoltorios                "mailto:", "<juan@x.com>", comillas
 *   - puntuacion espuria         "gmail;.com", "gmail..com", "juan.@x.com"
 *   - espacios tipeados          "juan @gmail.com", "gmail. com", "hmail .com"
 *
 * NO se corrige, porque seria inventar una direccion que nadie tiene:
 *   - falta el TLD               "admin@crediguia"
 *   - falta el `@`               "adanncortez1974gmail.com"
 *   - typo del proveedor         "Andres.yudica@hotmailcom"
 *   - mojibake irreparable       "gonzßlez@intersistemas.com.ar"
 * Todo eso da null -> 400 en los dos endpoints.
 *
 * DOS TRAMPAS que costaron corrupcion silenciosa y estan cubiertas con test:
 *
 * 1. Los espacios NO se sacan todos de una. Se sacan (a) los pegados a `@` o a
 *    `.`, que rompen la estructura de la direccion, y (b) el resto SOLO si
 *    quedaba uno solo y hay un solo `@`. Sacarlos todos siempre pegaria la
 *    prosa que rodea la direccion ("Facundo Tomas Lima 2644130910
 *    facundolima39@icloud.com") y las listas separadas por espacio
 *    ("juan@x.com maria@x.com" -> "juan@x.commaria").
 *
 * 2. El regex de rescate puede CORTAR una direccion por el medio en vez de
 *    aislarla, y lo que devuelve es una direccion valida pero de otra persona.
 *    "germán@m3kargentina.com.ar" se guardaba como "n@m3kargentina.com.ar".
 *    Por eso prospectoCorreoExtraer() exige que lo que quede pegado antes del
 *    match sea un separador; si no, rechaza.
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
 *   5. se baja a minuscula el valor ENTERO, host y path
 *
 * El paso 5 tiene un costo conocido y aceptado (decision del 2026-08-18): el
 * path es case sensitive, asi que lo que se guarda puede no resolver. Rompe los
 * vanity de Facebook (`facebook.com/MENDOSUR`), los ids de acortador
 * (`w.app/InternewNetworks`, `bit.ly/3SSePnt`) y los tokens de query de
 * Instagram (`?igshid=ZDc4ODBmNjlmNQ==`). Se prioriza tener el campo uniforme
 * para comparar y deduplicar. En dev al 2026-08-18 hay 569 filas ya cargadas
 * con mayusculas en el path; ninguna migracion las toca, asi que las viejas
 * conservan su capitalizacion y solo lo que entra de ahora en mas se baja.
 *
 * El `www.` se respeta tal cual venga: no se agrega ni se saca.
 *
 * A diferencia de los telefonos, lo que no queda como host valido NO se guarda
 * crudo: un "no posee" o un "en construccion" en `web` no es un dato que
 * alguien pueda corregir despues, es ruido de scraping. Va a null.
 *
 * Caso aparte: los correos cargados por error en `web`
 * ("electrorubenrodriguez@gmail.com"). prospectoWebComoCorreo() los devuelve
 * normalizados para que el llamador los mueva a `correo` cuando ese campo esta
 * libre; si el prospecto ya tiene correo, el valor se descarta igual que
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

function prospectoSoloDigitos(mixed $v): string {
    return preg_replace('/\D+/', '', (string)($v ?? ''));
}

// Largo del codigo de area de un numero nacional ya sin prefijos.
function prospectoAreaLen(string $d): int {
    if (str_starts_with($d, '11'))                          return 2;
    if (in_array(substr($d, 0, 3), CONTACTO_AREAS_3, true)) return 3;
    return 4;
}

// Aplica los pasos 2 a 7 sobre una cadena de digitos. No valida: eso es
// trabajo de prospectoTelefonoEsValido().
function prospectoDespejarPrefijos(string $d): string {
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
        $a = prospectoAreaLen($d);
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

function prospectoTelefonoEsValido(string $d): bool {
    return strlen($d) === 10 && preg_match(CONTACTO_PREFIJOS_VALIDOS, $d) === 1;
}

// Normaliza telefono / celular / whatsapp. Devuelve los 10 digitos cuando las
// reglas llegan a un numero nacional valido; si no, los digitos crudos; null
// si el campo no tiene ningun digito.
function prospectoNormalizarTelefono(mixed $v): ?string {
    $raw = trim((string)($v ?? ''));
    if ($raw === '') return null;

    $entero = prospectoDespejarPrefijos(prospectoSoloDigitos($raw));
    if (prospectoTelefonoEsValido($entero)) return $entero;

    // El campo puede traer dos numeros separados por espacios ("fijo movil").
    foreach (preg_split('/\s+/', $raw, -1, PREG_SPLIT_NO_EMPTY) as $token) {
        $cand = prospectoDespejarPrefijos(prospectoSoloDigitos($token));
        if (prospectoTelefonoEsValido($cand)) return $cand;
    }

    $digitos = prospectoSoloDigitos($raw);
    return $digitos === '' ? null : substr($digitos, 0, 255);
}

// Acentos y diacriticos -> ASCII. En un correo NUNCA son parte de la direccion
// real: son el nombre de la persona tipeado como se escribe
// ("german@", "analia_muniz@", "ricardo.trivino@"). El mapa es explicito y no
// iconv('ASCII//TRANSLIT') a proposito: ese depende del locale del server y
// segun el sistema devuelve "'n" o "?" en vez de "n", que es justo el tipo de
// diferencia que no se puede tener entre dev y prod.
//
// Se aplica tambien al dominio. Un dominio IDN real con eñe existe, pero en
// esta tabla lo que hay son cargas a mano ("saenzpeña.gob.ar" cuando el dominio
// registrado es saenzpena.gob.ar), asi que transliterar acierta mucho mas
// seguido de lo que falla. Ojo que `web` hace lo contrario y los conserva: ahi
// si hay dominios con eñe legitimos del padron.
const CONTACTO_CORREO_ACENTOS = [
    'á'=>'a', 'à'=>'a', 'ä'=>'a', 'â'=>'a', 'ã'=>'a', 'å'=>'a',
    'é'=>'e', 'è'=>'e', 'ë'=>'e', 'ê'=>'e',
    'í'=>'i', 'ì'=>'i', 'ï'=>'i', 'î'=>'i',
    'ó'=>'o', 'ò'=>'o', 'ö'=>'o', 'ô'=>'o', 'õ'=>'o', 'ø'=>'o',
    'ú'=>'u', 'ù'=>'u', 'ü'=>'u', 'û'=>'u',
    'ñ'=>'n', 'ç'=>'c', 'ý'=>'y', 'ÿ'=>'y',
];

// Reparaciones DETERMINISTAS sobre un valor ya recortado y en minuscula: las
// que no requieren adivinar cual era la direccion. Todo lo que si haria falta
// inventar (reponer un TLD ausente, corregir "hotmailcom", decidir donde iba un
// `@` que no vino) queda expresamente afuera y termina en null -> 400.
function prospectoCorreoRepararTipeos(string $s): string {
    // Envoltorios con que llega un correo copiado de un cliente de mail o
    // scrapeado de un HTML: "mailto:", "<juan@x.com>", comillas.
    $s = preg_replace('~^mailto:\s*~u', '', $s);
    $s = trim($s, " \t\n\r\0\x0B<>\"'");

    // `@` escrito con palabras, tipico de los sitios que ofuscan la direccion
    // para los bots: "informes(a)windnet.com.ar", "comercial[a]urbana.com.ar".
    $s = preg_replace('~\s*[(\[{]\s*(?:a|at|arroba)\s*[)\]}]\s*~u', '@', $s);

    $s = strtr($s, CONTACTO_CORREO_ACENTOS);

    // Puntuacion espuria pegada al separador: "gmail;.com", "gmail..com",
    // "juan.@x.com", "juan@.gmail.com".
    $s = preg_replace('~[;,]+\.~u', '.', $s);
    $s = preg_replace('~\.{2,}~u', '.', $s);
    return str_replace(['.@', '@.'], '@', $s);
}

// Lo que puede aparecer legitimamente pegado ANTES de una direccion cuando el
// campo trae una lista o una etiqueta ("correo: juan@x.com", "a@x.com, b@x.com").
// Cualquier otra cosa adelante significa que el rescate corto por el medio.
const CONTACTO_CORREO_SEPARADORES = " \t\n\r\0\x0B,;/|<>()[]{}:\"'";

// Extrae una direccion de un valor YA recortado, en minuscula y reparado.
// Devuelve null si no hay ninguna parseable. Separada de
// prospectoNormalizarCorreo() para poder correr las mismas dos reglas dos
// veces: con los espacios internos y sin ellos (ver alla el porque del orden).
function prospectoCorreoExtraer(string $s): ?string {
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
    if (preg_match('/[a-z0-9._%+-]+@[a-z0-9.-]+\.[a-z]{2,}/', $s, $m, PREG_OFFSET_CAPTURE) === 1) {
        [$dir, $off] = $m[0];
        // Guarda contra el rescate que CORTA en vez de aislar. Si lo que quedo
        // pegado justo antes del match no es un separador, el regex partio una
        // direccion por el medio y lo que devuelve es una direccion que no
        // existe. Pasa con:
        //   - mojibake que el mapa de acentos no cubre: "gonzßlez@x.com.ar"
        //     devolvia "lez@x.com.ar"
        //   - dobles `@`: "moreser@30@gmail.com" devolvia "30@gmail.com"
        // Eso no es corregir, es inventar: se rechaza y sale por el 400.
        if ($off > 0 && strpos(CONTACTO_CORREO_SEPARADORES, $s[$off - 1]) === false) {
            return null;
        }
        return substr($dir, 0, 255);
    }
    return null;
}

// Normaliza `correo` a minuscula. Devuelve null si el campo esta vacio o si no
// contiene ninguna direccion parseable.
function prospectoNormalizarCorreo(mixed $v): ?string {
    // mb_strtolower, no strtolower: el de PHP trabaja byte a byte y deja las
    // mayusculas acentuadas intactas ("ANALÍA@X.COM" -> "analÍa@x.com"), con lo
    // cual el mapa de acentos —que es solo minusculas— no las agarraria.
    $s = mb_strtolower(trim((string)($v ?? '')), 'UTF-8');
    if ($s === '') return null;

    $s = prospectoCorreoRepararTipeos($s);
    if ($s === '') return null;

    // Espacios, en dos pasos. NO se sacan todos de una: el campo muchas veces
    // trae la direccion rodeada de prosa o una lista, y pegar todo da un
    // engendro peor que no normalizar.
    //
    // (1) Los pegados a `@` o a `.` rompen la estructura de la direccion, asi
    //     que son siempre tipeos: "juan @gmail.com", "gmail. com", "hmail .com",
    //     "hotmail.com. ar".
    $s = preg_replace('~\s*([@.])\s*~u', '$1', $s);

    // (2) Si despues de eso queda UN solo espacio y UN solo `@`, tambien es un
    //     tipeo y se pega: "g.luengo 78@gmail.com" -> "g.luengo78@gmail.com".
    //     Con dos o mas espacios el campo trae otra cosa alrededor de la
    //     direccion ("Facundo Tomas Lima 2644130910 facundolima39@icloud.com")
    //     o es una lista ("juan@x.com maria@x.com"): ahi NO se pega nada y se
    //     deja que el rescate aisle la primera direccion.
    if (substr_count($s, ' ') === 1 && substr_count($s, '@') === 1) {
        $s = str_replace(' ', '', $s);
    }

    return prospectoCorreoExtraer($s);
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
function prospectoWebLimpiar(mixed $v): string {
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
function prospectoWebPartir(string $s): array {
    $i = strcspn($s, '/?#');
    return [substr($s, 0, $i), substr($s, $i)];
}

// Normaliza `web`. Devuelve host+path sin esquema, o null si el valor no es una
// URL (incluidos los correos, que resuelve prospectoWebComoCorreo()).
function prospectoNormalizarWeb(mixed $v): ?string {
    $s = prospectoWebLimpiar($v);
    if ($s === '') return null;

    [$host, $resto] = prospectoWebPartir($s);
    // Un `@` en el host es un correo mal cargado, no una URL. El `@` del path
    // si es legitimo ("youtube.com/@itscontrolseguridad").
    if ($host === '' || str_contains($host, '@')) return null;

    $host = mb_strtolower($host, 'UTF-8');
    if (preg_match(CONTACTO_WEB_HOST, $host) !== 1
        && preg_match(CONTACTO_WEB_IPV4, $host) !== 1) return null;

    // Minuscula TODO el valor, path y query incluidos. Ojo que el path SI es
    // case sensitive: esto rompe los vanity de Facebook
    // ("facebook.com/MENDOSUR"), los ids de acortador ("w.app/InternewNetworks")
    // y los tokens de Instagram ("?igshid=ZDc4ODBmNjlmNQ=="). Es una decision
    // explicita del 2026-08-18 — el campo se quiere uniforme para comparar y
    // deduplicar, y se acepta el costo en esos links.
    return mb_strtolower(mb_substr($host . $resto, 0, 255, 'UTF-8'), 'UTF-8');
}

// Devuelve el correo que estaba cargado por error en `web`, o null si el valor
// no es un correo. El `www.` pegado adelante ("www.tecnicatotal@hotmail.com")
// es un tipeo frecuente y se descarta antes de parsear.
function prospectoWebComoCorreo(mixed $v): ?string {
    $s = prospectoWebLimpiar($v);
    if ($s === '') return null;

    [$host] = prospectoWebPartir($s);
    if (!str_contains($host, '@')) return null;

    return prospectoNormalizarCorreo(preg_replace('~^www\.~i', '', $s));
}
