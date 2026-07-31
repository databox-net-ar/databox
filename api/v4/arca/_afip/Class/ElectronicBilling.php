<?php
// api/v4/arca/_afip/Class/ElectronicBilling.php
// Cliente SOAP para WSFEv1 (Factura Electronica v1) de AFIP.
//
// Portado de AfipSDK/afip.php v0.8.1 (MIT), adaptado para nuestro
// wrapper `ArcaAfip`:
//   - Sin flag de homologacion (siempre produccion).
//   - Sin CreatePDF (usaba `app.afipsdk.com/api/v1/pdfs`, servicio pago).
//   - SoapClient se construye aca (antes vivia en AfipWebService base).
//   - Inyecta <Auth><Token><Sign><Cuit> en cada request pidiendo el TA a
//     ArcaAfip::getServiceTA (que resuelve cache o llama WSAA).
//
// Docs oficiales AFIP: manual_desarrollador_COMPG_v2_10.pdf

class ElectronicBilling
{
    private const URL        = 'https://servicios1.afip.gov.ar/wsfev1/service.asmx';
    private const SERVICE_ID = 'wsfe';

    private ArcaAfip    $afip;
    private string      $wsdlPath;
    private ?SoapClient $client = null;

    public function __construct(ArcaAfip $afip)
    {
        $this->afip     = $afip;
        $this->wsdlPath = __DIR__ . '/../Afip_res/wsfe-production.wsdl';
        if (!file_exists($this->wsdlPath)) {
            throw new Exception('WSDL WSFE no encontrado en ' . $this->wsdlPath);
        }
    }

    // ------------------------------------------------------------------------
    // Operaciones publicas
    // ------------------------------------------------------------------------

    /**
     * FECompUltimoAutorizado: numero del ultimo comprobante autorizado para
     * (PtoVta, CbteTipo). Util para saber que numero le corresponde al
     * proximo. Retorna int.
     */
    public function GetLastVoucher(int $salesPoint, int $type): int
    {
        $req    = ['PtoVta' => $salesPoint, 'CbteTipo' => $type];
        $result = $this->execute('FECompUltimoAutorizado', $req);
        return (int)$result->CbteNro;
    }

    /**
     * FECAESolicitar: pide autorizacion de un comprobante. Recibe el $data
     * con los campos de un FECAEDetRequest + los campos de cabecera
     * (CantReg, PtoVta, CbteTipo) todos aplanados; internamente aca los
     * separamos y armamos la envoltura FeCAEReq.FeCabReq / FeDetReq.
     *
     * Retorna ['CAE' => string, 'CAEFchVto' => 'YYYY-MM-DD'] si fue
     * autorizado. Si AFIP rechaza (Resultado != 'A' o hay Errors), lanza
     * Exception con el codigo/mensaje devuelto.
     */
    public function CreateVoucher(array $data, bool $returnResponse = false)
    {
        $req = [
            'FeCAEReq' => [
                'FeCabReq' => [
                    'CantReg'  => $data['CbteHasta'] - $data['CbteDesde'] + 1,
                    'PtoVta'   => $data['PtoVta'],
                    'CbteTipo' => $data['CbteTipo'],
                ],
                'FeDetReq' => [
                    'FECAEDetRequest' => &$data,
                ],
            ],
        ];

        unset($data['CantReg'], $data['PtoVta'], $data['CbteTipo']);

        // Los sub-arrays van envueltos en el nombre del item singular segun
        // el WSDL de AFIP (esto es lo que espera FECAESolicitar por wire).
        if (isset($data['Tributos']))   $data['Tributos']   = ['Tributo'  => $data['Tributos']];
        if (isset($data['Iva']))        $data['Iva']        = ['AlicIva'  => $data['Iva']];
        if (isset($data['Opcionales'])) $data['Opcionales'] = ['Opcional' => $data['Opcionales']];
        if (isset($data['CbtesAsoc']))  $data['CbtesAsoc']  = ['CbteAsoc' => $data['CbtesAsoc']];

        $result = $this->execute('FECAESolicitar', $req);

        if ($returnResponse) return $result;

        return [
            'CAE'       => (string)$result->FeDetResp->FECAEDetResponse->CAE,
            'CAEFchVto' => self::formatDate((string)$result->FeDetResp->FECAEDetResponse->CAEFchVto),
        ];
    }

