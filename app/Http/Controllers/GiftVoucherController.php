<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Actions\Guest\PlaceGuestVoucherOrder;
use App\Actions\Vouchers\CreateVoucherOrder;
use App\Actions\Vouchers\VoucherRules;
use App\Exceptions\GuestCheckoutRejected;
use App\Exceptions\VoucherRejected;
use App\Http\Controllers\Public\GuestBookingController;
use App\Http\Requests\StoreVoucherOrderRequest;
use App\Models\User;
use App\Support\BusinessFacts;
use App\Support\GuestAccess;
use App\Support\Money;
use App\Support\Seo\Seo;
use App\Support\Turnstile;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;

/**
 * Gift vouchers (milestone 10e). /gift-vouchers is the public, cached page;
 * /gift-vouchers/buy is the purchase form (the recipient needs no account).
 * A signed-in customer buys as themselves; a guest (guest checkout,
 * decided 2026-11-12) gives their name and email (twice) and passes the
 * Turnstile check, and the purchase goes on the account behind the email
 * without showing or changing it (PlaceGuestVoucherOrder).
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

    public function create(VoucherRules $rules, BusinessFacts $facts, Turnstile $turnstile): View
    {
        return view('gift-vouchers.create', [
            'products' => $facts->products(),
            'minMinor' => $rules->minAmountMinor(),
            'maxMinor' => $rules->maxAmountMinor(),
            'months' => $rules->validityMonths(),
            'today' => VoucherRules::today(),
            'maxDays' => VoucherRules::MAX_SEND_AHEAD_DAYS,
            'turnstileSiteKey' => auth()->guest() ? $turnstile->siteKey() : null,
        ]);
    }

    public function store(StoreVoucherOrderRequest $request, CreateVoucherOrder $create, PlaceGuestVoucherOrder $guest, Turnstile $turnstile, GuestAccess $guests): RedirectResponse
    {
        $user = $request->user();
        $product = $request->validated('product_id');
        $amount = $request->validated('amount_pkr');
        $message = $request->validated('message');
        $args = [
            is_numeric($product) ? (int) $product : null,
            is_numeric($amount) ? (int) $amount * 100 : null,
            (string) $request->validated('recipient_name'),
            (string) $request->validated('recipient_email'),
            is_string($message) ? $message : null,
            $request->sendOn(),
        ];

        try {
            if ($user instanceof User) {
                $order = $create->handle($user, ...$args);
            } else {
                $email = (string) $request->validated('buyer_email');

                if (! $turnstile->verify($request->string(Turnstile::RESPONSE_FIELD)->toString(), $request->ip())) {
                    throw GuestCheckoutRejected::verification();
                }

                // The same per-email limit as guest bookings (shared key).
                $emailKey = 'guest-checkout-email:'.hash('sha256', Str::lower(trim($email)));

                if (RateLimiter::tooManyAttempts($emailKey, GuestBookingController::EMAIL_LIMIT_PER_HOUR)) {
                    throw GuestCheckoutRejected::tooMany();
                }

                RateLimiter::hit($emailKey, 3600);

                $order = $guest->handle(['name' => (string) $request->validated('buyer_name'), 'email' => $email], ...$args);
                $guests->grantVoucherOrder($order);
            }
        } catch (GuestCheckoutRejected $e) {
            return back()->withInput()->withErrors(['buyer_email' => $e->getMessage()]);
        } catch (VoucherRejected $e) {
            return back()->withInput()->withErrors(['voucher' => $e->getMessage()]);
        }

        return redirect()->route('gift-vouchers.show', $order->reference);
    }
}
