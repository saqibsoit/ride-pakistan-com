<?php

declare(strict_types=1);

namespace App\Actions\Booking;

use App\Actions\Orders\GenerateOrderReference;
use App\Enums\OrderStatus;
use App\Enums\ProductType;
use App\Enums\SettingKey;
use App\Exceptions\BookingRejected;
use App\Models\Order;
use App\Models\Product;
use App\Models\Slot;
use App\Models\User;
use App\Support\Settings;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Creates an unpaid order and holds its seats (PLAN §3.1).
 *
 * Prices come only from the product row: the caller passes ids, never
 * amounts. Single and private sessions cost the product price per rider;
 * a package costs the product price once and may include a first session
 * for one or more riders (paid with credits from the new pack on
 * fulfilment). Re-submitting the same booking while its order is still
 * held returns that order instead of holding seats twice.
 */
final class CreateOrder
{
    public function __construct(
        private readonly HoldSeats $holdSeats,
        private readonly SlotLock $slotLock,
        private readonly GenerateOrderReference $references,
        private readonly Settings $settings,
    ) {}

    /**
     * @param  list<int>  $riderIds  the customer's riders taking a seat in $slotId
     *
     * @throws BookingRejected
     */
    public function handle(User $user, Product $product, ?int $slotId, array $riderIds): Order
    {
        // Re-read: prices and type come from the database, never from a
        // model that a form or component may have filled in.
        $product = Product::query()->whereKey($product->id)->firstOrFail();

        $riderIds = array_values(array_unique(array_map('intval', $riderIds)));
        sort($riderIds);

        $this->validate($user, $product, $slotId, $riderIds);

        $draftKey = hash('sha256', implode('|', [$product->id, $slotId ?? '-', implode(',', $riderIds)]));

        $existing = $user->orders()
            ->where('status', OrderStatus::PendingPayment)
            ->where('expires_at', '>', now())
            ->where('meta->draft', $draftKey)
            ->latest('id')
            ->first();

        if ($existing !== null) {
            return $existing;
        }

        $expiresAt = CarbonImmutable::now()->addMinutes($this->settings->int(SettingKey::CardHoldMinutes) ?? 30);
        $private = $product->type === ProductType::Private;
        $package = $product->type === ProductType::Package;

        $amount = PriceQuote::for($product, count($riderIds))->totalMinor;

        return DB::transaction(function () use ($user, $product, $slotId, $riderIds, $draftKey, $expiresAt, $private, $package, $amount): Order {
            // Slot lock first. Inserting order items checks the slot_id
            // foreign key, which takes a shared lock on the slot row; two
            // orders holding that and then both asking for the exclusive
            // lock in HoldSeats would deadlock.
            if ($slotId !== null) {
                $this->slotLock->acquire($slotId);
            }

            $order = Order::query()->create([
                'reference' => $this->references->handle(),
                'user_id' => $user->id,
                'product_id' => $product->id,
                'amount_minor' => $amount,
                'currency' => 'PKR',
                'expires_at' => $expiresAt,
                'meta' => ['draft' => $draftKey],
            ]);

            if ($package) {
                // The pack itself; first-session seats below are paid by its credits.
                $order->items()->create(['slot_id' => null, 'rider_id' => null, 'unit_price_minor' => $product->price_minor]);
            }

            foreach ($riderIds as $riderId) {
                $order->items()->create([
                    'slot_id' => $slotId,
                    'rider_id' => $riderId,
                    'unit_price_minor' => $package ? 0 : $product->price_minor,
                ]);
            }

            if ($slotId !== null) {
                $this->holdSeats->handle($order, $slotId, $riderIds, $private, $expiresAt);
            }

            activity('orders')
                ->performedOn($order)
                ->causedBy($user)
                ->event('created')
                ->withProperties(['product' => $product->slug, 'slot_id' => $slotId, 'riders' => count($riderIds), 'amount_minor' => $amount])
                ->log('Order '.$order->reference.' created');

            return $order;
        });
    }

    /**
     * @param  list<int>  $riderIds
     *
     * @throws BookingRejected
     */
    private function validate(User $user, Product $product, ?int $slotId, array $riderIds): void
    {
        if (! $product->is_active) {
            throw BookingRejected::invalidProduct();
        }

        $owned = $riderIds === [] ? 0 : $user->riders()->whereIn('id', $riderIds)->count();

        if ($owned !== count($riderIds)) {
            throw BookingRejected::invalidRiders();
        }

        if ($product->type === ProductType::Package) {
            if ($slotId === null && $riderIds !== []) {
                throw BookingRejected::invalidRiders();
            }

            if ($slotId !== null && $riderIds === []) {
                throw BookingRejected::invalidRiders();
            }

            if (count($riderIds) > $product->sessions_count) {
                throw BookingRejected::noCredits('A '.$product->name.' has '.$product->sessions_count.' sessions.');
            }

            // The pack starts when it is paid; its first session must fall inside it.
            $startsAt = $slotId === null ? null : Slot::query()->whereKey($slotId)->value('starts_at');

            if ($startsAt !== null && CarbonImmutable::parse($startsAt)->isAfter(now()->addDays((int) $product->validity_days))) {
                throw BookingRejected::noCredits('That slot is after this package would expire. Choose an earlier one.');
            }

            return;
        }

        if ($slotId === null) {
            throw BookingRejected::slotNotBookable();
        }

        if ($riderIds === []) {
            throw BookingRejected::invalidRiders();
        }
    }
}
