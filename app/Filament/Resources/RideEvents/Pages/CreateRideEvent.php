<?php

declare(strict_types=1);

namespace App\Filament\Resources\RideEvents\Pages;

use App\Filament\Resources\RideEvents\RideEventResource;
use App\Models\RideEvent;
use Filament\Resources\Pages\CreateRecord;

/** New events start as drafts with one "Per person" price to edit. */
class CreateRideEvent extends CreateRecord
{
    protected static string $resource = RideEventResource::class;

    protected function afterCreate(): void
    {
        $event = $this->getRecord();

        if ($event instanceof RideEvent && ! $event->tiers()->exists()) {
            $event->tiers()->create(['name' => 'Per person', 'price_minor' => 0, 'sort' => 0]);
        }
    }

    protected function getRedirectUrl(): string
    {
        return RideEventResource::getUrl('edit', ['record' => $this->getRecord()]);
    }
}
