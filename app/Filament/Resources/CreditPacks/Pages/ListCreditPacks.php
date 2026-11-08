<?php

declare(strict_types=1);

namespace App\Filament\Resources\CreditPacks\Pages;

use App\Filament\Resources\CreditPacks\CreditPackResource;
use Filament\Resources\Pages\ListRecords;

class ListCreditPacks extends ListRecords
{
    protected static string $resource = CreditPackResource::class;
}
