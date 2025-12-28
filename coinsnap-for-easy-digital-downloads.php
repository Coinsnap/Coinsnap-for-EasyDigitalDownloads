<?php
/*
 * Plugin Name:     Bitcoin payment for Easy Digital Downloads
 * Plugin URI:      https://www.coinsnap.io
 * Description:     With this Bitcoin payment plugin for Easy Digital Downloads you can now offer downloads for Bitcoin right in the Easy Digital Downloads plugin!
 * Version:         1.3.2
 * Author:          Coinsnap
 * Author URI:      https://coinsnap.io/
 * Text Domain:     coinsnap-for-easy-digital-downloads
 * Domain Path:     /languages
 * Requires PHP:    7.4
 * Tested up to:    6.9
 * Requires at least: 5.2
 * EDD tested up to: 3.6.3
 * EDD Pro tested up to: 3.3.5.2
 * License:         GPL2
 * License URI:     https://www.gnu.org/licenses/gpl-2.0.html
 *
 * Network:         true
 */ 

defined('ABSPATH') || exit;
if(!defined('COINSNAPEDD_PLUGIN_VERSION')){ define( 'COINSNAPEDD_PLUGIN_VERSION', '1.3.2' ); }
if(!defined('COINSNAPEDD_REFERRAL_CODE')){ define( 'COINSNAPEDD_REFERRAL_CODE', 'D18876' ); }
if(!defined('COINSNAPEDD_PHP_VERSION')){ define( 'COINSNAPEDD_PHP_VERSION', '7.4' ); }
if(!defined('COINSNAPEDD_WP_VERSION')){ define( 'COINSNAPEDD_WP_VERSION', '5.2' ); }
if(!defined('COINSNAP_SERVER_URL')){define( 'COINSNAP_SERVER_URL', 'https://app.coinsnap.io' );}
if(!defined('COINSNAP_API_PATH')){define( 'COINSNAP_API_PATH', '/api/v1/');}
if(!defined('COINSNAP_SERVER_PATH')){define( 'COINSNAP_SERVER_PATH', 'stores' );}
if(!defined('COINSNAP_CURRENCIES')){define( 'COINSNAP_CURRENCIES', array("EUR","USD","SATS","BTC","CAD","JPY","GBP","CHF","RUB") );}



require_once(dirname(__FILE__) . "/library/loader.php");

use Coinsnap\Client\Webhook;

final class CoinsnapEDD {
    private static $_instance;
    public $gateway_id      = 'coinsnap';    
    public const COINSNAP_WEBHOOK_EVENTS = ['New','Expired','Settled','Processing','Invalid'];
    public const BTCPAY_WEBHOOK_EVENTS = ['InvoiceCreated','InvoiceExpired','InvoiceSettled','InvoiceProcessing','InvoiceInvalid']; 
    
    public function __construct(){
        
        $this->coinsnap_dependencies();
        
	add_filter('edd_payment_gateways', array( $this, 'register_gateway' ), 1, 1);		
        
        add_filter('edd_load_scripts_in_footer', '__return_false');                		
        if (is_admin()) {
            add_filter('edd_settings_sections_gateways', array( $this, 'settings_sections_gateways' ), 1, 1);
            add_filter('edd_settings_gateways', array( $this, 'settings_gateways' ), 1, 1);
            add_filter('edd_gateway_settings_url_coinsnap', array( $this, 'edd_gateway_settings_url' ), 1, 1);
            add_action('admin_notices', array($this, 'coinsnap_notice'));
            add_action( 'admin_enqueue_scripts', [$this, 'enqueueAdminScripts'] );
            add_action( 'wp_ajax_coinsnapedd_connection_handler', [$this, 'coinsnapConnectionHandler'] );
            add_action( 'wp_ajax_coinsnapedd_btcpay_server_apiurl_handler', [$this, 'btcpayApiUrlHandler']);
        }
        
	add_action('edd_coinsnap_cc_form', '__return_false');        	
        add_action('edd_gateway_coinsnap', array( $this, 'coinsnap_payment' ));		    
        add_action('init', array( $this, 'process_webhook'));
        
        // Adding template redirect handling for coinsnap-for-edd-btcpay-settings-callback.
        add_action( 'template_redirect', function(){
            global $wp_query;
            $notice = new \Coinsnap\Util\Notice();
            
            // Only continue on a coinsnap-for-edd-btcpay-settings-callback request.
            if (!isset( $wp_query->query_vars['coinsnap-for-edd-btcpay-settings-callback'])) {
                return;
            }
            
            if(!isset($wp_query->query_vars['coinsnap-for-edd-btcpay-nonce']) || !wp_verify_nonce($wp_query->query_vars['coinsnap-for-edd-btcpay-nonce'],'coinsnapedd-btcpay-nonce')){
                return;
            }

            $CoinsnapBTCPaySettingsUrl = admin_url('edit.php?post_type=download&page=edd-settings&tab=gateways&section=coinsnap');

            $rawData = file_get_contents('php://input');

            $btcpay_server_url = edd_get_option( 'btcpay_server_url');
            $btcpay_api_key  = filter_input(INPUT_POST,'apiKey',FILTER_SANITIZE_FULL_SPECIAL_CHARS);

            $client = new \Coinsnap\Client\Store($btcpay_server_url,$btcpay_api_key);
            if (count($client->getStores()) < 1) {
                $messageAbort = __('Error on verifiying redirected API Key with stored BTCPay Server url. Aborting API wizard. Please try again or continue with manual setup.', 'coinsnap-for-easy-digital-downloads');
                $notice->addNotice('error', $messageAbort);
                wp_redirect($CoinsnapBTCPaySettingsUrl);
            }

            // Data does get submitted with url-encoded payload, so parse $_POST here.
            if (!empty($_POST) || wp_verify_nonce(filter_input(INPUT_POST,'wp_nonce',FILTER_SANITIZE_FULL_SPECIAL_CHARS),'-1')) {
                $data['apiKey'] = filter_input(INPUT_POST,'apiKey',FILTER_SANITIZE_FULL_SPECIAL_CHARS) ?? null;
                if(isset($_POST['permissions'])){
                    $permissions = array_map('sanitize_text_field', wp_unslash($_POST['permissions']));
                    if(is_array($permissions)){
                        foreach ($permissions as $key => $value) {
                            $data['permissions'][$key] = sanitize_text_field($permissions[$key] ?? null);
                        }
                    }
                }
            }

            if (isset($data['apiKey']) && isset($data['permissions'])) {

                $apiData = new \Coinsnap\Client\BTCPayApiAuthorization($data);
                if ($apiData->hasSingleStore() && $apiData->hasRequiredPermissions()) {

                    edd_update_option( 'btcpay_api_key', $apiData->getApiKey());
                    edd_update_option( 'btcpay_store_id', $apiData->getStoreID());
                    edd_update_option( 'coinsnap_provider', 'btcpay');

                    $notice->addNotice('success', __('Successfully received api key and store id from BTCPay Server API. Please finish setup by saving this settings form.', 'coinsnap-for-easy-digital-downloads'));

                    // Register a webhook.

                    if ($this->registerWebhook($btcpay_server_url, $apiData->getApiKey(), $apiData->getStoreID())) {
                        $messageWebhookSuccess = __( 'Successfully registered a new webhook on BTCPay Server.', 'coinsnap-for-easy-digital-downloads' );
                        $notice->addNotice('success', $messageWebhookSuccess, true );
                    }
                    else {
                        $messageWebhookError = __( 'Could not register a new webhook on the store.', 'coinsnap-for-easy-digital-downloads' );
                        $notice->addNotice('error', $messageWebhookError );
                    }
                    wp_redirect($CoinsnapBTCPaySettingsUrl);
                    exit();
                }
                else {
                    $notice->addNotice('error', __('Please make sure you only select one store on the BTCPay API authorization page.', 'coinsnap-for-easy-digital-downloads'));
                    wp_redirect($CoinsnapBTCPaySettingsUrl);
                    exit();
                }
            }

            $notice->addNotice('error', __('Error processing the data from Coinsnap. Please try again.', 'coinsnap-for-easy-digital-downloads'));
            wp_redirect($CoinsnapBTCPaySettingsUrl);
        });
    }
    
