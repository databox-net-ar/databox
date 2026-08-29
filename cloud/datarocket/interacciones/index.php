<?php
/**
 * cloud/datarocket/interacciones/index.php
 * Redirect 301 a la ubicacion nueva de la ficha publica de interaccion.
 *
 *   https://cloud.databox.net.ar/datarocket/interacciones/?t=<token>
 *        -> https://www.databox.net.ar/datarocket/prospecto/?t=<token>
 *
 * POR QUE ESTE ARCHIVO SIGUE EXISTIENDO
 * -------------------------------------
 * La pagina se mudo a www/ porque es publica y sin login, y cloud/ es el
 * dominio del panel (ver la cabecera del archivo nuevo). Pero los enlaces ya
 * emitidos viajan por WhatsApp y viven hasta 30 dias — el vencimiento va
 * firmado DENTRO del token y no hay tabla de control, asi que no se pueden
 * revocar ni reescribir. Cada uno de esos mensajes en el celular de alguien
 * apunta todavia a esta ruta. Sin este redirect, un responsable que abra un
 * aviso de la semana pasada se come un 404.
 *
 * CUANDO SE PUEDE BORRAR
 * ----------------------
 * Cuando no queden enlaces vivos emitidos contra la base vieja, o sea 30 dias
 * (el default de `datarocket.interacciones.enlace.dias`) despues de que la
 * migracion 20260828_2400 cambiara la base. Si ese parametro se subio a mas
 * dias, contar desde ahi.
 *
 * EL TOKEN VIAJA TAL CUAL
 * -----------------------
 * No se re-firma ni se valida aca: la firma cubre el id y el vencimiento, no el
 * host, asi que el mismo token sirve en la URL nueva. Validar de este lado
 * seria duplicar el verificador y tener dos lugares donde equivocarse.
 *
 * 301 y no 302: el destino es permanente y conviene que los navegadores lo
 * cacheen. La query string se preserva entera — sin `?t=` la pagina destino no
 * tiene nada que mostrar.
 */

// Apunta directo al destino FINAL. La ruta intermedia
// www/datarocket/interacciones/ existio unos minutos entre dos renombres y no
// llego a emitirse ni un enlace con ella: encadenar dos 301 solo agregaria un
// salto que puede fallar.
const FICHA_DESTINO_PROD = 'https://www.databox.net.ar/datarocket/prospecto/';
const FICHA_DESTINO_DEV  = 'http://localhost:8113/datarocket/prospecto/';

require_once __DIR__ . '/../../api/db.php';   // trae env.php -> APP_ENV

$destino = (defined('APP_ENV') && APP_ENV === 'production')
    ? FICHA_DESTINO_PROD
    : FICHA_DESTINO_DEV;

$qs = (string) ($_SERVER['QUERY_STRING'] ?? '');
if ($qs !== '') $destino .= '?' . $qs;

// `noindex` tambien aca: la URL vieja no debe quedar indexada mientras el
// redirect siga vivo.
header('X-Robots-Tag: noindex, nofollow, noarchive');
header('Referrer-Policy: no-referrer');
header('Cache-Control: no-store');
header('Location: ' . $destino, true, 301);
echo 'Esta página se mudó a ' . htmlspecialchars($destino, ENT_QUOTES, 'UTF-8');
