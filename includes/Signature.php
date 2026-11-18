<?php
declare(strict_types=1);

namespace CoinsnapEDD\Webhook;

defined('ABSPATH') || exit;

// Each check returns null when the signature is valid, otherwise the reason for the log.
class Signature
{
    public const BAD_SIGNATURE = 'bad signature';
    public const STALE_TIMESTAMP = 'stale timestamp';
    public const MAX_AGE_SECONDS = 300;

    // X-Coinsnap-Signature: t=<unix time>,v1=<hex HMAC-SHA256 of "t.body">
    public static function coinsnapProblem(string $header, string $rawBody, string $secret, int $nowSeconds): ?string
    {
        parse_str(str_replace(',', '&', $header), $parts);
        $timestamp = (int) ($parts['t'] ?? 0);
        $signature = $parts['v1'] ?? '';

        if ($secret === '' || $timestamp <= 0 || !is_string($signature)) {
            return self::BAD_SIGNATURE;
        }
        if (!hash_equals(hash_hmac('sha256', $timestamp . '.' . $rawBody, $secret), $signature)) {
            return self::BAD_SIGNATURE;
        }
        if (abs($nowSeconds - $timestamp) > self::MAX_AGE_SECONDS) {
            return self::STALE_TIMESTAMP;
        }

        return null;
    }

    // BTCPay-Sig: sha256=<hex HMAC-SHA256 of body>
    public static function btcpayProblem(string $header, string $rawBody, string $secret): ?string
    {
        if ($secret === '') {
            return self::BAD_SIGNATURE;
        }

        return hash_equals('sha256=' . hash_hmac('sha256', $rawBody, $secret), $header) ? null : self::BAD_SIGNATURE;
    }
}