    public function coinsnapConnectionHandler(){
        
        $_nonce = filter_input(INPUT_POST,'_wpnonce',FILTER_SANITIZE_FULL_SPECIAL_CHARS);
        
        if(empty($this->getApiUrl()) || empty($this->getApiKey())){
            $response = [
                    'result' => false,
                    'message' => __('EasyDigitalDownloads: empty gateway URL or API Key', 'coinsnap-for-easy-digital-downloads')
            ];
            $this->sendJsonResponse($response);
        }
        
        $_provider = $this->get_payment_provider();
        $client = new \Coinsnap\Client\Invoice($this->getApiUrl(),$this->getApiKey());
        $store = new \Coinsnap\Client\Store($this->getApiUrl(),$this->getApiKey());
        $currency = edd_get_currency();
        
        
        if($_provider === 'btcpay'){
            try {
                $storePaymentMethods = $store->getStorePaymentMethods($this->getStoreId());

                if ($storePaymentMethods['code'] === 200) {
                    if($storePaymentMethods['result']['onchain'] && !$storePaymentMethods['result']['lightning']){
                        $checkInvoice = $client->checkPaymentData(0,$currency,'bitcoin','calculation');
                    }
                    elseif($storePaymentMethods['result']['lightning']){
                        $checkInvoice = $client->checkPaymentData(0,$currency,'lightning','calculation');
                    }
                }
            }
            catch (\Exception $e) {
                $response = [
                        'result' => false,
                        'message' => __('EasyDigitalDownloads: API connection is not established', 'coinsnap-for-easy-digital-downloads')
                ];
                $this->sendJsonResponse($response);
            }
        }
        else {
            $checkInvoice = $client->checkPaymentData(0,$currency,'coinsnap','calculation');
        }
        
        if(isset($checkInvoice) && $checkInvoice['result']){
            $connectionData = __('Min order amount is', 'coinsnap-for-easy-digital-downloads') .' '. $checkInvoice['min_value'].' '.$currency;
        }
        else {
            $connectionData = __('No payment method is configured', 'coinsnap-for-easy-digital-downloads');
        }
        
        $_message_disconnected = ($_provider !== 'btcpay')? 
            __('EasyDigitalDownloads: Coinsnap server is disconnected', 'coinsnap-for-easy-digital-downloads') :
            __('EasyDigitalDownloads: BTCPay server is disconnected', 'coinsnap-for-easy-digital-downloads');
        $_message_connected = ($_provider !== 'btcpay')?
            __('EasyDigitalDownloads: Coinsnap server is connected', 'coinsnap-for-easy-digital-downloads') : 
            __('EasyDigitalDownloads: BTCPay server is connected', 'coinsnap-for-easy-digital-downloads');
        
        if( wp_verify_nonce($_nonce,'coinsnapedd-ajax-nonce') ){
            $response = ['result' => false,'message' => $_message_disconnected];

            try {
                $this_store = $store->getStore($this->getStoreId());
                
                if ($this_store->getData()['code'] !== 200) {
                    $this->sendJsonResponse($response);
                }
                
                $webhookExists = $this->webhookExists($this->getApiUrl(), $this->getApiKey(), $this->getStoreId());

                if($webhookExists) {
                    $response = ['result' => true,'message' => $_message_connected.' ('.$connectionData.')'];
                    $this->sendJsonResponse($response);
                }

                $webhook = $this->registerWebhook( $this->getApiUrl(), $this->getApiKey(), $this->getStoreId());
                $response['result'] = (bool)$webhook;
                $response['message'] = $webhook ? $_message_connected.' ('.$connectionData.')' : $_message_disconnected.' (Webhook)';
            }
            catch (\Exception $e) {
                $response['message'] =  __('EasyDigitalDownloads: API connection is not established', 'coinsnap-for-easy-digital-downloads');
            }

            $this->sendJsonResponse($response);
        }      
    }

