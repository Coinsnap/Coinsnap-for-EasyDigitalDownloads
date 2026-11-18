<?php
// Removes only this plugin's own data: its webhook record and its keys inside the Easy Digital Downloads settings.
defined('WP_UNINSTALL_PLUGIN') || exit;

delete_option('edd_settings_coinsnap_webhook');
delete_option('coinsnapedd_records_since');

$coinsnapedd_settings = get_option('edd_settings');
if (is_array($coinsnapedd_settings)) {
    foreach (['coinsnap_api_key', 'coinsnap_store_id', 'btcpay_api_key', 'btcpay_store_id', 'btcpay_server_url'] as $coinsnapedd_key) {
        unset($coinsnapedd_settings[$coinsnapedd_key]);
    }
    update_option('edd_settings', $coinsnapedd_settings);
}
