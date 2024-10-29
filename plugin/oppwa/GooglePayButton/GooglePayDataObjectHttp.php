<?php

declare(strict_types=1);

// namespace BankPay\WooCommerce\Buttons\GooglePayButton;

use Psr\Log\LoggerInterface as Logger;
use Psr\Log\LogLevel;

class GooglePayDataObjectHttp
{

    /**
     * @var mixed
     */
    public $nonce;
    /**
     * @var mixed
     */
    public $validationUrl;
    /**
     * @var mixed
     */
    public $simplifiedContact;
    /**
     * @var mixed|null
     */
    public $needShipping;
    /**
     * @var mixed
     */
    public $productId;
    /**
     * @var mixed
     */
    public $productQuantity;
    /**
     * @var array|mixed
     */
    public $shippingMethod;
    /**
     * @var string[]
     */
    public $billingAddress = [];
    /**
     * @var string[]
     */
    public $shippingAddress = [];
    /**
     * @var mixed
     */
    public $callerPage;

    /**
     * @var array
     */
    public $errors = [];
    /**
     * @var Logger
     */
    protected $logger;

    /**
     * GooglePayDataObjectHttp constructor.
     */
    public function __construct(/*Logger $logger*/ $logger)
    {
        $this->logger = $logger;
    }


    /**
     * Resets the errors array
     */
    protected function resetErrors()
    {
        $this->errors = [];
    }

    /**
     * Returns if the object has any errors
     * @return bool
     */
    public function hasErrors()
    {
        return !empty($this->errors);
    }

    /**
     * Set the object with the data relevant to GooglePay validation
     */
    public function validationData(array $data)
    {
        $this->resetErrors();
        if (!$this->hasRequiredFieldsValuesOrError(
            $data,
            PropertiesDictionary::VALIDATION_REQUIRED_FIELDS
        )
        ) {
            return;
        }
        $this->assignDataObjectValues($data);
    }

    /**
     * Set the object with the data relevant to GooglePay on update shipping contact
     * Required data depends on callerPage
     */
    public function updateContactData(array $data)
    {
        $result = $this->updateRequiredData(
            $data,
            PropertiesDictionary::UPDATE_CONTACT_SINGLE_PROD_REQUIRED_FIELDS,
            PropertiesDictionary::UPDATE_CONTACT_CART_REQUIRED_FIELDS
        );
        if (!$result) {
            return;
        }
        $this->updateSimplifiedContact($data[PropertiesDictionary::SIMPLIFIED_CONTACT]);
    }

    /**
     * Set the object with the data relevant to GooglePay on update shipping method
     * Required data depends on callerPage
     */
    public function updateMethodData(array $data)
    {
        $result = $this->updateRequiredData(
            $data,
            PropertiesDictionary::UPDATE_METHOD_SINGLE_PROD_REQUIRED_FIELDS,
            PropertiesDictionary::UPDATE_METHOD_CART_REQUIRED_FIELDS
        );
        if (!$result) {
            return;
        }
        $this->updateSimplifiedContact($data[PropertiesDictionary::SIMPLIFIED_CONTACT]);
        $this->updateShippingMethod($data);
    }

    /**
     * Set the object with the data relevant to GooglePay on authorized order
     * Required data depends on callerPage
     *
     * @param       $callerPage
     */
    public function orderData(array $data, $callerPage)
    {
        $logger = new WC_Logger();

        $logger->debug(
            'oderData: ' . print_r($data, true)
        );

        $data[PropertiesDictionary::CALLER_PAGE] = $callerPage;
        $result = $this->updateRequiredData(
            $data,
            PropertiesDictionary::CREATE_ORDER_SINGLE_PROD_REQUIRED_FIELDS,
            PropertiesDictionary::CREATE_ORDER_CART_REQUIRED_FIELDS
        );
        if (!$result) {
            $logger->debug(
                'oderData: error 1'
            );
            return;
        }
        if (!array_key_exists('emailAddress', $data[PropertiesDictionary::SHIPPING_CONTACT])
            || !$data[PropertiesDictionary::SHIPPING_CONTACT]['emailAddress']
        ) {
            $logger->debug(
                'oderData: error 2'
            );
            $this->errors[] =  [
                'errorCode' => PropertiesDictionary::SHIPPING_CONTACT_INVALID,
                'contactField' => 'emailAddress'
            ];

            return;
        }

        $filteredShippingContact = array_map('sanitize_text_field', $data[PropertiesDictionary::SHIPPING_CONTACT]);
        $this->shippingAddress = $this->completeAddress(
            $filteredShippingContact,
            PropertiesDictionary::SHIPPING_CONTACT_INVALID
        );
        $filteredbillingContact = array_map('sanitize_text_field', $data[PropertiesDictionary::BILLING_CONTACT]);
        $this->billingAddress = $this->completeAddress(
            $filteredbillingContact,
            PropertiesDictionary::BILLING_CONTACT_INVALID
        );

        $logger->debug(
            'oderData: complete address ' . print_r($this->shippingAddress, true)
        );

        $this->updateShippingMethod($data);
    }