    private function sendJsonResponse(array $response): void {
        echo wp_json_encode($response);
        exit();
    }
    
    public function enqueueAdminScripts() {
	// Register the CSS file
	wp_register_style( 'coinsnapedd-admin-styles', plugins_url('assets/css/backend-style.css', __FILE__ ), array(), COINSNAPEDD_PLUGIN_VERSION );
	// Enqueue the CSS file
	wp_enqueue_style( 'coinsnapedd-admin-styles' );
        //  Enqueue admin fileds handler script
        wp_enqueue_script('coinsnapedd-admin-fields',plugins_url('assets/js/adminFields.js', __FILE__ ),[ 'jquery' ],COINSNAPEDD_PLUGIN_VERSION,true);
        wp_enqueue_script('coinsnapedd-connection-check',plugins_url('assets/js/connectionCheck.js', __FILE__ ),[ 'jquery' ],COINSNAPEDD_PLUGIN_VERSION,true);
        wp_localize_script('coinsnapedd-connection-check', 'coinsnapedd_ajax', array(
            'ajax_url' => admin_url('admin-ajax.php'),
            'nonce'  => wp_create_nonce( 'coinsnapedd-ajax-nonce' )
        ));
    }
    
    /**
     * Handles the BTCPay server AJAX callback from the settings form.
     */
    public function btcpayApiUrlHandler() {
        $_nonce = filter_input(INPUT_POST,'apiNonce',FILTER_SANITIZE_FULL_SPECIAL_CHARS);
        if ( !wp_verify_nonce( $_nonce, 'coinsnapedd-ajax-nonce' ) ) {
            wp_die('Unauthorized!', '', ['response' => 401]);
        }
        
        if ( current_user_can( 'manage_options' ) ) {
            $host = filter_var(filter_input(INPUT_POST,'host',FILTER_SANITIZE_FULL_SPECIAL_CHARS), FILTER_VALIDATE_URL);

            if ($host === false || (substr( $host, 0, 7 ) !== "http://" && substr( $host, 0, 8 ) !== "https://")) {
                wp_send_json_error("Error validating BTCPayServer URL.");
            }

            $permissions = array_merge([
		'btcpay.store.canviewinvoices',
		'btcpay.store.cancreateinvoice',
		'btcpay.store.canviewstoresettings',
		'btcpay.store.canmodifyinvoices'
            ],
            [
		'btcpay.store.cancreatenonapprovedpullpayments',
		'btcpay.store.webhooks.canmodifywebhooks',
            ]);

            try {
		// Create the redirect url to BTCPay instance.
		$url = \Coinsnap\Client\BTCPayApiKey::getAuthorizeUrl(
                    $host,
                    $permissions,
                    'Easy Digital Downloads',
                    true,
                    true,
                    home_url('?coinsnap-for-edd-btcpay-settings-callback'),
                    null
		);

		// Store the host to options before we leave the site.
		edd_update_option('btcpay_server_url', $host);

		// Return the redirect url.
		wp_send_json_success(['url' => $url]);
            }
            
            catch (\Throwable $e) {
                
            }
	}
        wp_send_json_error("Error processing Ajax request.");
    }
    
    
    //  Checks if PHP version is too low or WooCommerce is not installed or CURL is not available and displays notice on admin dashboard
    public function coinsnap_dependencies() {
        // Checks PHP version.
	if ( version_compare( PHP_VERSION, COINSNAPEDD_PHP_VERSION, '<' ) ) {
            add_action( 'admin_notices', array( self::$instance, 'coinsnap_php_version_notice' ) );
            return;
	}

	// Checks if EDD is installed.
	if ( ! class_exists( 'Easy_Digital_Downloads' ) ) {
            add_action( 'admin_notices', array( self::$instance, 'coinsnap_edd_notice' ) );
            return;
	}

	// Checks WP version.
	if ( version_compare( get_bloginfo( 'version' ), COINSNAPEDD_WP_VERSION, '<') ) {
            add_action( 'admin_notices', array( self::$instance, 'coinsnap_wp_version_notice' ) );
            return;
	}
    }
    
    public function coinsnap_php_version_notice() {
        $message = sprintf( 
            /* translators: 1: PHP version */
            __( 'Your PHP version is %1$s but Coinsnap Payment plugin requires version 7.4+.', 'coinsnap-for-easy-digital-downloads' ), PHP_VERSION );
        add_settings_error( 'edd-notices', 'edd-coinsnap-edd-notice', $message, 'error' );
        settings_errors( 'edd-notices' );
    }
    
    public function coinsnap_edd_notice() {
        $message = __( 'Easy Digital Downloads Payment Gateway by Coinsnap add-on requires Easy Digital Downloads or Easy Digital Downloads Pro plugin. Please install and activate it.', 'coinsnap-for-easy-digital-downloads' );
        add_settings_error( 'edd-notices', 'edd-coinsnap-php-version-notice', $message, 'error' );
        settings_errors( 'edd-notices' );
    }
    
