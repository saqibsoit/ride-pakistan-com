<?php

declare(strict_types=1);

namespace App\Filament\Resources\VoucherOrders\Pages;

use App\Filament\Resources\VoucherOrders\VoucherOrderResource;
use Filament\Resources\Pages\ListRecords;

class ListVoucherOrders extends ListRecords
{
    protected static string $resource = VoucherOrderResource::class;
}
