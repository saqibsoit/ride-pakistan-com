<?php

declare(strict_types=1);

namespace App\Http\Controllers\Account;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;

/** /account/credits: package credits with expiry and history. */
final class CreditController extends Controller
{
    public function __invoke(Request $request): View
    {
        /** @var User $user */
        $user = $request->user();

        return view('account.credits', [
            'packs' => $user->creditPacks()
                ->with(['order.product', 'ledger' => fn ($ledger) => $ledger->latest('id')])
                ->orderByDesc('expires_at')
                ->get(),
        ]);
    }
}