    /**
     * FECompConsultar: consulta un comprobante ya emitido. Retorna null si
     * el (PtoVta, CbteTipo, CbteNro) no existe en AFIP (error 602).
     */
    public function GetVoucherInfo(int $number, int $salesPoint, int $type)
    {
        $req = [
            'FeCompConsReq' => [
                'CbteNro'  => $number,
                'PtoVta'   => $salesPoint,
                'CbteTipo' => $type,
            ],
        ];
        try {
            $result = $this->execute('FECompConsultar', $req);
        } catch (Exception $e) {
            if ((int)$e->getCode() === 602) return null;
            throw $e;
        }
        return $result->ResultGet;
    }

    /** FEDummy: healthcheck de AFIP. Retorna objeto con AppServer/DbServer/AuthServer. */
    public function GetServerStatus()          { return $this->execute('FEDummy'); }

    /** FEParamGet* — catalogos. Cada uno retorna un array con los items del catalogo. */
    public function GetVoucherTypes()          { return $this->execute('FEParamGetTiposCbte')->ResultGet->CbteTipo; }
    public function GetConceptTypes()          { return $this->execute('FEParamGetTiposConcepto')->ResultGet->ConceptoTipo; }
    public function GetDocumentTypes()         { return $this->execute('FEParamGetTiposDoc')->ResultGet->DocTipo; }
    public function GetAliquotTypes()          { return $this->execute('FEParamGetTiposIva')->ResultGet->IvaTipo; }
    public function GetCurrenciesTypes()       { return $this->execute('FEParamGetTiposMonedas')->ResultGet->Moneda; }
    public function GetOptionsTypes()          { return $this->execute('FEParamGetTiposOpcional')->ResultGet->OpcionalTipo; }
    public function GetTaxTypes()              { return $this->execute('FEParamGetTiposTributos')->ResultGet->TributoTipo; }
    public function GetSalesPoints()           { return $this->execute('FEParamGetPtosVenta')->ResultGet->PtoVenta; }

    // ------------------------------------------------------------------------
    // Internos
    // ------------------------------------------------------------------------

    /**
     * Formato AFIP para fechas: YYYYMMDD -> YYYY-MM-DD.
     */
    public static function formatDate(string $ymd): string
    {
        $d = DateTime::createFromFormat('Ymd', $ymd);
        if ($d === false) return $ymd;
        return $d->format('Y-m-d');
    }

    /**
     * Prepara la envoltura Auth (excepto FEDummy que no la lleva), dispara
     * el SoapClient y valida errores AFIP.
     */
    private function execute(string $operation, array $params = [])
    {
        // FEDummy no requiere auth.
        if ($operation !== 'FEDummy') {
            $ta = $this->afip->getServiceTA(self::SERVICE_ID);
            $params = array_replace([
                'Auth' => [
                    'Token' => $ta['token'],
                    'Sign'  => $ta['sign'],
                    'Cuit'  => $this->afip->cuit,
                ],
            ], $params);
        }

        if ($this->client === null) {
            $this->client = new SoapClient($this->wsdlPath, [
                'soap_version'   => SOAP_1_2,
                'location'       => self::URL,
                'trace'          => 1,
                'exceptions'     => true,
                'stream_context' => stream_context_create([
                    'ssl' => ['ciphers' => 'AES256-SHA', 'verify_peer' => false, 'verify_peer_name' => false],
                ]),
            ]);
        }

        $raw = $this->client->{$operation}($params);
        if (is_soap_fault($raw)) {
            throw new Exception('WSFE SOAP fault: ' . $raw->faultcode . ' ' . $raw->faultstring);
        }

        $result = $raw->{$operation . 'Result'} ?? $raw;
        $this->checkErrors($operation, $result);
        return $result;
    }

    /**
     * AFIP puede devolver errores por dos vias:
     *   - <Errors><Err> (fatal, la request no procesa).
     *   - <FeDetResp><FECAEDetResponse><Observaciones> con Resultado != 'A'
     *     (procesa pero rechaza el comprobante individual).
     * Ambos casos se convierten a Exception con codigo AFIP.
     */
    private function checkErrors(string $operation, $res): void
    {
        if ($operation === 'FECAESolicitar' && isset($res->FeDetResp->FECAEDetResponse)) {
            $det = $res->FeDetResp->FECAEDetResponse;
            // A veces viene como array si CantReg > 1; nos quedamos con el primero.
            if (is_array($det)) { $det = $det[0]; }
            if (isset($det->Observaciones) && (string)$det->Resultado !== 'A') {
                $res->Errors      = new StdClass();
                $res->Errors->Err = $det->Observaciones->Obs;
            }
        }

        if (isset($res->Errors)) {
            $err = is_array($res->Errors->Err) ? $res->Errors->Err[0] : $res->Errors->Err;
            throw new Exception('(' . $err->Code . ') ' . $err->Msg, (int)$err->Code);
        }
    }
}
