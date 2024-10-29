<?php

use Psr\Log\LoggerInterface as Logger;
use Psr\Log\LogLevel;

$wcLogs = false; // new Logger;

include_once dirname(__FILE__) . '/config.default.php';

if (file_exists(dirname(__FILE__) . '/config.app.php')) {
	include_once dirname(__FILE__) . '/config.app.php';
} else {
    if ($wcLogs) $wcLogs->add( 'oppwa', __( 'OPPWA Plugin: ./config.app.php is required for non-sandbox mode', true), 'woocommerce');
    error_log('OPPWA Plugin: ./config.app.php is required for non-sandbox mode.');
}



require_once dirname(__FILE__) . '/GooglePayButton/PropertiesDictionary.php';
require_once dirname(__FILE__) . '/GooglePayButton/GooglePayDataObjectHttp.php';

// require_once 'plugin/oppwa/GooglePayButton/DataToGoogleButtonScripts.php';
// require_once 'plugin/oppwa/GooglePayButton/GoogleAjaxRequests.php';
// require_once 'plugin/oppwa/GooglePayButton/GooglePayDirectHandler.php';



// OPPWA API: init a payment / checkout
function paymentInitCheckout($pay) {
    global $oppwa, $order_id, $wcLogs;

    // payment settings
    if (!$pay || !isset($pay->amount)) {
        $pay = (object) [
            "amount" => "0.01",
            "currency" => "EUR",
            "paymentType" => "DB", // PA
        ];
    }

    if (empty($pay->currency)) {
        $pay->currency = 'EUR';
    }

    if (empty($pay->paymentType)) {
        $pay->paymentType = 'DB';
    }

    $url = $oppwa->apiEndpoint . "/checkouts";

    $post_data = array(
        'entityId' => $oppwa->clientId,
        'amount' => $pay->amount,
        'currency' => $pay->currency,
        'paymentType' => $pay->paymentType,
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

    if (is_wp_error($response)) {
        if ($wcLogs) $wcLogs->add( 'oppwa', __( 'OPPWA plugin: checkout error: ' . print_r($response->get_error_messages(), true), 'woocommerce') );
        return '<div class="error"><p>'.sprintf(__('OPPWA Checkout Fehler - <a href="%s">Error-Log anzeigen</a>', 'woothemes'), admin_url('admin.php?page=wc-status&tab=logs')).'</p></div>';
    } else {
        $checkout = (object) json_decode($response['body']);
    }

    if (!empty($checkout->id) && $order_id > 0) {
        add_post_meta($order_id, '_transaction_id', $checkout->uuid);
        add_post_meta($order_id, '_oppwa_checkout_id', $checkout->uuid);
        // $checkoutId = $checkout->id;
    } else if (empty($checkout->id)) {
        if ($wcLogs) $wcLogs->add( 'oppwa', __( 'OPPWA plugin: checkout error: ' . print_r($checkout, true), 'woocommerce') );
    }

    return $checkout;
}

// OPPWA API: get payment status
function paymentStatus($checkout) {
    global $oppwa, $wcLogs;

	$url = $oppwa->apiEndpoint . "/checkouts/" . $checkout->id . "/payment";
	$url .= "?entityId=" . $oppwa->clientId; //  . "&paymentType=DB";

    $authorization = array('Authorization' => 'Bearer ' . $oppwa->authToken);

    $args = array(
        'timeout'     => '5',
        'redirection' => '5',
        'httpversion' => '1.0',
        'blocking'    => true,
        'headers'     => $authorization,
        'cookies'     => array(),
    );

    $response = wp_remote_get( $url, $args );

    if (is_wp_error($response)) {
        if ($wcLogs) $wcLogs->add( 'oppwa', __( 'OPPWA plugin: status check error: ' . print_r($response->get_error_messages(), true), 'woocommerce') );
        return '<div class="error"><p>'.sprintf(__('OPPWA Status Check Error - <a href="%s">show Error-Log</a>', 'woothemes'), admin_url('admin.php?page=wc-status&tab=logs')).'</p></div>';
    } else {
        return (object) json_decode($response['body']);
    }
}

