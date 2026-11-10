<?php

declare(strict_types=1);

namespace App\Filament\Resources\Customers\Schemas;

use App\Enums\UserRole;
use App\Models\User;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class CustomerForm
{
    /** E.164: "+" then 8 to 15 digits, no leading zero. */
    public const PHONE_PATTERN = '/^\+[1-9]\d{7,14}$/';

    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Contact')
                ->columns(2)
                ->schema([
                    TextInput::make('name')->maxLength(255),
                    TextInput::make('email')->email()->required()->maxLength(190)
                        ->unique(ignoreRecord: true),
                    TextInput::make('phone')->label('Phone (international format)')
                        ->tel()
                        ->placeholder('+923001234567')
                        ->regex(self::PHONE_PATTERN)
                        ->validationMessages(['regex' => 'Use the international format, for example +923001234567.'])
                        ->maxLength(16),
                    TextInput::make('whatsapp_phone')->label('WhatsApp (international format)')
                        ->tel()
                        ->placeholder('+923001234567')
                        ->regex(self::PHONE_PATTERN)
                        ->validationMessages(['regex' => 'Use the international format, for example +923001234567.'])
                        ->maxLength(16),
                    Toggle::make('marketing_consent')->label('Agreed to marketing messages')
                        ->helperText('Only the customer can change this.')
                        ->disabled()
                        ->dehydrated(false),
                ]),
            Section::make('Access')
                ->schema([
                    Select::make('role')
                        ->options(UserRole::class)
                        ->required()
                        ->selectablePlaceholder(false)
                        ->helperText('Staff, trainers and admins must set up an authenticator app at their next sign-in. Trainers see only the sessions assigned to them.')
                        ->disabled(fn (?User $record): bool => ! self::canChangeRole($record))
                        ->dehydrated(fn (?User $record): bool => self::canChangeRole($record)),
                ]),
        ]);
    }

    private static function canChangeRole(?User $record): bool
    {
        $user = auth()->user();

        return $record !== null && $user instanceof User && $user->can('changeRole', $record);
    }
}
