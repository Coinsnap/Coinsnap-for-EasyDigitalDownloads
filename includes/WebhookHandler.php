<?php
declare(strict_types=1);

namespace CoinsnapEDD\Webhook;

defined('ABSPATH') || exit;

// Only the invoice id is taken from the request; every decision comes from the payment server's API.
class WebhookHandler
{
    private const COMPLETED = ['complete', 'publish'];
    private const FINAL = ['complete', 'publish', 'refunded', 'partially_refunded', 'revoked'];
    private const OPEN = ['pending', 'processing', 'private'];

    public function __construct(
        private string $provider,
        private string $secret,
        private array $statuses
    ) {
    }

    public function handle(string $rawBody, string $signatureHeader, int $nowSeconds): int
    {
        $problem = ($this->provider === 'btcpay')
            ? Signature::btcpayProblem($signatureHeader, $rawBody, $this->secret)
            : Signature::coinsnapProblem($signatureHeader, $rawBody, $this->secret, $nowSeconds);
        if ($problem !== null) {
            $this->logSecurity($problem);
            return 401;
        }

        $body = json_decode($rawBody, true);
        $invoiceId = (is_array($body) && is_string($body['invoiceId'] ?? null)) ? $body['invoiceId'] : '';
        if ($invoiceId === '') {
            return 200;
        }
        $claimsPaid = in_array($body['type'] ?? '', ['Settled', 'InvoiceSettled'], true);

        try {
            $invoice = $this->fetchInvoice($invoiceId);
        } catch (\Coinsnap\Exception\RequestException $e) {
            if (in_array($e->getCode(), [400, 404], true)) {
                // The dashboard's "Send test webhook" button lands here.
                $this->logInfo('unknown invoice', $invoiceId);
                return 200;
            }
            $this->logInfo('retry: API unavailable', $invoiceId);
            return 503;
        } catch (\Throwable $e) {
            $this->logInfo('retry: API unavailable', $invoiceId);
            return 503;
        }

        $metadata = is_array($invoice['metadata'] ?? null) ? $invoice['metadata'] : [];
        $orderId = ($this->provider === 'btcpay') ? ($metadata['orderId'] ?? '') : ($invoice['orderId'] ?? '');
        $paymentId = (is_scalar($orderId) && ctype_digit((string) $orderId)) ? (int) $orderId : 0;
        $payment = $paymentId > 0 ? $this->payment($paymentId) : null;
        if ($payment === null || $payment['gateway'] !== 'coinsnap') {
            $this->logInfo('not ours', $invoiceId);
            return 200;
        }

        $recorded = $payment['invoices'];
        if (is_array($recorded) && $recorded !== [] && !isset($recorded[$invoiceId])) {
            $this->logSecurity('invoice not created for this payment', $invoiceId);
            return 200;
        }
        // Payments created before 1.4.0 have no record; check against the payment total.
        $expected = (is_array($recorded) && isset($recorded[$invoiceId])) ? $recorded[$invoiceId] : [
            'amount' => number_format(round((float) $payment['total'], 2), 2, '.', ''),
            'currency' => (string) $payment['currency'],
        ];

        $decision = InvoiceDecision::decide($invoice, $this->provider === 'btcpay', (string) $expected['amount'], (string) $expected['currency']);
        if ($decision !== InvoiceDecision::PAID && ($claimsPaid || $decision === InvoiceDecision::MISMATCH)) {
            $this->logSecurity($claimsPaid ? 'forged status' : 'amount mismatch', $invoiceId);
            return 200;
        }
        if ($decision === InvoiceDecision::NONE) {
            $this->logInfo('no change', $invoiceId);
            return 200;
        }

        if (!$this->lock($paymentId)) {
            return 503;
        }
        try {
            // Read again under the lock so a parallel webhook's change is seen.
            $current = (string) ($this->payment($paymentId)['status'] ?? '');
            $this->apply($decision, $paymentId, $current, $invoiceId);
        } finally {
            $this->unlock($paymentId);
        }
        return 200;
    }

