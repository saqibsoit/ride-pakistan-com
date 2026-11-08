<?php

declare(strict_types=1);

namespace App\Support\Stripe;

use RuntimeException;
use Stripe\Checkout\Session;
use Stripe\Event;
use Stripe\Refund;
use Stripe\StripeClient;
use Stripe\Webhook;

/**
 * StripeGateway over the official stripe-php client. Keys come from
 * config/services.php (env only, never code).
 */
final class StripeClientGateway implements StripeGateway
{
    private ?StripeClient $client = null;

    public function __construct(
        private readonly string $secret,
        private readonly string $webhookSecret,
    ) {}

    public function isTestMode(): bool
    {
        return str_starts_with($this->secret, 'sk_test_') || str_starts_with($this->secret, 'rk_test_');
    }

    public function createCheckoutSession(array $params, string $idempotencyKey): Session
    {
        return $this->client()->checkout->sessions->create($params, ['idempotency_key' => $idempotencyKey]);
    }

    public function retrieveCheckoutSession(string $id): Session
    {
        return $this->client()->checkout->sessions->retrieve($id);
    }

    public function expireCheckoutSession(string $id): Session
    {
        return $this->client()->checkout->sessions->expire($id);
    }

    public function createRefund(array $params, string $idempotencyKey): Refund
    {
        return $this->client()->refunds->create($params, ['idempotency_key' => $idempotencyKey]);
    }

    public function constructEvent(string $payload, string $signatureHeader): Event
    {
        if ($this->webhookSecret === '') {
            throw new RuntimeException('STRIPE_WEBHOOK_SECRET is not set.');
        }

        return Webhook::constructEvent($payload, $signatureHeader, $this->webhookSecret);
    }

    private function client(): StripeClient
    {
        if ($this->secret === '') {
            throw new RuntimeException('STRIPE_SECRET is not set.');
        }

        return $this->client ??= new StripeClient($this->secret);
    }
}
