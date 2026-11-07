<?php

declare(strict_types=1);

namespace App\Filament\Resources\Customers\Pages;

use App\Actions\Users\ChangeUserRole;
use App\Enums\UserRole;
use App\Filament\Resources\Customers\CustomerResource;
use App\Models\User;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

class EditCustomer extends EditRecord
{
    protected static string $resource = CustomerResource::class;

    /**
     * Role is not fillable: it goes through ChangeUserRole (policy check
     * and audit log); everything else is a normal update.
     *
     * @param  array<string, mixed>  $data
     */
    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        $role = $data['role'] ?? null;
        unset($data['role']);

        return DB::transaction(function () use ($record, $data, $role): Model {
            $record->update($data);

            $actor = auth()->user();

            if ($record instanceof User && $actor instanceof User && $role !== null) {
                app(ChangeUserRole::class)->handle($record, $role instanceof UserRole ? $role : UserRole::from((string) $role), $actor);
            }

            return $record;
        });
    }
}
