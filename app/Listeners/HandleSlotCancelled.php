<?php

declare(strict_types=1);

namespace App\Listeners;

use App\Actions\Booking\CancelBookingsForSlot;
use App\Events\SlotCancelled;

/** Cancels the slot's bookings, returns credits, flags paid orders and emails customers. */
final class HandleSlotCancelled
{
    public function __construct(private readonly CancelBookingsForSlot $cancel) {}

    public function handle(SlotCancelled $event): void
    {
        $this->cancel->handle($event->slot->refresh());
    }
}
