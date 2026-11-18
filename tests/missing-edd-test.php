<?php
// Run: php tests/missing-edd-test.php
// With Easy Digital Downloads deactivated, loading this plugin must not be fatal; it only shows an admin notice.
if (PHP_SAPI !== 'cli') {
    exit;
}
define('ABSPATH', __DIR__ . '/');
$GLOBALS['hooks'] = [];
function add_action($hook, $cb, ...$a) { $GLOBALS['hooks'][] = [$hook, $cb]; }
function add_filter($hook, $cb, ...$a) { $GLOBALS['hooks'][] = [$hook, $cb]; }
function is_admin() { return true; }
function get_bloginfo($k) { return '6.8'; }
function get_plugins() { return []; }
function get_option($k, $d = false) { return $d; }
function add_option(...$a) { return true; }

$fail = 0;
function ok($c, $m) { global $fail; echo ($c ? 'PASS ' : 'FAIL ') . $m . "\n"; if (!$c) { $fail++; } }

try {
    require __DIR__ . '/../coinsnap-for-easy-digital-downloads.php';
    $error = '';
} catch (\Throwable $e) {
    $error = get_class($e) . ': ' . $e->getMessage();
}
ok($error === '', 'plugin loads without EDD (no fatal)' . ($error !== '' ? " [$error]" : ''));

$classHooks = array_values(array_filter($GLOBALS['hooks'], function ($h) { return is_array($h[1]) && $h[1][0] instanceof CoinsnapEDD; }));
ok(count($classHooks) === 1 && $classHooks[0][0] === 'admin_notices' && $classHooks[0][1][1] === 'coinsnap_edd_notice', 'only the "EDD required" admin notice is registered');
ok(count(array_filter($GLOBALS['hooks'], function ($h) { return $h[0] === 'edd_gateway_coinsnap' || ($h[0] === 'init' && is_array($h[1])); })) === 0, 'no gateway or webhook hooks without EDD');

echo $fail ? "\n$fail FAILED\n" : "\nALL PASS\n";
exit($fail ? 1 : 0);
