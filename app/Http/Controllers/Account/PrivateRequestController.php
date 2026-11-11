<?php

declare(strict_types=1);

namespace App\Http\Controllers\Account;

use App\Actions\PrivateRequests\WithdrawPrivateRequest;
use App\Enums\ProductType;
use App\Exceptions\PrivateRequestRejected;
use App\Http\Controllers\Controller;
use App\Models\PrivateSessionRequest;
use App\Models\Product;
use App\Models\User;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * /account/private-requests (prompt 15B): the customer's private session
 * requests with their status, the payment link while approved, and
 * withdraw while waiting. Another customer's request is a 404.
 */
final class PrivateRequestController extends Controller
{
    public function index(Request $request): View
    {
        /** @var User $user */
        $user = $request->user();

        return view('account.private-requests', [
            'requests' => PrivateSessionRequest::query()->where('user_id', $user->id)->with(['order', 'product'])->latest('id')->limit(30)->get(),
            'privateProduct' => Product::query()->where('type', ProductType::Private)->get()->first(fn (Product $p): bool => $p->isOnSale()),
        ]);
    }

    public function withdraw(Request $request, string $reference, WithdrawPrivateRequest $withdraw): RedirectResponse
    {
        /** @var User $user */
        $user = $request->user();

        $privateRequest = PrivateSessionRequest::query()->where('reference', $reference)->where('user_id', $user->id)->firstOrFail();

        try {
            $withdraw->handle($privateRequest, $user);
        } catch (PrivateRequestRejected $e) {
            return back()->withErrors(['request' => $e->getMessage()]);
        }

        return redirect()->route('account.private-requests')->with('status', 'private-request-withdrawn');
    }
}
