<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Actions\Vouchers\CreateVoucherOrder;
use App\Actions\Vouchers\VoucherRules;
use App\Exceptions\VoucherRejected;
use App\Http\Requests\StoreVoucherOrderRequest;
use App\Models\User;
use App\Support\BusinessFacts;
use App\Support\Money;
use App\Support\Seo\Seo;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;

/**
 * Gift vouchers (milestone 10e). /gift-vouchers is the public, cached page;
 * /gift-vouchers/buy is the purchase form for a signed-in customer (the
 * purchaser is the account holder; the recipient needs no account).
 */
final class GiftVoucherController extends Controller
{
    public function index(VoucherRules $rules, Seo $seo, BusinessFacts $facts): View
    {
        $months = $rules->validityMonths();

        return view('gift-vouchers.index', [
            'products' => $facts->products(),
            'minMinor' => $rules->minAmountMinor(),
            'maxMinor' => $rules->maxAmountMinor(),
            'months' => $months,
            'seo' => $seo->make(
                title: 'Horse riding gift vouchers in Islamabad',
                description: 'Give a riding session or any amount from '.Money::format($rules->minAmountMinor()).'. Emailed with a printable voucher, valid '.$months.' months, usable on any session or package.',
                breadcrumbs: [
                    ['label' => 'Home', 'url' => route('home')],
                    ['label' => 'Gift vouchers'],
                ],
                image: 'ride.jpg',
            ),
        ]);
    }

    public function create(VoucherRules $rules, BusinessFacts $facts): View
    {
        return view('gift-vouchers.create', [
            'products' => $facts->products(),
            'minMinor' => $rules->minAmountMinor(),
            'maxMinor' => $rules->maxAmountMinor(),
            'months' => $rules->validityMonths(),
            'today' => VoucherRules::today(),
            'maxDays' => VoucherRules::MAX_SEND_AHEAD_DAYS,
        ]);
    }

    public function store(StoreVoucherOrderRequest $request, CreateVoucherOrder $create): RedirectResponse
    {
        /** @var User $user */
        $user = $request->user();
        $product = $request->validated('product_id');
        $amount = $request->validated('amount_pkr');
        $message = $request->validated('message');

        try {
            $order = $create->handle(
                $user,
                is_numeric($product) ? (int) $product : null,
                is_numeric($amount) ? (int) $amount * 100 : null,
                (string) $request->validated('recipient_name'),
                (string) $request->validated('recipient_email'),
                is_string($message) ? $message : null,
                $request->sendOn(),
            );
        } catch (VoucherRejected $e) {
            return back()->withInput()->withErrors(['voucher' => $e->getMessage()]);
        }

        return redirect()->route('gift-vouchers.show', $order->reference);
    }
}
