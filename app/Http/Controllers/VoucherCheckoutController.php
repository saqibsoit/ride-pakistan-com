<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Actions\Vouchers\ChooseVoucherBankTransfer;
use App\Actions\Vouchers\CreateVoucherCheckout;
use App\Actions\Vouchers\SubmitVoucherTransfer;
use App\Enums\SettingKey;
use App\Enums\VoucherOrderStatus;
use App\Exceptions\VoucherRejected;
use App\Http\Requests\UploadTransferProofRequest;
use App\Models\User;
use App\Models\VoucherOrder;
use App\Support\Settings;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Gate;
use InvalidArgumentException;

/**
 * Paying for a gift voucher (milestone 10e): /gift-vouchers/{reference}
 * with card (Stripe Checkout) or bank transfer, the same two ways as an
 * order. The status page only reads what the webhook recorded; the
 * voucher is issued by IssueVoucher. Scoped to the signed-in purchaser:
 * another customer's reference is a 404.
 */
final class VoucherCheckoutController extends Controller
{
    public function show(Request $request, string $reference): View
    {
        $order = $this->order($request, $reference);
        Gate::authorize('view', $order);

        return view('gift-vouchers.show', [
            'order' => $order->load('voucher'),
            'cardFailed' => $order->status === VoucherOrderStatus::PendingPayment && (int) ($order->meta['stripe_attempts'] ?? 0) > 0 && $order->stripe_checkout_session_id === null,
        ]);
    }

    public function card(Request $request, string $reference, CreateVoucherCheckout $checkout): RedirectResponse
    {
        $order = $this->order($request, $reference);
        Gate::authorize('pay', $order);

        try {
            $url = $checkout->handle($order);
        } catch (VoucherRejected $e) {
            return redirect()->route('gift-vouchers.show', $order->reference)->with('checkout_error', $e->getMessage());
        }

        return redirect()->away($url, 303);
    }

    public function status(Request $request, string $reference): View
    {
        $order = $this->order($request, $reference);
        Gate::authorize('view', $order);

        return view('gift-vouchers.status', ['order' => $order, 'cancelled' => $request->boolean('cancelled')]);
    }

    public function chooseTransfer(Request $request, string $reference, ChooseVoucherBankTransfer $choose): RedirectResponse
    {
        $order = $this->order($request, $reference);
        Gate::authorize('pay', $order);

        try {
            $choose->handle($order);
        } catch (VoucherRejected $e) {
            return redirect()->route('gift-vouchers.show', $order->reference)->with('checkout_error', $e->getMessage());
        }

        return redirect()->route('gift-vouchers.transfer', $order->reference);
    }

    public function transfer(Request $request, string $reference, Settings $settings): View|RedirectResponse
    {
        $order = $this->order($request, $reference);
        Gate::authorize('view', $order);

        if ($order->status === VoucherOrderStatus::PendingPayment) {
            return redirect()->route('gift-vouchers.show', $order->reference);
        }

        return view('gift-vouchers.transfer', [
            'order' => $order,
            'canUpload' => in_array($order->status, [VoucherOrderStatus::AwaitingTransfer, VoucherOrderStatus::AwaitingVerification], true),
            'bank' => [
                'Account name' => $settings->string(SettingKey::BankAccountName),
                'Bank' => $settings->string(SettingKey::BankName),
                'IBAN' => $settings->string(SettingKey::BankIban),
                'SWIFT' => $settings->string(SettingKey::BankSwift),
                'Country' => $settings->string(SettingKey::BankCountry),
                'Bank address' => $settings->string(SettingKey::BankAddress),
            ],
        ]);
    }

    public function upload(UploadTransferProofRequest $request, string $reference, SubmitVoucherTransfer $submit): RedirectResponse
    {
        $order = $this->order($request, $reference);
        Gate::authorize('pay', $order);

        $file = $request->file('proof');
        $ref = $request->validated('transaction_ref');

        if (! $file instanceof UploadedFile) {
            return back()->withErrors(['proof' => 'Choose the screenshot or PDF of your transfer.']);
        }

        try {
            $submit->handle($order, $file, is_string($ref) ? $ref : null);
        } catch (InvalidArgumentException $e) {
            return back()->withErrors(['proof' => $e->getMessage()]);
        } catch (VoucherRejected $e) {
            return back()->with('transfer_error', $e->getMessage());
        }

        return redirect()->route('gift-vouchers.transfer', $order->reference)->with('transfer_uploaded', true);
    }

    private function order(Request $request, string $reference): VoucherOrder
    {
        /** @var User $user */
        $user = $request->user();

        return $user->voucherOrders()->where('reference', $reference)->firstOrFail();
    }
}
