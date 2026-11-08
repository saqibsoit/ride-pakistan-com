<?php

declare(strict_types=1);

namespace App\Http\Controllers\Account;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

/**
 * /account/orders and /account/orders/{reference} (status and printable
 * receipt). Lookups are scoped to the signed-in customer, so another
 * customer's reference is a 404, never a 403 that confirms it exists.
 */
final class OrderController extends Controller
{
    public function index(Request $request): View
    {
        /** @var User $user */
        $user = $request->user();

        return view('account.orders', [
            'orders' => $user->orders()->with('product')->latest('id')->get(),
        ]);
    }

    public function show(Request $request, string $reference): View
    {
        /** @var User $user */
        $user = $request->user();

        $order = $user->orders()
            ->where('reference', $reference)
            ->with(['product', 'items.slot', 'items.rider', 'payments', 'discounts'])
            ->firstOrFail();

        Gate::authorize('view', $order);

        return view('account.order', ['order' => $order, 'user' => $user]);
    }
}
