<?php

// OPPWA API APP Configuration
$oppwa = (object) [];

// Payment API Production or test mode
$oppwa->productionMode = false;


// Merchant settings
$oppwa->merchantName = 'BANKpay+ Demo Merchant';


// OPPWA API Configurable Settings
$oppwa->clientId = false;
$oppwa->authToken = false;

// OPPWA Sandbox
$oppwa->clientId = '8a8294174b7ecb28014b9699220015ca';
$oppwa->authToken = 'OGE4Mjk0MTc0YjdlY2IyODAxNGI5Njk5MjIwMDE1Y2N8c3k2S0pzVDg=';


// Card / Wallet Settings
$oppwa->brands = 'APPLEPAY GOOGLEPAY'; // VISA VISADEBIT MASTER MASTERDEBIT
// $oppwa->checkoutResultUrl = 'https://checkout.page/xyz';
$oppwa->checkoutResultUrl = '/?page_id=8&order-received='; // '/?woo-api=callback_oppwa';


// Apple | Google Pay
$oppwa->apay = (object) [];
$oppwa->gpay = (object) [];


// Apple Pay
$oppwa->apay->displayName = $oppwa->merchantName;
$oppwa->apay->merchantId = '01234567890123456789';


// Google Pay
$oppwa->gpay->merchantName = $oppwa->merchantName;
$oppwa->gpay->merchantId = '01234567890123456789';
// Sandbox Merchant Id
$oppwa->gpay->gatewayMerchantId = '8a8294174b7ecb28014b9699220015ca';


// - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - -
// API default settings
// - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - -
// Define OPPWA based on production mode settings
if ($oppwa->productionMode === true) {
    $oppwa->apiEndpoint = 'https://eu-prod.oppwa.com/v1';
} else {
    $oppwa->apiEndpoint = 'https://eu-test.oppwa.com/v1';
}

