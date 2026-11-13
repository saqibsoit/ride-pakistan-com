<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Actions\Payments\ChooseBankTransfer;
use App\Actions\Payments\SubmitBankTransfer;
use App\Actions\Payments\TransferWindow;
use App\Enums\BankTransferStatus;
use App\Enums\OrderStatus;
use App\Enums\SettingKey;
use App\Exceptions\CheckoutUnavailable;
use App\Http\Middleware\EnsureOrderAccess;
use App\Http\Requests\UploadTransferProofRequest;
use App\Support\Settings;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use InvalidArgumentException;

/**
 * /checkout/{reference}/transfer (PLAN §3.2): choose bank transfer, see
 * the bank details and deadline, and upload the proof. Same order and
 * reference as card payment. EnsureOrderAccess lets in the signed-in owner
 * or a guest with access to this order (guest checkout).
 */
final class BankTransferController extends Controller
{
    public function choose(Request $request, string $reference, ChooseBankTransfer $choose): RedirectResponse
    {
        $order = EnsureOrderAccess::order($request);

        try {
            $choose->handle($order);
        } catch (CheckoutUnavailable $e) {
            return redirect()->route('checkout.show', $order->reference)->with('checkout_error', $e->getMessage());
        }

        return redirect()->route('checkout.transfer', $order->reference);
    }

    public function show(Request $request, string $reference, Settings $settings, TransferWindow $window): View|RedirectResponse
    {
        $order = EnsureOrderAccess::order($request);

        if ($order->status === OrderStatus::PendingPayment) {
            return redirect()->route('checkout.show', $order->reference);
        }

        $order->load(['product', 'items.slot', 'items.rider']);

        $transfer = $order->bankTransfers()->latest('id')->first();

        return view('checkout.transfer', [
            'order' => $order,
            'transfer' => $transfer,
            'canUpload' => $order->status === OrderStatus::AwaitingTransfer
                || ($order->status === OrderStatus::AwaitingVerification && $transfer?->status === BankTransferStatus::Submitted),
            'bank' => [
                'Account name' => $settings->string(SettingKey::BankAccountName),
                'Bank' => $settings->string(SettingKey::BankName),
                'IBAN' => $settings->string(SettingKey::BankIban),
                'SWIFT' => $settings->string(SettingKey::BankSwift),
                'Country' => $settings->string(SettingKey::BankCountry),
                'Bank address' => $settings->string(SettingKey::BankAddress),
            ],
            'sessionStart' => $window->firstSessionStart($order),
            'guest' => $request->user()?->id !== $order->user_id,
        ]);
    }

    public function upload(UploadTransferProofRequest $request, string $reference, SubmitBankTransfer $submit): RedirectResponse
    {
        $order = EnsureOrderAccess::order($request);

        $file = $request->file('proof');
        $ref = $request->validated('transaction_ref');

        if (! $file instanceof UploadedFile) {
            return back()->withErrors(['proof' => 'Choose the screenshot or PDF of your transfer.']);
        }

        try {
            $submit->handle($order, $file, is_string($ref) ? $ref : null);
        } catch (InvalidArgumentException $e) {
            return back()->withErrors(['proof' => $e->getMessage()]);
        } catch (CheckoutUnavailable $e) {
            return back()->with('transfer_error', $e->getMessage());
        }

        return redirect()->route('checkout.transfer', $order->reference)->with('transfer_uploaded', true);
    }
}
