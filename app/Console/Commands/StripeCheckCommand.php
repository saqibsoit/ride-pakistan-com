<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Support\Stripe\StripeGateway;
use Illuminate\Console\Command;
use Illuminate\Support\Str;
use Throwable;

/**
 * Confirms the Stripe account can create a PKR Checkout Session (prompt 06,
 * step 1). Test keys only; the session is expired straight after.
 */
final class StripeCheckCommand extends Command
{
    /** PKR 10,000 in paisa. */
    private const AMOUNT_MINOR = 1_000_000;

    protected $signature = 'stripe:check';

    protected $description = 'Create (and immediately expire) a test PKR Checkout Session to confirm the Stripe account supports PKR';

    public function handle(StripeGateway $stripe): int
    {
        if (! $stripe->isTestMode()) {
            $this->error('STRIPE_SECRET must be a test key (sk_test_…). Refusing to run.');

            return self::FAILURE;
        }

        try {
            $session = $stripe->createCheckoutSession([
                'mode' => 'payment',
                'line_items' => [[
                    'quantity' => 1,
                    'price_data' => [
                        'currency' => 'pkr',
                        'unit_amount' => self::AMOUNT_MINOR,
                        'product_data' => ['name' => 'stripe:check (Ride Pakistan)'],
                    ],
                ]],
                'success_url' => url('/'),
                'cancel_url' => url('/'),
                'metadata' => ['purpose' => 'stripe:check'],
            ], 'stripe-check:'.Str::uuid()->toString());
        } catch (Throwable $e) {
            $this->error('Stripe could not create a PKR Checkout Session: '.$e->getMessage());

            return self::FAILURE;
        }

        $this->table(['Field', 'Value'], [
            ['id', $session->id],
            ['currency', (string) $session->currency],
            ['amount_total', (string) $session->amount_total],
            ['status', (string) $session->status],
            ['url', (string) $session->url],
        ]);

        $ok = $session->currency === 'pkr' && $session->amount_total === self::AMOUNT_MINOR;

        try {
            $stripe->expireCheckoutSession($session->id);
        } catch (Throwable $e) {
            $this->warn('Could not expire the test session: '.$e->getMessage());
        }

        if (! $ok) {
            $this->error('The session was created but not in PKR for the expected amount.');

            return self::FAILURE;
        }

        $this->info('OK: this account can create PKR Checkout Sessions.');

        return self::SUCCESS;
    }
}
