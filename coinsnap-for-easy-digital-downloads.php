<?php
/*
 * Plugin Name:     Bitcoin payment for Easy Digital Downloads
 * Plugin URI:      https://coinsnap.io/wp-add-ons/easy-digital-downloads/
 * Description:     With this Bitcoin payment plugin for Easy Digital Downloads you can now offer downloads for Bitcoin right in the Easy Digital Downloads plugin!
 * Version:         1.4.0
 * Author:          Coinsnap
 * Author URI:      https://coinsnap.io/
 * Text Domain:     coinsnap-for-easy-digital-downloads
 * Domain Path:     /languages
 * Requires PHP:    7.4
 * Tested up to:    6.9
 * Requires at least: 5.2
 * EDD tested up to: 3.6.5
 * EDD Pro tested up to: 3.6.6.1
 * License:         GPL2
 * License URI:     https://www.gnu.org/licenses/gpl-2.0.html
 *
 * Network:         true
 */ 

defined('ABSPATH') || exit;
if(!defined('COINSNAPEDD_PLUGIN_VERSION')){ define( 'COINSNAPEDD_PLUGIN_VERSION', '1.4.0' ); }
if(!defined('COINSNAPEDD_REFERRAL_CODE')){ define( 'COINSNAPEDD_REFERRAL_CODE', 'D18876' ); }
if(!defined('COINSNAPEDD_PHP_VERSION')){ define( 'COINSNAPEDD_PHP_VERSION', '7.4' ); }
if(!defined('COINSNAPEDD_WP_VERSION')){ define( 'COINSNAPEDD_WP_VERSION', '5.2' ); }
if(!defined('COINSNAP_SERVER_URL')){define( 'COINSNAP_SERVER_URL', 'https://app.coinsnap.io' );}
if(!defined('COINSNAP_API_PATH')){define( 'COINSNAP_API_PATH', '/api/v1/');}
if(!defined('COINSNAP_SERVER_PATH')){define( 'COINSNAP_SERVER_PATH', 'stores' );}
if(!defined('COINSNAP_CURRENCIES')){define( 'COINSNAP_CURRENCIES', array("EUR","USD","SATS","BTC","CAD","JPY","GBP","CHF","RUB") );}

require_once(dirname(__FILE__) . "/library/loader.php");
require_once(dirname(__FILE__) . "/includes/Signature.php");
require_once(dirname(__FILE__) . "/includes/InvoiceDecision.php");
require_once(dirname(__FILE__) . "/includes/WebhookHandler.php");
require_once(dirname(__FILE__) . "/includes/BtcpayKey.php");
use Coinsnap\Client\Webhook;

final class CoinsnapEDD {
    private static $_instance;
    public $gateway_id      = 'coinsnap';    
    public const COINSNAP_WEBHOOK_EVENTS = ['New','Expired','Settled','Processing','Invalid'];
    public const BTCPAY_WEBHOOK_EVENTS = ['InvoiceCreated','InvoiceExpired','InvoiceSettled','InvoiceProcessing','InvoiceInvalid']; 
    
