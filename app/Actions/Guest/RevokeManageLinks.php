<?php

declare(strict_types=1);

namespace App\Actions\Guest;

use App\Models\Order;
use App\Models\User;
use App\Notifications\ManageLinkReissued;
use App\Support\Notifier;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Stops every "Manage your booking" link sent for an order so far, and
 * every browser that opened one (guest checkout, decided 2026-11-12): the
 * order's manage_link_version goes up, so old signatures and old session
 * grants no longer match. A fresh link goes to the account's email.
 *
 * Admins revoke with a reason (audit log). The customer can revoke from
 * the link itself or from their account; the reason is then fixed.
 */
final class RevokeManageLinks
{
    public const CUSTOMER_REASON = 'The customer stopped the old links.';

    public function __construct(private readonly Notifier $notifier) {}

    public function handle(Order $order, ?User $admin, ?string $reason = null): Order
    {
        if ($admin !== null && ! $admin->isAdmin()) {
            throw new InvalidArgumentException('Only admins revoke manage links for a customer.');
        }

        $reason = $admin === null ? self::CUSTOMER_REASON : trim((string) $reason);

        if ($reason === '') {
            throw new InvalidArgumentException('Give a reason for revoking the links.');
        }

        $order = DB::transaction(function () use ($order, $admin, $reason): Order {
            /** @var Order $locked */
            $locked = Order::query()->whereKey($order->id)->lockForUpdate()->firstOrFail();
            $from = $locked->manage_link_version;

            $locked->forceFill(['manage_link_version' => $from + 1])->save();

            activity('orders')
                ->performedOn($locked)
                ->causedBy($admin)
                ->event('manage_link_revoked')
                ->withProperties(['reason' => $reason, 'from_version' => $from, 'by' => $admin === null ? 'customer' : 'admin'])
                ->log('Manage links for '.$locked->reference.' revoked');

            return $locked;
        });

        $this->notifier->send($order->user, new ManageLinkReissued($order));

        return $order;
    }
}
