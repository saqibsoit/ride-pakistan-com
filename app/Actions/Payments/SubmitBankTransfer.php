<?php

declare(strict_types=1);

namespace App\Actions\Payments;

use App\Actions\Orders\OrderStateMachine;
use App\Enums\BankTransferStatus;
use App\Enums\OrderStatus;
use App\Enums\UserRole;
use App\Exceptions\CheckoutUnavailable;
use App\Models\BankTransfer;
use App\Models\BookingHold;
use App\Models\Order;
use App\Models\Slot;
use App\Models\User;
use App\Notifications\TransferReceived;
use App\Notifications\TransferToVerify;
use App\Support\Notifier;
use App\Support\TransferProofs;
use Carbon\CarbonImmutable;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use Throwable;

/**
 * The customer uploads proof of their transfer (PLAN §3.2 step 3). The
 * first upload moves the order to awaiting_verification; until an admin
 * reviews it, a new upload replaces the file. From the first upload the
 * seats stay held until the admin decides (holds run to the session
 * start), so a slow review never cancels a paid booking.
 */
final class SubmitBankTransfer
{
    public function __construct(
        private readonly TransferProofs $proofs,
        private readonly OrderStateMachine $orders,
    ) {}

    /**
     * @throws CheckoutUnavailable
     * @throws InvalidArgumentException the file is not a usable image or PDF
     */
    public function handle(Order $order, UploadedFile $file, ?string $transactionRef): BankTransfer
    {
        $transactionRef = trim((string) $transactionRef);
        $transactionRef = $transactionRef === '' ? null : mb_substr($transactionRef, 0, 100);

        $path = $this->proofs->store($file);

        try {
            [$transfer, $replaced, $first] = DB::transaction(function () use ($order, $path, $transactionRef): array {
                $locked = Order::query()->whereKey($order->id)->lockForUpdate()->firstOrFail();
                $now = CarbonImmutable::now();

                $existing = BankTransfer::query()
                    ->where('order_id', $locked->id)
                    ->where('status', BankTransferStatus::Submitted)
                    ->lockForUpdate()
                    ->first();

                $first = $locked->status === OrderStatus::AwaitingTransfer;

                if (! $first && ! ($locked->status === OrderStatus::AwaitingVerification && $existing !== null)) {
                    throw CheckoutUnavailable::transferNotOpen();
                }

                if ($first && ($locked->expires_at === null || ! $locked->expires_at->isAfter($now))) {
                    throw CheckoutUnavailable::notPayable();
                }

                $replaced = $existing?->screenshot_path;

                if ($existing !== null) {
                    $existing->screenshot_path = $path;
                    $existing->transaction_ref = $transactionRef;
                    $existing->submitted_at = $now;
                    $existing->save();
                    $transfer = $existing;
                } else {
                    $transfer = BankTransfer::query()->create([
                        'order_id' => $locked->id,
                        'screenshot_path' => $path,
                        'transaction_ref' => $transactionRef,
                        'submitted_at' => $now,
                    ]);
                }

                if ($first) {
                    $this->holdUntilSessionStart($locked);
                    $this->orders->transition($locked, OrderStatus::AwaitingVerification, $locked->user, context: ['bank_transfer_id' => $transfer->id]);
                }

                activity('payments')
                    ->performedOn($locked)
                    ->causedBy($locked->user)
                    ->event($first ? 'transfer_submitted' : 'transfer_replaced')
                    ->withProperties(['bank_transfer_id' => $transfer->id, 'transaction_ref' => $transactionRef])
                    ->log(($first ? 'Transfer proof uploaded' : 'Transfer proof replaced').' for order '.$locked->reference);

                $order->setRawAttributes($locked->getAttributes(), true);

                return [$transfer, $replaced, $first];
            });
        } catch (Throwable $e) {
            $this->proofs->delete($path);

            throw $e;
        }

        $this->proofs->delete($replaced);

        if ($first) {
            $this->notify($order);
        }

        return $transfer;
    }

    /**
     * Holds run to the start of their session from now on (decision: an
     * uploaded transfer keeps the seats until it is reviewed).
     */
    private function holdUntilSessionStart(Order $order): void
    {
        $holds = BookingHold::query()->where('order_id', $order->id)->lockForUpdate()->get();

        foreach ($holds as $hold) {
            $start = Slot::query()->whereKey($hold->slot_id)->value('starts_at');

            if ($start !== null) {
                $hold->expires_at = CarbonImmutable::parse((string) $start, 'UTC');
                $hold->save();
            }
        }
    }

    private function notify(Order $order): void
    {
        $order->loadMissing('user');
        $notifier = app(Notifier::class);

        $notifier->send($order->user, new TransferReceived($order), Notifier::key(TransferReceived::type(), $order, $order->user));

        User::query()
            ->where('role', UserRole::Admin)
            ->whereNotNull('email')
            ->each(fn (User $admin) => $notifier->send($admin, new TransferToVerify($order), Notifier::key(TransferToVerify::type(), $order, $admin)));
    }
}
