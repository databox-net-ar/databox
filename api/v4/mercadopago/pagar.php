<?php
/**
 * api/v4/mercadopago/pagar.php
 *
 *   GET /v4/mercadopago/pagar?cta=&fct=&mnt=&cpt=&ret=
 *
 * Pagina del boton de pago (Checkout Pro). Es el unico endpoint del
 * microservicio que le contesta HTML a una persona: el sistema origen manda al
 * comprador aca con los datos de la factura, y desde aca sigue el circuito
 * `procesar` -> checkout de Mercado Pago -> `aprobado|pendiente|rechazado`.
 *
 * Port de `databox-api/v2/mercadopago/pagar.php`. Misma firma de query string,
 * mismo HTML, mismo comportamiento.
 *
 * ---------------------------------------------------------------------------
 * ESTE GET ESCRIBE
 * ---------------------------------------------------------------------------
 * Cada visita da de alta una fila en `mercadopagopagos` con estado 'I'
 * (iniciado), porque el `id` de esa fila es el `external_reference` que despues
 * permite correlacionar la notificacion del webhook con la factura del sistema
 * origen. Va contra la regla de la casa de que un GET no modifica, y se
 * mantiene a proposito: es la firma del legacy y cambiarla obligaria a tocar
 * todos los sistemas que hoy linkean aca.
 *
 * El efecto practico: recargar la pagina crea otro intento de pago. Es lo que
 * ya pasa en el legacy — por eso `mercadopagopagos` tiene muchas filas en 'I'
 * que nunca avanzaron. No son un problema; son intentos abandonados.
 *
 * ---------------------------------------------------------------------------
 * CREDENCIALES
 * ---------------------------------------------------------------------------
 * El par publicKey/accessToken que corresponde al `modo` de la cuenta se
 * guarda en la sesion para que `procesar` firme la preferencia con el mismo.
 * El accessToken NO se imprime en el HTML — solo viaja la publicKey, que es
 * publica por definicion.
 */

require_once __DIR__ . '/_lib/mercadopago.php';
require_once dirname(__DIR__) . '/_lib/log.php';

v4InitLog('v4/mercadopago.pagar');

$cuentaUuid      = trim((string)($_GET['cta'] ?? $_POST['cta'] ?? ''));
$facturaId       = (string)($_GET['fct'] ?? $_POST['fct'] ?? '');
$facturaMonto    = (string)($_GET['mnt'] ?? $_POST['mnt'] ?? '');
$facturaConcepto = (string)($_GET['cpt'] ?? $_POST['cpt'] ?? '');
$pagoRetorno     = (string)($_GET['ret'] ?? $_POST['ret'] ?? '');

// `ret` termina en un header Location, asi que se valida antes de guardarlo:
// sin el ancla `^https?://` alcanza un `javascript:` para convertir el retorno
// en un XSS almacenado. Mismo chequeo que el legacy.
if (!preg_match('#^https?://#i', $pagoRetorno)) {
    mpCortarNavegador('Parámetro "ret" inválido: debe ser una URL absoluta (https://...).');
}

$cuenta = mpCuentaPorUuid($cuentaUuid);
// El legacy no valida la cuenta: con un `cta` desconocido arma igual la pagina
// (sin logo ni nombre) y deja un pago con cuenta=0 que nunca se puede imputar.
// Aca se corta: un `cta` valido se comporta exactamente igual que antes, y uno
// invalido dice que pasa en vez de mostrar una pagina rota.
if ($cuenta === null) {
    mpCortarNavegador('Cuenta de Mercado Pago no encontrada. Revisá el parámetro "cta".', 404);
}

[$publicKey, $accessToken] = mpCredenciales($cuenta);

$pagoId = mpPagoAlta([
    'cuenta'   => (int)$cuenta['id'],
    'factura'  => (int)mpCero($facturaId),
    'monto'    => mpCero($facturaMonto),
    'concepto' => $facturaConcepto,
    'retorno'  => $pagoRetorno,
]);

