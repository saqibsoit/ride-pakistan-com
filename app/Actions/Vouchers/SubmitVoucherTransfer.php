<?php

declare(strict_types=1);

namespace App\Actions\Vouchers;

use App\Enums\UserRole;
use App\Enums\VoucherOrderStatus;
use App\Exceptions\VoucherRejected;
use App\Models\User;
use App\Models\VoucherOrder;
use App\Notifications\VoucherTransferReceived;
use App\Notifications\VoucherTransferToVerify;
use App\Support\Notifier;
use App\Support\TransferProofs;
use Carbon\CarbonImmutable;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use Throwable;

/**
 * Proof of a bank transfer for a gift voucher (milestone 10e). Same file
 * rules as order transfers (TransferProofs: sniffed type, re-encoded
 * images, random name on the private disk). The first upload moves the
 * purchase to awaiting_verification, from where it never expires on its
 * own; the customer can replace the file until an admin has reviewed it.
 * The customer and every admin are emailed once.
 */
final class SubmitVoucherTransfer
{
    public function __construct(
        private readonly TransferProofs $proofs,
        private readonly VoucherOrderStateMachine $orders,
        private readonly Notifier $notifier,
    ) {}

    /**
     * @throws VoucherRejected
     * @throws InvalidArgumentException the file is not a usable image or PDF
     */
    public function handle(VoucherOrder $order, UploadedFile $file, ?string $transactionRef): VoucherOrder
    {
        $transactionRef = trim((string) $transactionRef);
        $transactionRef = $transactionRef === '' ? null : mb_substr($transactionRef, 0, 100);

        $path = $this->proofs->store($file);

        try {
            [$replaced, $first] = DB::transaction(function () use ($order, $path, $transactionRef): array {
                $locked = VoucherOrder::query()->whereKey($order->id)->lockForUpdate()->firstOrFail();
                $now = CarbonImmutable::now();
                $first = $locked->status === VoucherOrderStatus::AwaitingTransfer;

                if (! $first && $locked->status !== VoucherOrderStatus::AwaitingVerification) {
                    throw VoucherRejected::transferNotOpen();
                }

                if ($first && ($locked->expires_at === null || ! $locked->expires_at->isAfter($now))) {
                    throw VoucherRejected::notPayable();
                }

                $replaced = $locked->screenshot_path;

                $locked->screenshot_path = $path;
                $locked->transaction_ref = $transactionRef;
                $locked->submitted_at = $now;
                $locked->save();

                if ($first) {
                    $this->orders->transition($locked, VoucherOrderStatus::AwaitingVerification, $locked->user);
                }

                activity('vouchers')
                    ->performedOn($locked)
                    ->causedBy($locked->user)
                    ->event($first ? 'transfer_submitted' : 'transfer_replaced')
                    ->withProperties(['transaction_ref' => $transactionRef])
                    ->log(($first ? 'Transfer proof uploaded' : 'Transfer proof replaced').' for voucher purchase '.$locked->reference);

                $order->setRawAttributes($locked->getAttributes(), true);

                return [$replaced, $first];
            });
        } catch (Throwable $e) {
            $this->proofs->delete($path);

            throw $e;
        }

        $this->proofs->delete($replaced);

        if ($first) {
            $order->loadMissing('user');
            $this->notifier->send($order->user, new VoucherTransferReceived($order), Notifier::key(VoucherTransferReceived::type(), $order, $order->user));

            User::query()
                ->where('role', UserRole::Admin)
                ->whereNotNull('email')
                ->each(fn (User $admin) => $this->notifier->send($admin, new VoucherTransferToVerify($order), Notifier::key(VoucherTransferToVerify::type(), $order, $admin)));
        }

        return $order;
    }
}
