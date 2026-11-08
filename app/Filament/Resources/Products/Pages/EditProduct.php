<?php

declare(strict_types=1);

namespace App\Filament\Resources\Products\Pages;

use App\Filament\Resources\Products\ProductResource;
use App\Filament\Resources\Products\Tables\ProductsTable;
use Filament\Resources\Pages\EditRecord;

class EditProduct extends EditRecord
{
    protected static string $resource = ProductResource::class;

    protected function getHeaderActions(): array
    {
        return [
            ProductsTable::archiveAction()->after(fn () => $this->redirect(ProductResource::getUrl('index'))),
            ProductsTable::deleteAction()->after(fn () => $this->redirect(ProductResource::getUrl('index'))),
        ];
    }
}