mpSesionEscribir('pagoId',            $pagoId);
mpSesionEscribir('cuentaId',          (int)$cuenta['id']);
mpSesionEscribir('facturaId',         $facturaId);
mpSesionEscribir('facturaMonto',      $facturaMonto);
mpSesionEscribir('cuentaNombre',      (string)$cuenta['nombre']);
mpSesionEscribir('cuentaPublicKey',   $publicKey);
mpSesionEscribir('cuentaAccessToken', $accessToken);

// Todo lo que se imprime pasa por aca. El legacy interpola `cpt` (que viene de
// la query string) crudo en el HTML y en el JS — un XSS reflejado a un link de
// distancia. El texto renderizado es identico; lo unico que cambia es que un
// `<script>` en el concepto ahora se ve como texto en vez de ejecutarse.
$h = static fn(mixed $v): string => htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');

$nombre   = (string)$cuenta['nombre'];
$logo     = (string)$cuenta['logo'];
$concepto = $facturaConcepto;
$monto    = $facturaMonto;
?>
<!DOCTYPE html>
<html lang="es">

<head>
  <title>Pago para <?= $h($nombre) ?></title>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <link rel="stylesheet" type="text/css" href="https://maxcdn.bootstrapcdn.com/bootstrap/4.0.0/css/bootstrap.min.css">
  <script src="https://ajax.googleapis.com/ajax/libs/jquery/3.4.1/jquery.min.js"></script>
  <script src="https://sdk.mercadopago.com/js/v2"></script>

  <!-- image preview -->
  <meta property="og:title" content="Pago para <?= $h($nombre) ?>">
  <meta property="og:description" content="Botón de Pago para <?= $h($nombre) ?>">
  <meta property="og:image" content="https://media.databox.net.ar/datacount/mercadopago/og-image.png">
  <meta property="og:type" content="website">
</head>

