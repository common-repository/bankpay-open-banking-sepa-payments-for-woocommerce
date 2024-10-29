<?php
/*
 * Plugin Name: BANKpay+ Open Banking SEPA Payments | Apple Pay™ | Google Pay™ for WooCommerce
 * Plugin URI: https://BANKpay.plus
 * Description: Accept SEPA Open Banking Payments | Apple Pay™ | Google Pay™ on your store using the BANKpay+ Payment Gateway for WooCommerce
 * Author: K42 Ventures OÜ
 * Author URI: https://K42.ventures
 * Version: 1.1.24
 * Requires at least: 5.7
 * Tested up to: 6.2
 * WC requires at least: 2.1
 * WC tested up to: 7.5
 */

declare(strict_types=1);

// namespace BANKpay\WooCommerce\Gateway;

# use Psr\Log\LoggerInterface as Logger;
# use Psr\Log\LogLevel;

# use UnexpectedValueException;
# use WP_Error;

use WC_Order as WC_Order;
use WC_Payment_Gateway as WC_Payment_Gateway;

/**
 * Class WC_Gateway_BANKpay file.
 */
if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

include plugin_dir_path(__FILE__) . 'callback.php';

function woocommerce_api_bankpay_init() {

	if (!class_exists('WC_Payment_Gateway')) return;

	/**
	 * BANKpay+ Payment Gateway.
	 *
	 * Provides a BANKpay+ Payment Gateway. Based on code by Mike Pepper.
	 *
	 * @class       WC_Gateway_BANKpay
	 * @extends     WC_Payment_Gateway
	 * @version     1.1.24
	 */
	class WC_Gateway_BANKpay extends WC_Payment_Gateway {

		/**
		 * Array of locales
		 *
		 * @var array
		 */
		public $locale;

		/**
		 * Constructor for the gateway.
		 */
		public function __construct() {

			$this->id                 = 'bankpay';
			$this->icon               = apply_filters( 'woocommerce_bankpay_icon', '' );
			$this->has_fields         = false;
			$this->method_title       = __( 'BANKpay+', 'woocommerce' );
			$this->method_description = __( 'Accept payments via BANKpay+ SEPA Open Banking Payments.', 'woocommerce' );

			// Load the settings.
			$this->init_form_fields();
			$this->init_settings();

			// Define user set variables.
			$this->title        = $this->get_option( 'title' );
			$this->description  = $this->get_option( 'description' );
			$this->instructions = $this->get_option( 'instructions' );

			$settings = array('mode', 'clientId', 'privateKey', 'returnUrl', 'debugMode');

			foreach ($settings as $key) {
				if (!isset($this->settings[ $key ]) || empty($this->settings[ $key ])) {
					$this->settings[ $key ] = null;
				}
			}

			$this->mode 			= isset($this->settings[ 'mode' ]) ? $this->settings[ 'mode' ] : false;
			$this->clientId 		= isset($this->settings[ 'clientId' ]) ? $this->settings[ 'clientId' ] : false;
			$this->privateKey 		= isset($this->settings[ 'privateKey' ]) ? $this->settings[ 'privateKey' ] : false;
			$this->returnUrl 		= isset($this->settings[ 'returnUrl' ]) ? $this->settings[ 'returnUrl' ] : false;
			$this->debugMode  		= isset($this->settings[ 'debugMode' ]) ? $this->settings[ 'debugMode' ] : false;
			$this->notify_url   	= add_query_arg( array(
														'woo-api' => 'callback_bankpay',
													), home_url( '/' ));
			$this->msg['message'] 	= '';
			$this->msg['class'] 	= '';

			if ( $this->debugMode == 'on' ) {
				$this->logs = new WC_Logger();
			}

			// BANKpay+ account fields shown on the thanks page and in emails.
			$this->account_details = get_option(
				'woocommerce_bankpay_accounts',
				array(
					array(
						'account_name'   => $this->get_option( 'account_name' ),
						'bank_name'      => $this->get_option( 'bank_name' ),
						'iban'           => $this->get_option( 'iban' ),
						'bic'            => $this->get_option( 'bic' ),
					),
				)
			);

			// Actions
			add_action( 'woocommerce_update_options_payment_gateways_' . $this->id, array( &$this, 'process_admin_options' ) );
			add_action( 'woocommerce_update_options_payment_gateways_' . $this->id, array( &$this, 'save_account_details' ) );
			add_action( 'woocommerce_thankyou_bankpay', array( &$this, 'thankyou_page' ) );
			add_action( 'woocommerce_receipt_bankpay', array( &$this, 'receipt_page' ) );

			// Customer Emails
			// TODO
			add_action( 'woocommerce_email_before_order_table', array( &$this, 'email_instructions' ), 10, 3 );
		}

		/**
		 * Check if the cart amount is > 0
		 *
		 * @return bool
		 */
		protected function cartAmountAvailable()
		{
			return WC()->cart && $this->get_order_total() > 0;
		}

		/**
		 * Initialise Gateway Settings Form Fields.
		 */
		public function init_form_fields() {

			$this->form_fields = array(
				'enabled'         => array(
					'title'   => __( 'Enable/Disable', 'woocommerce' ),
					'type'    => 'checkbox',
					'label'   => __( 'Enable BANKpay+', 'woocommerce' ),
					'default' => 'no',
				),
				'merchantName' => array(
					'title' 		=> __( 'Firmenname', 'woocommerce' ),
					'type' 			=> 'text',
					'description' 	=> __( 'Firmenname wird bei der Bezahlung angezeigt.', 'woocommerce' ),
					'required' 		=> true,
					'desc_tip'      => true,
				),
				'title'           => array(
					'title'       => __( 'Title', 'woocommerce' ),
					'type'        => 'text',
					'description' => __( 'This controls the title which the user sees during checkout.', 'woocommerce' ),
					'default'     => __( 'BANKpay+', 'woocommerce' ),
					'desc_tip'    => true,
				),
				'description'     => array(
					'title'       => __( 'Description', 'woocommerce' ),
					'type'        => 'textarea',
					'description' => __( 'Payment method description that the customer will see on your checkout.', 'woocommerce' ),
					'default'     => __( 'Make your payment directly and securely with your online banking account.', 'woocommerce' ),
					'desc_tip'    => true,
				),
				'instructions'    => array(
					'title'       => __( 'Instructions', 'woocommerce' ),
					'type'        => 'textarea',
					'description' => __( 'Information that will be added to the thank you page and emails.', 'woocommerce' ),
					'default'     => '',
					'desc_tip'    => true,
				),
				'account_details' => array(
					'type' => 'account_details',
					'description' => __( 'Bankkonto zum Empfang der Zahlungen.', 'woocommerce' ),
					'desc_tip'    => true,
				),
				'clientId' => array(
					'title' 		=> __( 'Client ID', 'woocommerce' ),
					'type' 			=> 'text',
					'description' 	=> __( 'Ihre Kunden-ID. Wird automatisch erstellt.', 'woocommerce' ),
					'required' 		=> true,
					'desc_tip'      => true,
					'custom_attributes' => array('readonly'=>'readonly'),
					),
				'privateKey' => array(
					'title' 		=> __( 'API Key', 'woocommerce' ),
					'type' 			=> 'text',
					'description' 	=> __( 'Ihr API Key. Wird automatisch erstellt.', 'woocommerce' ),
					'required' 		=> true,
					'desc_tip'      => true,
					'custom_attributes' => array('readonly'=>'readonly'),
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

			$currencies = array("EUR");

			if (!in_array(get_option('woocommerce_currency'), $currencies )) {
				echo '<div class="error"><p>'.__('BANKpay+ currently only supports EUR.', 'woocommerce').'</p></div>';
			}

			$accounts = $this->account_details;
			$current_user = wp_get_current_user();

			if (!array_key_exists('woocommerce_bankpay_merchantName', $_POST)) {
				$_POST['woocommerce_bankpay_merchantName'] = null;
			}

			$post_data = array(
				'email' => get_bloginfo('admin_email'),
				'url' => get_bloginfo('atom_url'),
				'contact_email' => $current_user->user_email,
				'company_name' => wc_clean( wp_unslash(!empty($_POST['woocommerce_bankpay_merchantName']) ? $_POST['woocommerce_bankpay_merchantName'] : '') ),
				'address' => WC()->countries->get_base_address(),
				'address_2' => WC()->countries->get_base_address_2(),
				'postcode' => WC()->countries->get_base_postcode(),
				'city' => WC()->countries->get_base_city(),
				'country' => WC()->countries->get_base_country(),
				'account_name' => $accounts[0]['account_name'],
				'account_iban' => $accounts[0]['iban'],
				'account_bic' => $accounts[0]['bic'],
				'uuid' => $this->clientId,
			);

			$authorization = array('Authorization' => 'Bearer ' . $this->privateKey);
			$headers = array();

			// create client if not exists
			if (empty($this->privateKey)) {
				$url = 'https://bankpay.plus/api/auth/wc-onboard';

				$post_data['password'] = random_bytes(22);

				if (!empty($this->privateKey)) {
					$headers = $authorization;
				}

				$args = array(
					'body'        => $post_data,
					'timeout'     => '5',
					'redirection' => '5',
					'httpversion' => '1.0',
					'blocking'    => true,
					'headers'     => $headers,
					'cookies'     => array(),
				);

				$response = wp_remote_post( $url, $args );

				if (is_wp_error($response)) {
					if ($this->logs) $this->logs->add( 'bankpay', __( 'BANKpay+ plugin: update error: ' . print_r($response->get_error_messages(), true), 'woocommerce') );
					echo '<div class="error"><p>'.sprintf(__('BANKpay+ Update Fehler - <a href="%s">Error-Log anzeigen</a>', 'woothemes'), admin_url('admin.php?page=wc-status&tab=logs')).'</p></div>';
				} else {
					$client = (object) json_decode($response['body']);
				}

				if (!empty($client->onboard->token) && !empty($client->onboard->token->new)) {
					// store api key
					$this->privateKey = $this->settings[ 'privateKey' ] = $client->onboard->token->new;
					$this->clientId = $this->settings[ 'clientId' ] = $client->onboard->client->uuid;
					if (isset($this->logs)) $this->logs->add( 'bankpay', __( 'BANKpay+ plugin: onboarding ok: ' . print_r($client, true), 'woocommerce') );
				} else if (!empty($client->onboard->client)) {
					if (isset($this->logs)) $this->logs->add( 'bankpay', __( 'BANKpay+ plugin: update ok: ' . print_r($client, true), 'woocommerce') );
				}
			}

			echo '<h3>'.__(	'BANKpay+ Payment Gateway', 'woocommerce' ).'</h3>';

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

			if ( $this->mode == 's' ) {
				echo '<p>';
				echo wpautop( wptexturize(  __('TEST MODE/SANDBOX ENABLED', 'woocommerce') )). ' ';
				echo '<p>';
			}

			echo '<p>'.__(
				sprintf(
						"<strong>Vielen Dank!</strong><br /><br /> ✓ Deine Bestellung ist bei uns eingegangen. <br /> ✓ Nach der Bezahlung wird diese schnellstmöglich auf den Weg gebracht!",
						$this->method_title
						), 'woocommerce' ).'</p>';

			echo '<p>'.__(
				sprintf(
						"<strong>Sofort schnell und sicher mit deinem Bankkonto bezahlen.</strong> <br />Eine Überweisung bei deiner Bank online beauftragen:",
						$this->method_title
						), 'woocommerce' ).'</p>';

			echo $this->set_payment_form( $order_id );


			// show bank details for manual / QR code transfer
			echo '<p>'.__(
				sprintf(
						"<strong>Zahlungshinweise | Zahlen mit Code</strong> <br />Eine Überweisung kann alternativ auch bei deiner Bank selbst durchgeführt werden.",
						$this->method_title
						), 'woocommerce' ).'</p>';

			$this->bank_details( $order_id );

		}

		/**
		 * Output for the order form.
		 *
		 * @param int $order_id Order ID.
		 */
		public function set_payment_form( $order_id ) {

			$order = new WC_Order( $order_id );
			$host = @parse_url(strtolower(get_bloginfo('atom_url')), PHP_URL_HOST);

			$data = $order->get_data();
			$order_id = $data['id'];

			if (empty($order_id)) {
				return '<div class="error"><p>'.sprintf(__('BANKpay+ Checkout Fehler - Keine Bestellung gefunden', 'woothemes')).'</p></div>';
			}

			$reference = "Bestellung $order_id"; // "-" . $data['date_created']->date('Ymd');
			$correlationId = 'wc-' . $order_id . '-' . $host . '-' . $this->settings[ 'clientId' ];

			// get_endpoint_url -- get_transaction_url -- api_request_url -- get_cancel_order_url -- get_edit_order_url -- get_return_url -- product_add_to_cart_url -- support_url -- webhook_delivery_url
			$redirectUrl = $order->get_checkout_order_received_url(); // woocommerce_get_checkout_order_received_url -- $order->get_view_order_url()
			$checkoutUrl = $order->get_checkout_payment_url(); // get_checkout_url

			$callback_url = add_query_arg( array(
				'order' => $order_id,
				'correlationId' => $correlationId,
			), $this->notify_url );

			// if empty checkoutId create new checkout
			if ($data['payment_method'] == 'bankpay'
				&& empty($data['transaction_id'])) {

				$post_data = array(
					'reference' => $reference,
					'amount' => $data['total'],
					'ipn' => $callback_url,
					'correlationId' => $correlationId,
					'clientId' => $this->settings[ 'clientId' ],
					'returnUrl' => $redirectUrl,
					'checkoutUrl' => $checkoutUrl,
				);

				// billing / customer email
				// https://www.hardworkingnerd.com/woocommerce-how-to-get-a-customer-details-from-an-order/
				// https://stackoverflow.com/questions/22843504/how-can-i-get-customer-details-from-an-order-in-woocommerce
				//

				$url = 'https://bankpay.plus/api/checkout';
				$authorization = array('Authorization' => 'Bearer ' . $this->settings[ 'privateKey' ]);

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

				if (is_wp_error($response)) {
					if ($this->logs) $this->logs->add( 'bankpay', __( 'BANKpay+ plugin: checkout error: ' . print_r($response->get_error_messages(), true), 'woocommerce') );
					return '<div class="error"><p>'.sprintf(__('BANKpay+ Checkout Fehler - <a href="%s">Error-Log anzeigen</a>', 'woothemes'), admin_url('admin.php?page=wc-status&tab=logs')).'</p></div>';
				} else {
					$checkout = (object) json_decode($response['body']);
				}

				if (!empty($checkout->uuid)) {
					add_post_meta($order_id, '_transaction_id', $checkout->uuid);
					add_post_meta($order_id, '_bankpay_checkout_id', $checkout->uuid);

					$checkoutId = $checkout->uuid;
				} else if (empty($checkout->uuid)) {
					if ($this->logs) $this->logs->add( 'bankpay', __( 'BANKpay+ plugin: checkout error: ' . print_r($checkout, true), 'woocommerce') );
				}
			} else if ($data['payment_method'] == 'bankpay'
				&& !empty($data['transaction_id'])) {
				$checkoutId = $data['transaction_id'];
			}

			$html = '';
			$html .= '<a onclick="pay(); return false;" class="bankpay-checkout button" href="https://bankpay.plus/checkout/' . $checkoutId . '">Jetzt mit meinem Bankkonto bezahlen</a>';
			$html .= '<div style="font-size: 0.8em; margin-top: 1.5em" id="bankpay-payment-checkout">Eine Überweisung direkt, sicher & schnell durchführen. BANKpay+ Instant!</div>';

			// $html .= 'IBAN: ' . $bankpay_account->iban . '<br />';

			$html .= '<br /> <small>
						✓ Du wirst zu deiner Bank weiter geleitet um dich anzumelden. <br />
						✓ Die Bezahldaten werden zur Freigabe der Überweisung angezeigt. <br />
						✓ Es wird eine SEPA Banküberweisung für dich in Auftrag gegeben. <br />
						⤬ Keine Kontodaten abgefragt — es wird nur eine Überweisung erstellt. <br />
						⤬ Keine Kontoumsätze ausgewerted / gespeichert — technisch unmöglich. <br />
						⤬ Kein Kundenkonto — deine IBAN ist nur in deinem Browser gespeichert.</small> <br /><br /><br /><br />';

			$html .= '<script>';
			$html .= 'console.log("** WC checkout **", " ** ' . $reference . '", " ** status: ' . $data['status'] . '", " ** transaction_id: ' . $data['transaction_id'] . '");';

			$html .= '
			/*
			const merchantUuid = ""

			$el = $("")
			let amount = parseFloat(
				$el
				.text()
				.match(/[0-9]+([,.][0-9]+)?/)[0]
				.toString()
				.replace(",", ".")
			)
			let reference = ""
			*/

			// BANKpay+
			let tx,
				baseUrl = "https://BANKpay.plus",
				BANKpay = {
					api: baseUrl + "/api",
					checkout: baseUrl + "/checkout",
					bank_sca: baseUrl + "/bank-sca",
				};

			/*
			function BANKpay() {

				return false;

				var payNow = $("");
				payNow
				.addClass("bankpay-payment-checkout")
				.css({ cursor: "pointer" })
				.on("click", function (e) {
					e.preventDefault();
					pay();
				});

				var qrUrl =
				"https://dev.matthiasschaffer.com/bezahlcode/api.php?iban=" +
				"&bic=BKAUATWW" +
				"&name=xxx GmbH" +
				"&usage=" +
				reference +
				" via www.BANKpay.plus" +
				"&amount=" +
				amount;

				let img = document.createElement("img");

				// div
				img.setAttribute("id", "sepa-digital-pay-code");
				img.setAttribute("class", "SEPAinstant-qr-code");

				// img
				img.setAttribute("src", qrUrl);
				img.setAttribute("style", "width: 120px; float: right; margin: 3px;");

				payNow.prepend(img);
			}
			*/

			function pay() {
				let msg = document.getElementById("bankpay-payment-checkout");

				msg.innerHTML =
					"Bezahlvorgang wird gestartet: mit BANKpay+ Instant Konto-Login bezahlen ...<br />";

				// open BANKpay+
				const width_of_popup_window = 501,
				height_of_popup_window = 911,
				// left = event.clientX + 20,
				// top = (screen.height - height_of_popup_window) / 2 - 50;
				left = (screen.width - width_of_popup_window) / 2 - 250,
				top = (screen.height - height_of_popup_window) / 2 - 50;

				/*
				function printMousePos(event) {
					document.body.textContent =
					  "clientX: " + event.clientX +
					  " - clientY: " + event.clientY;
				  }

				  document.addEventListener("click", printMousePos);
				*/

				const payWindow = window.open(
					BANKpay.checkout + "/' . $checkoutId . '",
					"bankpay",
					"left=" +
						left +
						",top=" +
						top +
						",height=" +
						height_of_popup_window +
						",width=" +
						width_of_popup_window
				);

				if (
					!payWindow ||
					payWindow.closed ||
					typeof payWindow.closed == "undefined"
				) {
					popupBlocked = true;
					window.location.href = BANKpay.checkout + "/' . $checkoutId . '";
				}

				// $(payWindow).on("close", function () {
				payWindow.addEventListener("close", function() {
					console.log("payWindow closed");
					msg.innerHTML =
						"Bezahlvorgang wurde abgebrochen."
				});

				if (window.focus) {
					payWindow.focus();
				}

				if (!payWindow.closed) {
					payWindow.focus();
				}
			}

			// BANKpay();

			';


			$html .= '</script>';

			return $html;
		}

		// TODO
		public function getPages( $title = false, $indent = true )
		{
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

		// TODO
		public function payment_fields()
		{
			// echo 'BANKPay+ Description:';
			if( $this->description ) {
				echo wpautop( wptexturize( $this->description ) );
			}
		}

		// TODO
		public function showMessage( $content )
		{
			$html  = 'BANKPay+ Message:';
			$html .= '<div class="box '.$this->msg['class'].'-box">';
			$html .= $this->msg['message'];
			$html .= '</div>';
			$html .= $content;

			return $html;
		}

		/**
		 * Generate account details html.
		 *
		 * @return string
		 */
		public function generate_account_details_html() {

			ob_start();

			$countries = WC()->countries->get_countries();
			$country = WC()->countries->get_base_country();
			$current_user = wp_get_current_user();
			$site = [];

			/**
			 * Check if WooCommerce is activated
			 */
			if ( class_exists( 'woocommerce' ) ) {
				$site['woocommerce'] = 1;
			} else {
				$site['woocommerce'] = 0;
			}

			$site['url'] = get_bloginfo('url');
			$site['admin_email'] = get_bloginfo('admin_email');
			$site['version'] = get_bloginfo('version');
			$site['language'] = get_bloginfo('language');
			$site['name'] = get_bloginfo('name');
			$site['atom_url'] = get_bloginfo('atom_url');
			// $site_metadata = get_site_meta(1);

			echo '<p>';
			echo '<strong>Adresse</strong><br />';
			echo WC()->countries->get_base_address() . '<br />';
			if (!empty(WC()->countries->get_base_address_2())) {
				echo '' . WC()->countries->get_base_address_2() . '<br />';
			}
			echo WC()->countries->get_base_postcode() . ' ' . WC()->countries->get_base_city() . ' <br />';
			echo $countries[WC()->countries->get_base_country()] . ' <br />';
			echo '</p>';

			// echo 'country: ' . $country . '<br />';
			// echo 'E-Mail: ' . $current_user->user_email . '<br />';
			// echo 'administrator: ' . $current_user->has_cap('administrator') . '<br />';
			// echo 'manage_woocommerce: ' . $current_user->has_cap('manage_woocommerce') . '<br />';
			?>
			<tr valign="top">
				<th scope="row" class="titledesc"><?php esc_html_e( 'Account details:', 'woocommerce' ); ?></th>
				<td class="forminp" id="bankpay_accounts">
					<div class="wc_input_table_wrapper">
						<table class="widefat wc_input_table sortable" cellspacing="0">
							<thead>
								<tr>
									<!-- <th class="sort">&nbsp;</th> -->
									<th><?php esc_html_e( 'Account name', 'woocommerce' ); ?></th>
									<th><?php esc_html_e( 'Bank name', 'woocommerce' ); ?></th>
									<th><?php esc_html_e( 'IBAN', 'woocommerce' ); ?></th>
									<th><?php esc_html_e( 'BIC', 'woocommerce' ); ?></th>
								</tr>
							</thead>
							<tbody class="accounts">
								<?php
								$i = -1;
								if ( $this->account_details ) {
									foreach ( $this->account_details as $account ) {
										$i++;

										echo '<tr class="account">
											<!-- <td class="sort"></td> -->
											<td><input type="text" value="' . esc_attr( wp_unslash( $account['account_name'] ) ) . '" name="bankpay_account_name[' . esc_attr( $i ) . ']" /></td>
											<td><input type="text" value="' . esc_attr( wp_unslash( $account['bank_name'] ) ) . '" name="bankpay_bank_name[' . esc_attr( $i ) . ']" /></td>
											<td><input type="text" value="' . esc_attr( $account['iban'] ) . '" name="bankpay_iban[' . esc_attr( $i ) . ']" /></td>
											<td><input type="text" value="' . esc_attr( $account['bic'] ) . '" name="bankpay_bic[' . esc_attr( $i ) . ']" /></td>
										</tr>';
									}
								}
								?>
							</tbody>
							<!--
							<tfoot>
								<tr>
									<th colspan="7">

										<a href="#" class="add button"><?php esc_html_e( '+ Add account', 'woocommerce' ); ?></a>

										<a href="#" class="remove_rows button"><?php esc_html_e( 'Remove selected account(s)', 'woocommerce' ); ?></a>
									</th>
								</tr>
							</tfoot>
							-->
							</table>
					</div>
					<script type="text/javascript">
						jQuery(function() {
							jQuery('#bankpay_accounts').on( 'click', 'a.add', function() {

								var size = jQuery('#bankpay_accounts').find('tbody .account').length;

								jQuery('<tr class="account">\
										<td class="sort"></td>\
										<td><input type="text" name="bankpay_account_name[' + size + ']" /></td>\
										<td><input type="text" name="bankpay_bank_name[' + size + ']" /></td>\
										<td><input type="text" name="bankpay_iban[' + size + ']" /></td>\
										<td><input type="text" name="bankpay_bic[' + size + ']" /></td>\
									</tr>').appendTo('#bankpay_accounts table tbody');

								return false;
							});
						});
					</script>
				</td>
			</tr>
			<?php
			return ob_get_clean();
		}

		/**
		 * Save account details table.
		 */
		public function save_account_details() {

			$accounts = array();

			// phpcs:disable WordPress.Security.NonceVerification.Missing -- Nonce verification already handled in WC_Admin_Settings::save()
			if ( isset( $_POST['bankpay_account_name'] ) && isset( $_POST['bankpay_bank_name'] )
				&& isset( $_POST['bankpay_iban'] ) && isset( $_POST['bankpay_bic'] ) ) {

				$account_names   = wc_clean( wp_unslash( $_POST['bankpay_account_name'] ) );
				$bank_names      = wc_clean( wp_unslash( $_POST['bankpay_bank_name'] ) );
				$ibans           = wc_clean( wp_unslash( $_POST['bankpay_iban'] ) );
				$bics            = wc_clean( wp_unslash( $_POST['bankpay_bic'] ) );

				foreach ( $account_names as $i => $name ) {
					if ( ! isset( $account_names[ $i ] ) ) {
						continue;
					}

					$accounts[] = array(
						'account_name'   => $account_names[ $i ],
						'bank_name'      => $bank_names[ $i ],
						'iban'           => $ibans[ $i ],
						'bic'            => $bics[ $i ],
					);
				}
			}
			// phpcs:enable

			// create or update BANKpay+ client
			$country = WC()->countries->get_base_country();
			$countries = WC()->countries->get_countries();
			$current_user = wp_get_current_user();

			$post_data = array(
				'email' => get_bloginfo('admin_email'),
				'url' => get_bloginfo('atom_url'),
				'contact_email' => $current_user->user_email,
				'company_name' => wc_clean( wp_unslash( $_POST['woocommerce_bankpay_merchantName'] ) ),
				'address' => WC()->countries->get_base_address(),
				'address_2' => WC()->countries->get_base_address_2(),
				'postcode' => WC()->countries->get_base_postcode(),
				'city' => WC()->countries->get_base_city(),
				'country' => WC()->countries->get_base_country(),
				'account_name' => $accounts[0]['account_name'],
				'account_iban' => $accounts[0]['iban'],
				'account_bic' => $accounts[0]['bic'],
				'uuid' => $this->clientId,
			);

			$authorization = array('Authorization' => 'Bearer ' . $this->privateKey);
			$headers = array();

			// create client if not exists
			if (empty($this->privateKey)) {
				$url = 'https://bankpay.plus/api/auth/wc-onboard';

				$post_data['password'] = random_bytes(22);
			} else {
				$url = 'https://bankpay.plus/api/auth/wc-update';
			}

			if (!empty($this->privateKey)) {
				$headers = $authorization;
			}

			$args = array(
				'body'        => $post_data,
				'timeout'     => '5',
				'redirection' => '5',
				'httpversion' => '1.0',
				'blocking'    => true,
				'headers'     => $headers,
				'cookies'     => array(),
			);

			$response = wp_remote_post( $url, $args );

			if (is_wp_error($response)) {
				if ($this->logs) $this->logs->add( 'bankpay', __( 'BANKpay+ plugin: update error: ' . print_r($response->get_error_messages(), true), 'woocommerce') );
				echo '<div class="error"><p>'.sprintf(__('BANKpay+ Update Fehler - <a href="%s">Error-Log anzeigen</a>', 'woothemes'), admin_url('admin.php?page=wc-status&tab=logs')).'</p></div>';
			} else {
				$client = (object) json_decode($response['body']);
			}

			if (!empty($client->onboard->token) && !empty($client->onboard->token->new)) {
				// store api key
				$this->privateKey = $this->settings[ 'privateKey' ] = $client->onboard->token->new;
				$this->clientId = $this->settings[ 'clientId' ] = $client->onboard->client->uuid;
				if ($this->logs) $this->logs->add( 'bankpay', __( 'BANKpay+ plugin: onboarding ok: ' . print_r($response, true), 'woocommerce') );
			} else if (!empty($client->onboard->client)) {
				if ($this->logs) $this->logs->add( 'bankpay', __( 'BANKpay+ plugin: update ok: ' . print_r($response, true), 'woocommerce') );
			}

			do_action( 'woocommerce_update_option', array( 'id' => 'woocommerce_bankpay_accounts' ) );
			update_option( 'woocommerce_bankpay_accounts', $accounts );
		}

		/**
		 * Output for the order received page.
		 *
		 * @param int $order_id Order ID.
		 */
		public function thankyou_page( $order_id ) {

			if ( $this->instructions ) {
				echo wp_kses_post( wpautop( wptexturize( wp_kses_post( $this->instructions ) ) ) );
			}

			$this->bank_details( $order_id );
		}

		/**
		 * Add content to the WC emails.
		 *
		 * @param WC_Order $order Order object.
		 * @param bool     $sent_to_admin Sent to admin.
		 * @param bool     $plain_text Email format: plain text or HTML.
		 */
		public function email_instructions( $order, $sent_to_admin, $plain_text = false ) {

			if ( ! $sent_to_admin && 'bankpay' === $order->get_payment_method() && $order->has_status( 'on-hold' ) ) {
				if ( $this->instructions ) {
					echo wp_kses_post( wpautop( wptexturize( $this->instructions ) ) . PHP_EOL );
				}
				$this->bank_details( $order->get_id() );
			}
		}

		/**
		 * Get bank details and place into a list format.
		 *
		 * @param int $order_id Order ID.
		 */
		private function bank_details( $order_id = '' ) {
			global $woocommerce;

			if ( empty( $this->account_details ) ) {
				return;
			}

			$bankpay_accounts = apply_filters( 'woocommerce_bankpay_accounts', $this->account_details, $order_id );

			if ( ! empty( $bankpay_accounts ) ) {
				$account_html = '';
				$has_details  = false;

				$order = wc_get_order( $order_id );
				$data = $order->get_data();

				// $reference = "$order_id SEPA.id/";
				$reference = "$order_id";
				$amount = $data['total'];
				$checkoutId = $data['transaction_id'];
				$shortId = '';

				// TODO get shortID for SEPA.id/shortID redirect ...
				$gateways = $woocommerce->payment_gateways->payment_gateways();
				$bankpay = $gateways['bankpay'];
				$transaction = bankpayRequestBankPay(
			        'wc-status/',
			        array(
			             'checkout'  => $checkoutId,
			             'client' => null
			        ),
			        $bankpay->privateKey
			    );

				if (isset($transaction['status']) && is_object($transaction['__details'])) {
					$shortId = $transaction['__details']->shortId;
				}

				// BANK account details

				foreach ( $bankpay_accounts as $bankpay_account ) {
					$bankpay_account = (object) $bankpay_account;

					if (!empty($bankpay_account->iban)) {
						// QR Code
						$qrUrl = 'https://dev.matthiasschaffer.com/bezahlcode/api.php?iban=' .

									urlencode(preg_replace('/\s+/', '', $bankpay_account->iban)) . '&bic=' .
									urlencode(preg_replace('/\s+/', '', $bankpay_account->bic)) . '&name=' .
									urlencode($bankpay_account->account_name) . '&usage=' .
									// $reference . ' www.SEPA.id/' . $shortId . ' QR&amount=' .
									// urlencode($reference) . ' www.SEPA.id QR&amount=' .
									urlencode($reference . ' SEPA.id/'. $shortId ) . '&amount=' .
									$amount . '';

						// base64 img
						//$data = file_get_contents($qrUrl);
						$data = '';
						$type = 'png';
						$base64img = 'data:image/' . $type . ';base64,' . base64_encode($data);

						$qrImg = $base64img;
						$qrImg = $qrUrl;

						$account_html .= '<span id="bankpay-sepa-qr-code">
							<img style="max-width: 150px; min-width: 150px; float: right;" src="' . $qrImg . '" />
						</span>
						';
					}

					if ( $bankpay_account->account_name ) {
						// $account_html .= '<h4 class="wc-bankpay-bank-details-account-name">' . wp_kses_post( wp_unslash( $bankpay_account->account_name ) ) . ':</h4>' . PHP_EOL;
					}

					$account_html .= '<ul class="wc-bankpay-bank-details bankpay_details">' . PHP_EOL;

					// bankpay account fields shown on the thanks page and in emails.
					$account_fields = apply_filters(
						'woocommerce_bankpay_account_fields',
						array(
							'account_owner'      => array(
								'label' => __( 'Empfänger', 'woocommerce' ),
								'value' => wp_kses_post( wp_unslash( $bankpay_account->account_name ) ),
							),
							'iban'           => array(
								'label' => __( 'Konto IBAN', 'woocommerce' ),
								'value' => $bankpay_account->iban,
							),
							'bank_name'      => array(
								'label' => __( 'Bank', 'woocommerce' ),
								'value' => $bankpay_account->bank_name,
							),
							'bic'      => array(
								'label' => __( 'BIC', 'woocommerce' ),
								'value' => $bankpay_account->bic,
							),
						),
						$order_id
					);

					foreach ( $account_fields as $field_key => $field ) {
						if ( ! empty( $field['value'] ) ) {
							$account_html .= '<li class="' . esc_attr( $field_key ) . '">' . wp_kses_post( $field['label'] ) . ': <strong>' . wp_kses_post( wptexturize( $field['value'] ) ) . '</strong></li>' . PHP_EOL;
							$has_details   = true;
						}
					}

					$account_html .= '</ul>';
				}


				// TX fields
				$tx_fields = array(
					'tx_reference'      => array(
						'label' => __( 'Zahlungsreferenz', 'woocommerce' ),
						'value' => wp_kses_post( wp_unslash( $reference ) . ' ID' /* . $shortId */ ),
					),
					'tx_amount'      => array(
						'label' => __( 'Betrag', 'woocommerce' ),
						'value' => '€ ' . wp_kses_post( wp_unslash( $amount ) ),
					),
					'sepa-id-url'            => array(
						'label' => __( 'Pay-by-link', 'woocommerce' ),
						'value' => '<a target="_blank" title="Jetzt online mit dem Bankkonto bezahlen" href="https://BANKpay.plus/checkout/' . $checkoutId . '">SEPA.id/' . $shortId . '</a>',
					),
					/*'sepa-id'            => array(
						'label' => __( 'SEPA.id', 'woocommerce' ),
						'value' => $checkoutId,
					),*/
				);

				$account_html .= '<ul>';
				foreach ( $tx_fields as $field_key => $field ) {
					if ( ! empty( $field['value'] ) ) {
						$account_html .= '<li class="' . esc_attr( $field_key ) . '">' . wp_kses_post( $field['label'] ) . ': <strong>' . wp_kses_post( wptexturize( $field['value'] ) ) . '</strong></li>' . PHP_EOL;
						$has_details   = true;
					}
				}
				$account_html .= '</ul>';

				// end TX details

				if ( $has_details ) {
					echo '<section class="woocommerce-bankpay-bank-details"><strong class="wc-bankpay-bank-details-heading">' . esc_html__( 'Our bank details', 'woocommerce' ) . '</strong>' . wp_kses_post( PHP_EOL . $account_html ) . '</section>';
				}
			}

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
				$order->update_status( apply_filters( 'woocommerce_bankpay_process_payment_order_status', 'pending', $order ), __( 'Awaiting BANKpay+ payment', 'woocommerce' ) );
			} else {
				$order->payment_complete();
				$order->add_order_note( __('BANKpay+ payment completed (free order).', 'woothemes') );
			}

			// payment failed
			//$order->update_status( apply_filters( 'woocommerce_bankpay_process_payment_order_status', 'failed', $order ), __( 'BANKpay+ payment failed', 'woocommerce' ) );

			if ( $wp_rewrite->permalink_structure == '' ) {
				$checkout_url = wc_get_checkout_url() . '&order-pay= ' . $order_id . '&key= ' . $order->order_key;
			} else {
				$checkout_url = wc_get_checkout_url() . 'order-pay/' . $order_id . '?key= ' . $order->order_key;
			}

			return array(
					'result' => 'success',
					'redirect' => $checkout_url
			);
		}
	}

	/**
	 * Add BANKpay+ Payment Method.
	 *
	 * @param array $methods Payment methods.
	 * @return array
	 */
	function woocommerce_add_api_bankpay( $methods ) {
		$methods[] = 'WC_Gateway_BANKpay';
		return $methods;
	}

	add_filter( 'woocommerce_payment_gateways', 'woocommerce_add_api_bankpay' );

	/**
	 * Add BANKpay+ links.
	 *
	 * @param array $links Plugin links.
	 * @return array
	 */
	function bankpay_action_links( $links ) {
		return array_merge( array(
			// '<a href="' . esc_url( 'https://BANKpay.plus' ) . '">' . __( 'BANKpay+ Website', 'woocommerce' ) . '</a>',
			'<a href="' . esc_url( get_admin_url() . 'admin.php?page=wc-settings&tab=checkout&section=bankpay'  ) . '">' . __( 'Settings', 'woocommerce' ) . '</a>'
		), $links );
	}

	add_filter( 'plugin_action_links_' . plugin_basename( __FILE__ ), 'bankpay_action_links' );
}

add_action( 'plugins_loaded', 'woocommerce_api_bankpay_init', 0 );


// include plugins
// include_once 'plugin/oppwa/plugin.php';


/*
 * helper methods
 */
function guidv4($data)
{
    assert(strlen($data) == 16);

    $data[6] = chr(ord($data[6]) & 0x0f | 0x40); // set version to 0100
    $data[8] = chr(ord($data[8]) & 0x3f | 0x80); // set bits 6-7 to 10

    return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
}