    public function coinsnap_wp_version_notice() {
        $message = sprintf( 
            /* translators: 1: Current WP version, 2: Required WP version  */
            __( 'Your Wordpress version is %1$s but Coinsnap Payment add-on requires version %2$s', 'coinsnap-for-easy-digital-downloads' ), get_bloginfo( 'version' ), COINSNAPEDD_WP_VERSION );
        add_settings_error( 'edd-notices', 'edd-coinsnap-wp-version-notice', $message, 'error' );
        settings_errors( 'edd-notices' );
    }
    
    public function coinsnap_notice(){
        
        $notice = new \Coinsnap\Util\Notice();
        $notice->showNotices();
        
        $page = (filter_input(INPUT_GET,'page',FILTER_SANITIZE_FULL_SPECIAL_CHARS ))? filter_input(INPUT_GET,'page',FILTER_SANITIZE_FULL_SPECIAL_CHARS ) : '';
        $tab = (filter_input(INPUT_GET,'tab',FILTER_SANITIZE_FULL_SPECIAL_CHARS ))? filter_input(INPUT_GET,'tab',FILTER_SANITIZE_FULL_SPECIAL_CHARS ) : '';
        
        if($page === 'edd-settings' && $tab === 'gateways' && !isset($_COOKIE['coinsnap_notices'])){
        
            $coinsnap_url = $this->getApiUrl();
            $coinsnap_api_key = $this->getApiKey();
            $coinsnap_store_id = $this->getStoreId();
                
                if(!isset($coinsnap_store_id) || empty($coinsnap_store_id)){
                    echo '<div class="notice notice-error"><p>';
                    esc_html_e('EasyDigitalDownloads: Coinsnap Store ID is not set', 'coinsnap-for-easy-digital-downloads');
                    echo '</p></div>';
                }

                if(!isset($coinsnap_api_key) || empty($coinsnap_api_key)){
                    echo '<div class="notice notice-error"><p>';
                    esc_html_e('EasyDigitalDownloads: Coinsnap API Key is not set', 'coinsnap-for-easy-digital-downloads');
                    echo '</p></div>';
                }
                
                if(!empty($coinsnap_api_key) && !empty($coinsnap_store_id)){
                    $client = new \Coinsnap\Client\Store($coinsnap_url, $coinsnap_api_key);
                    try {
                        
                        $store = $client->getStore($coinsnap_store_id);
                        if ($store->getData()['code'] === 200) {
                            echo '<div class="notice notice-success edd-notice"><p>';
                            esc_html_e('EasyDigitalDownloads: Established connection to Coinsnap Server', 'coinsnap-for-easy-digital-downloads');
                            echo '</p></div>';

                            if ( !$this->webhookExists( $coinsnap_url, $coinsnap_api_key, $coinsnap_store_id ) ) {
                                if ( !$this->registerWebhook( $coinsnap_url, $coinsnap_api_key, $coinsnap_store_id ) ) {
                                    echo '<div class="notice notice-error edd-notice"><p>';
                                    esc_html_e('EasyDigitalDownloads: Unable to create webhook on Coinsnap Server', 'coinsnap-for-easy-digital-downloads');
                                    echo '</p></div>';
                                }
                                else {
                                    echo '<div class="notice notice-success edd-notice"><p>';
                                    esc_html_e('EasyDigitalDownloads: Successfully registered a new webhook on Coinsnap Server', 'coinsnap-for-easy-digital-downloads');
                                    echo '</p></div>';
                                }
                            }
                            else {
                                echo '<div class="notice notice-info edd-notice"><p>';
                                esc_html_e('EasyDigitalDownloads: Webhook already exists, skipping webhook creation', 'coinsnap-for-easy-digital-downloads');
                                echo '</p></div>';
                            }
                        }
                        else {
                            echo '<div class="notice notice-error edd-notice"><p>';
                            esc_html_e('EasyDigitalDownloads: Coinsnap connection error:', 'coinsnap-for-easy-digital-downloads');
                            echo esc_html($store['result']['message']);
                            echo '</p></div>';
                        }
                        
                    }
                    catch (\Throwable $e){
                        echo '<div class="notice notice-error edd-notice"><p>';
                        esc_html_e('EasyDigitalDownloads: Coinsnap connection error:', 'coinsnap-for-easy-digital-downloads');
                        echo esc_html($e->getMessage());
                        echo '</p></div>';
                    }                    
                }
        }
    }
   
    public function edd_gateway_settings_url(){
        $gateway_settings_url = edd_get_admin_url(
            array(
                'page'    => 'edd-settings',
                'tab'     => 'gateways',
                'section' => 'coinsnap',
            )
        );
        return $gateway_settings_url;
    }

    public static function getInstance(){
        if (! isset(self::$_instance) && ! (self::$_instance instanceof CoinsnapEDD)) {
            self::$_instance = new CoinsnapEDD;
        }

        return self::$_instance;
    }
   
    public function register_gateway($gateways){
        $gateways[$this->gateway_id] = array(
                'admin_label'    => __('Coinsnap', 'coinsnap-for-easy-digital-downloads'),
                'checkout_label' => __('Bitcoin + Lightning', 'coinsnap-for-easy-digital-downloads'),
                'supports'       => array( 'buy_now' )
            );

        return $gateways;
    }
    
    public function registerPaymenticon($payment_icons){
        $payment_icons['coinsnap'] = 'Coinsnap';
	return $payment_icons;
    }
    
