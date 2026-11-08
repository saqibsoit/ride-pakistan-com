<?php

declare(strict_types=1);

namespace App\Filament\Resources\PromoCodes\Pages;

use App\Actions\Promos\SavePromoCode;
use App\Filament\Resources\PromoCodes\PromoCodeResource;
use App\Models\User;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\ValidationException;

class CreatePromoCode extends CreateRecord
{
    protected static string $resource = PromoCodeResource::class;

    /**
     * @param  array<string, mixed>  $data
     */
    protected function handleRecordCreation(array $data): Model
    {
        /** @var User $admin */
        $admin = auth()->user();

        try {
            return app(SavePromoCode::class)->handle(null, $data, $admin);
        } catch (ValidationException $e) {
            throw ValidationException::withMessages(EditPromoCode::prefixErrors($e));
        }
    }
}