<style>
  body {
    background-color: #fff;
    size: 100%;
    width: auto;
    height: auto;
    font-family: "Helvetica Neue", Helvetica, sans-serif;
    color: RGBA(0, 0, 0, 0.8);
  }

  main {
    margin: 4px 0 0px 0;
    background-color: #f6f6f6;
    min-height: 90%;
    padding-bottom: 100px;
  }

  .hidden {
    display: none
  }

  /* Shopping Cart Section - Start */
  .shopping-cart {
    padding-bottom: 10px;
    overflow: hidden;
    transition: max-height 5s ease-in-out;
  }

  .shopping-cart.hide {
    max-height: 0;
    pointer-events: none;
  }

  .shopping-cart .content {
    box-shadow: 0px 2px 10px rgba(0, 0, 0, 0.075);
    background-color: white;
  }

  .shopping-cart .block-heading {
    padding-top: 40px;
    margin-bottom: 30px;
    text-align: center;
  }

  .shopping-cart .block-heading p {
    text-align: center;
    max-width: 600px;
    margin: auto;
    color: RGBA(0, 0, 0, 0.45);
  }

  .shopping-cart .block-heading h1,
  .shopping-cart .block-heading h2,
  .shopping-cart .block-heading h3 {
    margin-bottom: 1.2rem;
    color: #009EE3;
  }

  .shopping-cart .items {
    margin: auto;
  }

  .shopping-cart .items .product {
    margin-bottom: 0px;
    padding-top: 20px;
    padding-bottom: 20px;
  }

  .shopping-cart .items .product .info {
    padding-top: 0px;
    text-align: left;
  }

  .shopping-cart .items .product .info .product-details .product-detail {
    padding-top: 40px;
    padding-left: 40px;
  }

  .shopping-cart .items .product .info .product-details h5 {
    color: #009EE3;
    font-size: 19px;
  }

  .shopping-cart .items .product .info .product-details .product-info {
    font-size: 15px;
    margin-top: 15px;
  }

  .shopping-cart .items .product .info .product-details label {
    width: 50px;
    color: #009EE3;
    font-size: 19px;
  }

  .shopping-cart .items .product .info .product-details input {
    width: 80px;
  }

  .shopping-cart .items .product .info .price {
    margin-top: 15px;
    font-weight: bold;
    font-size: 22px;
  }

  .shopping-cart .summary {
    border-top: 2px solid #C6E9FA;
    background-color: #f7fbff;
    height: 100%;
    padding: 30px;
  }

  .shopping-cart .summary h3 {
    text-align: center;
    font-size: 1.3em;
    font-weight: 400;
    padding-top: 20px;
    padding-bottom: 20px;
  }

  .shopping-cart .summary .summary-item:not(:last-of-type) {
    padding-bottom: 10px;
    padding-top: 10px;
  }

  .shopping-cart .summary .text {
    font-size: 1em;
    font-weight: 400;
  }

  .shopping-cart .summary .price {
    font-size: 1em;
    float: right;
  }

  .shopping-cart .summary button {
    margin-top: 20px;
    background-color: #009EE3;
  }

  @media (min-width: 768px) {

    .shopping-cart .items .product .info .product-details .product-detail {
      padding-top: 40px;
      padding-left: 40px;
    }

    .shopping-cart .items .product .info .price {
      font-weight: 500;
      font-size: 22px;
      top: 17px;
    }

    .shopping-cart .items .product .info .quantity {
      text-align: center;
    }

    .shopping-cart .items .product .info .quantity .quantity-input {
      padding: 4px 10px;
      text-align: center;
    }
  }

  /* Checkout Payment Section - Start */
  .container_payment {
    display: none;
  }

  .payment-form {
    padding-bottom: 10px;
    margin-right: 15px;
    margin-left: 15px;
    font-family: "Helvetica Neue", Helvetica, sans-serif;
  }

  .payment-form.dark {
    background-color: #f6f6f6;
  }

  .payment-form .content {
    box-shadow: 0px 2px 10px rgba(0, 0, 0, 0.075);
    background-color: white;
  }

  .payment-form .block-heading {
    padding-top: 40px;
    margin-bottom: 30px;
    text-align: center;
  }

  .payment-form .block-heading p {
    text-align: center;
    max-width: 420px;
    margin: auto;
    color: RGBA(0, 0, 0, 0.45);
  }

  .payment-form .block-heading h1,
  .payment-form .block-heading h2,
  .payment-form .block-heading h3 {
    margin-bottom: 1.2rem;
    color: #009EE3;
  }

  .payment-form .form-payment {
    border-top: 2px solid #C6E9FA;
    box-shadow: 0px 2px 10px rgba(0, 0, 0, 0.075);
    background-color: #ffffff;
    padding: 0;
    max-width: 600px;
    margin: auto;
  }

  .payment-form .title {
    font-size: 1em;
    border-bottom: 1px solid rgba(0, 0, 0, 0.1);
    margin-bottom: 0.8em;
    font-weight: 400;
    padding-bottom: 8px;
  }

  .payment-form .products {
    background-color: #f7fbff;
    padding: 25px;
  }

  .payment-form .products .item {
    margin-bottom: 1em;
  }

  .payment-form .products .item-name {
    font-weight: 500;
    font-size: 0.9em;
  }

  .payment-form .products .item p {
    margin-bottom: 0.2em;
  }

  .payment-form .products .price {
    float: right;
    font-weight: 500;
    font-size: 0.9em;
  }

  .payment-form .products .total {
    border-top: 1px solid rgba(0, 0, 0, 0.1);
    margin-top: 10px;
    padding-top: 19px;
    font-weight: 500;
    line-height: 1;
  }

  .payment-form .payment-details {
    padding: 25px 25px 15px;
    height: 100%;
  }

  .payment-form .payment-details label {
    font-size: 12px;
    font-weight: 600;
    margin-bottom: 15px;
    color: #8C8C8C;
    text-transform: uppercase;
  }

  .payment-form button {
    margin-top: 0.6em;
    padding: 12px 0;
    font-weight: 500;
    background-color: #009EE3;
    margin-bottom: 10px;
  }

  .payment-form .mercadopago-button {
    width: 100%;
    padding: 8px 0;
  }

  .payment-form a,
  .payment-form a:not([href]) {
    margin: 0;
    padding: 0;
    font-size: 13px;
    color: #009ee3;
    cursor: pointer;
  }

  .payment-form a:not([href]):hover {
    color: #3483FA;
    cursor: pointer;
  }

  .input-background {
    background-position: 98% 50%;
    background-repeat: no-repeat;
    background-color: #fff;
  }

  footer {
    padding: 2% 10% 6% 10%;
    margin: 0 auto;
    position: relative;
  }

  #horizontal_logo {
    width: 150px;
    margin: 0;
  }

  footer p a {
    color: #009ee3;
    text-decoration: none;
  }

  footer p a:hover {
    color: #3483FA;
    text-decoration: none;
  }

  @media (min-width: 576px) {
    .payment-form .title {
      font-size: 1.2em;
    }

    .payment-form .products {
      padding: 40px;
    }

    .payment-form .products .item-name {
      font-size: 1em;
    }

    .payment-form .products .price {
      font-size: 1em;
    }

    .payment-form .payment-details {
      padding: 20px 40px;
    }

    .payment-form .payment-details button {
      margin-top: 1em;
      margin-bottom: 15px;
    }

    .footer_logo {
      margin: 0 0 0 0;
      width: 20%;
      text-align: left;
      position: absolute;
    }

    .footer_text {
      margin: 0 0 0 65%;
      width: 200px;
      text-align: left;
      position: absolute
    }

    footer p {
      padding: 1px;
      font-size: 13px;
      color: RGBA(0, 0, 0, 0.45);
      margin-bottom: 0;
    }
  }

  @media (max-width: 576px) {
    footer {
      padding: 5% 1% 15% 1%;
      height: 55px;
    }

    footer p {
      padding: 1px;
      font-size: 11px;
      margin-bottom: 0;
    }

    .footer_text {
      margin: 0 0 0 45%;
      width: 180px;
      position: absolute
    }

    .footer_logo {
      margin: 0 0 0 0;
      position: absolute;
    }
  }
