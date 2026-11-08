<?php

declare(strict_types=1);

namespace App\Filament\Resources\Products\Pages;

use App\Events\PublicContentChanged;
use App\Filament\Resources\Products\ProductResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListProducts extends ListRecords
{
    protected static string $resource = ProductResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }

    /**
     * Filament saves the new order with one bulk query, which skips model
     * events: log it and refresh the public pages here (milestone 10a).
     *
     * @param  array<int|string>  $order
     */
    public function reorderTable(array $order, int|string|null $draggedRecordKey = null): void
    {
        parent::reorderTable($order, $draggedRecordKey);

        activity('content')
            ->causedBy(auth()->user())
            ->event('reordered')
            ->withProperties(['model' => 'product', 'order' => array_values(array_map('intval', $order))])
            ->log('Products reordered');

        PublicContentChanged::dispatch();
    }
}
