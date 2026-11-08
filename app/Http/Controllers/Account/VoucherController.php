<?php

declare(strict_types=1);

namespace App\Http\Controllers\Account;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Contracts\View\View;
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
}
