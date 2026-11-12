<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\Order;
use App\Models\PrivateSessionRequest;
use App\Models\User;
use App\Models\VoucherOrder;
use Illuminate\Contracts\Session\Session;

/**
 * What a signed-out visitor may open (guest checkout, decided 2026-11-12):
 * the orders, gift voucher purchases and private requests they placed in
 * this browser, or opened from a signed "Manage your booking" link. Kept
 * in the session only, as reference => link version, so revoking the
 * links (a higher manage_link_version) also ends access granted earlier.
 * One record never opens another: there is no "everything for this email".
 */
final class GuestAccess
{
    public const SESSION_KEY = 'guest_access';

    public const MAX_PER_KIND = 20;

    public function __construct(
        private readonly Session $session,
        private readonly ManageLinkWindow $window,
    ) {}

    public function grantOrder(Order $order): void
    {
        $this->grant('orders', $order->reference, $order->manage_link_version);
    }

    public function allowsOrder(Order $order): bool
    {
        return $this->has('orders', $order->reference, $order->manage_link_version) && $this->window->isOpen($order);
    }

    /** The order's own signed-in customer, or a current guest grant for it. */
    public function canOpenOrder(?User $user, Order $order): bool
    {
        return ($user !== null && $order->user_id === $user->id) || $this->allowsOrder($order);
    }

    /** The order for $reference if this visitor may open it. */
    public function findOrder(?User $user, string $reference): ?Order
    {
        $order = Order::query()->where('reference', $reference)->first();

        return $order !== null && $this->canOpenOrder($user, $order) ? $order : null;
    }

    public function grantVoucherOrder(VoucherOrder $order): void
    {
        $this->grant('vouchers', $order->reference, $order->manage_link_version);
    }

    public function allowsVoucherOrder(VoucherOrder $order): bool
    {
        return $this->has('vouchers', $order->reference, $order->manage_link_version);
    }

    public function grantRequest(PrivateSessionRequest $request): void
    {
        $this->grant('requests', $request->reference, $request->manage_link_version);
    }

    public function allowsRequest(PrivateSessionRequest $request): bool
    {
        return $this->has('requests', $request->reference, $request->manage_link_version) && PrivateRequestLink::isOpen($request);
    }

    /** The request for $reference if this visitor may open it: its own signed-in customer, or a current grant. */
    public function findRequest(?User $user, string $reference): ?PrivateSessionRequest
    {
        $request = PrivateSessionRequest::query()->where('reference', $reference)->first();

        if ($request === null) {
            return null;
        }

        return ($user !== null && $request->user_id === $user->id) || $this->allowsRequest($request) ? $request : null;
    }

    private function grant(string $kind, string $reference, int $version): void
    {
        /** @var array<string, int> $grants */
        $grants = (array) $this->session->get(self::SESSION_KEY.'.'.$kind, []);
        unset($grants[$reference]);
        $grants[$reference] = $version;

        // Newest last; a long-lived browser keeps only the latest few.
        $this->session->put(self::SESSION_KEY.'.'.$kind, array_slice($grants, -self::MAX_PER_KIND, null, true));
    }

    private function has(string $kind, string $reference, int $version): bool
    {
        $grants = (array) $this->session->get(self::SESSION_KEY.'.'.$kind, []);

        return ($grants[$reference] ?? null) === $version;
    }
}
