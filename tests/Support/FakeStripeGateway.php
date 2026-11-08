<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Support\Stripe\StripeGateway;
use RuntimeException;
use Stripe\Checkout\Session;
use Stripe\Event;
use Stripe\Exception\InvalidRequestException;
use Stripe\Refund;
use Stripe\Webhook;

/**
 * In-memory Stripe for tests: no request leaves the machine. Webhook
 * signatures are verified for real (stripe-php) against SECRET.
 */
final class FakeStripeGateway implements StripeGateway
{
    public const SECRET = 'whsec_test_ride_pakistan';

    public bool $testMode = true;

    public bool $failCreate = false;

    public bool $failRefund = false;

    public bool $failRetrieve = false;

    /**
     * Browser tests: send the customer straight back to the session's
     * success_url (as Stripe does after paying) instead of a checkout.stripe.com
     * address the test browser can't use. Payment still needs the webhook.
     */
    public bool $returnToSuccessUrl = false;

    /** @var array<string, array<string, mixed>> */
    public array $sessions = [];

    /** @var list<array{params: array<string, mixed>, key: string}> */
    public array $created = [];

    /** @var list<array{params: array<string, mixed>, key: string, id: string}> */
    public array $refunds = [];

    /** @var list<string> */
    public array $expired = [];

    /** @var array<string, string> idempotency key => session id */
    private array $keys = [];

    public function isTestMode(): bool
    {
        return $this->testMode;
    }

    public function createCheckoutSession(array $params, string $idempotencyKey): Session
    {
        if ($this->failCreate) {
            throw new InvalidRequestException('Simulated Stripe failure');
        }

        if (isset($this->keys[$idempotencyKey])) {
            return $this->session($this->keys[$idempotencyKey]);
        }

        $id = 'cs_test_'.(count($this->sessions) + 1);
        $this->created[] = ['params' => $params, 'key' => $idempotencyKey];
        $this->keys[$idempotencyKey] = $id;

        $lines = is_array($params['line_items'] ?? null) ? $params['line_items'] : [];
        $total = 0;
        $currency = null;

        foreach ($lines as $line) {
            $total += (int) $line['quantity'] * (int) $line['price_data']['unit_amount'];
            $currency = $line['price_data']['currency'];
        }

        $this->sessions[$id] = [
            'id' => $id,
            'object' => 'checkout.session',
            'status' => Session::STATUS_OPEN,
            'url' => $this->returnToSuccessUrl && is_string($params['success_url'] ?? null)
                ? str_replace('{CHECKOUT_SESSION_ID}', $id, $params['success_url'])
                : 'https://checkout.stripe.com/c/pay/'.$id,
            'amount_total' => $total,
            'currency' => $currency,
            'client_reference_id' => $params['client_reference_id'] ?? null,
            'metadata' => $params['metadata'] ?? [],
            'expires_at' => $params['expires_at'] ?? null,
        ];

        return $this->session($id);
    }

    public function retrieveCheckoutSession(string $id): Session
    {
        if ($this->failRetrieve) {
            throw new InvalidRequestException('Simulated Stripe failure');
        }

        if (! isset($this->sessions[$id])) {
            throw new InvalidRequestException('No such checkout.session: '.$id);
        }

        return $this->session($id);
    }

    public function expireCheckoutSession(string $id): Session
    {
        if (! isset($this->sessions[$id]) || $this->sessions[$id]['status'] !== Session::STATUS_OPEN) {
            throw new InvalidRequestException('Only open sessions can be expired.');
        }

        $this->sessions[$id]['status'] = Session::STATUS_EXPIRED;
        $this->expired[] = $id;

        return $this->session($id);
    }

    public function createRefund(array $params, string $idempotencyKey): Refund
    {
        if ($this->failRefund) {
            throw new InvalidRequestException('Simulated refund failure');
        }

        foreach ($this->refunds as $refund) {
            if ($refund['key'] === $idempotencyKey) {
                return Refund::constructFrom(['id' => $refund['id'], 'object' => 'refund', 'status' => 'succeeded', 'amount' => $refund['params']['amount'] ?? null]);
            }
        }

        $id = 're_test_'.(count($this->refunds) + 1);
        $this->refunds[] = ['params' => $params, 'key' => $idempotencyKey, 'id' => $id];

        return Refund::constructFrom(['id' => $id, 'object' => 'refund', 'status' => 'succeeded', 'amount' => $params['amount'] ?? null]);
    }

    public function constructEvent(string $payload, string $signatureHeader): Event
    {
        return Webhook::constructEvent($payload, $signatureHeader, self::SECRET);
    }

    /** Mark a session as paid/completed on "Stripe's side". */
    public function complete(string $id): void
    {
        $this->sessions[$id]['status'] = Session::STATUS_COMPLETE;
    }

    /** @return array<string, mixed> */
    public function lastParams(): array
    {
        return $this->created[array_key_last($this->created) ?? throw new RuntimeException('No session created')]['params'];
    }

    private function session(string $id): Session
    {
        return Session::constructFrom($this->sessions[$id]);
    }

    /**
     * Signed Stripe-Signature header for a payload, as Stripe sends it.
     */
    public static function sign(string $payload, ?int $timestamp = null, string $secret = self::SECRET): string
    {
        $timestamp ??= time();

        return 't='.$timestamp.',v1='.hash_hmac('sha256', $timestamp.'.'.$payload, $secret);
    }

    /**
     * @param  array<string, mixed>  $object
     * @return array<string, mixed>
     */
    public static function event(string $type, array $object, ?string $id = null): array
    {
        static $n = 0;

        return [
            'id' => $id ?? 'evt_test_'.(++$n).'_'.bin2hex(random_bytes(4)),
            'object' => 'event',
            'api_version' => '2025-01-27.acacia',
            'created' => time(),
            'type' => $type,
            'livemode' => false,
            'data' => ['object' => $object],
        ];
    }
}
