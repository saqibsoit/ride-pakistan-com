<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Actions\Vouchers\ChooseVoucherBankTransfer;
use App\Actions\Vouchers\CreateVoucherCheckout;
use App\Actions\Vouchers\SubmitVoucherTransfer;
use App\Enums\SettingKey;
use App\Enums\VoucherOrderStatus;
use App\Exceptions\VoucherRejected;
use App\Http\Middleware\EnsureVoucherOrderAccess;
use App\Http\Requests\UploadTransferProofRequest;
use App\Models\VoucherOrder;
use App\Support\GuestAccess;
use App\Support\Settings;
use App\Support\VoucherOrderLink;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Http\UploadedFile;
use InvalidArgumentException;

/**
 * Paying for a gift voucher (milestone 10e): /gift-vouchers/{reference}
 * with card (Stripe Checkout) or bank transfer, the same two ways as an
 * order. The status page only reads what the webhook recorded; the
 * voucher is issued by IssueVoucher. EnsureVoucherOrderAccess lets in the
 * signed-in buyer or a guest with access to this purchase (guest checkout,
 * decided 2026-11-12); anyone else gets the same answer for any reference.
 */
final class VoucherCheckoutController extends Controller
{
    public function show(Request $request, string $reference): View
    {
        $order = EnsureVoucherOrderAccess::order($request);

        return view('gift-vouchers.show', [
            'order' => $order->load('voucher'),
            'guest' => $request->user()?->id !== $order->user_id,
            'cardFailed' => $order->status === VoucherOrderStatus::PendingPayment && (int) ($order->meta['stripe_attempts'] ?? 0) > 0 && $order->stripe_checkout_session_id === null,
        ]);
    }

    public function card(Request $request, string $reference, CreateVoucherCheckout $checkout): RedirectResponse
    {
        $order = EnsureVoucherOrderAccess::order($request);

        try {
            $url = $checkout->handle($order);
        } catch (VoucherRejected $e) {
            return redirect()->route('gift-vouchers.show', $order->reference)->with('checkout_error', $e->getMessage());
        }

        return redirect()->away($url, 303);
    }

    public function status(Request $request, string $reference): View
    {
        $order = EnsureVoucherOrderAccess::order($request);

        return view('gift-vouchers.status', ['order' => $order, 'cancelled' => $request->boolean('cancelled')]);
    }

    public function chooseTransfer(Request $request, string $reference, ChooseVoucherBankTransfer $choose): RedirectResponse
    {
        $order = EnsureVoucherOrderAccess::order($request);

        try {
            $choose->handle($order);
        } catch (VoucherRejected $e) {
            return redirect()->route('gift-vouchers.show', $order->reference)->with('checkout_error', $e->getMessage());
        }

        return redirect()->route('gift-vouchers.transfer', $order->reference);
    }

    public function transfer(Request $request, string $reference, Settings $settings): View|RedirectResponse
    {
        $order = EnsureVoucherOrderAccess::order($request);

        if ($order->status === VoucherOrderStatus::PendingPayment) {
            return redirect()->route('gift-vouchers.show', $order->reference);
        }

        return view('gift-vouchers.transfer', [
            'order' => $order,
            'guest' => $request->user()?->id !== $order->user_id,
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
        $order = EnsureVoucherOrderAccess::order($request);

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

    /**
     * The signed link from the buyer's emails (guest checkout): grants this
     * browser that one purchase, then the plain page. A link that doesn't
     * work shows the same page as the manage links.
     */
    public function open(Request $request, string $reference, GuestAccess $guests): RedirectResponse|Response
    {
        $order = VoucherOrder::query()->where('reference', $reference)->first();

        if (! $request->hasValidSignature()
            || $order === null
            || $request->integer('v') !== $order->manage_link_version
            || ! VoucherOrderLink::isOpen($order)) {
            return response()->view('manage.link', ['closed' => true], 403);
        }

        $guests->grantVoucherOrder($order);

        return redirect()->route('gift-vouchers.show', $order->reference);
    }
}
