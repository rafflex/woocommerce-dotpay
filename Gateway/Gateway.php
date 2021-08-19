<?php

/**
*
*
* NOTICE OF LICENSE
*
* This source file is subject to the Academic Free License (AFL 3.0)
* that is bundled with this package in the file LICENSE.txt.
* It is also available through the world-wide-web at this URL:
* http://opensource.org/licenses/afl-3.0.php
* If you did not receive a copy of the license and are unable to
* obtain it through the world-wide-web, please send an email
* to tech@dotpay.pl so we can send you a copy immediately.
*
* DISCLAIMER
*
* Do not edit or add to this file if you wish to upgrade WooCommerce to newer
* versions in the future. If you wish to customize WooCommerce for your
* needs please refer to http://www.dotpay.pl for more information.
*
*  @author    Dotpay Team <tech@dotpay.pl>
*  @copyright Dotpay
*  @license   http://opensource.org/licenses/afl-3.0.php  Academic Free License (AFL 3.0)
*
*/

/**
 * Abstract gateway channel
 */
abstract class Gateway_Gateway extends Dotpay_Payment {

    /**
     * Status of order after complete payment
     */
    const STATUS_COMPLETED = 'processing';

	/**
     * Status of order after complete payment for virtual products
     */
    const STATUS_COMPLETED_VIRTUAL = 'completed';

    
    /**
     * Status of order after double complete payment
     */
    const STATUS_DOUBLE_COMPLETED = 'dp_double';

    /**
     * Status of order after double complete payment for virtual products
     */
    const STATUS_DOUBLE_COMPLETED_VIRTUAL = 'dp_double';

    
    /**
     * Status of order after failed payment
     */
    const STATUS_REJECTED = 'failed';

    /**
     * Status of order before complete payment
     */
    const STATUS_DEFAULT = 'pending';

    /**
     * Name of cash group of channels
     */
    const cashGroup = 'cash';

    /**
     * Name of transfer group of channels
     */
    const transferGroup = 'transfers';



    /**
     * Prepare gateway
     */
    public function __construct() {
        $this->id = 'dotpay';
        $this->icon = $this->getIcon();
        $this->has_fields = true;
        $this->method_title = __('DOTPAY PAYMENT', 'dotpay-payment-gateway');
        $this->description = __('Fast and secure payment via Dotpay', 'dotpay-payment-gateway');


        $this->init_form_fields();
        $this->init_settings();
        $this->enabled = ($this->isEnabled())?'yes':'no';

    }


    public function is_session_started() {
        if ( php_sapi_name() != 'cli' ) {
            if ( version_compare(phpversion(), '5.6', '>=') ) {
                return session_status() == PHP_SESSION_ACTIVE ? TRUE : FALSE;
            } else {
                return session_id() == '' ? FALSE : TRUE;
            }
        }
        return FALSE;
    }



    /**
     * Add actions to API plugin
     */
    protected function addActions() {
        add_action('woocommerce_api_'.strtolower($this->id).'_form', array($this, 'getRedirectForm'));
        add_action('woocommerce_api_'.strtolower($this->id).'_confirm', array($this, 'confirmPayment'));
        add_action('woocommerce_api_'.strtolower($this->id).'_status', array($this, 'checkStatus'));
    }

    /**
     * Return url to image with admin settings logo
     * @return string
     */
    protected function getAdminSettingsLogo() {
        $dotpay_logo_lang = ($this->getPaymentLang() == 'pl')?'pl':'en';
        return WOOCOMMERCE_DOTPAY_GATEWAY_URL . 'resources/images/Dotpay_logo_desc_'.$dotpay_logo_lang .'.png';
    }

    /**
     * Return option key for Dotpay plugin
     * @return string
     */
    public function get_option_key() {
        return $this->plugin_id . $this->id . '_settings';
    }



    /**
     * Init plugin settings and add save options action
     */
    public function init_settings() {
        parent::init_settings();
        if ( version_compare( WOOCOMMERCE_VERSION, '3.2.0', '>=' ) ) {
            add_action( 'woocommerce_update_options_payment_gateways_' . $this->id, array( &$this, 'process_admin_options' ) );
        } else {
            add_action( 'woocommerce_update_options_payment_gateways', array( &$this, 'process_admin_options' ) );
        }


    }

    /**
     * Includes payment fields for a specific channel
     */
    public function payment_fields() {
        include($this->getFormPath());
    }

