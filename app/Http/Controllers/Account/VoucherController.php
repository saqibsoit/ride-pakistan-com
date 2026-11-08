<?php

declare(strict_types=1);

namespace App\Http\Controllers\Account;

use App\Actions\Vouchers\UpdateVoucherRecipient;
use App\Exceptions\VoucherRejected;
use App\Http\Controllers\Controller;
use App\Http\Requests\UpdateVoucherRecipientRequest;
use App\Models\User;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/** /account/vouchers (milestone 10e): gift vouchers the customer bought, with status and balance. */
final class VoucherController extends Controller
{
    public function index(Request $request): View
    {
        /** @var User $user */
        $user = $request->user();

        return view('account.vouchers', [
            'orders' => $user->voucherOrders()->with('voucher')->latest('id')->get(),
        ]);
    }

    /** The buyer corrects the recipient before the voucher is sent. */
    public function recipient(UpdateVoucherRecipientRequest $request, string $reference, UpdateVoucherRecipient $update): RedirectResponse
    {
        /** @var User $user */
        $user = $request->user();
        $order = $user->voucherOrders()->where('reference', $reference)->firstOrFail();

        try {
            $update->handle($order, $user, (string) $request->validated('recipient_name'), (string) $request->validated('recipient_email'));
        } catch (VoucherRejected $e) {
            return back()->withErrors(['recipient_'.$order->reference => $e->getMessage()]);
        }

        return redirect()->route('account.vouchers')->with('status', 'Recipient updated for '.$order->reference.'.');
    }
}
