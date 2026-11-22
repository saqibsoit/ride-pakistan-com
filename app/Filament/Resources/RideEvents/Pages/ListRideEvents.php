<?php

declare(strict_types=1);

namespace App\Filament\Resources\RideEvents\Pages;

use App\Filament\Resources\RideEvents\RideEventResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListRideEvents extends ListRecords
{
    protected static string $resource = RideEventResource::class;

    protected function getHeaderActions(): array
    {
        return [CreateAction::make()->label('New event')];
    }
}