    /**
     * Create payment and return details of the next action
     * @global type $woocommerce WOOCOMMERCE object
     * @param int $order_id order id
     * @return array
     */
    public function process_payment($order_id) {
        global $woocommerce;

        $order = new WC_Order($order_id);
        $woocommerce->cart->empty_cart();
        $this->setOrderId($order_id);
        
        if (null !== WC()->session->get('dotpay_payment_one_product_name')) {
            $this->setOneProductName(WC()->session->get('dotpay_payment_one_product_name'));
        }

        $sellerApi = new Dotpay_SellerApi($this->getSellerApiUrl());
        
        if($this->isChannelInGroup($this->getChannel(), array(self::cashGroup, self::transferGroup)) &&
           $sellerApi->isAccountRight($this->getApiUsername(), $this->getApiPassword(),$this->getSellerId(),self::MODULE_VERSION ) ) 
        {
            $gateway = new Gateway_Transfer();
            $redirectUrl = $gateway->generateWcApiUrl('form');
        } else {
            $redirectUrl = $this->generateWcApiUrl('form');
        }
        return array(
            'result'   => 'success',
            'redirect' => $redirectUrl
        );
    }

    /**
     * Generate url address for plugin API functionality
     * @param string $target target API function
     * @return string
     */
    protected function generateWcApiUrl($target) {
        return add_query_arg('wc-api', $this->id.'_'.$target, home_url('/'));
    }

    /**
    * Check if the status page has not been removed from Wordpress, if so, create it again
    */
        function dotpay_install_missing_pages() {
               $page = new Dotpay_Page(DOTPAY_STATUS_PNAME);
               $page->setTitle(DOTPAY_STATUS_PTITLE)
                    ->setGuid('/dotpay/order/status')
                    ->add();

               $page = new Dotpay_Page(DOTPAY_PAYINFO_PNAME);
               $page->setTitle(DOTPAY_PAYINFO_PTITLE)
                    ->setGuid('/dotpay/payment/info')
                    ->add();
        }


    /**
     * Return data for payments form
     * @return array
     */
    protected function getDataForm() {
        global $file_prefix, $woocommerce;
        if(!$this->is_session_started()) {
            session_start();
         }
        if (function_exists('wp_cache_clean_cache')) {
            wp_cache_clean_cache($file_prefix, true);
        }
        if(empty(WC()->session->get('dotpay_payment_order_id'))) {
            die(__('Order not found', 'dotpay-payment-gateway'));
        }

        $this->setOrderId(WC()->session->get('dotpay_payment_order_id'));

        if(trim($this->getUrl()) == null){
            $url_return = $this->dotpay_install_missing_pages();
        }

        if (null !== WC()->session->get('dotpay_payment_one_product_name') && $this->isProductNameTitleEnabled() == true) {
            $this->setOneProductName(WC()->session->get('dotpay_payment_one_product_name'));
            $new_description = $this->getDescription().WC()->session->get('dotpay_payment_one_product_name');
        }else{
            $new_description = $this->getDescription();
        }

        $streetData = $this->getStreetAndStreetN1();

        $dotPostForm = array(
            'id' => (string) $this->getSellerId(),
            'control' => (string) $this->getControl('full'),
            'p_info' => (string) $this->getPinfo(),
            'amount' => (string) $this->getOrderAmount(),
            'currency' => (string) $this->getCurrency(),
            'description' => (string) $new_description,
            'lang' => (string) $this->getPaymentLang(),
            'url' => (string) $this->getUrl(),
            'urlc' => (string) $this->getUrlC(),
            'api_version' => (string) $this->getApiVersion(),
            'type' => '0',
            'firstname' => (string) $this->getFirstname(),
            'lastname' => (string) $this->getLastname(),
            'email' => (string) $this->getEmail(),
            //'ignore_last_payment_channel' => '1',
            'personal_data' => '1',
            'bylaw' => '1'
        );
        

            if( null != trim($this->getPhone()))
                {
                $dotPostForm["phone"] = (string) $this->getPhone();
                }

            if( null != trim($streetData['street']))
                {
                $dotPostForm["street"] = (string) $streetData['street'];
                }

            if( null != trim($streetData['street_n1']) || "0" != trim($streetData['street_n1']))
                {
                $dotPostForm["street_n1"] = (string) $streetData['street_n1'];
                }

            if( null != trim($this->getCity()))
                {
                $dotPostForm["city"] = (string) $this->getCity();
                }

            if( null != trim($this->getPostcode()))
                {
                $dotPostForm["postcode"] = (string) $this->getPostcode();
                }

            if( null != trim($this->getPostcode()))
                {
                $dotPostForm["country"] = (string) $this->getCountry();
                }    



        if( null != $this->getCustomerBase64())
           {
            $dotPostForm["customer"] = (string) $this->getCustomerBase64();
           }

           return $dotPostForm;

    }

    /**
     * Return fields for payments form with calculated CHK
     * @return array
     */
    protected function getHiddenFields() {
        $data = $this->getDataForm();
        $this->forgetChannel();
        $this->forgetProductName();
        $data['chk'] = $this->generateCHK($this->getSellerPin(), $data);
        return $data;
    }

