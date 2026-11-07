<?php

declare(strict_types=1);

namespace App\Filament\Resources\Slots\Pages;

use App\Filament\Resources\Slots\SlotResource;
use App\Filament\Resources\Slots\SlotStatusActions;
use App\Models\Slot;
use Filament\Resources\Pages\ViewRecord;

class ViewSlot extends ViewRecord
{
    protected static string $resource = SlotResource::class;

    public function getTitle(): string
    {
        $record = $this->getRecord();

        return $record instanceof Slot ? $record->label() : 'Slot';
    }

    protected function getHeaderActions(): array
    {
        return SlotStatusActions::all();
    }
}