</style>

<body>
  <main>

    <!-- cart -->
    <section class="shopping-cart dark">
      <div class="container" id="container">
        <div class="block-heading">
          <img id="horizontal_logo" alt="image of the logo" src="img/horizontal_logo.png">
        </div>
        <div class="content" style="padding-bottom:20px;">
          <div class="row">
            <div class="col-md-12">

              <div class="items">
                <div class="product">
                  <div class="info">
                    <div class="product-details">
                      <div class="row justify-content-md-center">

                        <div class="col-md-12" style="margin-top: 10px; margin-bottom: 6px;">
                          <img class="img-fluid mx-auto d-block image" alt="<?= $h('Logo ' . $nombre) ?>" src="<?= $h($logo . '?rnd=456') ?>">
                        </div>

                      </div>
                    </div>
                  </div>
                </div>
              </div>

            </div>
          </div>

          <div class="col-md-12">
            <div class="summary">

              <input type="hidden" id="unit-price" value="<?= $h($monto) ?>">
              <input type="hidden" id="quantity" value="1">

              <div class="summary-item">
                <span class="text font-weight-bold">Description</span><span class="price" id="product-description"><?= $h($concepto) ?></span>
              </div>

              <div class="summary-item">
                <span class="text font-weight-bold">Total</span><span class="price" id="cart-total">$ <?= $h($monto) ?></span>
              </div>

              <button class="btn btn-primary btn-lg btn-block" id="checkout-btn">Continuar</button>

              <br>
              <a href="javascript:void();" onclick="history.back(1);" class="payment-form" style="text-decoration: none;">
                <svg xmlns="http://www.w3.org/2000/svg" width="12" height="12" viewBox="0 0 10 10" class="chevron-left">
                  <path fill="#009EE3" fill-rule="nonzero" id="chevron_left" d="M7.05 1.4L6.2.552 1.756 4.997l4.449 4.448.849-.848-3.6-3.6z"></path>
                </svg>
                Volver
              </a>

            </div>
          </div>

        </div>

      </div>
    </section>
    <!-- /cart -->

    <!-- payment -->
    <section class="payment-form dark">
      <div class="container_payment">

        <div class="block-heading">
          <img id="horizontal_logo" alt="image of the logo" src="img/horizontal_logo.png">
        </div>

        <div class="form-payment">
          <div class="products">

            <div class="total">
              Descripcion <span class="price" id="summary-description"><?= $h($concepto) ?></span>
            </div>

            <div class="total">
              Total<span class="price" id="summary-total">$ <?= $h($monto) ?></span>
            </div>

          </div>
          <div class="payment-details">

            <div class="form-group col-sm-12">

              <br>

              <div id="button-checkout"></div>

              <br>
              <a id="go-back">
                <svg xmlns="http://www.w3.org/2000/svg" width="12" height="12" viewBox="0 0 10 10" class="chevron-left">
                  <path fill="#009EE3" fill-rule="nonzero" id="chevron_left" d="M7.05 1.4L6.2.552 1.756 4.997l4.449 4.448.849-.848-3.6-3.6z"></path>
                </svg>
                Volver
              </a>

            </div>

          </div>
        </div>
      </div>
    </section>
    <!-- /payment -->

  </main>

