<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

/**
 * /checkout/{reference} (PLAN §6): the order summary and how long the
 * seats are held. Card (milestone 6) and bank transfer (milestone 7)
 * payment are added here. Scoped to the signed-in customer: another
 * customer's reference is a 404.
 */
final class CheckoutController extends Controller
{
    public function show(Request $request, string $reference): View
    {
        /** @var User $user */
        $user = $request->user();

        $order = $user->orders()
            ->where('reference', $reference)
            ->with(['product', 'items.slot', 'items.rider', 'holds'])
            ->firstOrFail();

        Gate::authorize('view', $order);

        return view('checkout.show', ['order' => $order]);
    }
}