    public function __construct(){
        
        // Without Easy Digital Downloads (or on a too old PHP/WP) only the admin notice is registered.
        if (!$this->coinsnap_dependencies()) {
            return;
        }

        // Start of the short window in which payments without an invoice record (created before 1.4.0) are still accepted.
        if (get_option(\CoinsnapEDD\Webhook\WebhookHandler::RECORDS_SINCE_OPTION) === false) {
            add_option(\CoinsnapEDD\Webhook\WebhookHandler::RECORDS_SINCE_OPTION, time(), '', false);
        }
        
	add_filter('edd_payment_gateways', array( $this, 'register_gateway' ), 1, 1);		
        
        add_filter('edd_load_scripts_in_footer', '__return_false');                		
        if (is_admin()) {
            add_filter('edd_settings_sections_gateways', array( $this, 'settings_sections_gateways' ), 1, 1);
            add_filter('edd_settings_gateways', array( $this, 'settings_gateways' ), 1, 1);
            add_filter('edd_gateway_settings_url_coinsnap', array( $this, 'edd_gateway_settings_url' ), 1, 1);
            add_action('admin_notices', array($this, 'coinsnap_notice'));
            add_action('admin_enqueue_scripts', [$this, 'enqueueAdminScripts'] );
            add_action('wp_ajax_coinsnapedd_connection_handler', [$this, 'coinsnapConnectionHandler'] );
            add_action('wp_ajax_coinsnapedd_btcpay_server_apiurl_handler', [$this, 'btcpayApiUrlHandler']);
            add_action('edd_coinsnap_api_key', [$this, 'renderApiKeyField']);
            add_action('edd_btcpay_api_key', [$this, 'renderApiKeyField']);
            add_filter('edd_settings_gateways-coinsnap_sanitize', [$this, 'keepStoredApiKeys']);
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
            
            $CoinsnapBTCPaySettingsUrl = admin_url('edit.php?post_type=download&page=edd-settings&tab=gateways&section=coinsnap');

            // BTCPay posts here from the admin's browser; only the state handed out by btcpayApiUrlHandler() is accepted, once.
            $state = isset($_GET['state']) ? preg_replace('/[^A-Za-z0-9]/', '', (string) wp_unslash($_GET['state'])) : '';
            $stateKey = 'coinsnapedd_btcpay_state_' . $state;
            $stateHost = ($state !== '') ? get_transient($stateKey) : false;
            if ($state !== '') {
                delete_transient($stateKey);
            }
            $btcpay_server_url = is_string($stateHost) ? $stateHost : '';
            if ($btcpay_server_url === '') {
                status_header(403);
                exit();
            }

            // Only the key is taken from the POST. What it may do, and for which store, is asked from BTCPay.
            $apiKey = isset($_POST['apiKey']) ? sanitize_text_field(wp_unslash($_POST['apiKey'])) : '';
            $storeId = ($apiKey !== '') ? \CoinsnapEDD\BtcpayKey::verify($btcpay_server_url, $apiKey) : null;
            if ($storeId === null) {
                $notice->addNotice('error', __('The API key was refused. It must be limited to one store and have only the permissions this plugin asks for. Please try again or continue with manual setup.', 'coinsnap-for-easy-digital-downloads'));
                wp_redirect($CoinsnapBTCPaySettingsUrl);
                exit();
            }

            edd_update_option( 'btcpay_server_url', $btcpay_server_url);
            edd_update_option( 'btcpay_api_key', $apiKey);
            edd_update_option( 'btcpay_store_id', $storeId);
            edd_update_option( 'coinsnap_provider', 'btcpay');

            $notice->addNotice('success', __('Successfully received api key and store id from BTCPay Server API. Please finish setup by saving this settings form.', 'coinsnap-for-easy-digital-downloads'));

            try {
                $webhookReady = $this->webhookExists($btcpay_server_url, $apiKey, $storeId) || $this->registerWebhook($btcpay_server_url, $apiKey, $storeId);
            } catch (\Throwable $e) {
                $webhookReady = false;
            }
            if ($webhookReady) {
                $notice->addNotice('success', __( 'Successfully registered a new webhook on BTCPay Server.', 'coinsnap-for-easy-digital-downloads' ), true );
            }
            else {
                $notice->addNotice('error', __( 'Could not register a new webhook on the store.', 'coinsnap-for-easy-digital-downloads' ) );
            }
            wp_redirect($CoinsnapBTCPaySettingsUrl);
            exit();
        });
    }
    
    public function coinsnapConnectionHandler(){
        
        $_nonce = filter_input(INPUT_POST,'_wpnonce',FILTER_SANITIZE_FULL_SPECIAL_CHARS);
        // Before any API call: it can re-register the webhook and replace its secret.
        if (!wp_verify_nonce((string) $_nonce, 'coinsnapedd-ajax-nonce') || !current_user_can('manage_shop_settings')) {
            wp_send_json_error(['message' => 'Not allowed'], 403);
        }
        
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
        
        if ( current_user_can( 'manage_shop_settings' ) ) {
            $host = filter_var(filter_input(INPUT_POST,'host',FILTER_SANITIZE_FULL_SPECIAL_CHARS), FILTER_VALIDATE_URL);

            if ($host === false || (substr( $host, 0, 7 ) !== "http://" && substr( $host, 0, 8 ) !== "https://")) {
                wp_send_json_error("Error validating BTCPayServer URL.");
            }

            // Only what the plugin uses; the callback refuses a key with anything more.
            $permissions = \CoinsnapEDD\BtcpayKey::NEEDED_PERMISSIONS;

            $state = wp_generate_password(32, false);
            set_transient('coinsnapedd_btcpay_state_' . $state, $host, 15 * MINUTE_IN_SECONDS);

            try {
		// Create the redirect url to BTCPay instance.
		$url = \Coinsnap\Client\BTCPayApiKey::getAuthorizeUrl(
                    $host,
                    $permissions,
                    'Easy Digital Downloads',
                    true,
                    true,
                    add_query_arg('state', $state, home_url('?coinsnap-for-edd-btcpay-settings-callback')),
                    null
		);

		// The host is saved only by the callback, once a key for it is verified; the stored key never goes to an unverified host.

		// Return the redirect url.
		wp_send_json_success(['url' => $url]);
            }
            
            catch (\Throwable $e) {
                wp_send_json_error("Error processing Ajax request.");
            }
	}
        wp_send_json_error("Error processing Ajax request.");
    }
    
    
    //  Checks if PHP version is too low or WooCommerce is not installed or CURL is not available and displays notice on admin dashboard
    public function coinsnap_dependencies(): bool {
        // Checks PHP version.
	if ( version_compare( PHP_VERSION, COINSNAPEDD_PHP_VERSION, '<' ) ) {
            add_action( 'admin_notices', array( $this, 'coinsnap_php_version_notice' ) );
            return false;
	}

	// Checks if EDD is installed.
	if ( ! class_exists( 'Easy_Digital_Downloads' ) ) {
            add_action( 'admin_notices', array( $this, 'coinsnap_edd_notice' ) );
            return false;
	}

	// Checks WP version.
	if ( version_compare( get_bloginfo( 'version' ), COINSNAPEDD_WP_VERSION, '<') ) {
            add_action( 'admin_notices', array( $this, 'coinsnap_wp_version_notice' ) );
            return false;
	}
        return true;
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

                            // A page view (GET, no nonce) never changes the webhook; the AJAX connection check does that.
                        }
                        else {
                            echo '<div class="notice notice-error edd-notice"><p>';
                            esc_html_e('EasyDigitalDownloads: Coinsnap connection error. Check the Store ID and API key.', 'coinsnap-for-easy-digital-downloads');
                            echo '</p></div>';
                        }
                        
                    }
                    catch (\Throwable $e){
                        echo '<div class="notice notice-error edd-notice"><p>';
                        esc_html_e('EasyDigitalDownloads: Coinsnap connection error. Check the Store ID and API key.', 'coinsnap-for-easy-digital-downloads');
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
            'supports'       => array('buy_now')
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
                'type' => 'hook',
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
                    'type' => 'hook',
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
                'std'     => 'processing',										
                'desc' => 'select which status is processing (a payment is completed only when the invoice is settled)',
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
        
        $checkoutError = function (string $message) use ($purchase_data) {
            edd_set_error('coinsnap_error', $message);
            edd_send_back_to_checkout('?payment-mode=' . $purchase_data['post_data']['edd-gateway']);
        };

        // A customer's checkout never lists, deletes or registers webhooks: only the admin connection check
        // and the BTCPay connect callback do. Without a connected webhook the payment could not be confirmed.
        if (!$this->webhookConnected()) {
            edd_record_gateway_error('Coinsnap', 'Coinsnap: no webhook is connected for this store. Open the Coinsnap gateway settings to connect it.');
            $checkoutError(__('The payment could not be started. Please try again later.', 'coinsnap-for-easy-digital-downloads'));
            return;
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
            edd_record_gateway_error('Coinsnap', 'Coinsnap: the EDD payment could not be created');
            $checkoutError(__('The payment could not be started. Please try again later.', 'coinsnap-for-easy-digital-downloads'));
            return;
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
                        
                    }
                }
                
                $camount = ($currency === 'BTC')? \Coinsnap\Util\PreciseNumber::parseFloat($amount,8) : \Coinsnap\Util\PreciseNumber::parseFloat($amount,2);
            
                $redirectAutomatically = (edd_get_option( 'coinsnap_autoredirect', '' ) > 0)? true : false;
                $walletMessage = '';
		
                try {
                    $invoiceData = $client->createInvoice(
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
                    )->getData();
                } catch (\Throwable $e) {
                    $invoiceData = [];
                }

                if (empty($invoiceData['checkoutLink']) || empty($invoiceData['id'])) {
                    edd_record_gateway_error('Coinsnap', 'Coinsnap: invoice creation failed', $payment_id);
                    $checkoutError(__('The payment could not be started. Please try again later.', 'coinsnap-for-easy-digital-downloads'));
                    return;
                }

                // Only invoices recorded on the payment can pay it, and only for the amount and currency sent.
                $invoices = edd_get_payment_meta($payment_id, '_coinsnap_invoices', true);
                $invoices = is_array($invoices) ? $invoices : [];
                $invoices[(string) $invoiceData['id']] = ['amount' => (string) $camount, 'currency' => $currency];
                edd_update_payment_meta($payment_id, '_coinsnap_invoices', $invoices);

                wp_redirect($invoiceData['checkoutLink']);
                exit;
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
        if (filter_input(INPUT_GET, 'edd-listener', FILTER_SANITIZE_FULL_SPECIAL_CHARS) !== 'coinsnap') {
            return;
        }
        $provider = $this->get_payment_provider();
        $webhook = get_option('edd_settings_coinsnap_webhook');
        $secret = is_array($webhook) ? (string) ($webhook['secret'] ?? '') : '';
        $header = ($provider === 'btcpay') ? 'HTTP_BTCPAY_SIG' : 'HTTP_X_COINSNAP_SIGNATURE';
        $statuses = [
            'settled' => (string) edd_get_option('settled_status', 'complete'),
            'processing' => (string) edd_get_option('processing_status', ''),
            'expired' => (string) edd_get_option('expired_status', 'failed'),
        ];

        $status = (new \CoinsnapEDD\Webhook\WebhookHandler($provider, $secret, $statuses))->handle(
            (string) file_get_contents('php://input'),
            isset($_SERVER[$header]) ? (string) wp_unslash($_SERVER[$header]) : '',
            time()
        );
        status_header($status);
        echo $status === 200 ? 'OK' : ($status === 401 ? 'Unauthorized' : 'Retry');
        exit;
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

    // Throws when the API can't be asked; the caller must not treat that as "no webhook".
    public function webhookExists(string $apiUrl, string $apiKey, string $storeId): bool {
        $whClient = new Webhook($apiUrl, $apiKey);
        $stored = get_option('edd_settings_coinsnap_webhook');

        if (is_array($stored) && !empty($stored['id']) && !empty($stored['secret'])) {
            try {
                $existing = $whClient->getWebhook($storeId, (string) $stored['id']);
                if (strpos((string) ($existing->getData()['url'] ?? ''), $this->get_webhook_url()) !== false) {
                    return true;
                }
            } catch (\Coinsnap\Exception\RequestException $e) {
                if (!in_array($e->getCode(), [400, 404], true)) {
                    throw $e;
                }
            }
        }
        // Remove webhooks of ours whose secret this site does not hold.
        foreach ($whClient->getWebhooks($storeId) as $webhook) {
            if (strpos((string) $webhook->getData()['url'], $this->get_webhook_url()) !== false) {
                try {
                    $whClient->deleteWebhook($storeId, $webhook->getData()['id']);
                } catch (\Throwable $e) {
                    edd_record_gateway_error('Coinsnap', 'Coinsnap: a stale webhook could not be deleted');
                }
            }
        }
        return false;
    }

    public function registerWebhook(string $apiUrl, $apiKey, $storeId): bool {
        try {
            $whClient = new Webhook($apiUrl, $apiKey);
            $webhook_events = ($this->get_payment_provider() === 'btcpay')? self::BTCPAY_WEBHOOK_EVENTS : self::COINSNAP_WEBHOOK_EVENTS;
            $webhook = $whClient->createWebhook($storeId, $this->get_webhook_url(), $webhook_events, null)->getData();
        }
        catch (\Throwable $e) {
            edd_record_gateway_error('Coinsnap', sprintf('Coinsnap: the webhook could not be registered (status %d)', (int) $e->getCode()));
            return false;
        }
        if (empty($webhook['id']) || empty($webhook['secret'])) {
            return false;
        }
        update_option('edd_settings_coinsnap_webhook', ['id' => $webhook['id'], 'secret' => $webhook['secret'], 'url' => $webhook['url'] ?? $this->get_webhook_url(), 'store' => $this->webhookStoreKey($storeId)], false);
        return true;
    }

    private function webhookStoreKey(string $storeId): string {
        return $this->get_payment_provider() . ':' . $storeId;
    }

    // Local check only (no API call): a webhook secret is stored, for the configured provider and store.
    // Records written before this check existed have no 'store' entry and are accepted.
    public function webhookConnected(): bool {
        $stored = get_option('edd_settings_coinsnap_webhook');
        if (!is_array($stored) || empty($stored['id']) || empty($stored['secret'])) {
            return false;
        }
        return !isset($stored['store']) || $stored['store'] === $this->webhookStoreKey((string) $this->getStoreId());
    }

    // The stored key is never printed; an empty field keeps it.
    public function renderApiKeyField($args) {
        $id = ($args['id'] ?? '') === 'btcpay_api_key' ? 'btcpay_api_key' : 'coinsnap_api_key';
        $stored = (string) edd_get_option($id, '');
        $hint = ($stored !== '') ? sprintf(
            /* translators: 1: last characters of the stored API key */
            __('A key ending in %1$s is saved. Leave empty to keep it.', 'coinsnap-for-easy-digital-downloads'),
            substr($stored, -4)
        ) : ($args['desc'] ?? '');
        printf(
            '<input type="password" class="regular-text %1$s" id="edd_settings[%2$s]" name="edd_settings[%2$s]" value="" autocomplete="new-password"/><p class="description">%3$s</p>',
            esc_attr($id === 'btcpay_api_key' ? 'btcpay' : 'coinsnap'),
            esc_attr($id),
            esc_html($hint)
        );
    }

    public function keepStoredApiKeys($input) {
        if (!is_array($input)) {
            return $input;
        }
        if (array_key_exists('coinsnap_api_key', $input) && trim((string) $input['coinsnap_api_key']) === '') {
            $input['coinsnap_api_key'] = (string) edd_get_option('coinsnap_api_key', '');
        }
        if (array_key_exists('btcpay_api_key', $input)) {
            $submitted = trim((string) $input['btcpay_api_key']);
            $serverUrl = trim((string) ($input['btcpay_server_url'] ?? ''));
            // A key is never sent to a server it was not created on.
            $urlUnchanged = $serverUrl === (string) edd_get_option('btcpay_server_url', '');
            $keep = $urlUnchanged ? (string) edd_get_option('btcpay_api_key', '') : '';
            if ($submitted === '') {
                $input['btcpay_api_key'] = $keep;
            }
            else {
                // A pasted key must be limited to the configured store with only the needed permissions, as BTCPay reports it.
                $storeId = trim((string) ($input['btcpay_store_id'] ?? ''));
                if (\CoinsnapEDD\BtcpayKey::verify($serverUrl, $submitted, $storeId) === null) {
                    $input['btcpay_api_key'] = $keep;
                    add_settings_error('edd-notices', 'coinsnapedd-btcpay-key', __('The BTCPay API key was not saved: BTCPay must confirm that it is limited to the store ID entered here, with only the permissions this plugin needs.', 'coinsnap-for-easy-digital-downloads'), 'error');
                }
                else {
                    $input['btcpay_api_key'] = $submitted;
                }
            }
        }
        return $input;
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
