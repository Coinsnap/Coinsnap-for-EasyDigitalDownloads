<?php
/*
 * Plugin Name:     Bitcoin payment for Easy Digital Downloads
 * Plugin URI:      https://www.coinsnap.io
 * Description:     With this Bitcoin payment plugin for Easy Digital Downloads you can now offer downloads for Bitcoin right in the Easy Digital Downloads plugin!
 * Version:         1.0.0
 * Author:          Coinsnap
 * Author URI:      https://coinsnap.io/
 * Text Domain:     coinsnap-for-easy-digital-downloads
 * Domain Path:     /languages
 * Requires PHP:    7.4
 * Tested up to:    6.8
 * Requires at least: 5.2
 * Requires Plugins: easy-digital-downloads
 * EDD tested up to: 3.3.8.1
 * License:         GPL2
 * License URI:     https://www.gnu.org/licenses/gpl-2.0.html
 *
 * Network:         true
 */ 

defined('ABSPATH') || exit;
if(!defined('COINSNAPEDD_REFERRAL_CODE')){ define( 'COINSNAPEDD_REFERRAL_CODE', 'D18876' ); }
if(!defined('COINSNAPEDD_PHP_VERSION')){ define( 'COINSNAPEDD_PHP_VERSION', '7.4' ); }
if(!defined('COINSNAPEDD_WP_VERSION')){ define( 'COINSNAPEDD_WP_VERSION', '5.2' ); }
if(!defined('COINSNAP_CURRENCIES')){define( 'COINSNAP_CURRENCIES', array("EUR","USD","SATS","BTC","CAD","JPY","GBP","CHF","RUB") );}

include_once(ABSPATH . 'wp-admin/includes/plugin.php' );    
require_once(ABSPATH . "wp-content/plugins/easy-digital-downloads/easy-digital-downloads.php");
require_once(ABSPATH . "wp-content/plugins/easy-digital-downloads/includes/payments/class-edd-payment.php");
require_once(dirname(__FILE__) . "/library/loader.php");


final class CoinsnapEDD {
    private static $_instance;
    public $gateway_id      = 'coinsnap';    
    public const WEBHOOK_EVENTS = ['New','Expired','Settled','Processing'];	 
    