    // Statuses only move forward; a completed, refunded or revoked payment is never changed.
    private function apply(string $decision, int $paymentId, string $current, string $invoiceId): void
    {
        if (in_array($current, self::FINAL, true)) {
            $this->logInfo('already processed', $invoiceId);
            return;
        }
        $target = '';
        if ($decision === InvoiceDecision::PAID) {
            // A late payment may still settle a failed or abandoned payment.
            $target = $this->statuses['settled'] ?: 'complete';
        }
        elseif ($decision === InvoiceDecision::PROCESSING && in_array($current, ['pending', 'private'], true)) {
            $target = $this->statuses['processing'];
        }
        elseif ($decision === InvoiceDecision::EXPIRED && in_array($current, self::OPEN, true)) {
            $target = $this->statuses['expired'];
        }
        // Only a settled, verified invoice may complete a payment, whatever the settings say.
        if ($decision !== InvoiceDecision::PAID && in_array($target, self::FINAL, true)) {
            $target = '';
        }
        if ($target === '' || $target === $current) {
            $this->logInfo('no change', $invoiceId);
            return;
        }
        $this->setStatus($paymentId, $target, sprintf('Coinsnap invoice %s: %s', $invoiceId, $decision));
        $this->logInfo('status ' . $decision, $invoiceId);
    }

    protected function fetchInvoice(string $invoiceId): array
    {
        $apiUrl = ($this->provider === 'btcpay') ? (string) edd_get_option('btcpay_server_url') : COINSNAP_SERVER_URL;
        $apiKey = ($this->provider === 'btcpay') ? (string) edd_get_option('btcpay_api_key') : (string) edd_get_option('coinsnap_api_key', '');
        $storeId = ($this->provider === 'btcpay') ? (string) edd_get_option('btcpay_store_id') : (string) edd_get_option('coinsnap_store_id', '');
        return (new \Coinsnap\Client\Invoice($apiUrl, $apiKey))->getInvoice($storeId, $invoiceId)->getData();
    }

    protected function payment(int $paymentId): ?array
    {
        $payment = edd_get_payment($paymentId);
        if (!$payment || empty($payment->ID)) {
            return null;
        }
        return [
            'status' => (string) $payment->status,
            'gateway' => (string) $payment->gateway,
            'total' => (string) $payment->total,
            'currency' => (string) $payment->currency,
            'invoices' => edd_get_payment_meta($paymentId, '_coinsnap_invoices', true),
        ];
    }

    protected function setStatus(int $paymentId, string $status, string $note): void
    {
        edd_update_payment_status($paymentId, $status);
        edd_insert_payment_note($paymentId, $note);
    }

    protected function lock(int $paymentId): bool
    {
        global $wpdb;
        return (int) $wpdb->get_var($wpdb->prepare('SELECT GET_LOCK(%s, 10)', 'coinsnap_edd_' . $paymentId)) === 1;
    }

    protected function unlock(int $paymentId): void
    {
        global $wpdb;
        $wpdb->get_var($wpdb->prepare('SELECT RELEASE_LOCK(%s)', 'coinsnap_edd_' . $paymentId));
    }

    protected function logInfo(string $reason, string $invoiceId = ''): void
    {
        if (function_exists('edd_debug_log')) {
            edd_debug_log(self::line('INFO', $reason, $invoiceId));
        }
    }

    // At most 10 security lines a minute, so a flood of fake requests can't fill the log.
    protected function logSecurity(string $reason, string $invoiceId = ''): void
    {
        $key = 'coinsnapedd_seclog_' . gmdate('YmdHi');
        $count = (int) get_transient($key) + 1;
        set_transient($key, $count, 120);
        if ($count === 11) {
            edd_record_gateway_error('Coinsnap', 'Coinsnap SECURITY webhook: more rejections this minute, not logged');
        }
        if ($count <= 10) {
            edd_record_gateway_error('Coinsnap', self::line('SECURITY', $reason, $invoiceId));
        }
    }

    public static function line(string $level, string $reason, string $invoiceId): string
    {
        // The invoice id comes from the request.
        $id = substr((string) preg_replace('/[^A-Za-z0-9_-]/', '', $invoiceId), 0, 64);
        return sprintf('Coinsnap %s webhook reason=%s invoice=%s', $level, $reason, $id !== '' ? $id : '-');
    }
}
