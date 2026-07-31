<?php
// api/v4/arca/_afip/Afip.php
// Wrapper delgado sobre los webservices SOAP de AFIP (WSAA + WSFEv1).
//
// Basado en la version 0.8.1 de AfipSDK/afip.php (MIT), la ultima liberada
// antes de que el paquete pasara a ser un proxy contra `app.afipsdk.com`.
// Aca solo queda lo que necesitamos:
//
//   - SIEMPRE PRODUCCION. No hay flag `homologacion`.
//   - Cert / llave PEM se reciben como STRING (leidos de la tabla
//     `arca_certificados`), no como paths a archivos en disco.
//   - El cache del "Ticket de Acceso" (TA) se delega a dos closures
//     inyectados al construir. Nuestro consumidor cachea en la tabla
//     `arca_ta_cache` (una fila por certificado+service).
//   - Se elimino todo el tracking (mixpanel, sdk-events), toda la lib
//     `Requests`, y todos los otros webservices (RegisterScopeFour, etc.).
//
// El archivo se mantiene sin namespace a proposito, para copiar el estilo
// PSR-0 del SDK original y para que el resto del monorepo (que tampoco usa
// namespaces en `api/v4/`) lo levante con un simple `require_once`.

if (!defined('SOAP_1_2')) { define('SOAP_1_2', 2); }

class ArcaAfip
{
    /** CUIT del contribuyente (int sin guiones). */
    public int $cuit;

    /** Certificado X.509 en formato PEM (BEGIN CERTIFICATE ...). */
    public string $certPem;

    /** Clave privada en formato PEM (BEGIN [RSA ]PRIVATE KEY ...). */
    public string $keyPem;

    /** Passphrase de la clave privada (vacio si no tiene). */
    public string $passphrase;

    /** Callback para leer un TA cacheado.
     *  fn(string $service): ?array{token: string, sign: string, expira_en: int}
     *  Retornar null si no hay TA vivo -> se pide uno nuevo a WSAA. */
    public $taLoad;

    /** Callback para persistir el TA recien emitido.
     *  fn(string $service, string $token, string $sign, int $expira_en): void */
    public $taSave;

    private string $wsaaWsdl;
    private string $wsaaUrl = 'https://wsaa.afip.gov.ar/ws/services/LoginCms';

    /** Servicios ya instanciados (memoria por instancia). */
    private array $webServices = [];

    /**
     * @param array $opts
     *   - cuit       (int, requerido)
     *   - cert       (string PEM, requerido)
     *   - key        (string PEM, requerido)
     *   - passphrase (string, opcional -- default '')
     *   - taLoad     (callable, requerido)
     *   - taSave     (callable, requerido)
     */
    public function __construct(array $opts)
    {
        if (!isset($opts['cuit']))    throw new Exception('Falta cuit');
        if (!isset($opts['cert']))    throw new Exception('Falta cert');
        if (!isset($opts['key']))     throw new Exception('Falta key');
        if (!isset($opts['taLoad']))  throw new Exception('Falta taLoad');
        if (!isset($opts['taSave']))  throw new Exception('Falta taSave');

        $this->cuit       = (int)$opts['cuit'];
        $this->certPem    = (string)$opts['cert'];
        $this->keyPem     = (string)$opts['key'];
        $this->passphrase = (string)($opts['passphrase'] ?? '');
        $this->taLoad     = $opts['taLoad'];
        $this->taSave     = $opts['taSave'];

        $this->wsaaWsdl = __DIR__ . '/Afip_res/wsaa.wsdl';
        if (!file_exists($this->wsaaWsdl)) {
            throw new Exception('WSDL WSAA no encontrado en ' . $this->wsaaWsdl);
        }

        // Evita que PHP guarde el WSDL en el cache global (queremos que lea
        // siempre nuestro archivo local, sin fetch al vuelo).
        ini_set('soap.wsdl_cache_enabled', '0');
    }

    /**
     * Devuelve el par (token, sign) valido para el service. Usa el cache
     * si esta vivo, si no dispara WSAA.
     *
     * @return array{token: string, sign: string}
     */
    public function getServiceTA(string $service): array
    {
        // 1. Intentar cache.
        $cached = ($this->taLoad)($service);
        if (is_array($cached) && isset($cached['token'], $cached['sign'], $cached['expira_en'])) {
            // Margen de 10 min antes de considerarlo expirado (el TA dura 12h,
            // pero renovamos un rato antes para evitar carreras al vencer).
            if ($cached['expira_en'] > time() + 600) {
                return ['token' => (string)$cached['token'], 'sign' => (string)$cached['sign']];
            }
        }

        // 2. Emitir uno nuevo contra WSAA.
        return $this->requestNewTA($service);
    }