	/**
	 * Returns data to 'customer' parameter
	 * @return string encoded base64
	 */
	public function getCustomerBase64() {



        if ($this->getFirstname() != "" && $this->getLastname() != "" && $this->getEmail() != "" && $this->getShippingCity() != "" && $this->getShippingStreetAndStreetN1()['street'] != "" && $this->getShippingStreetAndStreetN1()['street_n1'] && $this->getShippingPostcode())
        {

            $customer = array (
                "payer" => array(
                    "first_name" => (string) $this->getFirstname(),
                    "last_name" => (string) $this->getLastname(),
                    "email" => (string) $this->getEmail()
                ),
                "order" => array(
                    "delivery_address" => array(
    
                        "city" => (string) $this->getShippingCity(),
                        "street" => (string) $this->getShippingStreetAndStreetN1()['street'],
                        "building_number" => (string) $this->getShippingStreetAndStreetN1()['street_n1'],
                        "postcode" => (string) $this->getShippingPostcode(),
                        "country" => (string) $this->getShippingCountry()
                    )
                )
            );

            if($user = $this->getOrder()->get_user()) {

                $customer["registered_since"] = (string) date("Y-m-d", strtotime($user->get('user_registered')));
                $customer["order_count"] = (string) wc_get_customer_order_count($user->ID);
            }
    
            if ($this->getPhone() != "") {
                $customer["payer"]["phone"] = (string) $this->getPhone();
            }
    
    
            if ($this->getSelectedCarrierMethodGroup() != "") {
                $customer["order"]["delivery_type"] = (string) $this->getSelectedCarrierMethodGroup();
            }
    
    
            $customer_base64 = base64_encode(json_encode($customer, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));


            return $customer_base64;

        } else {

            return null;
        }

		
	}

	protected function getSelectedCarrierMethodGroup()
	{
		$methods = $this->getOrder()->get_shipping_methods();
		$method = array_pop($methods);
		return $this->getShippingMapping($method) ? $this->getShippingMapping($method->get_instance_id()) : "";
	}

    /**
     * Check, if channel is in channels groups
     * @param int $channelId channel id
     * @param array $group names of channel groups
     * @return boolean
     */
    public function isChannelInGroup($channelId, array $groups) {
        $resultJson = $this->getDotpayChannels($this->getOrderAmount());
        if(false != $resultJson) {
            $result = json_decode($resultJson, true);
            if (isset($result['channels']) && is_array($result['channels'])) {
                foreach ($result['channels'] as $channel) {
                    if (isset($channel['group']) && $channel['id']==$channelId && in_array($channel['group'], $groups)) {
                        return true;
                    }
                }
            }
        }
        return false;
    }

    /**
     * Return flag, if this channel is enabled
     * @return bool
     */
    protected function isEnabled() {
        $result = false;
        if ('yes' == $this->get_option('enabled')) {
            $result = true;
        }

        return $result;
    }

    /**
     * Return admin prompt class name
     * @return string
     */
    protected function getAdminPromptClass() {
        $result = 'error';
        if ('yes' == $this->get_option('enabled')) {
            $result = 'updated';
        }

        return $result;
    }

    /**
     * Return flag, if main channel is enabled
     * @return boolean
     */
    protected function isMainChannelEnabled() {
        $result = true;
        if($this->isDotSelectedCurrency($this->get_option('dontview_currency'))) {
            $result = false;
        }
        return $result;
    }

    /**
     * Return flag, if Dotpay widget is enabled
     * @return boolean
     */
    protected function isWidgetEnabled() {
        $result = false;
        if ('yes' == $this->get_option('channels_show')) {
            $result = true;
        }

        return $result;
    }


        /**
     * Return flag, if  product name in payment title enabled
     * @return boolean
     */
    protected function isProductNameTitleEnabled() {
        $result = false;
        if ('yes' == $this->get_option('productname')) {
            $result = true;
        }

        return $result;
    }


    /**
     * Return flag, if One Click is enabled
     * @return boolean
     */
    protected function isOneClickEnabled() {
        $result = false;
        if ('yes' == $this->get_option('oneclick_show')) {
            $result = true;
        }

        return $result;
    }

    /**
     * Return flag, if MasterPass is enabled
     * @return boolean
     */
    protected function isMasterPassEnabled() {
        $result = false;
        if ('yes' == $this->get_option('masterpass_show')) {
            $result = true;
        }

        return $result;
    }



    /**
     * Return flag, if PayPo is enabled
     * @return boolean
     */
    protected function isPayPoEnabled($cartAmountTotal=0) {

        $result = false;

        if ('yes' == $this->get_option('paypo_show') ) {    
            $result = true;
        }

        return $result;
    }

   

