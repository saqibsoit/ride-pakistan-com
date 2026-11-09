<?php

declare(strict_types=1);

namespace App\Filament\Auth;

use App\Actions\Auth\ReplaceTemporaryPassword;
use App\Models\User;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Pages\SimplePage;
use Filament\Schemas\Components\Actions;
use Filament\Schemas\Components\Component;
use Filament\Schemas\Components\EmbeddedSchema;
use Filament\Schemas\Components\Form;
use Filament\Schemas\Schema;
use Filament\Support\Components\Attributes\Exposed;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Validation\Rules\Password;

/**
 * Shown after sign-in while the account still has the temporary password
 * from admin:create; EnsurePasswordIsChanged blocks the rest of the panel
 * until a new password meeting the password rules is saved. Filament's
 * required MFA set-up follows.
 *
 * @property-read Schema $form
 */
class ChangeTemporaryPassword extends SimplePage
{
    public const ROUTE_NAME = 'filament.admin.auth.change-password';

    public ?string $password = '';

    public ?string $passwordConfirmation = '';

    public function mount(): void
    {
        if (! $this->user()->must_change_password) {
            $this->redirect(Filament::getUrl());

            return;
        }

        $this->form->fill();
    }

    #[Exposed]
    public function save(ReplaceTemporaryPassword $replace): void
    {
        $data = $this->form->getState();
        $user = $this->user();

        $replace->handle($user, (string) $data['password']);

        // Keep this session valid: AuthenticateSession compares the
        // password hash stored in the session with the account's.
        if (request()->hasSession()) {
            request()->session()->put(['password_hash_'.Filament::getAuthGuard() => $user->refresh()->getAuthPassword()]);
        }

        $this->password = '';
        $this->passwordConfirmation = '';

        Notification::make()->success()->title('Password changed')->send();

        // Next: Filament's required authenticator-app set-up.
        $this->redirect(Filament::getUrl());
    }

    public function form(Schema $schema): Schema
    {
        return $schema->components([
            TextInput::make('password')
                ->label('New password')
                ->password()
                ->revealable()
                ->autocomplete('new-password')
                ->required()
                ->rule(Password::defaults())
                ->same('passwordConfirmation')
                ->helperText('At least 12 characters, with upper and lower case letters, a number and a symbol.')
                ->validationAttribute('password'),
            TextInput::make('passwordConfirmation')
                ->label('Confirm new password')
                ->password()
                ->revealable()
                ->autocomplete('new-password')
                ->required()
                ->dehydrated(false),
        ]);
    }

    public function content(Schema $schema): Schema
    {
        return $schema->components([$this->getFormContentComponent()]);
    }

    public function getFormContentComponent(): Component
    {
        return Form::make([EmbeddedSchema::make('form')])
            ->id('form')
            ->livewireSubmitHandler('save')
            ->footer([
                Actions::make([
                    Action::make('save')->label('Save new password')->submit('save'),
                ])->fullWidth()->key('form-actions'),
            ]);
    }

    public function getTitle(): string|Htmlable
    {
        return 'Change your password';
    }

    public function getHeading(): string|Htmlable|null
    {
        return 'Change your temporary password';
    }

    public function getSubheading(): string|Htmlable|null
    {
        return 'Your account was set up with a temporary password. Choose your own before you continue; you\'ll then set up your authenticator app.';
    }

    private function user(): User
    {
        $user = Filament::auth()->user();
        assert($user instanceof User);

        return $user;
    }
}