    public function settings_sections_gateways($gateway_sections){
        if (edd_is_gateway_active($this->gateway_id)) {
            $gateway_sections['coinsnap'] = __('Coinsnap', 'coinsnap-for-easy-digital-downloads');
	}
        return $gateway_sections;
    }

    
    public function settings_gateways($gateway_settings){
        $edd_statuses = edd_get_payment_statuses();
        $default_coinsnap_settings = array(
            'coinsnap' => array(
                'id'   => 'coinsnap',
                'name' => '<strong>' . __('Coinsnap Settings', 'coinsnap-for-easy-digital-downloads') . '</strong>',
                'type' => 'header',
                'desc' => '',
            ),
            
            'coinsnap_connection' => array(
                'id'   => 'coinsnap',
                'name'  => __( 'Connection Status', 'coinsnap-for-easy-digital-downloads' ),
                'type' => 'descriptive_text',
                'desc' => '<div id="coinsnapConnectionStatus"></div>',
            ),
            
            'coinsnap_provider' => array(
                    'id'   => 'coinsnap_provider',
                    'name' => __( 'Payment provider', 'coinsnap-for-easy-digital-downloads' ),
                    'desc' => __( 'Select payment provider', 'coinsnap-for-easy-digital-downloads' ),
                    'type'        => 'select',
                    'options'   => [
                        'coinsnap'  => 'Coinsnap',
                        'btcpay'    => 'BTCPay Server'
                    ]
                ),
            
            //  Coinsnap fields
            'coinsnap_store_id' => array(
                'id'   => 'coinsnap_store_id',
                'name' => __('Store ID', 'coinsnap-for-easy-digital-downloads'),
                'desc' => __('Enter Store ID Given by Coinsnap', 'coinsnap-for-easy-digital-downloads'),
                'type' => 'text',
                'size' => 'regular',
                'class' => 'coinsnap',
            ),
            'coinsnap_api_key' => array(
                'id'   => 'coinsnap_api_key',
                'name' => __('API Key', 'coinsnap-for-easy-digital-downloads'),
                'desc' => __('Enter API Key Given by Coinsnap', 'coinsnap-for-easy-digital-downloads'),
                'type' => 'text',
                'size' => 'regular',
                'class' => 'coinsnap',
            ),
            
            //  BTCPay fields
            'btcpay_server_url' => array(
                    'id' => 'btcpay_server_url',
                    'name'       => __( 'BTCPay server URL*', 'coinsnap-for-easy-digital-downloads' ),
                    'type'        => 'text',
                    'desc'        => __( '<a href="#" class="btcpay-apikey-link">Check connection</a>', 'coinsnap-for-easy-digital-downloads' ).'<br/><br/><button class="button btcpay-apikey-link" type="button" id="pmpro_btcpay_wizard_button" target="_blank">'. __('Generate API key','coinsnap-for-easy-digital-downloads').'</button>',
                    'std'     => '',
                'size' => 'regular',
                    'class' => 'btcpay'
                ),
            
            'btcpay_store_id' => array(
                    'id'   => 'btcpay_store_id',
                    'name' => __( 'Store ID*', 'coinsnap-for-easy-digital-downloads' ),
                    'desc' => __( 'Enter Store ID', 'coinsnap-for-easy-digital-downloads' ),
                    'type' => 'text',
                    'std'     => '',
                'size' => 'regular',
                    'class' => 'btcpay'
                ),
            'btcpay_api_key' => array(
                    'id'   => 'btcpay_api_key',
                    'name' => __( 'API Key*', 'coinsnap-for-easy-digital-downloads' ),
                    'desc' => __( 'Enter API Key', 'coinsnap-for-easy-digital-downloads' ),
                    'type' => 'text',
                    'std'     => '',
                'size' => 'regular',
                    'class' => 'btcpay'
                ),
            
            'coinsnap_autoredirect' => array(
                'id'   => 'coinsnap_autoredirect',
                'name' => __('Redirect after payment', 'coinsnap-for-easy-digital-downloads'),
                'desc' => __('Redirect after payment on Thank you page automatically', 'coinsnap-for-easy-digital-downloads'),
                'type' => 'checkbox',
                'value' => 1,
                'std' => 1
            ),
            'coinsnap_returnurl' => array(
                'id'   => 'coinsnap_returnurl',
                'name' => __('Return URL after payment', 'coinsnap-for-easy-digital-downloads'),
                'desc' => __('Custom return URL after successful payment (default URL if blank)', 'coinsnap-for-easy-digital-downloads'),
                'type' => 'text',
                'size' => 'regular',
            ),
            'expired_status' => array(
                'id'   => 'expired_status',
                'name'       => __( 'Expired Status', 'coinsnap-for-easy-digital-downloads' ),
                'type'        => 'select',
                'std'     => 'failed',										
                'desc' => 'select which status is Expired',
                'options'     => $edd_statuses,
            ),	
            'settled_status' => array(
                'id'   => 'settled_status',
                'name'       => __( 'Settled Status', 'coinsnap-for-easy-digital-downloads' ),
                'type'        => 'select',
                'std'     => 'complete',										
                'desc' => 'select which status is settled',
                'options'     => $edd_statuses,
            ),	
            'processing_status' => array(
                'id'   => 'processing_status',
                'name'       => __( 'Processing Status', 'coinsnap-for-easy-digital-downloads' ),
                'type'        => 'select',
                'std'     => 'complete',										
                'desc' => 'select which status is processing',
                'options'     => $edd_statuses,
            ),	
            
        );

        $gateway_settings['coinsnap'] = apply_filters('edd_default_coinsnap_settings', $default_coinsnap_settings);
        return $gateway_settings;
    }
    
