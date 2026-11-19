<?php
// Run: php tests/invoice-metadata-test.php
// The create-invoice request must carry orderId = EDD payment ID (the webhook binds the invoice to the payment by it)
// and the order and buyer metadata, for Coinsnap and BTCPay.
if (PHP_SAPI !== 'cli') {
    exit;
}
define('ABSPATH', __DIR__ . '/');
define('MINUTE_IN_SECONDS', 60);

$GLOBALS['opts'] = [];
$GLOBALS['edd'] = ['coinsnap_provider' => 'coinsnap', 'coinsnap_store_id' => 'STORE1', 'coinsnap_api_key' => 'KEY1', 'currency' => 'EUR',
    'btcpay_server_url' => 'https://btcpay.example', 'btcpay_store_id' => 'BS1', 'btcpay_api_key' => 'BKEY'];
$GLOBALS['bodies'] = [];
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
function wp_remote_retrieve_headers($r) { return new class { public function getAll() { return []; } }; }

function wp_strip_all_tags($s) { return strip_tags((string) $s); }
function get_locale() { return 'de_DE'; }
function admin_url($p = '') { return 'https://shop.example/wp-admin/' . $p; }
function edd_get_payment_number($id) { return 'EDD-' . $id; }
function edd_get_admin_url($args) { return 'https://shop.example/wp-admin/edit.php?' . http_build_query($args); }
function edd_get_download_sku($id) { return $id === 7 ? 'EBOOK-7' : '-'; }

// Fake Coinsnap / BTCPay API and rates; invoice request bodies are recorded.
function wp_remote_request($url, $args) {
    $r = function ($code, $body) { return ['response' => ['code' => $code], 'body' => json_encode($body)]; };
    if (strpos($url, 'coingecko') !== false) {
        return $r(200, ['rates' => ['btc' => ['value' => 1], 'eur' => ['value' => 60000]]]);
    }
    if (strpos($url, '/payment-methods') !== false) {
        return $r(200, [['paymentMethodId' => 'BTC-LN', 'enabled' => true]]);
    }
    if (strpos($url, '/rates') !== false) {
        return $r(200, [['currencyPair' => 'BTC_EUR', 'rate' => '60000', 'errors' => []]]);
    }
    if (preg_match('#/invoices$#', $url)) {
        $GLOBALS['bodies'][] = json_decode($args['body'], true);
        return $r(200, ['id' => 'inv1', 'checkoutLink' => 'https://pay.example/i/inv1']);
    }
    return $r(404, []);
}

require __DIR__ . '/../coinsnap-for-easy-digital-downloads.php';

$fail = 0;
function ok($c, $m) { global $fail; echo ($c ? 'PASS ' : 'FAIL ') . $m . "\n"; if (!$c) { $fail++; } }
function checkout() {
    $data = [
        'gateway_nonce' => 'good', 'price' => 18.0, 'tax' => 1.5, 'date' => '2026-11-18 10:00:00', 'user_email' => 'b@example.com', 'purchase_key' => 'k',
        'downloads' => [], 'buy_now' => false, 'post_data' => ['edd-gateway' => 'coinsnap'],
        'cart_details' => [[
            'name' => 'Ebook', 'id' => 7, 'item_number' => ['id' => 7, 'options' => ['price_id' => 2]], 'item_price' => 10.0,
            'quantity' => 2, 'discount' => 2.0, 'subtotal' => 20.0, 'tax' => 1.5, 'price' => 18.0,
        ]],
        'user_info' => ['email' => 'b@example.com', 'first_name' => 'Ana', 'last_name' => 'Buyer', 'discount' => 'SAVE2',
            'address' => ['line1' => 'Street 1', 'line2' => '', 'city' => 'Belgrade', 'state' => '', 'zip' => '11000', 'country' => 'RS']],
    ];
    try {
        CoinsnapEDD::getInstance()->coinsnap_payment($data);
    } catch (CheckoutStopped $e) {
    }
    return end($GLOBALS['bodies']) ?: [];
}

$GLOBALS['opts']['edd_settings_coinsnap_webhook'] = ['id' => 'wh1', 'secret' => 'S1', 'url' => 'https://shop.example/index.php?edd-listener=coinsnap'];
foreach (['coinsnap' => 'STORE1', 'btcpay' => 'BS1'] as $provider => $store) {
    $GLOBALS['edd']['coinsnap_provider'] = $provider;
    $GLOBALS['opts']['edd_settings_coinsnap_webhook']['store'] = $provider . ':' . $store;
    $GLOBALS['bodies'] = [];
    $body = checkout();
    $m = $body['metadata'] ?? [];
    ok($body !== [], "$provider: invoice request sent");
    ok((string) ($body['orderId'] ?? '') === '42', "$provider: top-level orderId = payment ID");
    ok((string) ($m['orderId'] ?? '') === '42', "$provider: metadata.orderId = payment ID (webhook match)");
    ok(($m['orderNumber'] ?? '') === 'EDD-42', "$provider: orderNumber = EDD payment number");
    ok(strpos((string) ($m['orderUrl'] ?? ''), 'view-order-details') !== false && strpos((string) ($m['orderUrl'] ?? ''), 'id=42') !== false, "$provider: orderUrl");
    ok(($m['itemDesc'] ?? '') === 'Ebook x 2' && ($m['itemCode'] ?? '') === 'EBOOK-7', "$provider: itemDesc, itemCode");
    $li = $m['lineItems'][0] ?? [];
    ok(($li['name'] ?? '') === 'Ebook' && ($li['sku'] ?? '') === 'EBOOK-7' && ($li['productId'] ?? 0) === 7 && ($li['priceId'] ?? -1) === 2
        && ($li['quantity'] ?? 0) === 2 && (float) ($li['price'] ?? 0) === 10.0 && (float) ($li['total'] ?? 0) === 18.0, "$provider: lineItems");
    ok(($m['couponCodes'] ?? []) === ['SAVE2'] && (float) ($m['discountTotal'] ?? 0) === 2.0 && (float) ($m['taxTotal'] ?? 0) === 1.5, "$provider: coupons, discount, tax");
    ok(($m['platform'] ?? '') === 'easydigitaldownloads' && ($m['pluginVersion'] ?? '') === COINSNAPEDD_PLUGIN_VERSION && ($m['language'] ?? '') === 'de_DE', "$provider: platform, pluginVersion, language");
    ok(($body['buyerEmail'] ?? '') === 'b@example.com' && ($m['buyerEmail'] ?? '') === 'b@example.com', "$provider: buyerEmail (top level and metadata)");
    ok(($m['buyerName'] ?? '') === 'Ana Buyer' && ($m['customerName'] ?? '') === 'Ana Buyer', "$provider: buyerName, customerName");
    ok(($m['buyerAddress1'] ?? '') === 'Street 1' && ($m['buyerCity'] ?? '') === 'Belgrade' && ($m['buyerZip'] ?? '') === '11000' && ($m['buyerCountry'] ?? '') === 'RS', "$provider: address");
    ok(!array_key_exists('buyerAddress2', $m) && !array_key_exists('buyerState', $m), "$provider: empty values left out");
}

echo $fail ? "\n$fail FAILED\n" : "\nALL PASS\n";
exit($fail ? 1 : 0);
