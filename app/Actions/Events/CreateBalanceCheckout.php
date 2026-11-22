<?php

declare(strict_types=1);

namespace App\Actions\Events;

use App\Actions\Payments\CreateStripeCheckout;
use App\Exceptions\BookingRejected;
use App\Models\Order;
use App\Models\OrderBalance;
use App\Support\Money;
use App\Support\Stripe\StripeGateway;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Stripe\Checkout\Session;
use Throwable;

/**
 * "Pay the balance" on the manage page (decided 2026-11-22): a Stripe
 * Checkout Session for an event order's balance, card only, while the
 * balance is due and online payment is still open. Marked
 * metadata.kind = balance and client_reference_id = balance:{id}, so the
 * webhook sends it to HandleBalanceStripeEvent and never to FulfilOrder
 * (the deposit already confirmed the seats). An open session is reused.
 */
final class CreateBalanceCheckout
{
    public const KIND = 'balance';

    public const SESSION_MINUTES = 60;

    public function __construct(private readonly StripeGateway $stripe) {}

    /**
     * @return string the Stripe-hosted Checkout URL
     *
     * @throws BookingRejected
     */
    public function handle(Order $order): string
    {
        $balance = $order->balance()->first();

        if ($balance === null || ! $balance->canPayOnline()) {
            throw BookingRejected::notAllowed('There is no balance to pay online for this booking.');
        }

        if ($balance->stripe_checkout_session_id !== null) {
            try {
                $session = $this->stripe->retrieveCheckoutSession($balance->stripe_checkout_session_id);

                if ($session->status === Session::STATUS_OPEN) {
                    return (string) $session->url;
                }

                if ($session->status === Session::STATUS_COMPLETE) {
                    throw BookingRejected::notAllowed('Your balance payment is already on its way. It can take a minute to show here.');
                }
            } catch (BookingRejected $e) {
                throw $e;
            } catch (Throwable) {
                // Make a new one below.
            }
        }

        $attempt = DB::transaction(function () use ($balance): int {
            $locked = OrderBalance::query()->whereKey($balance->id)->lockForUpdate()->firstOrFail();

            if (! $locked->canPayOnline()) {
                throw BookingRejected::notAllowed('There is no balance to pay online for this booking.');
            }

            $locked->stripe_attempts++;
            $locked->save();

            return $locked->stripe_attempts;
        });

        $balance->refresh();
        $order->loadMissing('user');

        try {
            $session = $this->stripe->createCheckoutSession($this->params($order, $balance), 'balance-checkout:'.$balance->id.':'.$attempt);
        } catch (Throwable $e) {
            Log::error('Stripe Checkout Session failed for the balance of order '.$order->reference, ['error' => $e->getMessage()]);

            throw BookingRejected::notAllowed('Card payment is not available right now. Please try again in a minute, or pay on the day.');
        }

        $balance->stripe_checkout_session_id = $session->id;
        $balance->save();

        activity('payments')
            ->performedOn($order)
            ->causedBy($order->user)
            ->event('balance_checkout_started')
            ->withProperties(['session' => $session->id, 'attempt' => $attempt, 'amount_minor' => $balance->amount_minor])
            ->log('Balance payment started for order '.$order->reference);

        return (string) $session->url;
    }

    /**
     * @return array<string, mixed>
     */
    private function params(Order $order, OrderBalance $balance): array
    {
        $metadata = ['kind' => self::KIND, 'order_balance_id' => (string) $balance->id, 'reference' => $order->reference];
        $manageUrl = route('manage.show', ['reference' => $order->reference]);
        $suffix = trim((string) config('services.stripe.statement_descriptor_suffix'));
        $expiresAt = CarbonImmutable::now()->addMinutes(self::SESSION_MINUTES);

        $params = [
            'mode' => 'payment',
            'line_items' => [[
                'quantity' => 1,
                'price_data' => [
                    'currency' => 'pkr',
                    'unit_amount' => $balance->amount_minor,
                    'product_data' => [
                        'name' => $order->productName().', balance',
                        'description' => 'Balance for booking '.$order->reference.' ('.Money::format($balance->amount_minor, $balance->currency).').',
                    ],
                ],
            ]],
            'client_reference_id' => self::KIND.':'.$balance->id,
            'metadata' => $metadata,
            'payment_intent_data' => array_filter([
                'description' => CreateStripeCheckout::BRAND_LINE.', balance '.$order->reference,
                'metadata' => $metadata,
                'statement_descriptor_suffix' => $suffix === '' ? null : mb_substr($suffix, 0, 22),
            ]),
            'success_url' => $manageUrl.'?balance=paid',
            'cancel_url' => $manageUrl.'?balance=cancelled',
            'expires_at' => $expiresAt->getTimestamp(),
        ];

        $email = (string) $order->user->email;

        if ($email !== '' && ! str_ends_with($email, '.invalid')) {
            $params['customer_email'] = $email;
        }

        return $params;
    }
}