    /**
     * Checks if the array contains all required fields and if those
     * are not empty.
     * If not it adds an unkown error to the object's error list, as this errors
     * are not supported by GooglePay
     *
     *
     * @return bool
     */
    protected function hasRequiredFieldsValuesOrError(array $data, array $required)
    {
        foreach ($required as $requiredField) {
            if (!array_key_exists($requiredField, $data)) {
                /*$this->logger->debug(
                    sprintf('GooglePay Data Error: Missing index %s', $requiredField)
                );*/

                $this->errors[]= ['errorCode' => 'unknown'];
                continue;
            }
            if (!$data[$requiredField]) {
                /*$this->logger->debug(
                    sprintf('GooglePay Data Error: Missing value for %s', $requiredField)
                );*/
                $this->errors[]= ['errorCode' => 'unknown'];
                continue;
            }
        }
        return !$this->hasErrors();
    }

    /**
     * Sets the value to the appropriate field in the object
     */
    protected function assignDataObjectValues(array $data)
    {
        foreach ($data as $key => $value) {
            $filterType = $this->filterType($value);
            if($key === 'woocommerce-process-checkout-nonce'){
                $key = 'nonce';
            }
            $this->$key = $filterType ? filter_var($value, $filterType) : sanitize_text_field(wp_unslash($value));
        }
    }

    /**
     * Selector for the different filters to apply to each field
     * @param $value
     *
     * @return int
     */
    protected function filterType($value)
    {
        $filterInt = [
            PropertiesDictionary::PRODUCT_QUANTITY,
            PropertiesDictionary::PRODUCT_ID
        ];
        $filterBoolean = [PropertiesDictionary::NEED_SHIPPING];
        switch ($value) {
            case in_array($value, $filterInt):
                return FILTER_SANITIZE_NUMBER_INT;
            case in_array($value, $filterBoolean):
                return FILTER_VALIDATE_BOOLEAN;
            default:
                return false;
        }
    }

    /**
     * Returns the address details used in pre-authorization steps
     * @param array $contactInfo
     *
     * @return string[]
     *
     */
    protected function simplifiedAddress($contactInfo)
    {
        $required = [
            'locality' => 'locality',
            'postalCode' => 'postalCode',
            'countryCode' => 'countryCode'
        ];
        if (!$this->addressHasRequiredFieldsValues(
            $contactInfo,
            $required,
            PropertiesDictionary::SHIPPING_CONTACT_INVALID
        )
        ) {
            return [];
        }
        return [
            'city' => $contactInfo['locality'],
            'postcode' => $contactInfo['postalCode'],
            'country' => strtoupper($contactInfo['countryCode'])
        ];
    }

    /**
     * Checks if the address array contains all required fields and if those
     * are not empty.
     * If not it adds a contacField error to the object's error list
     *
     * @param array  $post      The address to check
     * @param array  $required  The required fields for the given address
     * @param string $errorCode Either shipping or billing kind
     *
     * @return bool
     */
    protected function addressHasRequiredFieldsValues(
        array $post,
        array $required,
        $errorCode
    ) {
        $logger = new WC_Logger();

        foreach ($required as $requiredField => $errorValue) {
            if (!array_key_exists($requiredField, $post)) {
                $logger->debug(
                    sprintf('GooglePay Data Error: Missing index %s', $requiredField)
                );

                $this->errors[]= ['errorCode' => 'unknown'];
                continue;
            }
            if (!$post[$requiredField]) {
                $logger->debug(
                    sprintf('GooglePay Data Error: Missing value for %s', $requiredField)
                );
                $this->errors[]
                    = [
                    'errorCode' => $errorCode,
                    'contactField' => $errorValue
                ];
                continue;
            }
        }
        return !$this->hasErrors();
    }

