<?php

declare(strict_types=1);

namespace App\Filament\Resources\Orders\Pages;

use App\Filament\Resources\Orders\EventOrderActions;
use App\Filament\Resources\Orders\OrderActions;
use App\Filament\Resources\Orders\OrderResource;
use Filament\Resources\Pages\ViewRecord;

class ViewOrder extends ViewRecord
{
    protected static string $resource = OrderResource::class;

    protected function getHeaderActions(): array
    {
        return [
            OrderActions::resend(),
            OrderActions::clearReview(),
            OrderActions::refund(),
            OrderActions::transferRefund(),
            OrderActions::refundCovered(),
            OrderActions::revokeManageLinks(),
            // Events (decided 2026-11-22).
            EventOrderActions::markBalancePaid(),
            EventOrderActions::cancelForCustomer(),
            EventOrderActions::refundCancelledDate(),
            EventOrderActions::moveCancelledDate(),
        ];
    }
}