</body>

<script>
  // La publicKey es publica por definicion (identifica al vendedor en el SDK
  // del navegador). El accessToken NO baja al cliente: vive en la sesion y lo
  // usa `procesar` del lado del servidor.
  const mercadopago = new MercadoPago(<?= json_encode($publicKey, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>, {
    locale: 'es-AR'
  });

  // Handle call to backend and generate preference.
  document.getElementById("checkout-btn").addEventListener("click", function() {

    $('#checkout-btn').attr("disabled", true);

    const orderData = {
      quantity: document.getElementById("quantity").value,
      description: document.getElementById("product-description").textContent,
      price: document.getElementById("unit-price").value
    };

    fetch("/v4/mercadopago/procesar", {
        method: "POST",
        headers: {
          "Content-Type": "application/json",
        },
        body: JSON.stringify(orderData),
      })
      .then(function(response) {
        return response.json();
      })
      .then(function(preference) {
        if (!preference || !preference.id) throw new Error(preference && preference.error ? preference.error : "sin preferencia");
        createCheckoutButton(preference.id);
        $(".shopping-cart").fadeOut(500);
        setTimeout(() => {
          $(".container_payment").show(500).fadeIn();
        }, 500);
      })
      .catch(function() {
        alert("Unexpected error");
        $('#checkout-btn').attr("disabled", false);
      });
  });

  // Create preference when click on checkout button
  function createCheckoutButton(preferenceId) {
    mercadopago.checkout({
      preference: {
        id: preferenceId
      },

      render: {
        container: '#button-checkout',
        label: 'Pagar',
      }
    });
  }

  // Handle price update
  function updatePrice() {
    let quantity = document.getElementById("quantity").value;
    let unitPrice = document.getElementById("unit-price").value;
    let amount = parseInt(unitPrice) * parseInt(quantity);
    document.getElementById("cart-total").innerHTML = "$ " + amount;
    document.getElementById("summary-total").innerHTML = "$ " + amount;
  }

  document.getElementById("quantity").addEventListener("change", updatePrice);

  // Go back
  document.getElementById("go-back").addEventListener("click", function() {
    $(".container_payment").fadeOut(500);
    setTimeout(() => {
      $(".shopping-cart").show(500).fadeIn();
    }, 500);
    $('#checkout-btn').attr("disabled", false);
  });
</script>

</html>