    /** Crea el TRA XML, lo firma con PKCS7 y llama loginCms del WSAA. */
    private function requestNewTA(string $service): array
    {
        // -- Armar TRA XML.
        $now = time();
        $tra = new SimpleXMLElement(
            '<?xml version="1.0" encoding="UTF-8"?>' .
            '<loginTicketRequest version="1.0"></loginTicketRequest>'
        );
        $header = $tra->addChild('header');
        $header->addChild('uniqueId',       (string)$now);
        $header->addChild('generationTime', date('c', $now - 600));
        $header->addChild('expirationTime', date('c', $now + 600));
        $tra->addChild('service', $service);

        // -- Firmar TRA (PKCS7). openssl_pkcs7_sign necesita archivos, no
        //    strings, asi que escribimos cert/key/tra en tempfiles y
        //    los borramos al terminar.
        $tmpDir  = sys_get_temp_dir();
        $traFile = tempnam($tmpDir, 'arca_tra_');
        $cmsFile = tempnam($tmpDir, 'arca_cms_');
        $crtFile = tempnam($tmpDir, 'arca_crt_');
        $keyFile = tempnam($tmpDir, 'arca_key_');
        try {
            file_put_contents($traFile, $tra->asXML());
            file_put_contents($crtFile, $this->certPem);
            file_put_contents($keyFile, $this->keyPem);

            $ok = openssl_pkcs7_sign(
                $traFile,
                $cmsFile,
                'file://' . $crtFile,
                ['file://' . $keyFile, $this->passphrase],
                [],
                !PKCS7_DETACHED
            );
            if (!$ok) {
                throw new Exception('openssl_pkcs7_sign fallo: ' . openssl_error_string());
            }

            // El CMS que devuelve openssl viene con headers MIME antes del
            // cuerpo base64; hay que saltear las 4 primeras lineas para
            // extraer solo el CMS crudo (asi lo hace tambien la lib
            // upstream).
            $lines = file($cmsFile, FILE_IGNORE_NEW_LINES);
            if ($lines === false) {
                throw new Exception('No se pudo leer el CMS firmado');
            }
            $cms = implode("\n", array_slice($lines, 4));
        } finally {
            foreach ([$traFile, $cmsFile, $crtFile, $keyFile] as $f) {
                if ($f && file_exists($f)) @unlink($f);
            }
        }

        // -- Enviar CMS a WSAA via SOAP.
        $client = new SoapClient($this->wsaaWsdl, [
            'soap_version'   => SOAP_1_2,
            'location'       => $this->wsaaUrl,
            'trace'          => 1,
            'exceptions'     => true,
            // AFIP a veces sirve cadenas de certs sin la raiz completa;
            // desactivamos verificacion de peer para que no falle por eso.
            // (La conexion sigue siendo TLS; solo saltamos el chain check.)
            'stream_context' => stream_context_create([
                'ssl' => ['ciphers' => 'AES256-SHA', 'verify_peer' => false, 'verify_peer_name' => false],
            ]),
        ]);

        $resp = $client->loginCms(['in0' => $cms]);
        if (is_soap_fault($resp)) {
            throw new Exception('WSAA SOAP fault: ' . $resp->faultcode . ' ' . $resp->faultstring);
        }

        $ta = new SimpleXMLElement((string)$resp->loginCmsReturn);
        $token     = (string)$ta->credentials->token;
        $sign      = (string)$ta->credentials->sign;
        $expira_en = (new DateTime((string)$ta->header->expirationTime))->getTimestamp();

        // -- Persistir en cache (delegado al consumidor).
        ($this->taSave)($service, $token, $sign, $expira_en);

        return ['token' => $token, 'sign' => $sign];
    }

    /** Accesor magico para instanciar servicios (mismo estilo que la lib upstream). */
    public function __get(string $property)
    {
        if ($property === 'ElectronicBilling') {
            if (!isset($this->webServices[$property])) {
                require_once __DIR__ . '/Class/ElectronicBilling.php';
                $this->webServices[$property] = new ElectronicBilling($this);
            }
            return $this->webServices[$property];
        }
        throw new Exception("Servicio no implementado: {$property}");
    }
}