    public function coinsnapedd_amount_validation( $amount, $currency ) {
        $client =new \Coinsnap\Client\Invoice($this->getApiUrl(), $this->getApiKey());
        $store = new \Coinsnap\Client\Store($this->getApiUrl(), $this->getApiKey());
        
        try {
            $_provider = $this->get_payment_provider();
            if($_provider === 'btcpay'){
                try {
                    $storePaymentMethods = $store->getStorePaymentMethods($this->getStoreId());

                    if ($storePaymentMethods['code'] === 200) {
                        if(!$storePaymentMethods['result']['onchain'] && !$storePaymentMethods['result']['lightning']){
                            $errorMessage = __( 'No payment method is configured on BTCPay server', 'coinsnap-for-easy-digital-downloads' );
                            $checkInvoice = array('result' => false,'error' => esc_html($errorMessage));
                        }
                    }
                    else {
                        $errorMessage = __( 'Error store loading. Wrong or empty Store ID', 'coinsnap-for-easy-digital-downloads' );
                        $checkInvoice = array('result' => false,'error' => esc_html($errorMessage));
                    }

                    if($storePaymentMethods['result']['onchain'] && !$storePaymentMethods['result']['lightning']){
                        $checkInvoice = $client->checkPaymentData((float)$amount,strtoupper( $currency ),'bitcoin');
                    }
                    elseif($storePaymentMethods['result']['lightning']){
                        $checkInvoice = $client->checkPaymentData((float)$amount,strtoupper( $currency ),'lightning');
                    }
                }
                catch (\Throwable $e){
                    $errorMessage = __( 'API connection is not established', 'coinsnap-for-easy-digital-downloads' );
                    $checkInvoice = array('result' => false,'error' => esc_html($errorMessage));
                }
            }
            else {
                $checkInvoice = $client->checkPaymentData((float)$amount,strtoupper( $currency ));
            }
        }
        catch (\Throwable $e){
            $errorMessage = __( 'API connection is not established', 'coinsnap-for-easy-digital-downloads' );
            $checkInvoice = array('result' => false,'error' => esc_html($errorMessage));
        }
        return $checkInvoice;
    }
    
    public function coinsnap_payment($purchase_data){
        global $edd_options;
        
        if (! wp_verify_nonce($purchase_data['gateway_nonce'], 'edd-gateway')) {
            wp_die(
                esc_html__('Nonce verification has failed', 'coinsnap-for-easy-digital-downloads'),
                esc_html__('Error', 'coinsnap-for-easy-digital-downloads'),
                array( 'response' => 403 )
            );
        }        
        
        if (! $this->webhookExists($this->getApiUrl(), $this->getApiKey(), $this->getStoreId())){
            if (! $this->registerWebhook($this->getApiUrl(), $this->getApiKey(),$this->getStoreId())){
                
                edd_record_gateway_error(
                    esc_html__('Connection Error', 'coinsnap-for-easy-digital-downloads'), 
                    esc_html__('Unable to set Webhook url', 'coinsnap-for-easy-digital-downloads'),
                    $payment_id);
                edd_set_error(esc_html__('Connection Error', 'coinsnap-for-easy-digital-downloads'), esc_html__('Unable to set Webhook url', 'coinsnap-for-easy-digital-downloads'));    
                edd_send_back_to_checkout('?payment-mode=' . $purchase_data['post_data']['edd-gateway']);
            }
        }
        
        $payment_data = array(
            'price'         => $purchase_data['price'],
            'date'          => $purchase_data['date'],
            'user_email'    => $purchase_data['user_email'],
            'purchase_key'  => $purchase_data['purchase_key'],
            'currency'      => edd_get_currency(),
            'downloads'     => $purchase_data['downloads'],
            'user_info'     => $purchase_data['user_info'],
            'cart_details'  => $purchase_data['cart_details'],
            'gateway'       => 'coinsnap',
            'status'        => ! empty($purchase_data['buy_now']) ? 'private' : 'pending'
        );
        
        
        

        $payment_id = edd_insert_payment($payment_data);
        
        
        
        if (! $payment_id) {
            $errorMessage = sprintf(
                /* translators: 1: Payment data */
                esc_html__('Payment creation failed before sending buyer to Coinsnap. Payment data: %1$s', 'coinsnap-for-easy-digital-downloads'), wp_json_encode($payment_data));
            edd_record_gateway_error(esc_html__('Payment Error', 'coinsnap-for-easy-digital-downloads'), $errorMessage, $payment_id);
            edd_set_error(esc_html__('Payment Error', 'coinsnap-for-easy-digital-downloads'), $errorMessage);    
            edd_send_back_to_checkout('?payment-mode=' . $purchase_data['post_data']['edd-gateway']);
        }
        
        else {            
            $amount = round($purchase_data['price'], 2);
            $currency = strtoupper(edd_get_currency());
            
            $client = new \Coinsnap\Client\Invoice($this->getApiUrl(), $this->getApiKey());
            
            $checkInvoice = $this->coinsnapedd_amount_validation($amount,$currency);
                
            if($checkInvoice['result']){
            
		$redirectUrl = (!empty(edd_get_option('coinsnap_returnurl')))? edd_get_option('coinsnap_returnurl') : edd_get_success_page_uri();
                $buyerEmail = isset($purchase_data['user_email']) ? $purchase_data['user_email'] : $purchase_data['user_info']['email'];
		$buyerName =  $purchase_data['user_info']['first_name'] . ' ' . $purchase_data['user_info']['last_name'];
		    		
                $metadata = [];
    		$metadata['orderNumber'] = $payment_id;
                $metadata['customerName'] = $buyerName;
                
                if($this->get_payment_provider() === 'btcpay') {
                    $metadata['orderId'] = $payment_id;
                }
                
                if($this->get_payment_provider() === 'btcpay' && $currency !== 'BTC'){
                    $store = new \Coinsnap\Client\Store($this->getApiUrl(), $this->getApiKey());
                    $btcpayCurrencies = $store -> getStoreCurrenciesRates($this->getStoreId(),array($currency));
                    $isCurrency = true;
                    if(!isset($btcpayCurrencies['result']['error']) && count($btcpayCurrencies['result']['currencies'])>0){
                        if(!isset($btcpayCurrencies['result']['currencies']['BTC_'.$currency])){
                            $isCurrency = false;
                        }
                    }
                    else {
                        $isCurrency = false;
                    }
                    
                    // Handle currencies non-supported by BTCPay Server, we need to change them BTC and adjust the amount.
                    if( !$isCurrency ){
                        $currency = 'BTC';
                        $rate = 1/$checkInvoice['rate'];
                        $amountBTC = bcdiv(strval($amount), strval($rate), 8);
                        $amount = (float)$amountBTC;
                        
                        edd_record_gateway_error(esc_html__('Currency error', 'coinsnap-for-easy-digital-downloads'), json_encode($btcpayCurrencies['result']), $payment_id);
                    }
                }
                
                $camount = ($currency === 'BTC')? \Coinsnap\Util\PreciseNumber::parseFloat($amount,8) : \Coinsnap\Util\PreciseNumber::parseFloat($amount,2);
            
                $redirectAutomatically = (edd_get_option( 'coinsnap_autoredirect', '' ) > 0)? true : false;
                $walletMessage = '';
		
                $invoice = $client->createInvoice(
                    $this->getStoreId(),  
                    $currency,
                    $camount,
                    $payment_id,
                    $buyerEmail,
                    $buyerName, 
                    $redirectUrl,
                    COINSNAPEDD_REFERRAL_CODE,     
                    $metadata,
                    $redirectAutomatically,
                    $walletMessage
		);
                
                $payurl = $invoice->getData()['checkoutLink'] ;	
                
                if($payurl) {	                                
                     wp_redirect($payurl);
                     exit;
                }
                
                else {
                    $errorMessage = sprintf(
                        /* translators: 1: Payment data */    
                        esc_html__('Payment creation failed before sending buyer to Coinsnap. Payment data: %1$s', 'coinsnap-for-easy-digital-downloads'), wp_json_encode($payment_data));
                    edd_record_gateway_error(esc_html__('Payment Error', 'coinsnap-for-easy-digital-downloads'), $errorMessage, $payment_id);
                    edd_set_error(esc_html__('Payment Error', 'coinsnap-for-easy-digital-downloads'), $errorMessage);    
                    edd_send_back_to_checkout('?payment-mode=' . $purchase_data['post_data']['edd-gateway']);
                }
            }
            else {
                
                if($checkInvoice['error'] === 'currencyError'){
                    $errorMessage = sprintf( 
                    /* translators: 1: Currency */
                    __( 'Currency %1$s is not supported by Coinsnap', 'coinsnap-for-easy-digital-downloads' ), strtoupper( $currency ));
                }      
                elseif($checkInvoice['error'] === 'amountError'){
                    $errorMessage = sprintf( 
                    /* translators: 1: Amount, 2: Currency */
                    __( 'Invoice amount cannot be less than %1$s %2$s', 'coinsnap-for-easy-digital-downloads' ), $checkInvoice['min_value'], strtoupper( $currency ));
                }
                else {
                    $errorMessage = $checkInvoice['error'];
                }
                edd_record_gateway_error(esc_html__('Payment Error', 'coinsnap-for-easy-digital-downloads'), $errorMessage, $payment_id);
                edd_set_error(esc_html__('Payment Error', 'coinsnap-for-easy-digital-downloads'), $errorMessage);
                edd_send_back_to_checkout('?payment-mode=' . $purchase_data['post_data']['edd-gateway']);
            }
        }
    }

