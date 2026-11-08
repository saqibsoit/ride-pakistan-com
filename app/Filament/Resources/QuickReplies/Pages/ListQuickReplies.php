<?php

declare(strict_types=1);

namespace App\Filament\Resources\QuickReplies\Pages;

use App\Filament\Resources\QuickReplies\QuickReplyResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListQuickReplies extends ListRecords
{
    protected static string $resource = QuickReplyResource::class;

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
            ->withProperties(['model' => 'quick_reply', 'order' => array_values(array_map('intval', $order))])
            ->log('Reply templates reordered');
    }
}