    /**
     * Return flag, if Credit card PV is enabled
     * @return boolean
     */
    protected function isCcPVEnabled() {
        $result = true;
        if ('no' == $this->get_option('ccPV_show')) {
            $result = false;
        }
        if(!$this->isDotSelectedCurrency($this->get_option('ccPV_currency'))) {
            $result = false;
        }

        return $result;
    }

    /**
     * Return flag, if BLIK is enabled
     * @return boolean
     */
    protected function isBlikEnabled() {
        $result = false;
        if ('yes' == $this->get_option('blik_show')) {
            $result = true;
        }
        if($this->getCurrency() != 'PLN') {
            $result = false;
        }

        return $result;
    }

    /**
     * Return flag, if credit card is enabled
     * @return boolean
     */
    protected function isCreditCardEnabled() {
        $result = false;
        if ('yes' == $this->get_option('credit_card_show')) {
            $result = true;
        }
        return $result;
    }

    /**
     * Generate CHK for seller and payment data
     * @param type $DotpayPin Dotpay seller PIN
     * @param array $ParametersArray parameters of payment
     * @return string
     */
    
    
    ## function: counts the checksum from the defined array of all parameters

protected function generateCHK($DotpayPin, $ParametersArray)
{
    
        //sorting the parameter list
        ksort($ParametersArray);
        
        // Display the semicolon separated list
        $paramList = implode(';', array_keys($ParametersArray));
        
        //adding the parameter 'paramList' with sorted list of parameters to the array
        $ParametersArray['paramsList'] = $paramList;
        
        //re-sorting the parameter list
        ksort($ParametersArray);
        
        //json encoding  
        $json = json_encode($ParametersArray, JSON_UNESCAPED_SLASHES);

    return hash_hmac('sha256', $json, $DotpayPin, false);
   
}

    /**
     * Return url to icon file
     * @return string
     */
    protected function getIcon() {
        return '';
    }

    /**
     * Return rendered status page HTML
     * @return string
     */
    public function getStatusPage() {
        $this->message = null;
        if($this->getParam('error_code')!=false) {
            switch($this->getParam('error_code')) {
                case 'PAYMENT_EXPIRED':
                    $this->message = __('Exceeded expiration date of the generated payment link.', 'dotpay-payment-gateway');
                    break;
                case 'UNKNOWN_CHANNEL':
                    $this->message = __('Selected payment channel is unknown.', 'dotpay-payment-gateway');
                    break;
                case 'DISABLED_CHANNEL':
                    $this->message = __('Selected channel payment is desabled.', 'dotpay-payment-gateway');
                    break;
                case 'BLOCKED_ACCOUNT':
                    $this->message = __('Account is disabled.', 'dotpay-payment-gateway');
                    break;
                case 'INACTIVE_SELLER':
                    $this->message = __('Seller account is inactive.', 'dotpay-payment-gateway');
                    break;
                case 'AMOUNT_TOO_LOW':
                    $this->message = __('Amount is too low.', 'dotpay-payment-gateway');
                    break;
                case 'AMOUNT_TOO_HIGH':
                    $this->message = __('Amount is too high.', 'dotpay-payment-gateway');
                    break;
                case 'BAD_DATA_FORMAT':
                    $this->message = __('Data format is bad.', 'dotpay-payment-gateway');
                    break;
                case 'HASH_NOT_EQUAL_CHK':
                    $this->message = __('Request has been modified during transmission.', 'dotpay-payment-gateway');
                    break;
                case 'REQUIRED_PARAMS_NOT_FOUND':
                    $this->message = __('There were not given all request parameters.', 'dotpay-payment-gateway');
                    break;
                case 'URLC_INVALID':
                    $this->message = __('Account settings in Dotpay require the seller to have SSL certificate enabled on his website.', 'dotpay-payment-gateway');
                    break;
                default:
                    $this->message = __('There was an unidentified error. Please contact to your seller and give him the order number.', 'dotpay-payment-gateway');
            }
        }
        return $this->render('check_status.phtml');
    }

    /**
     * Return content of bylaw agreement
     * @return string
     */
    protected function getBylaw() {
        return $this->getDotpayAgreement($this->get_order_total(), 'bylaw');
    }

    /**
     * Return content of personal data agreement
     * @return string
     */
    protected function getPersonalData() {
        return $this->getDotpayAgreement($this->get_order_total(), 'personal_data');
    }

    /**
     * Validate fields before creation of order
     * @return boolean
     */
    public function validate_fields() {
        if($this->getParam('bylaw')!= '1' ) {
            wc_add_notice( __('Please accept all agreements', 'dotpay-payment-gateway') , 'error' );
            return false;
        }
        return true;
    }

    /**
     * Return ID of current shop client
     * @return int
     */
    protected function getCurrentUserId() {
        return wp_get_current_user()->ID;
    }

