<?php

// quick-checkout: /?page_id=8&add-to-cart=11&payment=oppwa

require_once 'app.php';

use Psr\Log\LoggerInterface as Logger;
use Psr\Log\LogLevel;

function woocommerce_api_oppwa_init() {

	if (!class_exists('WC_Payment_Gateway')) {
		return;
	}

	/**
	 * OPPWA OPPWA Payment Gateway.
	 *
	 * Provides a OPPWA OPPWA Payment Gateway. Based on code by Mike Pepper.
	 *
	 * @class       WC_Gateway_oppwa
	 * @extends     WC_Payment_Gateway
	 * @version     1.1.21
	 */
	class WC_Gateway_oppwa extends WC_Payment_Gateway {

		/**
		 * Array of locales
		 *
		 * @var array
		 */
		public $locale;

		/**
		 * @var Logger
		 */
		protected $logger;

		/**
		 * Constructor for the gateway.
		 */
		public function __construct() {

			// print_r(get_option( 'active_plugins', array() ));

			$this->logger = false; // new Logger; //$logger;

			$this->id                 = 'oppwa';
			$this->icon               = apply_filters( 'woocommerce_oppwa_icon', '' );
			$this->has_fields         = false;
			$this->method_title       = __( 'OPPWA', 'woocommerce' );
			$this->method_description = __( 'Accept payments via OPPWA', 'woocommerce' );

			// Load the settings.
			$this->init_form_fields();
			$this->init_settings();

			// Define user set variables.
			$this->title        = $this->get_option( 'title' );
			$this->description  = $this->get_option( 'description' );
			$this->instructions = $this->get_option( 'instructions' );

			$settings = array('mode', 'returnUrl', 'debugMode');

			foreach ($settings as $key) {
				if (!isset($this->settings[ $key ]) || empty($this->settings[ $key ])) {
					$this->settings[ $key ] = null;
				}
			}

			$this->mode 			= $this->settings[ 'mode' ];
			$this->returnUrl 		= $this->settings[ 'returnUrl' ];
			$this->debugMode  		= $this->settings[ 'debugMode' ];
			$this->notify_url   	= add_query_arg( array(
														'woo-api' => 'callback_oppwa',
													), home_url( '/' ));
			$this->msg['message'] 	= '';
			$this->msg['class'] 	= '';

			if ( $this->debugMode == 'on' ) {
				$this->logs = new WC_Logger();
			}

			// Actions
			add_action( 'woocommerce_update_options_payment_gateways_' . $this->id, array( &$this, 'process_admin_options' ) );
			add_action( 'woocommerce_receipt_oppwa', array( &$this, 'receipt_page' ) );

			// print_r(apply_filters( 'active_plugins', get_option( 'active_plugins' ) ));
			// echo 'Gateway enabled: ' . $this->enabled;
			/*
			if ( !$this->is_gateway_active() ) {
				// echo "NOT active";
			} */
		}

		function is_gateway_active() {
			if ( 'yes' === $this->enabled ) {
				return true;
			} else {
				return false;
			}
		}

		function wc_custom_thank_you_page( $order_id ) {
			$order = wc_get_order( $order_id );

			$paymethod = $order->payment_method_title;
			$orderstat = $order->get_status();

			echo $orderstat . ' - ' . $paymethod;

			if ($order && !is_wp_error($order)) {
				$order_key = $order->get_order_key();
			}

			// http://localhost:8000/?page_id=8&order-received=67&key=wc_order_Uor4oqIvzMNXD

			if ( $order->get_billing_email() ) {
				// wp_redirect( '' );
				exit;
			}
		}

		public function __ajaxEndpointTest()
		{
			WC()->cart->empty_cart();

			if ( wp_verify_nonce( $_POST['_wpnonce'], 'wp_rest' ) ){
				echo json_encode(
					array(
						'youSent' => $_POST['foo'],
						'wcpay' => 'ok'
					)
				);
				exit;
			} else {
				echo 'nonce check failed';
				exit;
			}
		}

		public function createWcOrderFromCart()
		{
			$logs = new WC_Logger();

			$this->responseAfterSuccessfulResult();
			$googlePayRequestDataObject = $this->googlePayDataObjectHttp();
			$googlePayRequestDataObject->orderData($_POST, 'cart');

			// $logs->add( 'oppwa', __( 'OPPWA plugin: process_checkout - googlePayRequestDataObject ' . print_r($googlePayRequestDataObject, true), 'woocommerce') );

			$this->addAddressesToOrder($googlePayRequestDataObject, $_POST['payment_method_id']);

			// $logs->add( 'oppwa', __( 'OPPWA plugin: process_checkout', 'woocommerce') );


			WC()->checkout()->process_checkout();

		}

		public function checkPaymentStatus( $order_id, $posted_data ) {
			global $woocommerce;

			$logs = new WC_Logger();

			// $order_id = $order->get_id();

			if ($order_id < 1) {
				$logs->add( 'oppwa', __( 'OPPWA plugin: can not check payment status without order id.', 'woocommerce') );
				return;
			}

			// check payment status
			$gateways = WC()->payment_gateways->payment_gateways();
			try {
				// $logs->add( 'oppwa', __( 'OPPWA plugin: check payment status Order #' . $order_id, 'woocommerce') );

				$order = new WC_Order( $order_id );
				$order_data = $order->get_data();

				$oppwa = $gateways['oppwa'];
				$checkout = (object) [];
				$checkout->id = $order->get_transaction_id();

				// get payment status
				$transaction = paymentStatus($checkout);

				$logs->add( 'oppwa', __( 'OPPWA plugin: check response Order: ' . $order_id . ' | Checkout: ' . $checkout->id . ' - ' . print_r($transaction, true), 'woocommerce') );

				if (!isset($transaction->result)) {
					throw new Exception('Transaction not found: ' . $checkout->id);
				}

				$successStatusCodesRegEx = '/^(000.000.|000.100.1|000.[36]|000.400.[1][12]0)/';
				$pendingStatusCodesRegEx = '/^(000\.200)/';
				$reviewStatusCodesRegEx = '/^(000.400.0[^3]|000.400.100)/';
				preg_match($successStatusCodesRegEx, $transaction->result->code, $successMatches);
				preg_match($pendingStatusCodesRegEx, $transaction->result->code, $pendingMatches);
				preg_match($reviewStatusCodesRegEx, $transaction->result->code, $reviewMatches);

				// $testSuccessCode = '000.100.110'
				$successCode = (is_array($successMatches) && !empty($successMatches[1]));
				$pendingCode = (is_array($pendingMatches) && !empty($pendingMatches[1]));
				$reviewCode = (is_array($reviewMatches) && !empty($reviewMatches[1]));

				$isStatusClosed = (isset($transaction->paymentBrand) && ($successCode === true || $pendingCode === true || $reviewCode === true));

			    if ($isStatusClosed) {
				    $order->payment_complete();

					if ($successCode === true) {
						$order->add_order_note('Payment success.');
						$order->add_order_note(
							sprintf(
								"%s Payment completed and confirmed by the OPPWA <a href='https://docs.oppwa.com/#checkout/%s'>%s</a> (%s)",
								$oppwa->method_title . ' | ' . $transaction->paymentBrand,
								$transaction->id,
								$transaction->result->description,
								$transaction->result->code,
							)
						);
					} else {
						$order->add_order_note('Payment pending / review. ' . $transaction->id . ' - ' . $transaction->result->code . ' - ' . $transaction->result->description);
					}

					$order->add_order_note('Payment check done.');

					$order->save();

					$woocommerce->cart->empty_cart();

					// $redirect_url = $order->get_view_order_url();
					$redirect_url = '?page_id=8&order-received=' . $order->get_id() . '&key=' . $order->get_order_key(); // http://localhost:8000/?page_id=8&order-received=96&key=wc_order_woTBykndlfC4z

					// wp_redirect( $redirect_url );

					//*
					wp_send_json_success(array(
						'redirect' => $redirect_url
					));
					//*/

					exit;

			    } else {

			    	$order->add_order_note(
			            sprintf(
			                "%s Payment failed. Order-ID: %s <a href='https://docs.oppwa.com/#checkout/%s'>%s</a> (%s)",
			                $oppwa->method_title . ' | ' . $transaction->paymentBrand,
							$order_id,
			                $transaction->id,
			                $transaction->result->description,
							$transaction->result->code,
			            )
			        );

			        wc_add_notice(__( 'Transaction Error: Could not complete your payment - Order-ID: ' . $order_id . ' (Payment: ' . $transaction->id . ' | Code: ' . $transaction->result->code . ': ' . $transaction->result->description . ')', 'woocommerce'), 'error');
			        // wp_redirect( wc_get_checkout_url() );

					wp_send_json_success(array(
						'redirect' => wc_get_checkout_url()
					));

					exit;
			    }
			} catch ( Exception $e ) {

				$trxnresponsemessage = $e->getMessage();
				$order->add_order_note(
			            sprintf(
			                "%s Payment Failed with message: '%s'",
			                $oppwa->method_title,
			                $trxnresponsemessage
			            )
			        );

				wc_add_notice(__( 'Transaction Error: Could not complete your payment' , 'woocommerce'), 'error');
		  		// wp_redirect( wc_get_checkout_url() );
				wp_send_json_success(array(
					'redirect' => wc_get_checkout_url()
				));
				exit;
			}
		}

		/**
		 * Data Object to collect and validate all needed data collected
		 * through HTTP
		 */
		protected function googlePayDataObjectHttp(): GooglePayDataObjectHttp
		{
			return new GooglePayDataObjectHttp($this->logger);
		}

		protected function updateShippingMethod(array $data)
		{
			if (
				array_key_exists(
					PropertiesDictionary::SHIPPING_METHOD,
					$data
				)
			) {
				$this->shippingMethod = filter_var_array(
					$data[PropertiesDictionary::SHIPPING_METHOD],
					FILTER_SANITIZE_SPECIAL_CHARS
				);
			}
		}

	    /**
		 * @var array|mixed
		 */
		protected $shippingMethod = [];

		protected $billingAddress = [];
		/**
		 * @var string[]
		 */
		protected $shippingAddress = [];


		/**
		 * Add address billing and shipping data to order
		 *
		 * @param GooglePayDataObjectHttp $googlePayRequestDataObject
		 * @param                        $order
		 *
		 */

		protected function addAddressesToOrder(
			GooglePayDataObjectHttp $googlePayRequestDataObject,
			$transaction_id = false
		) {

			$logs = new WC_Logger();

			// $logs->add( 'oppwa', __( 'OPPWA plugin: addAddressesToOrder for order with TX ' . $transaction_id, 'woocommerce') );

			$thisPlugin = $this;

			add_action(
				'woocommerce_checkout_create_order',
				static function ($order, $data) use ($googlePayRequestDataObject, $transaction_id, $thisPlugin ) {
						$logs = new WC_Logger();

					// $logs->add( 'oppwa', __( 'OPPWA plugin: addAddressesToOrder shippingMethod: ' . print_r($googlePayRequestDataObject->shippingMethod, true), 'woocommerce') );

					// if ($googlePayRequestDataObject->shippingMethod !== null) {

						$billingAddress = $googlePayRequestDataObject->billingAddress;
						$shippingAddress = $googlePayRequestDataObject->shippingAddress;
						//google puts email in shippingAddress while we get it from WC's billingAddress
						// $billingAddress['email'] = $shippingAddress['email'] = $shippingAddress['emailAddress'];
						// $billingAddress['phone'] = $shippingAddress['phone'] = $shippingAddress['phoneNumber'];

						// $logs->add( 'oppwa', __( 'OPPWA plugin: addAddressesToOrder billingAddress ' . print_r($billingAddress, true), 'woocommerce') );
						// $logs->add( 'oppwa', __( 'OPPWA plugin: addAddressesToOrder shippingAddress ' . print_r($shippingAddress, true), 'woocommerce') );

						$order->set_address($billingAddress, 'billing');
						$order->set_address($shippingAddress, 'shipping');
					// }

					// set oppwa transaction id
					$order->set_transaction_id($transaction_id);


					$order->add_order_note(
						sprintf(
							"Update direct order with addresses and Payment ID: '%s'",
							$transaction_id
						)
					);


					// create new user account or assign order to existing user if not logged in
					$order_id = $order->get_id();
					$email = $billingAddress['email'];
					$default_password = wp_generate_password();
					$user = get_user_by('email', $email); // or 'login'

					if (!$user) {

						// use: $shippingAddress
						$args = array();
						$args['first_name'] = $_POST['shippingContact']['givenName'];
						$args['last_name'] = $_POST['shippingContact']['familyName'];
						$user_id = wc_create_new_customer( $email, wc_create_new_customer_username( $email, $args ), $default_password );

						/*foreach ( $billingAddress as $key => $value ) {
							update_user_meta( $user_id, $key, $value );
						}

						foreach ( $shippingAddress as $key => $value ) {
							update_user_meta( $user_id, $key, $value );
						}*/

						// TODO do not use $_POST
						$_POST['first_name'] = $_POST['shippingContact']['givenName'];
						$_POST['last_name'] = $_POST['shippingContact']['familyName'];
						foreach ( $_POST as $key => $value ) {
							update_user_meta( $user_id, $key, $value );
						}

						update_user_meta( $user_id, 'auto_created_at_checkout',  $order_id);

						$order->add_order_note(
							sprintf(
								"1 Created new user with ID: %s",
								$user_id
							)
						);

						$user = get_user_by('id', $user_id);
					}

					// Getting the postmeta customer ID for 'order' post-type
					$customer_id = $order->get_customer_id();

					if ( /*! is_user_logged_in() &&*/ (empty($customer_id) || $customer_id === 0) && isset($user->ID) ) {
						$order->set_customer_id($user->ID);
					}
				},
				10,
				2
			);


			add_action( 'woocommerce_checkout_order_processed', [ $this, 'add_order_meta' ], 10, 2 );

			add_action( 'woocommerce_checkout_order_processed', [ $this, 'checkPaymentStatus' ], 10, 2 );
		}


		function redirect_thank_you() {

			// do nothing if we are not on the order received page
			if ( ! is_wc_endpoint_url( 'order-received' ) || empty( $_GET[ 'key' ] ) ) {
				// return;
			}

			if ( /*is_wc_endpoint_url( 'order-received' )*/ isset( $_GET[ 'order-received' ] ) && !empty( $_GET[ 'resourcePath' ] ) ) {
				// wp_safe_redirect( site_url( 'thank-you' ) );
				wp_redirect('thank-you');
        		exit;
			}

		}

		public function add_order_meta( $order_id, $posted_data = false ) {
			if ( empty( $_POST['payment_request_type'] ) || ! isset( $_POST['payment_method'] ) || 'oppwa' !== $_POST['payment_method'] ) {
				return;
			}

			$order = wc_get_order( $order_id );

			$payment_request_type = wc_clean( wp_unslash( $_POST['payment_request_type'] ) );

			if ( 'apple_pay' === $payment_request_type ) {
				$order->set_payment_method_title( 'Apple Pay (OPPWA)' );
				// $order->save();
			} elseif ( 'google_pay' === $payment_request_type ) {
				$order->set_payment_method_title( 'Google Pay (OPPWA)' );
				// $order->save();
			} elseif ( 'payment_request_api' === $payment_request_type ) {
				$order->set_payment_method_title( 'Payment Request (OPPWA)' );
				// $order->save();
			}

			$order->save();
		}

		protected function responseAfterSuccessfulResult(): void
		{
			add_filter(
				'woocommerce_payment_successful_result',
				function ($result, $order_id) {
					if (
						isset($result['result'])
						&& 'success' === $result['result']
					) {
						/*$this->responseTemplates->responseSuccess(
							$this->responseTemplates->authorizationResultResponse(
								'STATUS_SUCCESS',
								$order_id
							)
						);*/
					} else {
						/* translators: Placeholder 1: Payment method title */
						$message = sprintf(
							__(
								'Could not create %s payment.',
								'bankpay-payments-for-woocommerce'
							),
							'GooglePay'
						);

						// $this->notice->addNotice($message, 'error');

						/*wp_send_json_error(
							$this->responseTemplates->authorizationResultResponse(
								'STATUS_FAILURE',
								0,
								[['errorCode' => 'unknown']]
							)
						);*/
					}
					return $result;
				},
				10,
				2
			);
		}

		/**
		 * Initialise Gateway Settings Form Fields.
		 */
		public function init_form_fields() {

			$this->form_fields = array(
				'enabled'         => array(
					'title'   => __( 'Enable/Disable', 'woocommerce' ),
					'type'    => 'checkbox',
					'label'   => __( 'Enable OPPWA', 'woocommerce' ),
					'default' => 'no',
				),
				'merchantName' => array(
					'title' 		=> __( 'Company', 'woocommerce' ),
					'type' 			=> 'text',
					'description' 	=> __( 'Company name will be used at the checkout.', 'woocommerce' ),
					'required' 		=> true,
					'desc_tip'      => true,
				),
				'title'           => array(
					'title'       => __( 'Title', 'woocommerce' ),
					'type'        => 'text',
					'description' => __( 'This controls the title which the user sees during checkout.', 'woocommerce' ),
					'default'     => __( 'Apple Pay™ | Google Pay™', 'woocommerce' ),
					'desc_tip'    => true,
				),
				'description'     => array(
					'title'       => __( 'Description', 'woocommerce' ),
					'type'        => 'textarea',
					'description' => __( 'Payment method description that the customer will see on your checkout.', 'woocommerce' ),
					'default'     => __( 'Make your payment directly and securely with Apple | Google Pay or Debit | Credit cards.', 'woocommerce' ),
					'desc_tip'    => true,
				),
				'instructions'    => array(
					'title'       => __( 'Instructions', 'woocommerce' ),
					'type'        => 'textarea',
					'description' => __( 'Information that will be added to the thank you page and emails.', 'woocommerce' ),
					'default'     => '',
					'desc_tip'    => true,
				),
				'debugMode' => array(
					'title' 		=> __( 'Debug Mode', 'woocommerce' ),
					'type' 			=> 'select',
					'description' 	=> '',
					'options'     	=> array(
						'off' 		=> __( 'Off', 'woocommerce' ),
						'on' 		=> __( 'On', 'woocommerce' )
					)
				)
			);
		}

		/**
		 * Admin Options.
		 */
		public function admin_options()
		{
			if ($this->mode == 'p' && get_option('woocommerce_force_ssl_checkout') == 'no' && $this->enabled == 'yes') {
				// echo '<div class="error"><p>'.sprintf(__('%s Sandbox testing is disabled and can performe live transactions but the <a href="%s">force SSL option</a> is disabled; your checkout is not secure! Please enable SSL and ensure your server has a valid SSL certificate.', 'woothemes'), $this->method_title, admin_url('admin.php?page=wc-settings&tab=checkout')).'</p></div>';
			}

			// TODO
			$currencies = array("EUR");

			if (!in_array(get_option('woocommerce_currency'), $currencies )) {
				// echo '<div class="error"><p>'.__('OPPWA currently only supports EUR.', 'woocommerce').'</p></div>';
			}

			echo '<h3>'.__(	'OPPWA Payment Gateway', 'woocommerce' ).'</h3>';

			echo '<table class="form-table">';
			$this->generate_settings_html();
			echo '</table>';
		}

		/**
		 * Output for the order receipt page.
		 *
		 * @param int $order_id Order ID.
		 */
		public function receipt_page( $order_id ) {

			/*echo '<p>'.__(
				sprintf(
						"Apple Pay™ | Google Pay™",
						$this->method_title
						), 'woocommerce' ).'</p>';*/

			echo $this->set_payment_form( $order_id );

			if ( $this->mode == 's' ) {
				echo '<p>';
				echo wpautop( wptexturize(  __('TEST MODE/SANDBOX ENABLED', 'woocommerce') )). ' ';
				echo '<p>';
			}
		}

		public function addProductToChart() {
			// TODO direct checkout from product page
		}

		public function set_payment_buttons() {
			global $oppwa;

			// return false;

			if ( !$this->is_gateway_active() ) {
				return false;
			}

			if (!is_checkout() || is_cart() || is_shop() || is_woocommerce()) {
				?>
				<div id="oppwa-payment-button">
					<?php wp_nonce_field('woocommerce-process_checkout', 'woocommerce-process-checkout-nonce');  ?>
				</div>
				<?php
			}

			if ( WC()->cart->is_empty() ) {
				return;
			}

			WC()->cart->calculate_totals();

			if ( ! WC()->cart->prices_include_tax ) {
				$order_total = WC()->cart->cart_contents_total;
			} else {
				$order_total = WC()->cart->cart_contents_total + WC()->cart->tax_total;
			}

			$checkout = paymentInitCheckout((object) ['amount' => $order_total]);
			$checkoutId = $checkout->id;
			$checkoutMsg = $checkout->result->description . ' - ' . $checkout->id;


			$html = '<script>';

			$html .= '
				var wpwlOptions = {
					style: "logos",
					imageStyle: "svg",

					inlineFlow: ["APPLEPAY", "GOOGLEPAY"],

					brandDetection: true,
					brandDetectionType: "binlist",
					brandDetectionPriority: ["APPLEPAY", "GOOGLEPAY", "MASTER", "VISA"],

					applePay: {
						displayName: "' . $oppwa->apay->displayName . '",
						total: {
							label: "' . $oppwa->apay->displayName . '"
						}
					},

					googlePay: {
						// Channel Entity ID
						gatewayMerchantId: "' . $oppwa->gpay->gatewayMerchantId . '",
						"merchantInfo": {
							"merchantName": "' . $oppwa->apay->displayName . '"
						},
						emailRequired: true,
						shippingAddressRequired: true,
						shippingAddressParameters : {
							phoneNumberRequired: true,
	 					},
						billingAddressRequired: true,
						billingAddressParameters : { "format": "FULL", phoneNumberRequired : true },
						onPaymentDataChanged : function onPaymentDataChanged(intermediatePaymentData) {
							// console.log("onPaymentDataChanged", intermediatePaymentData);
							return new Promise(function(resolve, reject) {
								resolve({});
							});
						},
						submitOnPaymentAuthorized: [ "customer", "billing" ],
						// The merchant onPaymentAuthorized implementation
						onPaymentAuthorized: function onPaymentAuthorized(paymentData) {
							console.log("onPaymentAuthorized", paymentData);

							const ajaxUrl = "' . admin_url('admin-ajax.php') . '";

							const nonce = document.getElementById("woocommerce-process-checkout-nonce").value;
							let shippingContact = paymentData.shippingAddress;

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


							//*
							let selectedShippingMethod = jQuery(\'#shipping_method li\').first().find(\'input[type="hidden"]\').val();

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
								  payment_method_id: "' . $checkoutId . '",
								  _wp_http_referer: "/?wc-ajax=update_order_review",
								},
								complete: (jqXHR, textStatus) => {},
								success: (authorizationResult, textStatus, jqXHR) => {
									console.log("checkout ok", authorizationResult);
								  let result = authorizationResult.data;
								  // if (authorizationResult.result === "success") {
								  if (authorizationResult.success === true) {
									// session.completePayment(result["responseToApple"]);
									window.location.href = result.redirect;
								  } else {
									// result.errors = createAppleErrors(result.errors);
									console.log(result);
									// session.completePayment(result);
								  }
								},
								error: (jqXHR, textStatus, errorThrown) => {
								  console.warn(textStatus, errorThrown);
								  // session.abort();
								},
							  });
							  // */

							return new Promise(function(resolve, reject) {
								resolve(
									{ transactionState: "SUCCESS" }
								);
							});
						}
					},

					onReady: function() {
						console.log(
							"OPPWA WooCommerce Plugin: ready for payment"
						);

						ready = true;

						// jQuery(".wpwl-group-brand").hide();
						jQuery(".wpwl-group-expiry").hide();
						jQuery(".wpwl-group-cardHolder").hide();
						jQuery(".wpwl-group-cvv").hide();
						jQuery(".wpwl-group-submit").hide();

					},

					onChangeBrand: function() {
						console.log(
							"OPPWA WooCommerce Plugin: payment brand changed"
						);
					}
				};

				var ready = false;

			</script>

			<script src="' . $oppwa->apiEndpoint . '/paymentWidgets.js?checkoutId=' . $checkoutId .'"></script>';

			/*$html .= '<div id="oppwa-payment" data-oppwa-id="' . $checkoutId .'">
				<div id="oppwa-payment-cards" data-brands="' . $oppwa->brands . '">
					<form action="' . $oppwa->checkoutResultUrl . '" class="paymentWidgets" data-brands="' . $oppwa->brands . '"></form>
				</div>
				<!-- p id="oppwa-payment-message">' . $checkoutMsg .'</p -->
			</div>';*/

			// echo $html;
		}

		/**
		 * Output for the order form.
		 *
		 * @param int $order_id Order ID.
		 */
		public function set_payment_form( $order_id = false) {

			global $oppwa;

			if ( !$this->is_gateway_active() ) {
				return false;
			}

			$order_total = $this->get_order_total();
			// $order_id = $this->get_order_id();

			if (!$order_id) {
				$order_id = absint( get_query_var( 'order-pay' ) );
			}


			if (!empty($order_id)) {
				$order = new WC_Order( $order_id );
				// $host = @parse_url(strtolower(get_bloginfo('atom_url')), PHP_URL_HOST);

				$data = $order->get_data();
				$order_id = $data['id'];
			}

			if (empty($order_id)) {
				// return '<div class="error"><p>'.sprintf(__('OPPWA Checkout Fehler - Keine Bestellung gefunden', 'woothemes')).'</p></div>';
				$reference = '';
			} else {
				$reference = "Order #$order_id-" . $data['date_created']->date('Ymd');
			}

			if (!$pay || !isset($pay->amount)) {
				$pay = (object) [
					"amount" => "0",
					"currency" => "EUR"
				];
			}

			if (empty($order_total)) {
				WC()->cart->calculate_totals();

				if ( ! WC()->cart->prices_include_tax ) {
					$order_total = WC()->cart->cart_contents_total;
				} else {
					$order_total = WC()->cart->cart_contents_total + WC()->cart->tax_total;
				}
			}

			// echo 'total: ' . $order_total;

			if ($order_total >= 0) {
				$pay->amount = $order_total;
				$order_id = 0;
			}

			if (empty($data['payment_method'])) {
				// $data['payment_method'] = 'oppwa';
			}

			if ($data['payment_method'] == 'oppwa'
				&& empty($order->get_transaction_id() /*$data['oppwa_transaction_id']*/)) {

					// echo 'new TX';

					if (empty($pay->currency)) {
						$pay->currency = 'EUR';
					}

					$url = $oppwa->apiEndpoint . "/checkouts";


					if ($this->logs) $this->logs->add( 'oppwa', __( 'OPPWA plugin: init TX: ' . print_r($data, true), 'woocommerce') );
					// echo '<div class="error"><p>'.sprintf(__('OPPWA Update Fehler - set payment form - <a href="%s">Error-Log anzeigen</a>', 'woothemes'), admin_url('admin.php?page=wc-status&tab=logs')).'</p></div>';

					if (!empty($data['total'])) {
						$pay->amount = $data['total'];
					}

					$post_data = array(
						'entityId' => $oppwa->clientId,
						'amount' => $pay->amount,
						'currency' => $pay->currency,
						'paymentType' => 'DB', // PA
					);

					$authorization = array('Authorization' => 'Bearer ' . $oppwa->authToken);

					$args = array(
						'body'        => $post_data,
						'timeout'     => '5',
						'redirection' => '5',
						'httpversion' => '1.0',
						'blocking'    => true,
						'headers'     => $authorization,
						'cookies'     => array(),
					);

					$response = wp_remote_post( $url, $args );

					// print_r($response);

					if (is_wp_error($response)) {
						if ($this->logs) $this->logs->add( 'oppwa', __( 'OPPWA plugin: checkout error: ' . print_r($response->get_error_messages(), true), 'woocommerce') );
						return '<div class="error"><p>'.sprintf(__('OPPWA Checkout Fehler - <a href="%s">Error-Log anzeigen</a>', 'woothemes'), admin_url('admin.php?page=wc-status&tab=logs')).'</p></div>';
					} else {
						$checkout = (object) json_decode($response['body']);
					}

					if (!empty($checkout->id) && $order_id > 0) {
						add_post_meta($order_id, '_oppwa_transaction_id', $checkout->uuid);
						add_post_meta($order_id, '_oppwa_checkout_id', $checkout->uuid);

						$checkoutId = $checkout->id;
					} else if (empty($checkout->id)) {
						// print_r($checkout, true);
						if ($this->logs) $this->logs->add( 'oppwa', __( 'OPPWA plugin: checkout error: ' . print_r($checkout, true), 'woocommerce') );
					}

					$checkoutId = $checkout->id;
					$checkoutMsg = $checkout->result->description;
			} else if ($data['payment_method'] == 'oppwa'
				&& !empty($data['oppwa_transaction_id'])) {
				$checkoutId = $data['oppwa_transaction_id'];
				$checkoutMsg = 'TX loaded';
			} else {
				// create transaction
				$checkout = paymentInitCheckout((object) ['amount' => $order_total]);
				$checkoutId = $checkout->id;
				$checkoutMsg = $checkout->result->description;
			}

			$checkoutMsg .= ' <br /> # ' . $checkoutId;

			$html = '<script>';

			$html .= '
				var wpwlOptions = {
					style: "logos",
					imageStyle: "svg",

					brandDetection: true,
					brandDetectionType: "binlist",
					brandDetectionPriority: ["APPLEPAY", "GOOGLEPAY", "MASTER", "VISA"],

					applePay: {
						displayName: "' . $oppwa->apay->displayName . '",
						total: {
							label: "' . $oppwa->apay->displayName . '"
						}
					},

					googlePay: {
						// Channel Entity ID
						gatewayMerchantId: "' . $oppwa->gpay->gatewayMerchantId . '",
					},

					onReady: function() {
						console.log(
							"OPPWA WooCommerce Plugin: ready for payment"
						);

						ready = true;

						// jQuery(".wpwl-group-brand").hide();
						jQuery(".wpwl-group-expiry").hide();
						jQuery(".wpwl-group-cardHolder").hide();
						jQuery(".wpwl-group-cvv").hide();
						jQuery(".wpwl-group-submit").hide();

						showBrands();
					},

					onChangeBrand: function() {
						console.log(
							"OPPWA WooCommerce Plugin: payment brand changed"
						);

						hideBrands();
					}
				};

				var ready = false;

				function showBrands() {
					// jQuery(".wpwl-group-brand").show();
					// jQuery(".wpwl-group-card-logos-horizontal > div:is(.wpwl-brand-card)").addClass("wpwl-hidden")

					window.setTimeout(function() {
						// show visa and mastercard
						/*
						jQuery(".wpwl-group-card-logos-horizontal > div.wpwl-brand-card-logo-highlighted").removeClass("wpwl-brand-card-logo-highlighted");
						jQuery(".wpwl-group-card-logos-horizontal > div[value=\'VISA\']").removeClass("wpwl-hidden").show();
						jQuery(".wpwl-group-card-logos-horizontal > div[value=\'MASTER\']").removeClass("wpwl-hidden").show();
						// */
					}, 1991);
				}

				function hideBrands() {
					if (!ready /*|| dotsClicked*/ ) {
						return;
					}

					/*
					jQuery(".wpwl-group-card-logos-horizontal > div[value=\'VISADEBIT\']").addClass("wpwl-hidden").hide();
					jQuery(".wpwl-group-card-logos-horizontal > div[value=\'MASTERDEBIT\']").addClass("wpwl-hidden").hide();

					jQuery(".wpwl-group-brand").show();

					jQuery(".wpwl-group-expiry").show();
					jQuery(".wpwl-group-cardHolder").show();
					jQuery(".wpwl-group-cvv").show();
					jQuery(".wpwl-group-submit").show();

					// Clears all previous dots-hidden logos, if any
					// jQuery(".wpwl-group-card-logos-horizontal > div").removeClass("dots-hidden");

					// Selects all non-hidden logos. They are detected brands which otherwise would be shown by default.
					// var $logos = jQuery(".wpwl-group-card-logos-horizontal > div:is(.wpwl-brand-card)");
					/*var $logos = jQuery(".wpwl-group-card-logos-horizontal > div:not(.wpwl-hidden)");
					var $isSelected = jQuery(".wpwl-group-card-logos-horizontal > div:is(.wpwl-brand-card-logo-highlighted)");

					if ($isSelected.length < 1) {
					}

					if ($($logos).size() < 2) {
						return;
					}

					jQuery(".wpwl-group-card-logos-horizontal").css("width", "100%");

					// Hides all except the first logo, and displays three dots (...)
					$logos.first().after($("<div>...</div>").addClass("dots"));
					$logos.filter(function(index) { return index > 0; }).addClass("dots-hidden");

					// If ... is clicked, un-hides the logos
					$(".dots").click(function() {
						dotsClicked = true;
						$(".dots-hidden").removeClass("dots-hidden").show();
						$(this).remove();
					});
					*/
				}
			</script>

			<script src="' . $oppwa->apiEndpoint . '/paymentWidgets.js?checkoutId=' . $checkoutId .'"></script>';

			$html .= '<style>
				ul.order_details {
					max-width: 50%;
					min-width: 40%;
					float: left;
				}

				ul.order_details li.date, ul.order_details li.method {
					display: none;
				}

				#oppwa-payment {
					margin: 0.5em auto;
					padding: 0.5em;

					max-width: 50%;
					min-width: 50%;

					__min-height: 222px;
					__max-height: 999px;
					__min-width: 240px;
					__max-width: 540px;

					font-family: Arial, Helvetica, sans-serif;
					font-size: 1.1em;

					float: right;

					text-align: center;
				}

				#oppwa-payment-message {
					text-align: center;
					font-size: 0.5em;
					padding-top: 1.5em;
				}

				.wpwl-button-pay {
					width: 100%;
				}

				.wpwl-message {
					font-size: 0.8em;
					line-height: 1.5em;
				}

				.wpwl-message span {
					font-size: 0.9em;
					font-weight: bold;
					padding: 1.5em;
					display: block;
				}

				.wpwl-group-button div {
					margin: 0.1em auto;
					width: 240px;
				}

				/*
				.dots { margin-left: 4px; }
				.dots-hidden { display: none; }
				*/
			</style>';

			/*$html .= '<div id="oppwa-payment">
				<div id="oppwa-payment-cards">
					<form action="' . $oppwa->checkoutResultUrl . '&order=' . $order_id . '" class="paymentWidgets" data-brands="' . $oppwa->brands . '"></form>
				</div>
				<!-- p id="oppwa-payment-message">' . $checkoutMsg .'</p -->
			</div>';*/

			$html .= '<script>console.log("WC OPPWA Checkout", "' . $order_id . ' | ' . $checkoutId . ' | ' . $reference . '");</script>';

			// return $html;
		}

		public function getPages( $title = false, $indent = true ) {
			$wp_pages = get_pages( 'sort_column=menu_order' );
			$page_list = array();
			if ( $title ) $page_list[] = $title;
			foreach ( $wp_pages as $page ) {
				$prefix = '';
				if ( $indent ) {
					$has_parent = $page->post_parent;
					while( $has_parent ) {
						$prefix .=  ' - ';
						$next_page = get_page( $has_parent );
						$has_parent = $next_page->post_parent;
					}
				}
				$page_list[$page->ID] = $prefix . $page->post_title;
			}
			return $page_list;
		}

		public function payment_fields( ) {
			if ( !empty($this->description) ) {
				echo wpautop( wptexturize( $this->description ) );
			}

			/*$order_total = $this->get_order_total();
			$order_id = $this->get_order_id();
			$currency = $this->getCurrencyFromOrder();
			$billingCountry = $this->getBillingCountry();
			$paymentLocale = $this->dataService->getPaymentLocale();*/

			echo "<script>
					var paymentMethod = getQueryVariable('payment-method');
					if (document.getElementById('payment_method_' + paymentMethod)) {
						document.getElementById('payment_method_' + paymentMethod).checked = true
						document.getElementById('payment_method_' + paymentMethod).click()
					}

					// console.log('WC Payment Method', paymentMethod);

					function getQueryVariable(variable) {
						var query = window.location.search.substring(1);
						var vars = query.split('&');
						for (var i=0;i<vars.length;i++) {
							var pair = vars[i].split('=');
							if (pair[0] == variable) {
							return pair[1];
							}
						}
					}
				</script>";
		}

		// TODO
		public function showMessage( $content ) {
			$html  = 'OPPWA Plugin Message:';
			$html .= '<div class="box '.$this->msg['class'].'-box">';
			$html .= $this->msg['message'];
			$html .= '</div>';
			$html .= $content;

			return $html;
		}

		/**
		 * Process the payment and return the result.
		 *
		 * @param int $order_id Order ID.
		 * @return array
		 */
		public function process_payment( $order_id ) {

			global $wp_rewrite;

			$order = wc_get_order( $order_id );

			if ( $order->get_total() > 0 ) {
				// Mark as pending (we're awaiting the payment).
				$order->update_status( apply_filters( 'woocommerce_oppwa_process_payment_order_status', 'pending', $order ), __( 'Awaiting OPPWA payment', 'woocommerce' ) );
			} else {
				$order->payment_complete();
				$order->add_order_note( __('OPPWA: no payment required for 0,- (free order).', 'woothemes') );
			}

			// payment failed
			//$order->update_status( apply_filters( 'woocommerce_oppwa_process_payment_order_status', 'failed', $order ), __( 'OPPWA payment failed', 'woocommerce' ) );

			if ( $wp_rewrite->permalink_structure == '' ) {
				$checkout_url = wc_get_checkout_url() . '&order-pay= ' . $order_id . '&key= ' . $order->order_key;
			} else {
				$checkout_url = wc_get_checkout_url() . '/order-pay/' . $order_id . '?key= ' . $order->order_key;
			}

			return array(
					'result' => 'success',
					'redirect' => $checkout_url
			);
		}

	}

	/**
	 * Add OPPWA Payment Method.
	 *
	 * @param array $methods Payment methods.
	 * @return array
	 */
	function woocommerce_add_api_oppwa( $methods ) {
		$methods[] = 'WC_Gateway_oppwa';
		return $methods;
	}

	add_filter( 'woocommerce_payment_gateways', 'woocommerce_add_api_oppwa' );


	/**
	 * Add OPPWA links.
	 *
	 * @param array $links Plugin links.
	 * @return array
	 */
	function oppwa_action_links( $links ) {
		return array_merge( array(
			'<a href="' . esc_url( 'https://docs.oppwa.com' ) . '">' . __( 'OPPWA Docs', 'woocommerce' ) . '</a>',
			'<a href="' . esc_url( get_admin_url() . 'admin.php?page=wc-settings&tab=checkout&section=oppwa' ) . '">' . __( 'Settings', 'woocommerce' ) . '</a>'
		), $links );
	}

	add_filter( 'plugin_action_links_' . plugin_basename( __FILE__ ), 'oppwa_action_links' );
}

