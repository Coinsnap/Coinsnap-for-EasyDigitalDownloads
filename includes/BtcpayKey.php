<?php
declare(strict_types=1);

namespace CoinsnapEDD;

defined('ABSPATH') || exit;

// What a BTCPay API key may do is asked from BTCPay, never taken from the request that brought the key.
class BtcpayKey
{
    // Only what the plugin uses: invoices, the store's payment methods and rates, and its own webhook.
    public const NEEDED_PERMISSIONS = [
        'btcpay.store.canviewinvoices',
        'btcpay.store.cancreateinvoice',
        'btcpay.store.canviewstoresettings',
        'btcpay.store.webhooks.canmodifywebhooks',
    ];

    // The one store the key is limited to, or null if the key is unrestricted, spans stores or lacks a needed permission.
    public static function storeId(array $permissions): ?string
    {
        if ($permissions === []) {
            return null;
        }
        $storeId = null;
        foreach ($permissions as $permission) {
            $parts = explode(':', (string) $permission, 2);
            $policy = $parts[0];
            $scope = $parts[1] ?? '';
            if (strpos($policy, 'btcpay.store.') !== 0 || $scope === '') {
                return null; // unrestricted, server or user rights, or valid for every store
            }
            if ($storeId !== null && $scope !== $storeId) {
                return null; // more than one store
            }
            $storeId = $scope;
        }
        foreach (self::NEEDED_PERMISSIONS as $needed) {
            if (!in_array($needed . ':' . $storeId, $permissions, true)) {
                return null;
            }
        }
        return $storeId;
    }

    // Permissions of the key as BTCPay reports them (GET /api/v1/api-keys/current), or null.
    public static function permissions(string $serverUrl, string $apiKey): ?array
    {
        if ($serverUrl === '' || $apiKey === '') {
            return null;
        }
        $response = wp_remote_get(rtrim($serverUrl, '/') . '/api/v1/api-keys/current', [
            'timeout' => 15,
            'headers' => ['Authorization' => 'token ' . $apiKey, 'Accept' => 'application/json'],
        ]);
        if (is_wp_error($response) || (int) wp_remote_retrieve_response_code($response) !== 200) {
            return null;
        }
        $key = json_decode((string) wp_remote_retrieve_body($response), true);
        return (is_array($key) && is_array($key['permissions'] ?? null)) ? array_values(array_map('strval', $key['permissions'])) : null;
    }

    // The store the key is limited to, if BTCPay confirms it is acceptable (and, when given, equals $expectedStoreId).
    public static function verify(string $serverUrl, string $apiKey, string $expectedStoreId = ''): ?string
    {
        $permissions = self::permissions($serverUrl, $apiKey);
        $storeId = ($permissions === null) ? null : self::storeId($permissions);
        if ($storeId === null || ($expectedStoreId !== '' && $storeId !== $expectedStoreId)) {
            return null;
        }
        return $storeId;
    }
}