    /**
     * Display redirect form with payment details
     */
    public function getRedirectForm() {
        die($this->render('redirect_form.phtml'));
    }


       /* 
           Get Dotpay transaction number and the set status from the notes saved for the order
       */
      public function get_DpTrNumberNote($note,$what)
      {
          if($what == 'nr'){
              $regex = '/<span[^>].* id="dptrnr">(M\d\d\d\d-\d\d\d\d\d)<\/span>/';     
          }else{
             // $regex = '/<span[^>].* id="dptrst">([\p{L}a-z\s \–\-\\(\\)]{4,40})<\/span>/u';  
              $regex = '/<span[^>].* id="dptrst">(.*)<\/span>/u';    
          }
      
          preg_match($regex, $note, $idtrdp);
          
          if(isset($idtrdp[1])){
             $tr_dp = $idtrdp[1];
          }else{
               $tr_dp = null;
          } 

      return $tr_dp;
      
      }
      
     /*
      Returns unique Dotpay transaction numbers and the number of positive notifications for each payment (for one order)
     */
    

     public function count_Double_Payment($a)
      {
         
      $data = array();
      
      for ($i = 0; $i < count($a); $i++) {
             $b = $this->get_DpTrNumberNote($a[$i],'nr');
             $c = $this->get_DpTrNumberNote($a[$i],'status');
            
             // for english and polish lang (use ASCII translation of this section for it to work well)
             // if you use a different translation of the Woocommerce administration panel - complete this condition:
             if(trim($c) == 'paid : processing' || trim($c) == 'paid : completed (virtual product)' || trim($c) == 'oplacone : przetwarzane' || trim($c) == 'oplacone : zrealizowane (produkt wirtualny)')
              {
                      $data[] = $b;
              }
      }
      return array_count_values($data);
         
      }

 /**
 * Get all approved WooCommerce order notes.
 *
 * @param  int|string $order_id The order ID.
 * @return array      $notes    The order notes, or an empty array if none.
 */
function custom_get_order_notes( $order_id ) {
    remove_filter( 'comments_clauses', array( 'WC_Comments', 'exclude_order_comments' ) );
    $comments = get_comments( array(
        'post_id' => $order_id,
        'orderby' => 'comment_ID',
        'order'   => 'DESC',
        'approve' => 'approve',
        'type'    => 'order_note',
    ) );
    $notes = wp_list_pluck( $comments, 'comment_content' );
    add_filter( 'comments_clauses', array( 'WC_Comments', 'exclude_order_comments' ) );
    return $notes;
}

