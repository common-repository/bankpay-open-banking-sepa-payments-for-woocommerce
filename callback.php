<?php

// TODO fix / add translations placeholder
// curl -X POST "http://localhost:8000/?woo-api=callback_bankpay&order=xxx&correlationId=xxx&checkoutId=xxx"


if ( in_array( 'woocommerce/woocommerce.php', apply_filters( 'active_plugins', get_option( 'active_plugins' ) ) ) )
{

	function  bankpay_api_callback_bank()
	{
		global $woocommerce;

		$logs = new WC_Logger();
		$order_id = (!empty($_REQUEST['order']) ? filter_var($_REQUEST['order'], FILTER_SANITIZE_NUMBER_INT) : '' );

		$order = new WC_Order( $order_id );
		$order_data = $order->get_data();

		// TODO if order is canceld etc. exit early

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
				$checkoutId = wc_clean( wp_unslash( (($_REQUEST && isset($_REQUEST['checkoutId'])) ? $_REQUEST['checkoutId'] : '') ) );
				$correlationId = wc_clean( wp_unslash( (($_REQUEST && isset($_REQUEST['correlationId'])) ? $_REQUEST['correlationId'] : '') ) );

				// update bank status
				// $updateTx = @json_decode(@file_get_contents('https://BANKpay.plus/bank-update/' . $checkoutId . '?notification=off'));

				// get status
			    $transaction = bankpayRequestBankPay(
			        'wc-status/',
			        array(
			             'checkout'  => $checkoutId,
			             'client' => null
			        ),
			        $bankpay->privateKey
			    );

				if (!is_array($transaction) && (empty($transaction['uuid']) || empty($transaction['uuid']))) {
					$logs->add( 'bankpay', __( 'BANKpay+ plugin: check payment error - transaction status not found #' . $checkoutId, 'woocommerce') );
					exit;
				}

				$lastStatus = $order->get_meta( '_bankpay_settlement_status');
				$isNewStatus = ($lastStatus != $transaction['payment']);

				$order->update_meta_data( '_bankpay_settlement_status', $transaction['payment'] );

				if ( $order->get_status() == 'pending' && $isNewStatus ) {

					$order->update_meta_data( '_bankpay_payment_uuid', $transaction['paymentId'] );
					$order->update_meta_data( '_bankpay_checkout_uuid', $transaction['uuid'] );

					$order->add_order_note(
						sprintf(
							"%s payment <br/>| Settlement: %s <br />| ID: <a target='_blank' href='https://BANKpay.plus/checkout/%s#%s'>%s</a> <br />| TX: %s",
							$bankpay->method_title,
							$transaction['payment'],
							$checkoutId,
							$transaction['paymentId'],
							$transaction['uuid'],
							$transaction['paymentId'],
						)
					);
				}

				// $logs->add( 'bankpay', __( 'BANKpay+ plugin: check response #' . $checkoutId . ' - ' . print_r($transaction, true), 'woocommerce') );
				/*
					// RCVD - created

					// ACCP, ACTC, ACWC ACWP PDNG

					// RJCT UNKN

					// created / SCArequired
				*/
				$isStatusClosed = isset($transaction['payment']) && (
					$transaction['payment'] != 'created'
					|| $transaction['payment'] != 'SCArequired'
					|| $transaction['payment'] != 'UNKN'
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

				$isRejected = isset($transaction['payment']) && (
					$transaction['payment'] == 'RJCT'
					|| $transaction['payment'] == 'Rejected'
					|| $transaction['payment'] == 'RejectedCancellationRequest'
				);

			    if (($isStatusClosed || $isRejected) && $order_data['transaction_id'] == $transaction['uuid'] && $transaction['correlationId'] == $correlationId) {

					if ($isNewStatus && in_array($transaction['payment'],
							['ACCC', 'ACSP', 'ACSC', 'Accepted', 'AcceptedSettlementCompleted', 'AcceptedSettlementInProcess', 'AcceptedWithChange', 'AcceptedWithoutPosting']))
					{

						if ( $order->get_status() == 'pending' ) {
							$order->payment_complete($transaction['paymentId']);
							$order->update_status( 'processing' );
						}

						$order->add_order_note(
							sprintf(
								"%s Payment completed ✓ <br />| Settlement: `%s`",
								$bankpay->method_title,
								$transaction['payment'],
								/*$checkoutId,
								$transaction['paymentId'],
								$transaction['uuid'],*/
							)
						);
					} else if ($isNewStatus && !$isRejected) {
						// BG: ACCP, ACTC, ACWC ACWP PDNG -- OIB: AcceptedTechnicalValidation, PartiallyAcceptedTechnicalCorrect, Received, Pending

						if ( $order->get_status() == 'pending' ) {
							$order->payment_complete($transaction['paymentId']);
							$order->update_status( 'processing' );
						}

						$order->add_order_note(
							sprintf(
								"%s Payment processing <br />| Settlement: `%s` - <a target='_blank' href='https://BANKpay.plus/bank-update/%s#%s'>update</a>",
								$bankpay->method_title,
								$transaction['payment'],
								$checkoutId,
								$transaction['paymentId'],
							)
						);
					} else if ($isNewStatus && $isRejected) {
						if ( $order->get_status() == 'pending' ) {
							$order->update_status( 'on-hold' );
						}

						$order->add_order_note(
							sprintf(
								"%s Payment rejected <br />| Settlement: `%s` - <a target='_blank' href='https://BANKpay.plus/bank-update/%s#%s'>update</a>",
								$bankpay->method_title,
								$transaction['payment'],
								$checkoutId,
								$transaction['paymentId'],
							)
						);
					}

					$woocommerce->cart->empty_cart();

					$redirect_url = $order->get_view_order_url();

					/*
					// TODO activate later
					// make order id, ... available as replacement key ...
					if ($bankpay->returnUrl == '' || $bankpay->returnUrl == 0 ) {
						$redirect_url = $bankpay->get_return_url( $order );
					} else {
						$redirect_url = get_permalink( $bankpay->returnUrl );
					}
					*/

					wp_redirect( $redirect_url );
					exit;

			    } else if ($isNewStatus && $order_data['transaction_id'] == $transaction['uuid'] && $transaction['correlationId'] == $correlationId) {

					if (!isset($transaction['paymentId'])) $transaction['paymentId'] = false;
					if (!isset($transaction['uuid'])) $transaction['uuid'] = false;
					if (!isset($transaction['payment'])) $transaction['payment'] = false;

					// $order->update_status( 'on-hold' );

			    	$order->add_order_note(
			            sprintf(
			                "%s Payment failed - bank status code: `%s` - Checkout ID: <a href='https://BANKpay.plus/checkout/%s#%s'>%s</a>",
			                $bankpay->method_title,
							$transaction['payment'],
							$checkoutId,
			                $transaction['paymentId'],
			                $transaction['uuid'],
			            )
			        );

			        wc_add_notice(__( 'Transaction Error: Could not complete your payment - Checkout-ID: ' . $checkoutId . ' (' . $transaction['payment']. ')', 'woocommerce'), 'error');
			        wp_redirect( wc_get_checkout_url() ); exit;
			    }
			} catch ( Exception $e ) {

				$trxnresponsemessage = $e->getMessage();

				// $order->update_status( 'on-hold' );

				$order->add_order_note(
			            sprintf(
			                "%s Payment failed with message: '%s'",
			                $bankpay->method_title,
			                $trxnresponsemessage
			            )
			        );

				wc_add_notice(__( 'Transaction Error: Could not complete your payment' , 'woocommerce'), 'error');
		  		wp_redirect( wc_get_checkout_url() ); exit;
			}
		}
	}

	add_action( 'init', 'bankpay_api_callback_bank' );

	/**
	 * Perform HTTP request to REST endpoint
	 *
	 * @param string $action
	 * @param array  $params
	 * @param string $privateApiKey
	 *
	 * @return array
	 */
	function payRequestBankApiBank($action = '', $params = array(), $privateApiKey)
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
	function bankpayRequestBankPay($action, $params = array(), $privateApiKey)
	{
		if (!is_array($params)) {
			$params = array();
		}

		$logs = new WC_Logger();

		$logs->add( 'bankpay', __( 'BANKpay+ plugin: API Request params: ' . $action . ' -- ' . json_encode($params), 'woocommerce') );

		$responseArray = payRequestBankApiBank($action, $params, $privateApiKey);
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

/*
$order_statuses = array(
    'wc-pending'    => _x( 'Pending payment', 'Order status', 'woocommerce' ),
    'wc-processing' => _x( 'Processing', 'Order status', 'woocommerce' ),
    'wc-on-hold'    => _x( 'On hold', 'Order status', 'woocommerce' ),
    'wc-completed'  => _x( 'Completed', 'Order status', 'woocommerce' ),
    'wc-cancelled'  => _x( 'Cancelled', 'Order status', 'woocommerce' ),
    'wc-refunded'   => _x( 'Refunded', 'Order status', 'woocommerce' ),
    'wc-failed'     => _x( 'Failed', 'Order status', 'woocommerce' ),
);
*/