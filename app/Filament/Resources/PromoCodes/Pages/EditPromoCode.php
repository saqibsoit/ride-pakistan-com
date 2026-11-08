<?php

declare(strict_types=1);

namespace App\Filament\Resources\PromoCodes\Pages;

use App\Actions\Promos\SavePromoCode;
use App\Filament\Resources\PromoCodes\PromoCodeResource;
use App\Models\PromoCode;
use App\Models\User;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\ValidationException;

class EditPromoCode extends EditRecord
{
    protected static string $resource = PromoCodeResource::class;

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeFill(array $data): array
    {
        $record = $this->getRecord();

        return $record instanceof PromoCode ? PromoCodeResource::fillState($record, $data) : $data;
    }

    /**
     * Fields locked after first use are disabled and not submitted, so
     * their stored values are filled back in before saving.
     *
     * @param  array<string, mixed>  $data
     */
    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        /** @var PromoCode $record */
        /** @var User $admin */
        $admin = auth()->user();
        $data = [...PromoCodeResource::fillState($record, $record->only(['code', 'percent'])), ...$data];

        try {
            return app(SavePromoCode::class)->handle($record, $data, $admin);
        } catch (ValidationException $e) {
            throw ValidationException::withMessages(self::prefixErrors($e));
        }
    }

    /**
     * Action errors are keyed by field; the form's are under "data.".
     *
     * @return array<string, list<string>>
     */
    public static function prefixErrors(ValidationException $e): array
    {
        $messages = [];

        foreach ($e->errors() as $key => $errors) {
            $messages['data.'.$key] = array_values($errors);
        }

        return $messages;
    }
}