    /**
     * Confirm payment after getting confirmation info from Dotpay
     * @global string $wp_version version of installed instance of WordPress
     * @global type $woocommerce WOOCOMMERCE object
     */
    public function confirmPayment() {
        global $wp_version, $woocommerce;

        $dotpay_office = false;

        if($this->isProxyNotUses()) {
            if($_SERVER["REMOTE_ADDR"] == self::OFFICE_IP && strtoupper($_SERVER['REQUEST_METHOD']) == 'GET'){
                $dotpay_office = true;
            }else{
                $dotpay_office = false;
            }
        }else{
            if(($_SERVER["REMOTE_ADDR"] == self::OFFICE_IP || $this->getClientIp() == self::OFFICE_IP) && strtoupper($_SERVER['REQUEST_METHOD']) == 'GET'){
                $dotpay_office = true;
            }else{
                $dotpay_office = false;
            }
        }

        if($dotpay_office == true) {
            $sellerApi = new Dotpay_SellerApi($this->getSellerApiUrl());
            $dotpayGateways = '';
            $connection = mysqli_connect(DB_HOST, DB_USER, DB_PASSWORD, DB_NAME);
			$curlvalues = curl_version();
            foreach(self::getDotpayChannelsList() as $channel) {
                $gateway = new $channel();
                $dotpayGateways .= $gateway->id.': '.$this->checkIfEnabled($gateway)."<br />";
            }
            $shopGateways = '';
            foreach(WC_Payment_Gateways::instance()->payment_gateways() as $channel) {
                $gateway = new $channel();
                $shopGateways .= $gateway->id.': '.$this->checkIfEnabled($gateway)."<br />";
            }
            die("WooCommerce Dotpay payment module debug:<br><br>
			      * Dotpay module ver: ".self::MODULE_VERSION.
                "<br> * Wordpress ver: ". $wp_version .
                "<br> * Woocommerce ver: ". $woocommerce->version .
                "<br> * PHP ver: ". phpversion() .
                "<br> * cURL ver: ". $curlvalues['version']." ".$curlvalues['ssl_version'].
				"<br> * MySQL ver: ".  mysqli_get_server_info($connection).
                "<br>  _____________ ".
                "<br>  - Active: ".(bool)$this->isEnabled().
				"<br>  - ID: ".$this->getSellerId().
                "<br>  - Test: ".(bool)$this->isTestMode().
                "<br>  - Proxy server not uses: ".(bool)$this->isProxyNotUses().
                "<br>  - currencies_that_block_main:  ".$this->get_option('dontview_currency').
                "<br>  - is_multisite: ".(bool)is_multisite().
                "<br>  - is_plugin_active_for_network: ".(bool)is_plugin_active_for_network('woocommerce/woocommerce.php').
				"<br><br /> --- Dotpay API data: --- ".
				"<br>  - Dotpay username: ".$this->getApiUsername().
                "<br>  - correct API auth data: ".$sellerApi->isAccountRight($this->getApiUsername(), $this->getApiPassword()).
                "<br>  - URL return: ".$this->getUrl().
                "<br><br /> --- Dotpay channels: --- <br />".$dotpayGateways.
                "<br /> --- Shop channels: --- <br />".$shopGateways

            );
        }

        if($this->isProxyNotUses())
        {
            if ($this->isAllowedIp($_SERVER["REMOTE_ADDR"], self::DOTPAY_IP_WHITE_LIST) != true) {
                die("WooCommerce - ERROR (REMOTE ADDRESS: ".$this->getClientIp(true)."/".$_SERVER["REMOTE_ADDR"].")");
            }

        }else{
            
            if (($this->isAllowedIp($_SERVER["REMOTE_ADDR"], self::DOTPAY_IP_WHITE_LIST) != true) && ($this->isAllowedIp($this->getClientIp(), self::DOTPAY_IP_WHITE_LIST) != true) ) {
                die("WooCommerce - ERROR (REMOTE ADDRESS: ".$this->getClientIp(true)."/".$_SERVER["REMOTE_ADDR"].")");
            }
        }


        if (strtoupper($_SERVER['REQUEST_METHOD']) != 'POST') {
            die("WooCommerce - ERROR (METHOD <> POST)");
        }

        if (!$this->checkConfirmSign()) {
            die("WooCommerce - ERROR SIGN");
        }

        if ($this->getParam('id') != $this->getSellerId()) {
            die("WooCommerce - ERROR ID: ".$this->getSellerId());
        }


        $reg_control = '/\/id:(\d+)\|domain:/m';
        preg_match_all($reg_control, (string)$this->getParam('control'), $matches_control, PREG_SET_ORDER, 0);

        if(count($matches_control) == 1 && (isset($matches_control[0][1]) && (int)$matches_control[0][1] >0)){
    
            $controlNr =  (int)$matches_control[0][1];
        }else {

            $controlNr1 = explode('|', (string)$this->getParam('control'));
            $controlNr2 = explode('/id:', (string)$controlNr1[0]);
            if(count($controlNr2) >1) {
                $controlNr = $controlNr2[1];
            }else{
                $controlNr = $controlNr2[0];
            }
            
        }

        $controlNr = (int)trim(str_replace('#', '', $controlNr));

        $order = new WC_Order($controlNr);
        if (!$order && $order->get_id() == null && $order->get_order_number() == null) {
            die('FAIL ORDER: not exist');
        }


        $this->checkCurrency($order);
        $this->checkAmount($order);

        $status = $this->getParam('operation_status');
        $operationNR = $this->getParam('operation_number');
        $chNR = $this->getParam('channel');
        $chDataNR = $this->getChannelName($chNR);
        $PaymentChannelName = $chDataNR['name'];
        $PaymentChannelLogo= $chDataNR['logo'];
        $note = __("Dotpay send notification", 'dotpay-payment-gateway') . ": <br><span style=\"color: #4b5074; font-style: italic;\" >".__("transaction number:", 'dotpay-payment-gateway') ." <span style=\"font-weight: bold;\" id=\"dptrnr\">".$operationNR."</span>, <br>". __("payment channel:", 'dotpay-payment-gateway')." <span style=\"font-weight: bold;\">".$PaymentChannelName."</span> /<span style=\"font-weight: bold;\">".$chNR."</span>/</span><br><img src=\"".$PaymentChannelLogo."\" width=\"100px\" height=\"50px\" alt=\"".$PaymentChannelName."\"> <br><span style=\"font-weight: bold; \">status</span>: ";


        $order_status_note =  $order->needs_processing() ? __('paid : processing', 'dotpay-payment-gateway') :  __('paid : completed (virtual product)', 'dotpay-payment-gateway');

        switch ($status) {

            case 'completed':

                $order->add_order_note($note.' <span style="color: green; font-weight: bold;" id=\"dptrst\">'.$order_status_note.'</span>. <br>');
                $order->save();
                $order->update_status($order->needs_processing() ? self::STATUS_COMPLETED : self::STATUS_COMPLETED_VIRTUAL);
                usleep(500000);
                $count_double_payment1 = count($this->count_Double_Payment($this->custom_get_order_notes($order->get_id())));
                if($count_double_payment1 >1){

                    $received_notifications = "";

                    foreach($this->count_Double_Payment($this->custom_get_order_notes($order->get_id())) as $key=>$value){
                       $received_notifications .= $key." -> <span style=\"font-size: 0.8em;color: #5a4d4d;\" >". __('positive notifications:', 'dotpay-payment-gateway')."</span> ".  $value ."\n";
                    }
                    
                    $order->set_status($order->needs_processing() ? self::STATUS_DOUBLE_COMPLETED : self::STATUS_DOUBLE_COMPLETED_VIRTUAL);

                    $order->add_order_note('<span style="color: red; font-weight: bold;" >'.__('DOUBLE PAYMENT !', 'dotpay-payment-gateway').'<br>'.__('for the order no:', 'dotpay-payment-gateway').' '.$order->get_id().': <span style="background-color: yellow; padding: 2px;" >'.$count_double_payment1 .'</span></span><br>'.__('Dotpay registered under numbers:', 'dotpay-payment-gateway').' <br><span style="color: #4b5074; font-weight: bold;" >'.$received_notifications.'<br><hr> '. __('Check the posting for this order in your Dotpay panel - there is a risk that the payer has paid more than 1 time for this order.', 'dotpay-payment-gateway').'</span>');
                    $order->save();
                }


			    do_action('woocommerce_order_status_pending_to_quote', $order->get_id());
                do_action('woocommerce_payment_complete', $order->get_id());
                break;
            
            case 'rejected':

                usleep(500000);
                $count_double_payment2 = count($this->count_Double_Payment($this->custom_get_order_notes($order->get_id())));
                if($count_double_payment2 <1){
                    $order->update_status(self::STATUS_REJECTED, $note.' <span style="color: red; font-weight: bold;" id=\"dptrst\">'.__('cancelled', 'dotpay-payment-gateway').'</span>. <br>');
                }else{
                    $order->add_order_note($note.' <span style="color: red; font-weight: bold;" id=\"dptrst\">'.__('cancelled', 'dotpay-payment-gateway').'</span>. <br>');
                    $order->add_order_note('<span style="color: #4b5074; font-size:0.9em;">'.__('A message for: ','dotpay-payment-gateway').' <strong>'.$operationNR.'</strong><br>'.__('The order status has not been changed to', 'dotpay-payment-gateway') .'<span style="color: #db4444; font-weight: bold;" > '.__('cancelled', 'dotpay-payment-gateway').'</span> '.__('because the order has previously been paid for (check previous notes).', 'dotpay-payment-gateway').'<br>'.__('You can also check the accounting for this order in the Dotpay panel.', 'dotpay-payment-gateway').'<br>'.__('So it\'s current status:', 'dotpay-payment-gateway').'<span style="color: green; font-weight: bold;" id=\"dptrst\"> <br>'.$order_status_note.'</span></span>');
                    $order->save();
                }
                break;
            
            default:

            usleep(500000);
            $count_double_payment3 = count($this->count_Double_Payment($this->custom_get_order_notes($order->get_id())));

            if($count_double_payment3 <1){
                $order->update_status(self::STATUS_DEFAULT, $note.'  <span style="color: orange; font-weight: bold;" id=\"dptrst\">'.__('processing', 'dotpay-payment-gateway').'</span>. <br>');
            }else{
                $order->add_order_note($note.'  <span style="color: orange; font-weight: bold;" id=\"dptrst\">'.__('processing', 'dotpay-payment-gateway').'</span>. <br>');
                $order->add_order_note('<span style="color: #4b5074; font-size:0.9em;">'.__('A message for: ','dotpay-payment-gateway').' <strong>'.$operationNR.'</strong><br>'.__('The order status has not been changed to', 'dotpay-payment-gateway') .'<span style="color: #997024; font-weight: bold;" > '.__('processing', 'dotpay-payment-gateway').'</span> '.__('because the order has previously been paid for (check previous notes).', 'dotpay-payment-gateway').'<br>'.__('You can also check the accounting for this order in the Dotpay panel.', 'dotpay-payment-gateway').'<br>'.__('So it\'s current status:', 'dotpay-payment-gateway').'<span style="color: green; font-weight: bold;" id=\"dptrst\"> <br>'.$order_status_note.'</span></span>');
                $order->save();

            }

        }
        if($this->postConfirmOrder($order)) {
            die('OK');
        }
    }

