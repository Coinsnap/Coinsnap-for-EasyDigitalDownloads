<?php
// Run: php tests/checkout-webhook-test.php
// A customer's checkout must make no webhook API call (list, get, create, delete); only admin actions manage the webhook.
if (PHP_SAPI !== 'cli') {
    exit;
}
define('ABSPATH', __DIR__ . '/');
define('MINUTE_IN_SECONDS', 60);

$GLOBALS['opts'] = [];
$GLOBALS['edd'] = ['coinsnap_provider' => 'coinsnap', 'coinsnap_store_id' => 'STORE1', 'coinsnap_api_key' => 'KEY1', 'currency' => 'EUR'];
$GLOBALS['http'] = [];
$GLOBALS['errors'] = [];
$GLOBALS['meta'] = [];

class Easy_Digital_Downloads {}
class CheckoutStopped extends \RuntimeException {}

function add_action(...$a) {}
function add_filter(...$a) {}
function is_admin() { return false; }
function get_bloginfo($k) { return '6.8'; }
function get_plugins() { return []; }
function get_option($k, $d = false) { return $GLOBALS['opts'][$k] ?? $d; }
function add_option($k, $v, $x = '', $a = null) { $GLOBALS['opts'][$k] = $v; return true; }
function update_option($k, $v, $a = null) { $GLOBALS['opts'][$k] = $v; return true; }
function edd_get_option($k, $d = '') { return $GLOBALS['edd'][$k] ?? $d; }
function edd_get_currency() { return 'EUR'; }
function edd_get_success_page_uri() { return 'https://shop.example/success'; }
function home_url($p = '') { return 'https://shop.example/' . ltrim($p, '/'); }
function add_query_arg($args, $url) { return $url . '?' . http_build_query($args); }
function esc_url_raw($u) { return $u; }
function esc_url($u) { return $u; }
function esc_html($s) { return (string) $s; }
function esc_html__($s, $d = '') { return $s; }
function __($s, $d = '') { return $s; }
function wp_json_encode($v, $f = 0) { return json_encode($v, $f); }
function wp_verify_nonce($n, $a) { return $n === 'good'; }
function wp_die(...$a) { throw new CheckoutStopped('die'); }
function edd_insert_payment($d) { return 42; }
function edd_get_payment_meta($id, $k, $s = true) { return $GLOBALS['meta'][$k] ?? ''; }
function edd_update_payment_meta($id, $k, $v) { $GLOBALS['meta'][$k] = $v; }
function edd_record_gateway_error($t, $m, $p = 0) { $GLOBALS['errors'][] = $m; }
function edd_set_error($k, $m) { $GLOBALS['errors'][] = $m; }
function edd_send_back_to_checkout($a = '') { throw new CheckoutStopped('back'); }
function wp_redirect($url) { throw new CheckoutStopped('redirect ' . $url); }
function is_wp_error($r) { return false; }
function wp_strip_all_tags($s) { return strip_tags((string) $s); }
function get_locale() { return 'en_US'; }
function admin_url($p = '') { return 'https://shop.example/wp-admin/' . $p; }
function wp_remote_retrieve_headers($r) { return new class { public function getAll() { return []; } }; }

// Fake Coinsnap API and rates; every request is recorded.
function wp_remote_request($url, $args) {
    $GLOBALS['http'][] = $args['method'] . ' ' . $url;
    $r = function ($code, $body) { return ['response' => ['code' => $code], 'body' => json_encode($body)]; };
    if (strpos($url, 'coingecko') !== false) {
        return $r(200, ['rates' => ['btc' => ['value' => 1], 'eur' => ['value' => 60000]]]);
    }
    if (preg_match('#/webhooks$#', $url) && $args['method'] === 'GET') {
        return $r(200, [['id' => 'wh1', 'url' => 'https://shop.example/index.php?edd-listener=coinsnap']]);
    }
    if (preg_match('#/webhooks/\w+$#', $url) && $args['method'] === 'GET') {
        return $r(404, []);
    }
    if (preg_match('#/webhooks(/\w+)?$#', $url)) {
        return $r(200, ['id' => 'wh2', 'secret' => 'NEWSECRET', 'url' => 'https://shop.example/index.php?edd-listener=coinsnap']);
    }
    if (preg_match('#/invoices$#', $url)) {
        return $r(200, ['id' => 'inv1', 'checkoutLink' => 'https://pay.example/i/inv1']);
    }
    return $r(404, []);
}

