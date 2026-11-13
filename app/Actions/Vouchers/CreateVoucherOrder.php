<?php

declare(strict_types=1);

namespace App\Actions\Vouchers;

use App\Exceptions\VoucherRejected;
use App\Models\Product;
use App\Models\User;
use App\Models\VoucherOrder;
use App\Support\Money;
use Carbon\CarbonImmutable;

/**
 * Starts a gift voucher purchase (milestone 10e). The amount is either
 * the current price of an active product (re-read from the database,
 * never taken from the request) or a PKR amount within the settings'
 * range. A product voucher holds that price in PKR, labelled with the
 * product name; it can be spent on any order. Nothing is issued here: the
 * voucher exists only once payment is confirmed (IssueVoucher).
 */
final class CreateVoucherOrder
{
    public function __construct(
        private readonly VoucherRules $rules,
        private readonly GenerateVoucherReference $references,
    ) {}

    /**
     * @param  int|null  $amountMinor  used when no product is chosen
     * @param  CarbonImmutable|null  $sendOn  Pakistan date; null sends straight after payment
     *
     * @throws VoucherRejected
     */
    public function handle(
        User $user,
        ?int $productId,
        ?int $amountMinor,
        string $recipientName,
        string $recipientEmail,
        ?string $message,
        ?CarbonImmutable $sendOn,
        ?string $guestName = null,
    ): VoucherOrder {
        $product = null;

        if ($productId !== null) {
            $product = Product::query()->active()->whereKey($productId)->first();

            if ($product === null || $product->price_minor <= 0) {
                throw VoucherRejected::productNotOnSale();
            }

            $amountMinor = $product->price_minor;
        } else {
            $amountMinor = (int) $amountMinor;
            $min = $this->rules->minAmountMinor();
            $max = $this->rules->maxAmountMinor();

            if ($amountMinor < $min || $amountMinor > $max || $amountMinor % Money::MINOR_PER_MAJOR !== 0) {
                throw VoucherRejected::amountOutOfRange(Money::format($min), Money::format($max));
            }
        }

        if ($sendOn !== null) {
            $today = VoucherRules::today();
            $sendOn = CarbonImmutable::parse($sendOn->format('Y-m-d'), (string) config('app.display_timezone'))->startOfDay();

            if ($sendOn->lessThan($today) || $sendOn->greaterThan($today->addDays(VoucherRules::MAX_SEND_AHEAD_DAYS))) {
                throw VoucherRejected::sendDate(VoucherRules::MAX_SEND_AHEAD_DAYS);
            }

            // Today means straight away.
            if ($sendOn->equalTo($today)) {
                $sendOn = null;
            }
        }

        $message = trim((string) $message);

        $order = VoucherOrder::query()->create([
            'reference' => $this->references->handle(),
            'user_id' => $user->id,
            'product_id' => $product?->id,
            'product_name' => $product?->name,
            'amount_minor' => $amountMinor,
            'currency' => 'PKR',
            'recipient_name' => mb_substr(trim($recipientName), 0, 120),
            'recipient_email' => mb_strtolower(trim($recipientEmail)),
            'message' => $message === '' ? null : mb_substr($message, 0, VoucherRules::MAX_MESSAGE_LENGTH),
            'send_on' => $sendOn?->format('Y-m-d'),
            'expires_at' => CarbonImmutable::now()->addHours(VoucherRules::UNPAID_HOURS),
            // Guest checkout: the buyer's typed name is who the voucher is "from".
            'placed_as_guest' => $guestName !== null,
            'contact_name' => $guestName === null ? null : mb_substr(trim($guestName), 0, 120),
        ]);

        activity('vouchers')
            ->performedOn($order)
            ->causedBy($user)
            ->event('created')
            ->withProperties(['amount_minor' => $amountMinor, 'product_id' => $product?->id, 'send_on' => $order->send_on?->format('Y-m-d')])
            ->log('Voucher purchase '.$order->reference.' started');

        return $order;
    }
}