    /**
     * Check, if payment gateway is enabled
     * @param type $object Payment gateway instance
     * @return int
     */
    private function checkIfEnabled($object) {
        return (int)$object->is_available();
    }

    /**
     * Display status number of selected order
     */
    public function checkStatus() {
        switch($this->getOrder()->get_status()) {
            case self::STATUS_COMPLETED:
                $this->forgetOrder();
                die('1');
			case self::STATUS_COMPLETED_VIRTUAL:
                $this->forgetOrder();
                die('1');
            case self::STATUS_DOUBLE_COMPLETED:
                $this->forgetOrder();
                die('1');
            case self::STATUS_DOUBLE_COMPLETED_VIRTUAL:
                $this->forgetOrder();
                die('1');
            case self::STATUS_REJECTED:
                $this->forgetOrder();
                die('-1');
            case self::STATUS_DEFAULT:
                die('0');
            default:
                die('ERROR');
        }
    }

    /**
     * Return url for check status request
     * @return string
     */
    public function getCheckStatusUrl() {
        return get_site_url(Dotpay_Page::getPageId(DOTPAY_STATUS_PNAME));
    }

    /**
     * Overrides a method from parent, because Dotpay Payment Gateway uses another method to checking if gateway is available
     * @return boolean
     */
    public function is_available() {
        return $this->isEnabled();
    }