add_action( 'plugins_loaded', 'woocommerce_api_oppwa_init', 0 );


// if ( $this->is_gateway_active() ) {
	// xxxx();

	add_action(
		'wp_ajax_' . 'bankpay_google_pay_create_order_cart',
		function() {
			$oppwaApi = new WC_Gateway_oppwa();
			$oppwaApi->createWcOrderFromCart();
		}
	);

	add_action(
		'wp_ajax_nopriv_' . 'bankpay_google_pay_create_order_cart',
		function() {
			$oppwaApi = new WC_Gateway_oppwa();
			$oppwaApi->createWcOrderFromCart();
		}
	);

	$renderPlaceholder = apply_filters('bankpay_wc_gateway_googlepay_render_hook_cart', 'woocommerce_cart_totals_after_order_total');
	$renderPlaceholder = is_string($renderPlaceholder) ? $renderPlaceholder : 'woocommerce_cart_totals_before_order_total'; // 'woocommerce_cart_totals_after_order_total';

	add_action(
		'woocommerce_cart_totals_after_order_total', // $renderPlaceholder,
		function() {
			$oppwaApi = new WC_Gateway_oppwa();
			// $oppwaApi->set_payment_buttons();
		}
	);

	add_action(
		'woocommerce_before_checkout_form', // $renderPlaceholder,
		function() {
			$oppwaApi = new WC_Gateway_oppwa();
			// $oppwaApi->set_payment_buttons();
		}
	);

	add_action(
		'woocommerce_after_add_to_cart_form', // $renderPlaceholder,
		'directCheckoutCart'
	);

	add_action(
		'woocommerce_before_mini_cart', // $renderPlaceholder,
		'directCheckoutCart'
	);