    public function process_webhook() {
        if (null === ( filter_input(INPUT_GET,'edd-listener',FILTER_SANITIZE_FULL_SPECIAL_CHARS) ) || filter_input(INPUT_GET,'edd-listener',FILTER_SANITIZE_FULL_SPECIAL_CHARS) !== 'coinsnap'){
            return;
        }
        
        try {
            // First check if we have any input
            $rawPostData = file_get_contents("php://input");
            if (!$rawPostData) {
                    wp_die('No raw post data received', '', ['response' => 400]);
            }

            // Get headers and check for signature
            $headers = getallheaders();
            $signature = null; $payloadKey = null;
                
            foreach ($headers as $key => $value) {
                if (strtolower($key) === 'x-coinsnap-sig' || strtolower($key) === 'btcpay-sig') {
                    $signature = $value;
                    $payloadKey = strtolower($key);
                }
            }

            // Handle missing or invalid signature
            if ($signature === null) {
                wp_die('Authentication required', '', ['response' => 401]);
            }

            // Validate the signature
            $webhook = get_option( 'edd_settings_coinsnap_webhook');
            if (!Webhook::isIncomingWebhookRequestValid($rawPostData, $signature, $webhook['secret'])) {
                wp_die('Invalid authentication signature', '', ['response' => 401]);
            }
            
            try{
                // Parse the JSON payload
                $postData = json_decode($rawPostData, false, 512, JSON_THROW_ON_ERROR);

                if ($postData->invoiceId === null) {
                    wp_die('No Coinsnap invoiceId provided', '', ['response' => 400]);
                }

                if(strpos($postData->invoiceId,'test_') !== false){
                    wp_die('Successful webhook test', '', ['response' => 200]);
                }

                $invoice_id = esc_html($postData->invoiceId);

                $client = new \Coinsnap\Client\Invoice( $this->getApiUrl(), $this->getApiKey() );			
                $csinvoice = $client->getInvoice($this->getStoreId(), $invoice_id);
                $status = $csinvoice->getData()['status'] ;
                $order_id = ($this->get_payment_provider() === 'btcpay')? $csinvoice->getData()['metadata']['orderId'] : $csinvoice->getData()['orderId'];

                $order_status = 'pending';
                switch($status){
                    case 'Expired':
                    case 'InvoiceExpired':
                        $order_status = edd_get_option('expired_status', '');
                        break;
                    
                    case 'Processing':
                    case 'InvoiceProcessing':
                        $order_status = edd_get_option('processing_status', '');
                        break;
                    
                    case 'Settled':
                    case 'InvoiceSettled':
                        $order_status = edd_get_option('settled_status', '');
                        break;
                }
                
                edd_update_payment_status( $order_id, $order_status );

                echo "OK";
                exit;
            }
            catch (JsonException $e) {
                wp_die('Invalid JSON payload', '', ['response' => 400]);
            }
        }
        
        catch (\Throwable $e) {
            wp_die('Internal server error', '', ['response' => 500]);
        }
    }
	
