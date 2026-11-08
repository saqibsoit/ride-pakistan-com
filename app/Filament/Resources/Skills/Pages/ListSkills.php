<?php

declare(strict_types=1);

namespace App\Filament\Resources\Skills\Pages;

use App\Filament\Resources\Skills\SkillResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListSkills extends ListRecords
{
    protected static string $resource = SkillResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }

    /**
     * Filament saves the new order with one bulk query, which skips model
     * events: log it here.
     *
     * @param  array<int|string>  $order
     */
    public function reorderTable(array $order, int|string|null $draggedRecordKey = null): void
    {
        parent::reorderTable($order, $draggedRecordKey);

        activity('content')
            ->causedBy(auth()->user())
            ->event('reordered')
            ->withProperties(['model' => 'skill', 'order' => array_values(array_map('intval', $order))])
            ->log('Rider skills reordered');
    }
}
