<?php
declare(strict_types=1);
namespace Coinsnap\Result;

if (!defined('ABSPATH')) {
    exit;
}

class WebhookCreated extends Webhook {
    public function getSecret(): string
    {
        $data = $this->getData();
        return $data['secret'];
    }
}