// }

/** direct checkout **/
function directCheckoutCart() {
	$oppwaApi = new WC_Gateway_oppwa();

	if (is_product()) {
		$product = wc_get_product(get_the_id());
		if (!$product) {
			return;
		}

		if ($product->is_type('subscription') && !is_user_logged_in()) {
			return;
		}

		$product = wc_get_product(get_the_id());
		if (!$product) {
			return [];
		}
		$isVariation = false;
		if ($product->get_type() === 'variable' || $product->get_type() === 'variable-subscription') {
			$isVariation = true;
		}
		$productNeedShipping = false; //$this->checkIfNeedShipping($product);
		$productId = get_the_id();
		$productPrice = $product->get_price();
		$productStock = $product->get_stock_status();

		$encode = [
			'product' => [
				'needShipping' => $productNeedShipping,
				'id' => $productId,
				'price' => $productPrice,
				'isVariation' => $isVariation,
				'stock' => $productStock,
			],
			/*'shop' => [
				'countryCode' => $shopCountryCode,
				'currencyCode' => $currencyCode,
				'totalLabel' => $totalLabel,
			],*/
			'ajaxUrl' => admin_url('admin-ajax.php'),
		];

		echo '<script>
			let checkoutDetails = ' . json_encode($encode) . ';

			checkoutDetails.product.productQuantity = document.querySelector("input.qty").value;

			document.querySelector("input.qty").addEventListener("change", event => {
				checkoutDetails.product.productQuantity = event.currentTarget.value
			})

			const amountWithoutTax = checkoutDetails.productQuantity * checkoutDetails.price;


		</script>';
	}

	$oppwaApi->addProductToChart();

	// $oppwaApi->set_payment_buttons();
}


