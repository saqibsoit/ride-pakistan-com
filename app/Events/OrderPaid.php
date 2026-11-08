<?php

declare(strict_types=1);

namespace App\Events;

use App\Models\Order;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * An order was paid and fulfilled (card webhook now, bank transfer
 * approval in milestone 7). Dispatched only once the transaction commits.
 */
final class OrderPaid implements ShouldDispatchAfterCommit
{
    use Dispatchable, SerializesModels;

    public function __construct(public readonly Order $order) {}
}