require __DIR__ . '/../coinsnap-for-easy-digital-downloads.php';

$fail = 0;
function ok($c, $m) { global $fail; echo ($c ? 'PASS ' : 'FAIL ') . $m . "\n"; if (!$c) { $fail++; } }
function webhookCalls() { return count(preg_grep('#/webhooks#', $GLOBALS['http'])); }
function checkout() {
    $GLOBALS['http'] = [];
    $data = [
        'gateway_nonce' => 'good', 'price' => 10.0, 'date' => '2026-11-18 10:00:00', 'user_email' => 'b@example.com', 'purchase_key' => 'k',
        'downloads' => [], 'cart_details' => [], 'buy_now' => false, 'post_data' => ['edd-gateway' => 'coinsnap'],
        'user_info' => ['email' => 'b@example.com', 'first_name' => 'A', 'last_name' => 'B'],
    ];
    try {
        CoinsnapEDD::getInstance()->coinsnap_payment($data);
        return 'none';
    } catch (CheckoutStopped $e) {
        return $e->getMessage();
    }
}

// 1. Webhook connected for this store: invoice created, no webhook call.
$GLOBALS['opts']['edd_settings_coinsnap_webhook'] = ['id' => 'wh1', 'secret' => 'S1', 'url' => 'https://shop.example/index.php?edd-listener=coinsnap'];
$r = checkout();
ok($r === 'redirect https://pay.example/i/inv1', 'connected webhook (record from 1.3.5/1.4.0): checkout redirects to the invoice');
ok(webhookCalls() === 0, 'connected webhook: checkout makes no webhook API call');

$GLOBALS['opts']['edd_settings_coinsnap_webhook']['store'] = 'coinsnap:STORE1';
$r = checkout();
ok($r === 'redirect https://pay.example/i/inv1' && webhookCalls() === 0, 'record for this provider and store: invoice, no webhook call');

// 2. No webhook stored: checkout is refused, nothing registered or deleted, secret not created.
unset($GLOBALS['opts']['edd_settings_coinsnap_webhook']);
$r = checkout();
ok($r === 'back', 'no webhook: checkout sent back with an error');
ok(webhookCalls() === 0, 'no webhook: checkout makes no webhook API call (no list, delete or register)');
ok(count(preg_grep('#/invoices#', $GLOBALS['http'])) === 0, 'no webhook: no invoice created');
ok(!isset($GLOBALS['opts']['edd_settings_coinsnap_webhook']), 'no webhook: checkout stores no secret');

// 3. Webhook record for another store (provider switched without connecting): refused, no API call.
$GLOBALS['opts']['edd_settings_coinsnap_webhook'] = ['id' => 'wh9', 'secret' => 'S9', 'url' => 'x', 'store' => 'btcpay:BS1'];
$r = checkout();
ok($r === 'back' && webhookCalls() === 0, 'record for another store: checkout refused, no webhook call');
ok($GLOBALS['opts']['edd_settings_coinsnap_webhook']['secret'] === 'S9', 'record for another store: stored secret unchanged');

// 4. The admin path still registers and records the store.
$GLOBALS['http'] = [];
$ok = CoinsnapEDD::getInstance()->registerWebhook('https://app.coinsnap.io', 'KEY1', 'STORE1');
ok($ok && ($GLOBALS['opts']['edd_settings_coinsnap_webhook']['store'] ?? '') === 'coinsnap:STORE1', 'admin registerWebhook stores the secret with provider:store');
ok(CoinsnapEDD::getInstance()->webhookConnected(), 'after admin registration the webhook counts as connected');

foreach ($GLOBALS['errors'] as $e) {
    if (strpos($e, 'KEY1') !== false || strpos($e, 'STORE1') !== false || strpos($e, 'S9') !== false) {
        ok(false, 'error message leaks key, store or secret: ' . $e);
    }
}
echo $fail ? "\n$fail FAILED\n" : "\nALL PASS\n";
exit($fail ? 1 : 0);