add_action('wp_enqueue_scripts', 'oppwa_enqueue');
function oppwa_enqueue($hook) {

	/*if ( !$this->is_gateway_active() ) {
		return false;
	}*/

	wp_enqueue_script( 'ajax-script',
		plugins_url( '/js/oppwa.js', __FILE__ ),
		array('jquery'),
		false,
		true
	);

	/* wp_enqueue_script( 'ajax-script',
		plugins_url( '/js/oppwaCheckoutCart.js', __FILE__ ),
		array('jquery'),
		false,
		true
	); */

	wp_enqueue_style(
		'oppwa_sytlesheet',
		plugins_url( '/css/oppwa.css', __FILE__ ),
	);

	$rest_nonce = wp_create_nonce( 'wp_rest' );
	wp_localize_script( 'ajax-script', 'my_var', array(
		'ajaxurl' => admin_url( 'admin-ajax.php' ),
		'nonce' => $rest_nonce,
	));
}

function dataForProductPage(
	$shopCountryCode,
	$currencyCode,
	$totalLabel
) {

	/*if ( !$this->is_gateway_active() ) {
		return false;
	}*/

	$product = wc_get_product(get_the_id());
	if (!$product) {
		return [];
	}
	$isVariation = false;
	if ($product->get_type() === 'variable' || $product->get_type() === 'variable-subscription') {
		$isVariation = true;
	}
	$productNeedShipping = false; //$this->checkIfNeedShipping($product);
	$productId = get_the_id();
	$productPrice = $product->get_price();
	$productStock = $product->get_stock_status();

	return [
		'product' => [
			'needShipping' => $productNeedShipping,
			'id' => $productId,
			'price' => $productPrice,
			'isVariation' => $isVariation,
			'stock' => $productStock,
		],
		'shop' => [
			'countryCode' => $shopCountryCode,
			'currencyCode' => $currencyCode,
			'totalLabel' => $totalLabel,
		],
		'ajaxUrl' => admin_url('admin-ajax.php'),
	];
}
