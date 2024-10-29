jQuery(document.body).on("wc_fragments_refreshed", function () {
  var oppwaId = jQuery("#oppwa-payment").data("oppwa-id");

  var s = document.createElement("script");
  s.type = "text/javascript";
  s.src =
    "https://eu-test.oppwa.com/v1/paymentWidgets.js?checkoutId=" + oppwaId;
  jQuery("body").append(s);

  var wpwlOptions = {
    style: "logos",
    imageStyle: "svg",

    inlineFlow: ["APPLEPAY", "GOOGLEPAY"],

    brandDetection: true,
    brandDetectionType: "binlist",
    brandDetectionPriority: ["APPLEPAY", "GOOGLEPAY", "MASTER", "VISA"],

    applePay: {
      displayName: "MISTR",
      total: {
        label: "MISTR",
      },
    },

    googlePay: {
      // Channel Entity ID
      gatewayMerchantId: "8a8294174b7ecb28014b9699220015ca",
      merchantInfo: {
        merchantName: "MISTR",
      },
      emailRequired: true,
      shippingAddressRequired: true,
      shippingAddressParameters: {
        phoneNumberRequired: true,
      },
      billingAddressRequired: true,
      billingAddressParameters: { format: "FULL", phoneNumberRequired: true },
      onPaymentDataChanged: function onPaymentDataChanged(
        intermediatePaymentData
      ) {
        return new Promise(function (resolve, reject) {
          resolve({});
        });
      },
      submitOnPaymentAuthorized: ["customer", "billing"],
      // The merchant onPaymentAuthorized implementation
      onPaymentAuthorized: function onPaymentAuthorized(paymentData) {
        const ajaxUrl = "/wp-admin/admin-ajax.php";
        const nonce = document.getElementById(
          "woocommerce-process-checkout-nonce"
        ).value;
        let shippingContact = paymentData.shippingAddress;

        console.log("process data ...");

        shippingContact.emailAddress = paymentData.email || "";

        shippingContact.addressLines = [];
        shippingContact.addressLines[0] = shippingContact.address1;
        shippingContact.addressLines[1] = shippingContact.address2;
        shippingContact.addressLines[2] = shippingContact.address3;

        // TODO
        let name = shippingContact.name.split(" ");
        shippingContact.givenName = name.shift();
        shippingContact.familyName = name.join(" ");

        let billingContact = paymentData.paymentMethodData.info.billingAddress;
        billingContact.emailAddress = paymentData.email || "";

        billingContact.addressLines = [];
        billingContact.addressLines[0] = billingContact.address1;
        billingContact.addressLines[1] = billingContact.address2;
        billingContact.addressLines[2] = billingContact.address3;

        name = billingContact.name.split(" ");
        billingContact.givenName = name.shift();
        billingContact.familyName = name.join(" ");

        // TODO
        let selectedShippingMethod = jQuery("#shipping_method li")
          .first()
          .find('input[type="hidden"]')
          .val();

        jQuery.ajax({
          dataType: "json",
          url: ajaxUrl,
          method: "POST",
          data: {
            action: "bankpay_google_pay_create_order_cart",
            shippingContact: shippingContact,
            billingContact: billingContact,
            //token: ApplePayPayment.payment.token,
            shippingMethod: selectedShippingMethod,
            "woocommerce-process-checkout-nonce": nonce,
            billing_first_name: billingContact.givenName || "",
            billing_last_name: billingContact.familyName || "",
            billing_company: "",
            billing_country: billingContact.countryCode || "",
            billing_address_1: billingContact.addressLines[0] || "",
            billing_address_2: billingContact.addressLines[1] || "",
            billing_postcode: billingContact.postalCode || "",
            billing_city: billingContact.locality || "",
            billing_state: billingContact.administrativeArea || "",
            billing_phone: billingContact.phoneNumber || "000000000000",
            billing_email: shippingContact.emailAddress || "",
            shipping_first_name: shippingContact.givenName || "",
            shipping_last_name: shippingContact.familyName || "",
            shipping_company: "",
            shipping_country: shippingContact.countryCode || "",
            shipping_address_1: shippingContact.addressLines[0] || "",
            shipping_address_2: shippingContact.addressLines[1] || "",
            shipping_postcode: shippingContact.postalCode || "",
            shipping_city: shippingContact.locality || "",
            shipping_state: shippingContact.administrativeArea || "",
            shipping_phone: shippingContact.phoneNumber || "000000000000",
            shipping_email: shippingContact.emailAddress || "",
            order_comments: "",
            payment_method: "oppwa",
            payment_request_type: "google_pay",
            payment_method_id: jQuery("#oppwa-payment").data("oppwa-id"),
            _wp_http_referer: "/?wc-ajax=xxx",
          },
          complete: (jqXHR, textStatus) => {},
          success: (authorizationResult, textStatus, jqXHR) => {
            let result = authorizationResult.data;
            if (authorizationResult.success === true) {
              window.location.href = result.redirect;
            } else {
              console.log("payment done", result);
            }
          },
          error: (jqXHR, textStatus, errorThrown) => {
            console.warn(textStatus, errorThrown);
          },
        });

        return new Promise(function (resolve, reject) {
          resolve({ transactionState: "SUCCESS" });
        });
      },
    },

    onReady: function () {
      console.log("OPPWA WooCommerce Plugin: ready for payment");

      ready = true;

      // jQuery(".wpwl-group-brand").hide();
      jQuery(".wpwl-group-expiry").hide();
      jQuery(".wpwl-group-cardHolder").hide();
      jQuery(".wpwl-group-cvv").hide();
      jQuery(".wpwl-group-submit").hide();
    },

    onChangeBrand: function () {
      console.log("OPPWA WooCommerce Plugin: payment brand changed");
    },
  };
});
