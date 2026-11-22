<?php

declare(strict_types=1);

namespace App\Filament\Resources\RideEventCategories\Pages;

use App\Filament\Resources\RideEventCategories\RideEventCategoryResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ManageRecords;

class ManageRideEventCategories extends ManageRecords
{
    protected static string $resource = RideEventCategoryResource::class;

    protected function getHeaderActions(): array
    {
        return [CreateAction::make()];
    }
}
