<?php

declare(strict_types=1);

namespace App\Http\Controllers\Account;

use App\Enums\BookingStatus;
use App\Enums\CreditPackStatus;
use App\Enums\OrderStatus;
use App\Http\Controllers\Controller;
use App\Models\CreditPack;
use App\Models\PrivateSessionRequest;
use App\Models\User;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;

/** /account: next session and credits left (PLAN §6). */
final class DashboardController extends Controller
{
    public function __invoke(Request $request): View
    {
        /** @var User $user */
        $user = $request->user();

        $nextBooking = $user->bookings()
            ->upcoming()
            ->where('bookings.status', BookingStatus::Confirmed)
            ->with(['slot', 'rider'])
            ->first();

        $packs = $user->creditPacks()
            ->where('status', CreditPackStatus::Active)
            ->where('expires_at', '>', now())
            ->orderBy('expires_at')
            ->get()
            ->filter(fn (CreditPack $pack): bool => $pack->creditsRemaining() > 0)
            ->values();

        return view('account.dashboard', [
            'user' => $user,
            'nextBooking' => $nextBooking,
            'packs' => $packs,
            'creditsLeft' => $packs->sum(fn (CreditPack $pack): int => $pack->creditsRemaining()),
            'openOrders' => $user->orders()->whereIn('status', OrderStatus::unpaidStatuses())->count(),
            // An open private session request (prompt 15B).
            'openRequest' => PrivateSessionRequest::query()->where('user_id', $user->id)->open()->with('order')->latest('id')->first(),
        ]);
    }
}
