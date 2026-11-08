<?php

declare(strict_types=1);

namespace App\Filament\Pages;

use App\Actions\Settings\UpdateSettings;
use App\Enums\SettingKey;
use App\Enums\SettingType;
use App\Filament\Support\ContentFields;
use App\Models\User;
use App\Support\Settings;
use BackedEnum;
use Filament\Forms\Components\Field;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use UnitEnum;

/**
 * Admin-editable settings (App\Enums\SettingKey), grouped by key prefix.
 * Saved through UpdateSettings, which logs and clears caches.
 *
 * @property-read Schema $form
 */
class ManageSettings extends Page
{
    protected string $view = 'filament.pages.manage-settings';

    protected static string|UnitEnum|null $navigationGroup = 'System';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedCog6Tooth;

    protected static ?string $title = 'Settings';

    protected static ?int $navigationSort = 100;

    private const GROUPS = [
        'business' => 'Business',
        'contact' => 'Contact and links',
        'schedule' => 'Schedule',
        'booking' => 'Booking rules',
        'policies' => 'Policies',
        'bank' => 'Bank transfer details',
        'privacy' => 'Privacy and data retention',
        'seo' => 'Search engines',
    ];

    /** @var array<string, mixed> */
    public array $data = [];

    public static function canAccess(): bool
    {
        $user = auth()->user();

        return $user instanceof User && $user->can('access-admin');
    }

    public function mount(Settings $settings): void
    {
        $state = [];

        foreach (SettingKey::cases() as $key) {
            $state[self::field($key)] = $settings->get($key);
        }

        $this->form->fill($state);
    }

    public function form(Schema $schema): Schema
    {
        $sections = [];

        foreach (self::GROUPS as $group => $label) {
            $fields = array_map(
                fn (SettingKey $key): Field => self::input($key),
                array_values(array_filter(SettingKey::cases(), fn (SettingKey $key): bool => $key->group() === $group)),
            );

            $sections[] = Section::make($label)->columns(2)->collapsible()->schema($fields);
        }

        return $schema->components($sections)->statePath('data');
    }

    public function save(UpdateSettings $update): void
    {
        abort_unless(self::canAccess(), 403);

        /** @var array<string, mixed> $state */
        $state = $this->form->getState();
        $values = [];

        foreach (SettingKey::cases() as $key) {
            if (array_key_exists(self::field($key), $state)) {
                $values[$key->value] = $state[self::field($key)];
            }
        }

        $user = auth()->user();
        $changed = $update->handle($values, $user instanceof User ? $user : null);

        Notification::make()
            ->success()
            ->title($changed === [] ? 'No changes' : 'Settings saved')
            ->send();
    }

    /** Form state keys cannot contain dots (they would nest). */
    private static function field(SettingKey $key): string
    {
        return str_replace('.', '__', $key->value);
    }

    private static function input(SettingKey $key): Field
    {
        $name = self::field($key);

        $field = match ($key->type()) {
            SettingType::LongText => Textarea::make($name)->rows(3)->maxLength(2000)->columnSpanFull(),
            SettingType::List => ContentFields::list($name, $key->label()),
            SettingType::Integer => TextInput::make($name)->integer()->minValue(0),
            SettingType::Url => TextInput::make($name)->url()->maxLength(500),
            SettingType::Email => TextInput::make($name)->email()->maxLength(190),
            default => TextInput::make($name)->maxLength(255),
        };

        // Every setting explains itself (SettingKey::help(), milestone 10a).
        return $field->label($key->label())->helperText($key->help());
    }
}
