<?php

// TODO fix / add translations placeholder

// curl -X POST "http://localhost:8000/?woo-api=callback_bankpay&order=xxx&correlationId=xxx&checkoutId=xxx"

if ( in_array( 'woocommerce/woocommerce.php', apply_filters( 'active_plugins', get_option( 'active_plugins' ) ) ) )
{

	function  bankpay_api_callback_oppwa()
	{
		global $woocommerce;

		$logs = new WC_Logger();
		$order_id = (!empty($_REQUEST['order']) ? filter_var($_REQUEST['order'], FILTER_SANITIZE_NUMBER_INT) : '' );
		$order = new WC_Order( $order_id );
		$order_data = $order->get_data();

		if ($order_id && $order) {

			$gateways = $woocommerce->payment_gateways->payment_gateways();

			if ( $_REQUEST['woo-api'] != 'callback_bankpay' ) {
				$logs->add( 'bankpay', __('BANKpay+ plugin: Invalid Callback URL', 'woocommerce') );
				return;
			}

			if ( !isset($gateways['bankpay']) ) {
				$logs->add( 'bankpay', __('BANKpay+ plugin: not enabled in woocommerce', 'woocommerce') );
				return;
			}

			if (!isset($_REQUEST['correlationId'])) {
				$logs->add( 'bankpay', __( 'BANKpay+ plugin: correlationId is missing', 'woocommerce') );
				return;
			}

			try
			{
				$logs->add( 'bankpay', __( 'BANKpay+ plugin: check payment status Order #' . $order_id, 'woocommerce') );

				$bankpay = $gateways['bankpay'];
				$checkoutId = wc_clean( wp_unslash( $_REQUEST['checkoutId'] ) );
				$correlationId = wc_clean( wp_unslash( $_REQUEST['correlationId'] ) );

			    $transaction = bankpayRequestBank(
			        'wc-status/',
			        array(
			             'checkout'  => $checkoutId,
			             'client' => null
			        ),
			        $bankpay->privateKey
			    );

				// $logs->add( 'bankpay', __( 'BANKpay+ plugin: check response #' . $checkoutId . ' - ' . print_r($transaction, true), 'woocommerce') );
				/*
					// RCVD - created

					// ACCP, ACTC, ACWC ACWP PDNG

					// RJCT UNKN

					// created / SCArequired
				*/
				$isStatusClosed = isset($transaction['payment']) && (
					$transaction['payment'] != 'created'
					|| $transaction['payment'] != 'RCVD'
					|| $transaction['payment'] != 'AcceptedCancellationRequest'
					|| $transaction['payment'] != 'Rejected'
					|| $transaction['payment'] != 'RejectedCancellationRequest'
					|| $transaction['payment'] != 'PendingCancellationRequest'
					|| $transaction['payment'] != 'PaymentCancelled'
					|| $transaction['payment'] != 'PartiallyAcceptedCancellationRequest'
					|| $transaction['payment'] != 'Cancelled'
					// TODO move into default
					|| $transaction['payment'] != 'NoCancellationProcess'
					|| $transaction['payment'] != 'AcceptedCustomerProfile'
					|| $transaction['payment'] != 'AcceptedFundsChecked'
					// || $transaction['payment'] != ''
				);

			    if ($isStatusClosed && $order_data['transaction_id'] == $transaction['uuid'] && $transaction['correlationId'] == $correlationId) {
				    $order->payment_complete();

					if (in_array($transaction['payment'], ['ACCC', 'ACSP', 'ACSC',
									'__AcceptedCancellationRequest', 'Accepted', 'AcceptedSettlementCompleted', 'AcceptedSettlementInProcess', 'AcceptedWithChange', 'AcceptedWithoutPosting', '', '', ''])) {
						$order->add_order_note(
							sprintf(
								"%s Payment completed and confirmed by the bank with Checkout-ID <a href='https://BANKpay.plus/checkout/%s#%s'>%s</a> (%s)",
								$bankpay->method_title,
								$checkoutId,
								$transaction['paymentId'],
								$transaction['uuid'],
								$transaction['payment'],
							)
						);
					} else {
						// OIB: AcceptedTechnicalValidation, PartiallyAcceptedTechnicalCorrect, Received, Pending
						$order->add_order_note(
							sprintf(
								"%s Payment completed wihtout bank confirmation with Checkout-ID <a href='https://BANKpay.plus/checkout/%s#%s'>%s</a> (%s)",
								$bankpay->method_title,
								$checkoutId,
								$transaction['paymentId'],
								$transaction['uuid'],
								$transaction['payment'],
							)
						);
					}

					$woocommerce->cart->empty_cart();

					$redirect_url = $order->get_view_order_url();

					/*
					// TODO activate later
					if ($bankpay->returnUrl == '' || $bankpay->returnUrl == 0 ) {
						$redirect_url = $bankpay->get_return_url( $order );
					} else {
						$redirect_url = get_permalink( $bankpay->returnUrl );
					}
					*/

					wp_redirect( $redirect_url );
					exit;

			    } else {

			    	$order->add_order_note(
			            sprintf(
			                "%s Payment failed. Checkout-ID: <a href='https://BANKpay.plus/checkout/%s#%s'>%s</a> (%s)",
			                $bankpay->method_title,
							$checkoutId,
			                $transaction['paymentId'],
			                $transaction['uuid'],
							$transaction['payment'],
			            )
			        );

			        wc_add_notice(__( 'Transaction Error: Could not complete your payment - Checkout-ID: ' . $checkoutId . ' (' . $transaction['payment']. ')', 'woocommerce'), 'error');
			        wp_redirect( wc_get_checkout_url() ); exit;
			    }
			} catch ( Exception $e ) {

				$trxnresponsemessage = $e->getMessage();
				$order->add_order_note(
			            sprintf(
			                "%s Payment Failed with message: '%s'",
			                $bankpay->method_title,
			                $trxnresponsemessage
			            )
			        );

				wc_add_notice(__( 'Transaction Error: Could not complete your payment' , 'woocommerce'), 'error');
		  		wp_redirect( wc_get_checkout_url() ); exit;
			}
		}
	}

	add_action( 'init', 'bankpay_api_callback_oppwa' );

	/**
	 * Perform HTTP request to REST endpoint
	 *
	 * @param string $action
	 * @param array  $params
	 * @param string $privateApiKey
	 *
	 * @return array
	 */
	function bankpayRequestBankApi($action = '', $params = array(), $privateApiKey)
	{
		$logs = new WC_Logger();

		$args = array(
			'body'        => $params,
			'timeout'     => '5',
			'redirection' => '5',
			'httpversion' => '1.0',
			'blocking'    => true,
			'headers'     => array(),
			'cookies'     => array(),
		);

		$response = wp_remote_post( 'https://BANKpay.plus/api/' . $action, $args );

		if (is_wp_error($response)) {
			if ($logs) $logs->add( 'bankpay', __( 'BANKpay+ plugin: API error: ' . print_r($response->get_error_messages(), true), 'woocommerce') );

			return array(
				'header' => array(
					'status' => 500,
					'reason' => null,
				),
				'body' => array('error' => print_r($response->get_error_messages(), true)),
			);
		} else {
			return array(
				'header' => array(
					'status' => 200,
					'reason' => null,
				),
				'body' => $response['body'],
			);
		}
	}

	/**
	 * Perform API and handle exceptions
	 *
	 * @param        $action
	 * @param array  $params
	 * @param string $privateApiKey
	 *
	 * @return mixed
	 */
	function bankpayRequestBank($action, $params = array(), $privateApiKey)
	{
		if (!is_array($params)) {
			$params = array();
		}

		$logs = new WC_Logger();

		$logs->add( 'bankpay', __( 'BANKpay+ plugin: API Request params: ' . $action . ' -- ' . json_encode($params), 'woocommerce') );

		$responseArray = bankpayRequestBankApi($action, $params, $privateApiKey);
		$httpStatusCode = $responseArray['header']['status'];

		$logs->add( 'bankpay', __( 'BANKpay+ plugin: API Request response: ' . $action . ' -- ' . json_encode($responseArray), 'woocommerce') );

		if ($httpStatusCode != 200) {
			$errorMessage = 'Client returned HTTP status code ' . $httpStatusCode;
			if (isset($responseArray['body']['error'])) {
				$errorMessage = $responseArray['body']['error'];
			}

			$responseCode = '';
			if (isset($responseArray['body']['data']['response_code'])) {
				$responseCode = $responseArray['body']['data']['response_code'];
			}

			return array("data" => array(
				"error"            => $errorMessage,
				"response_code"    => $responseCode,
				"http_status_code" => $httpStatusCode
			));
		}

		return (array) json_decode($responseArray['body']);
	}
}


// plugins
// require_once dirname(__FILE__) . '/plugin/oppwa/callback.php';


// OIB status codes
// see-also: https://openbankinguk.github.io/read-write-api-site3/v3.1.8/profiles/payment-initiation-api-profile.html
/*
Accepted
AcceptedCancellationRequest
AcceptedCreditSettlementCompleted
AcceptedCustomerProfile
AcceptedFundsChecked
AcceptedSettlementCompleted
AcceptedSettlementInProcess
AcceptedTechnicalValidation
AcceptedWithChange
AcceptedWithoutPosting
Cancelled
NoCancellationProcess
PartiallyAcceptedCancellationRequest
PartiallyAcceptedTechnicalCorrect
PaymentCancelled
Pending
PendingCancellationRequest
Received
Rejected
RejectedCancellationRequest
*/