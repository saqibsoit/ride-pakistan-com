<?php

declare(strict_types=1);

namespace App\Actions\Events;

use App\Enums\BalanceMethod;
use App\Enums\BalanceStatus;
use App\Enums\PaymentPurpose;
use App\Enums\PaymentStatus;
use App\Models\Order;
use App\Models\OrderBalance;
use App\Models\Payment;
use App\Models\User;
use App\Notifications\EventBalancePaid;
use App\Support\Notifier;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * An admin records an event balance paid at the venue or by transfer
 * (decided 2026-11-22): cash, card at the venue or bank transfer, with an
 * optional reference. Admins only, like every other money action. Writes a
 * balance payment row, marks the balance paid, logs it (payments) and
 * emails the customer a receipt. Paying twice changes nothing.
 */
final class MarkBalancePaid
{
    public function __construct(private readonly Notifier $notifier) {}

    /**
     * @throws AuthorizationException
     */
    public function handle(OrderBalance $balance, User $admin, BalanceMethod $method, ?string $reference = null): OrderBalance
    {
        if (! $admin->isAdmin()) {
            throw new AuthorizationException('Only admins can record payments.');
        }

        if ($method === BalanceMethod::CardOnline) {
            throw new InvalidArgumentException('Online card payments are recorded by Stripe.');
        }

        $reference = $reference === null ? null : (mb_substr(trim($reference), 0, 100) ?: null);

        $result = DB::transaction(function () use ($balance, $admin, $method, $reference): ?OrderBalance {
            $locked = OrderBalance::query()->whereKey($balance->id)->lockForUpdate()->firstOrFail();

            if (! $locked->isDue()) {
                return null;
            }

            $order = Order::query()->whereKey($locked->order_id)->lockForUpdate()->firstOrFail();

            Payment::query()->create([
                'order_id' => $order->id,
                'purpose' => PaymentPurpose::Balance,
                'provider' => $method->provider(),
                // Unique per provider: a typed reference once, else this balance.
                'provider_id' => $reference ?? 'balance-'.$locked->id,
                'amount_minor' => $locked->amount_minor,
                'currency' => $locked->currency,
                'status' => PaymentStatus::Succeeded,
                'raw' => ['recorded_by' => $admin->id],
            ]);

            $locked->status = BalanceStatus::Paid;
            $locked->method = $method;
            $locked->paid_at = CarbonImmutable::now();
            $locked->recorded_by = $admin->id;
            $locked->reference = $reference;
            $locked->save();

            activity('payments')
                ->performedOn($order)
                ->causedBy($admin)
                ->event('balance_paid')
                ->withProperties(['method' => $method->value, 'reference' => $reference, 'amount_minor' => $locked->amount_minor])
                ->log('Balance marked paid ('.$method->getLabel().') for order '.$order->reference);

            return $locked;
        });

        if ($result === null) {
            return $balance->refresh();
        }

        $order = $result->order()->with('user')->firstOrFail();
        $this->notifier->send($order->user, new EventBalancePaid($order, $result), Notifier::key(EventBalancePaid::type(), $order, $order->user));

        $balance->setRawAttributes($result->getAttributes(), true);

        return $balance;
    }
}