    public function __construct(){
        
        $this->coinsnap_dependencies();
        
	add_filter('edd_payment_gateways', array( $this, 'register_gateway' ), 1, 1);		
        
        add_filter('edd_load_scripts_in_footer', '__return_false');                		
        if (is_admin()) {
            add_filter('edd_settings_sections_gateways', array( $this, 'settings_sections_gateways' ), 1, 1);
            add_filter('edd_settings_gateways', array( $this, 'settings_gateways' ), 1, 1);
            add_filter('edd_gateway_settings_url_coinsnap', array( $this, 'edd_gateway_settings_url' ), 1, 1);
            add_action('admin_notices', array($this, 'coinsnap_notice'));    
        }
        
	add_action('edd_coinsnap_cc_form', '__return_false');        	
        add_action('edd_gateway_coinsnap', array( $this, 'coinsnap_payment' ));		    
        add_action('init', array( $this, 'process_webhook'));
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
    
        public function coinsnap_admin_notices() {
		add_settings_error( 'edd-notices', 'edd-coinsnap-admin-error', ( ! is_plugin_active( 'easy-digital-downloads/easy-digital-downloads.php' ) ? __( '<b>Easy Digital Downloads Payment Gateway by Coinsnap</b>add-on requires <a href="https://easydigitaldownloads.com" target="_new"> Easy Digital Downloads</a> plugin. Please install and activate it.', 'coinsnap-for-easy-digital-downloads' ) : ( ! extension_loaded( 'curl' ) ? ( __( '<b>Easy Digital Downloads Payment Gateway by Coinsnap</b>requires PHP CURL. You need to activate the CURL function on your server. Please contact your hosting provider.', 'coinsnap-for-easy-digital-downloads' ) ) : '' ) ), 'error' );
		settings_errors( 'edd-notices' );
    }
    
    public function coinsnap_php_version_notice() {
        $eddMessage = __( 'Easy Digital Downloads Payment Gateway by Coinsnap add-on requires <a href="https://easydigitaldownloads.com" target="_new"> Easy Digital Downloads</a> plugin. Please install and activate it.', 'coinsnap-for-easy-digital-downloads' );
        add_settings_error( 'edd-notices', 'edd-coinsnap-php-version-notice', $eddMessage, 'error' );
        settings_errors( 'edd-notices' );
    }
    
    public function coinsnap_edd_notice() {
        $PHPVersionMessage = sprintf( 
            /* translators: 1: PHP version */
            __( 'Your PHP version is %1$s but Coinsnap Payment plugin requires version 7.4+.', 'coinsnap-for-easy-digital-downloads' ), PHP_VERSION );
        add_settings_error( 'edd-notices', 'edd-coinsnap-edd-notice', $PHPVersionMessage, 'error' );
        settings_errors( 'edd-notices' );
    }
    
    public function coinsnap_wp_version_notice() {
        $wpMessage = sprintf( 
            /* translators: 1: Current WP version, 2: Required WP version  */
            __( 'Your Wordpress version is %1$s but Coinsnap Payment add-on requires version %2$s', 'coinsnap-for-easy-digital-downloads' ), get_bloginfo( 'version' ), COINSNAPEDD_WP_VERSION );
        add_settings_error( 'edd-notices', 'edd-coinsnap-wp-version-notice', $wpMessage, 'error' );
        settings_errors( 'edd-notices' );
    }
    
    public function coinsnap_notice(){
        
        $page = (filter_input(INPUT_GET,'page',FILTER_SANITIZE_FULL_SPECIAL_CHARS ))? filter_input(INPUT_GET,'page',FILTER_SANITIZE_FULL_SPECIAL_CHARS ) : '';
        $tab = (filter_input(INPUT_GET,'tab',FILTER_SANITIZE_FULL_SPECIAL_CHARS ))? filter_input(INPUT_GET,'tab',FILTER_SANITIZE_FULL_SPECIAL_CHARS ) : '';
        
        if($page === 'edd-settings' && $tab === 'gateways'){
        
            $coinsnap_url = $this->getApiUrl();
            $coinsnap_api_key = $this->getApiKey();
            $coinsnap_store_id = $this->getStoreId();
            $coinsnap_webhook_url = $this->get_webhook_url();
                
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
                    $store = $client->getStore($coinsnap_store_id);
                    if ($store['code'] === 200) {
                        echo '<div class="notice notice-success"><p>';
                        esc_html_e('EasyDigitalDownloads: Established connection to Coinsnap Server', 'coinsnap-for-easy-digital-downloads');
                        echo '</p></div>';
                        
                        if ( ! $this->webhookExists( $coinsnap_store_id, $coinsnap_api_key, $coinsnap_webhook_url ) ) {
                            if ( ! $this->registerWebhook( $coinsnap_store_id, $coinsnap_api_key, $coinsnap_webhook_url ) ) {
                                echo '<div class="notice notice-error"><p>';
                                esc_html_e('EasyDigitalDownloads: Unable to create webhook on Coinsnap Server', 'coinsnap-for-easy-digital-downloads');
                                echo '</p></div>';
                            }
                            else {
                                echo '<div class="notice notice-success"><p>';
                                esc_html_e('EasyDigitalDownloads: Successfully registered a new webhook on Coinsnap Server', 'coinsnap-for-easy-digital-downloads');
                                echo '</p></div>';
                            }
                        }
                        else {
                            echo '<div class="notice notice-info"><p>';
                            esc_html_e('EasyDigitalDownloads: Webhook already exists, skipping webhook creation', 'coinsnap-for-easy-digital-downloads');
                            echo '</p></div>';
                        }
                    }
                    else {
                        echo '<div class="notice notice-error"><p>';
                        esc_html_e('EasyDigitalDownloads: Coinsnap connection error:', 'coinsnap-for-easy-digital-downloads');
                        echo esc_html($store['result']['message']);
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

    
    public function settings_gateways($gateway_settings)
    {
        $edd_statuses = edd_get_payment_statuses();
        $default_coinsnap_settings = array(
            'coinsnap' => array(
                'id'   => 'coinsnap',
                'name' => '<strong>' . __('Coinsnap Settings', 'coinsnap-for-easy-digital-downloads') . '</strong>',
                'type' => 'header',
            ),
            'coinsnap_seller_id' => array(
                'id'   => 'coinsnap_store_id',
                'name' => __('Store ID', 'coinsnap-for-easy-digital-downloads'),
                'desc' => __('Enter Store ID Given by Coinsnap', 'coinsnap-for-easy-digital-downloads'),
                'type' => 'text',
                'size' => 'regular',
            ),
            'coinsnap_api_key' => array(
                'id'   => 'coinsnap_api_key',
                'name' => __('API Key', 'coinsnap-for-easy-digital-downloads'),
                'desc' => __('Enter API Key Given by Coinsnap', 'coinsnap-for-easy-digital-downloads'),
                'type' => 'text',
                'size' => 'regular',
            ),
            'coinsnap_autoredirect' => array(
                'id'   => 'coinsnap_autoredirect',
                'name' => __('Redirect after payment', 'coinsnap-for-easy-digital-downloads'),
                'desc' => __('Redirect after payment on Thank you page automatically', 'coinsnap-for-easy-digital-downloads'),
                'type' => 'checkbox',
                'value' => 1,
                'std' => 1
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
    
    
    public function coinsnap_payment($purchase_data){
        global $edd_options;
        
        if (! wp_verify_nonce($purchase_data['gateway_nonce'], 'edd-gateway')) {
            wp_die(
                esc_html__('Nonce verification has failed', 'coinsnap-for-easy-digital-downloads'),
                esc_html__('Error', 'coinsnap-for-easy-digital-downloads'),
                array( 'response' => 403 )
            );
        }        
        
        $webhook_url = $this->get_webhook_url();
        
        if (! $this->webhookExists($this->getStoreId(), $this->getApiKey(), $webhook_url)){
            if (! $this->registerWebhook($this->getStoreId(), $this->getApiKey(),$webhook_url)){
                
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
            $currency_code = edd_get_currency();
            
            $client =new \Coinsnap\Client\Invoice($this->getApiUrl(), $this->getApiKey());
            $checkInvoice = $client->checkPaymentData($amount,strtoupper($currency_code));
                
            if($checkInvoice['result'] === true){
            
		$redirectUrl = edd_get_success_page_uri();
                $buyerEmail = isset($purchase_data['user_email']) ? $purchase_data['user_email'] : $purchase_data['user_info']['email'];
		$buyerName =  $purchase_data['user_info']['first_name'] . ' ' . $purchase_data['user_info']['last_name'];
		    		
                $metadata = [];
    		$metadata['orderNumber'] = $payment_id;
                $metadata['customerName'] = $buyerName;
                $redirectAutomatically = (edd_get_option( 'coinsnap_autoredirect', '' ) > 0)? true : false;
                $walletMessage = '';
		$camount = \Coinsnap\Util\PreciseNumber::parseFloat($amount,2);
		
                $invoice = $client->createInvoice(
                    $this->getStoreId(),  
                    $currency_code,
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
                    __( 'Currency %1$s is not supported by Coinsnap', 'coinsnap-for-easy-digital-downloads' ), strtoupper( $currency_code ));
                }      
                elseif($checkInvoice['error'] === 'amountError'){
                    $errorMessage = sprintf( 
                    /* translators: 1: Amount, 2: Currency */
                    __( 'Invoice amount cannot be less than %1$s %2$s', 'coinsnap-for-easy-digital-downloads' ), $checkInvoice['min_value'], strtoupper( $currency_code ));
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

        $notify_json = file_get_contents('php://input');            
        
        $notify_ar = json_decode($notify_json, true);
        $invoice_id = $notify_ar['invoiceId'];        


        try {
			$client = new \Coinsnap\Client\Invoice( $this->getApiUrl(), $this->getApiKey() );			
			$invoice = $client->getInvoice($this->getStoreId(), $invoice_id);
			$status = $invoice->getData()['status'] ;
			$order_id = $invoice->getData()['orderId'] ;
	
		}catch (\Throwable $e) {									
			
			echo "Error";
			exit;
		}

        $order_status = 'pending';
		if ($status == 'Expired') $order_status = edd_get_option('expired_status', '');
		else if ($status == 'Processing') $order_status = edd_get_option('processing_status', '');
		else if ($status == 'Settled') $order_status = edd_get_option('settled_status', '');
                
        edd_update_payment_status( $order_id, $order_status );
        
        echo "OK";
        exit;
		
    }               
    
    
	


    private function get_webhook_url() {
		return esc_url_raw( add_query_arg( array( 'edd-listener' => 'coinsnap' ), home_url( 'index.php' ) ) );
	}
    private function getApiKey() {
		return edd_get_option( 'coinsnap_api_key', '' );
	}
    private function getStoreId() {
		return edd_get_option( 'coinsnap_store_id', '' );
	}
    public function getApiUrl() {
        return 'https://app.coinsnap.io';
    }	

    public function webhookExists(string $storeId, string $apiKey, string $webhook): bool {	
		try {		
			$whClient = new \Coinsnap\Client\Webhook( $this->getApiUrl(), $apiKey );		
			$Webhooks = $whClient->getWebhooks( $storeId );																		
            
			
			foreach ($Webhooks as $Webhook){					
//				$this->deleteWebhook($storeId,$apiKey, $Webhook->getData()['id']);
				if ($Webhook->getData()['url'] == $webhook) return true;	
			}
		}catch (\Throwable $e) {			
			return false;
		}
	
		return false;
	}
        
	public function registerWebhook(string $storeId, string $apiKey, string $webhook): bool {	
		try {			
			$whClient = new \Coinsnap\Client\Webhook($this->getApiUrl(), $apiKey);
			
			$webhook = $whClient->createWebhook(
				$storeId,   //$storeId
				$webhook, //$url
				self::WEBHOOK_EVENTS,   //$specificEvents
				null    //$secret
			);		
			
			return true;
		} catch (\Throwable $e) {
			return false;	
		}

		return false;
	}

    public function deleteWebhook(string $storeId, string $apiKey, string $webhookid): bool {	    
		
        try {			
            $whClient = new \Coinsnap\Client\Webhook($this->getApiUrl(), $apiKey);
			
            $webhook = $whClient->deleteWebhook(
                $storeId,   //$storeId
                $webhookid, //$url			
            );					
            return true;
	}
        catch (\Throwable $e) {
            return false;	
        }
    }
}

function CoinsnapEDD(){
    return CoinsnapEDD::getInstance();
}
CoinsnapEDD();
