<?php

declare(strict_types=1);

namespace App\Filament\Resources\PrivateRequests\Pages;

use App\Filament\Resources\PrivateRequests\PrivateRequestResource;
use Filament\Resources\Pages\ListRecords;

class ListPrivateRequests extends ListRecords
{
    protected static string $resource = PrivateRequestResource::class;
}