    private function get_payment_provider() {
        return (edd_get_option( 'coinsnap_provider') === 'btcpay')? 'btcpay' : 'coinsnap';
    }

    private function get_webhook_url() {
        return esc_url_raw( add_query_arg( array( 'edd-listener' => 'coinsnap' ), home_url( 'index.php' ) ) );
    }
    
    private function getApiKey() {
        return ($this->get_payment_provider() === 'btcpay')? edd_get_option( 'btcpay_api_key') : edd_get_option( 'coinsnap_api_key', '' );
    }
    
    private function getStoreId() {
	return ($this->get_payment_provider() === 'btcpay')? edd_get_option( 'btcpay_store_id') : edd_get_option( 'coinsnap_store_id', '' );
    }
    
    public function getApiUrl() {
        return ($this->get_payment_provider() === 'btcpay')? edd_get_option( 'btcpay_server_url') : COINSNAP_SERVER_URL;
    }	

    public function webhookExists(string $apiUrl, string $apiKey, string $storeId): bool {
	$whClient = new Webhook( $apiUrl, $apiKey );
	if ($storedWebhook = get_option( 'edd_settings_coinsnap_webhook')) {
            
            try {
		$existingWebhook = $whClient->getWebhook( $storeId, $storedWebhook['id'] );
                
                if($existingWebhook->getData()['id'] === $storedWebhook['id'] && strpos( $existingWebhook->getData()['url'], $storedWebhook['url'] ) !== false){
                    return true;
		}
            }
            catch (\Throwable $e) {
		$errorMessage = __( 'Error fetching existing Webhook. Message: ', 'coinsnap-for-easy-digital-downloads' ).$e->getMessage();
            }
	}
        try {
            $storeWebhooks = $whClient->getWebhooks( $storeId );
            foreach($storeWebhooks as $webhook){
                if(strpos( $webhook->getData()['url'], $this->get_webhook_url() ) !== false){
                    $whClient->deleteWebhook( $storeId, $webhook->getData()['id'] );
                }
            }
        }
        catch (\Throwable $e) {
            $errorMessage = sprintf( 
                /* translators: 1: StoreId */
                __( 'Error fetching webhooks for store ID %1$s Message: ', 'coinsnap-for-easy-digital-downloads' ), $storeId).$e->getMessage();
        }
        
	return false;
    }
    
    public function registerWebhook(string $apiUrl, $apiKey, $storeId){
        try {
            $whClient = new Webhook( $apiUrl, $apiKey );
            $webhook_events = ($this->get_payment_provider() === 'btcpay')? self::BTCPAY_WEBHOOK_EVENTS : self::COINSNAP_WEBHOOK_EVENTS;
            $webhook = $whClient->createWebhook(
                $storeId,   //$storeId
		$this->get_webhook_url(), //$url
		$webhook_events,   //$specificEvents
		null    //$secret
            );

            update_option(
                'edd_settings_coinsnap_webhook',
                [
                    'id' => $webhook->getData()['id'],
                    'secret' => $webhook->getData()['secret'],
                    'url' => $webhook->getData()['url']
                ]
            );

            return $webhook;
                        
	}
        catch (\Throwable $e) {
            $errorMessage = __('Error creating a new webhook on Coinsnap instance: ', 'coinsnap-for-easy-digital-downloads' ) . $e->getMessage();
            throw new PaymentGatewayException(esc_html($errorMessage));
	}

	return null;
    }
}

add_action('init', function() {
// Setting up and handling custom endpoint for api key redirect from BTCPay Server.
    add_rewrite_endpoint('coinsnap-for-edd-btcpay-settings-callback', EP_ROOT);
});

// To be able to use the endpoint without appended url segments we need to do this.
add_filter('request', function($vars) {
    if (isset($vars['coinsnap-for-edd-btcpay-settings-callback'])) {
        $vars['coinsnap-for-edd-btcpay-settings-callback'] = true;
        $vars['coinsnap-for-edd-btcpay-nonce'] = wp_create_nonce('coinsnapedd-btcpay-nonce');
    }
    return $vars;
});

function CoinsnapEDD(){
    
    if ( ! function_exists( 'get_plugins' ) ) {
        include_once(ABSPATH . "wp-admin/includes/plugin.php" );  
    }

    $edd_all_plugins = get_plugins();
    if ( ! empty( $edd_all_plugins ) ) {
        foreach ( $edd_all_plugins as $edd_plugin_file => $edd_plugin_data ) {
            if(stripos($edd_plugin_file,'easy-digital-downloads') !== false && stripos($edd_plugin_file,'for-easy-digital-downloads') === false && is_plugin_active($edd_plugin_file)){
                require_once(WP_PLUGIN_DIR . '/'. $edd_plugin_file);
                require_once(plugin_dir_path(WP_PLUGIN_DIR . '/'. $edd_plugin_file) . "includes/payments/class-edd-payment.php");
            }
        }
    }
    
    return CoinsnapEDD::getInstance();
}
CoinsnapEDD();
