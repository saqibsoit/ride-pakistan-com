<?php

declare(strict_types=1);

namespace App\Actions\Guest;

use App\Actions\Vouchers\CreateVoucherOrder;
use App\Exceptions\GuestCheckoutRejected;
use App\Exceptions\VoucherRejected;
use App\Models\VoucherOrder;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * A gift voucher bought without signing in (guest checkout, decided
 * 2026-11-12): the account behind the buyer's email (ResolveGuestCustomer,
 * never changed or shown), then the usual purchase (CreateVoucherOrder),
 * marked placed_as_guest with the buyer's typed name, which is who the
 * voucher says it is from. One transaction; the caller checks Turnstile
 * and the limits first and grants this browser access afterwards.
 */
final class PlaceGuestVoucherOrder
{
    public function __construct(
        private readonly ResolveGuestCustomer $customers,
        private readonly CreateVoucherOrder $create,
    ) {}

    /**
     * @param  array{name: string, email: string}  $buyer
     *
     * @throws GuestCheckoutRejected
     * @throws VoucherRejected
     */
    public function handle(array $buyer, ?int $productId, ?int $amountMinor, string $recipientName, string $recipientEmail, ?string $message, ?CarbonImmutable $sendOn): VoucherOrder
    {
        return DB::transaction(function () use ($buyer, $productId, $amountMinor, $recipientName, $recipientEmail, $message, $sendOn): VoucherOrder {
            $user = $this->customers->handle($buyer['email'], $buyer['name'], null);

            return $this->create->handle($user, $productId, $amountMinor, $recipientName, $recipientEmail, $message, $sendOn, guestName: $buyer['name']);
        });
    }
}
