<?php
declare(strict_types=1);
namespace Coinsnap\Exception;

if (!defined('ABSPATH')) {
    exit;
}

class RequestException extends CSException {
    private $responseBody;

    // The body stays out of the message: error replies can echo submitted customer data.
    public function __construct(string $method, string $url, int $status, string $body){
        $this->responseBody = $body;
        // Host only: the path holds store and invoice IDs.
        $message = 'Error during ' . $method . ' to ' . (parse_url($url, PHP_URL_HOST) ?: 'the payment server') . '. Got response (' . $status . ').';
        parent::__construct($message, $status);
    }

    public function getResponseBody(): string {
        return $this->responseBody;
    }
}
