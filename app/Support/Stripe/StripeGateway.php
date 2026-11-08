<?php

declare(strict_types=1);

namespace App\Support\Stripe;

use Stripe\Checkout\Session;
use Stripe\Event;
use Stripe\Exception\ApiErrorException;
use Stripe\Exception\SignatureVerificationException;
use Stripe\Refund;

/**
 * The few Stripe calls the app makes (PLAN §3.1). Bound to
 * StripeClientGateway; tests bind a fake so no request leaves the machine.
 */
interface StripeGateway
{
    /** True when the configured secret key is a test-mode key. */
    public function isTestMode(): bool;

    /**
     * @param  array<string, mixed>  $params
     *
     * @throws ApiErrorException
     */
    public function createCheckoutSession(array $params, string $idempotencyKey): Session;

    /** @throws ApiErrorException */
    public function retrieveCheckoutSession(string $id): Session;

    /** @throws ApiErrorException */
    public function expireCheckoutSession(string $id): Session;

    /**
     * @param  array<string, mixed>  $params
     *
     * @throws ApiErrorException
     */
    public function createRefund(array $params, string $idempotencyKey): Refund;

    /**
     * Verifies the Stripe-Signature header against the webhook secret.
     *
     * @throws SignatureVerificationException
     * @throws \UnexpectedValueException invalid JSON
     */
    public function constructEvent(string $payload, string $signatureHeader): Event;
}