    /**
     * Returns the address details for after authorization steps
     *
     * @param array  $data
     *
     * @param string $errorCode differentiates between billing and shipping information
     *
     * @return string[]
     */
    protected function completeAddress($data, $errorCode)
    {
        $required = [
            'givenName' => 'name',
            'familyName' => 'name',
            'address1' => 'address_1',
            'locality' => 'locality',
            'postalCode' => 'postalCode',
            'countryCode' => 'countryCode'
        ];

        $nameParts = explode(' ', $data['name']);

        $data['givenName'] = array_shift($nameParts);
        $data['familyName'] = implode(' ', $nameParts);

        $logger = new WC_Logger();
        $logger->debug(
            'GooglePay Data -- ' . print_r($data, true)
        );

        if (!$this->addressHasRequiredFieldsValues(
            $data,
            $required,
            $errorCode
        )
        ) {
            return [];
        }


        $adr = [
            'first_name' => sanitize_text_field(wp_unslash($data['givenName'])),
            'last_name' => sanitize_text_field(wp_unslash($data['familyName'])),
            'email' => isset($data['emailAddress']) ? sanitize_text_field(wp_unslash($data['emailAddress'])) : '',
            'phone' => isset($data['phoneNumber']) ? sanitize_text_field(wp_unslash($data['phoneNumber'])) : '',
            'address_1' => isset($data['address1'])
                ? sanitize_text_field(wp_unslash($data['address1'])) : '',
            'address_2' => isset($data['address2'])
                ? sanitize_text_field(wp_unslash($data['address2'])) : '',
            'city' => sanitize_text_field(wp_unslash($data['locality'])),
            'state' => sanitize_text_field(wp_unslash($data['administrativeArea'])),
            'postcode' => sanitize_text_field(wp_unslash($data['postalCode'])),
            'country' => strtoupper(sanitize_text_field(wp_unslash($data['countryCode']))),
        ];


        $logger->debug(
            'GooglePay Data $adr -- ' . print_r($adr, true)
        );

        return $adr;
    }

    /**
     * @param       $requiredProductFields
     * @param       $requiredCartFields
     */
    protected function updateRequiredData(array $data, $requiredProductFields, $requiredCartFields)
    {
        $this->resetErrors();
        $requiredFields = $requiredProductFields;
        if (isset($data[PropertiesDictionary::CALLER_PAGE])
            && $data[PropertiesDictionary::CALLER_PAGE] === 'cart'
        ) {
            $requiredFields = $requiredCartFields;
        }
        $hasRequiredFieldsValues = $this->hasRequiredFieldsValuesOrError(
            $data,
            $requiredFields
        );
        if (!$hasRequiredFieldsValues) {
            return false;
        }
        $this->assignDataObjectValues($data);
        return true;
    }

    /**
     * @param $data
     */
    protected function updateSimplifiedContact($data)
    {
        $simplifiedContactInfo = array_map('sanitize_text_field', $data);
        $this->simplifiedContact = $this->simplifiedAddress(
            $simplifiedContactInfo
        );
    }

    protected function updateShippingMethod(array $data)
    {
        $logger = new WC_Logger();
        $logger->debug(
            'GooglePay Data updateShippingMethod() -- ' . print_r($data, true)
        );

        if (array_key_exists(PropertiesDictionary::SHIPPING_METHOD, $data)) {
            $logger->debug(
                'GooglePay Data updateShippingMethod() ok -- ' . print_r($data[PropertiesDictionary::SHIPPING_METHOD], true)
            );
            $this->shippingMethod = array_map('sanitize_text_field', $data[PropertiesDictionary::SHIPPING_METHOD]);
        }
    }
}