    protected function checkConfirmSign() 
    {
       $signature = $this->getSellerPin().$this->getSellerId().
                    $this->getParam('operation_number').
                    $this->getParam('operation_type').
                    $this->getParam('operation_status').
                    $this->getParam('operation_amount').
                    $this->getParam('operation_currency').
                    $this->getParam('operation_withdrawal_amount').
                    $this->getParam('operation_commission_amount').
                    $this->getParam('is_completed').
                    $this->getParam('operation_original_amount').
                    $this->getParam('operation_original_currency').
                    $this->getParam('operation_datetime').
                    $this->getParam('operation_related_number').
                    $this->getParam('control').
                    $this->getParam('description').
                    $this->getParam('email').
                    $this->getParam('p_info').
                    $this->getParam('p_email').
                    $this->getParam('credit_card_issuer_identification_number').
                    $this->getParam('credit_card_masked_number').
                    $this->getParam('credit_card_expiration_year').
                    $this->getParam('credit_card_expiration_month').
                    $this->getParam('credit_card_brand_codename').
                    $this->getParam('credit_card_brand_code').
                    $this->getParam('credit_card_unique_identifier').
                    $this->getParam('credit_card_id').
                    $this->getParam('channel').
                    $this->getParam('channel_country').
                    $this->getParam('geoip_country').
                    $this->getParam('payer_bank_account_name').
                    $this->getParam('payer_bank_account').
                    $this->getParam('payer_transfer_title').
                    $this->getParam('blik_voucher_pin').
                    $this->getParam('blik_voucher_amount').
                    $this->getParam('blik_voucher_amount_used');

        return ($this->getParam('signature') == hash('sha256', $signature));
    }

    /**
     * Break the program, if currency in order and in confirmation are different
     * @param WC_Order $order order object
     */
    protected function checkCurrency($order) {
        $currencyOrder = $order->get_currency();
        $currencyResponse = $this->getParam('operation_original_currency');

        if ($currencyOrder != $currencyResponse) {
            die('FAIL CURRENCY (org: '.$currencyOrder.' <> notification: '.$currencyResponse.')');
        }
    }

    /**
     * Break the program, if amount in order and in confirmation are different
     * @param WC_Order $order order object
     */
    protected function checkAmount($order) {
       // $amount = $this->getFormatAmount(round($order->get_total(), 2));
       // $amountOrder = sprintf("%01.2f", $amount);
        $amountOrder = $this->normalizeDecimalAmount($order->get_total());
        $amountResponse = $this->getParam('operation_original_amount');

        if ($amountOrder != $amountResponse) {
            die('FAIL AMOUNT (org: '.$amountOrder.' <> notification: '.$amountResponse.')');
        }
    }

    protected function postConfirmOrder($order) {
        return true;
    }

    /**
     * Save channel id persistent
     * @param int $channel channel id
     */
    protected function setChannel($channel) {

        WC()->session->set('dotpay_payment_channel',(int)$channel);
    }

    /**
     * Return channel id
     * @return int/null
     */
    protected function getChannel() {

        if(null !== WC()->session->get('dotpay_payment_channel')) {    
            $channel = WC()->session->get('dotpay_payment_channel');
        } else {
            $channel = null;
        }
        return $channel;
    }

    /**
     * Forget channel id
     */
    protected function forgetChannel() {
        WC()->session->__unset( 'dotpay_payment_channel' );
    }

        /**
     * Forget product name
     */
    protected function forgetProductName() {
        WC()->session->__unset( 'dotpay_payment_one_product_name' );
    }


}
