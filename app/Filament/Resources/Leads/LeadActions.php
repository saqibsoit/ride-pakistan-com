<?php

declare(strict_types=1);

namespace App\Filament\Resources\Leads;

use App\Actions\Leads\ChangeLeadStatus;
use App\Actions\Leads\ConvertLeadToCustomer;
use App\Actions\Leads\CreateManualLead;
use App\Actions\Leads\SuggestFollowUp;
use App\Actions\Leads\UpdateLeadPipeline;
use App\Enums\LeadInterest;
use App\Enums\LeadSource;
use App\Enums\LeadStatus;
use App\Exceptions\InvalidStatusTransition;
use App\Models\Lead;
use App\Models\User;
use Carbon\CarbonImmutable;
use Filament\Actions\Action;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Illuminate\Validation\ValidationException;

/**
 * Lead tools shared by the list, the view page and the board. Every
 * change goes through an action in app/Actions/Leads and is logged.
 * Staff and admins work leads; converting is admin-only (LeadPolicy).
 */
final class LeadActions
{
    /**
     * "Add lead": built for Instagram DMs. Five fields, source preset to
     * Instagram DM, "Add & add another" keeps the modal open.
     */
    public static function add(): Action
    {
        return Action::make('addLead')
            ->label('Add lead')
            ->icon(Heroicon::OutlinedPlus)
            ->keyBindings(['mod+shift+l'])
            ->visible(fn (): bool => self::user()?->can('create', Lead::class) ?? false)
            ->modalHeading('Add a lead')
            ->modalDescription('For Instagram DMs, WhatsApp and calls. Add a handle, phone or email so we can reply.')
            ->modalWidth('lg')
            ->modalSubmitActionLabel('Add lead')
            ->extraModalFooterActions(fn (Action $action): array => [
                $action->makeModalSubmitAction('addAnother', arguments: ['another' => true])->label('Add & add another'),
            ])
            ->schema([
                TextInput::make('name')->label('Name')->required()->maxLength(120)->autofocus(),
                Grid::make(2)->schema([
                    TextInput::make('instagram_handle')
                        ->label('Instagram handle')
                        ->prefix('@')
                        ->maxLength(60)
                        ->live(onBlur: true)
                        ->helperText(fn (Get $get): ?string => self::duplicateHint($get('instagram_handle'), $get('phone'))),
                    TextInput::make('phone')->label('Phone or WhatsApp')->tel()->maxLength(32)->live(onBlur: true),
                ]),
                Grid::make(2)->schema([
                    Select::make('interest')->label('Interested in')->options(self::interestOptions()),
                    Select::make('source')->label('Source')->options(LeadSource::manualOptions())->default(LeadSource::InstagramDm->value)->required()->selectablePlaceholder(false),
                ]),
                Textarea::make('note')->label('Note')->rows(3)->maxLength(5000)->placeholder('What did they ask? Dates, number of riders…'),
                TextInput::make('email')->label('Email (optional)')->email()->maxLength(190),
            ])
            ->action(function (array $data, array $arguments, Action $action, Schema $schema): void {
                $user = self::user();

                if ($user === null) {
                    return;
                }

                try {
                    /** @var array{name: string, phone?: string|null, instagram_handle?: string|null, email?: string|null, interest?: string|null, note?: string|null, source?: string|null} $data */
                    $lead = app(CreateManualLead::class)->handle($data, $user);
                } catch (ValidationException $e) {
                    Notification::make()->danger()->title('Lead not added')->body(collect($e->errors())->flatten()->first())->send();
                    $action->halt();

                    return;
                }

                Notification::make()->success()->title('Lead added')->body($lead->name)
                    ->actions([Action::make('open')->label('Open')->url(LeadResource::getUrl('view', ['record' => $lead]))])
                    ->send();

                if ($arguments['another'] ?? false) {
                    $schema->fill(['source' => $data['source'] ?? LeadSource::InstagramDm->value]);
                    $action->halt();
                }
            });
    }

    public static function changeStatus(): Action
    {
        return Action::make('changeStatus')
            ->label('Change status')
            ->icon(Heroicon::OutlinedArrowsRightLeft)
            ->visible(fn (Lead $record): bool => self::canUpdate($record) && $record->status !== LeadStatus::Booked)
            ->fillForm(fn (Lead $record): array => ['status' => null, 'next_follow_up_at' => app(SuggestFollowUp::class)->date()?->toDateString()])
            ->schema([
                Select::make('status')
                    ->label('Move to')
                    ->options(fn (Lead $record): array => self::targets($record))
                    ->required()
                    ->live(),
                Textarea::make('reason')
                    ->label('Why was it lost?')
                    ->rows(2)
                    ->maxLength(500)
                    ->required(fn (Get $get): bool => $get('status') === LeadStatus::Lost->value)
                    ->visible(fn (Get $get): bool => $get('status') === LeadStatus::Lost->value),
                // Milestone 10c: a suggested follow-up when the lead is contacted.
                DatePicker::make('next_follow_up_at')
                    ->label('Next follow-up')
                    ->native(false)
                    ->displayFormat('j M Y')
                    ->minDate(now(self::tz())->startOfDay())
                    ->default(fn (): ?string => app(SuggestFollowUp::class)->date()?->toDateString())
                    ->helperText('Suggested from the follow-up setting. Clear it to leave the date as it is.')
                    ->visible(fn (Get $get): bool => $get('status') === LeadStatus::Contacted->value),
            ])
            ->action(fn (Lead $record, array $data) => self::move(
                $record,
                (string) $data['status'],
                $data['reason'] ?? null,
                followUp: filled($data['next_follow_up_at'] ?? null) ? CarbonImmutable::parse((string) $data['next_follow_up_at'], self::tz()) : null,
                fromForm: true,
            ));
    }

    /**
     * Board and list: move to one status (lost asks for a reason). Moving
     * to Contacted sets the follow-up date chosen in the form or, from the
     * board, the suggested one when the lead has none from today on.
     */
    public static function move(Lead $lead, string $status, ?string $reason = null, ?CarbonImmutable $followUp = null, bool $fromForm = false): bool
    {
        $user = self::user();
        $to = LeadStatus::tryFrom($status);

        if ($user === null || $to === null || ! $user->can('update', $lead)) {
            return false;
        }

        try {
            app(ChangeLeadStatus::class)->handle($lead, $to, $user, $reason);
        } catch (InvalidStatusTransition $e) {
            Notification::make()->danger()->title('Status not changed')->body($e->getMessage())->send();

            return false;
        }

        $note = null;

        if ($to === LeadStatus::Contacted) {
            if ($fromForm && $followUp !== null) {
                app(UpdateLeadPipeline::class)->followUp($lead, $followUp, $user);
                $note = 'Follow up on '.$followUp->format('j M');
            } elseif (! $fromForm) {
                $set = app(SuggestFollowUp::class)->applyAfterContact($lead->refresh(), $user);
                $note = $set === null ? null : 'Follow-up set for '.$set->format('j M').'. Change it on the lead if needed.';
            }
        }

        Notification::make()->success()->title($lead->name.' → '.$to->label())->body($note)->send();

        return true;
    }

    public static function assign(): Action
    {
        return Action::make('assign')
            ->label('Assign')
            ->icon(Heroicon::OutlinedUserCircle)
            ->visible(fn (Lead $record): bool => self::canUpdate($record))
            ->fillForm(fn (Lead $record): array => ['assigned_to' => $record->assigned_to])
            ->schema([
                Select::make('assigned_to')->label('Assignee')->options(self::teamOptions())->placeholder('Nobody'),
            ])
            ->action(function (Lead $record, array $data): void {
                $user = self::user();

                if ($user === null) {
                    return;
                }

                $assignee = filled($data['assigned_to'] ?? null) ? User::query()->find((int) $data['assigned_to']) : null;

                try {
                    app(UpdateLeadPipeline::class)->assign($record, $assignee, $user);
                } catch (ValidationException $e) {
                    Notification::make()->danger()->title('Not assigned')->body(collect($e->errors())->flatten()->first())->send();

                    return;
                }

                Notification::make()->success()->title('Assignee saved')->send();
            });
    }

    public static function followUp(): Action
    {
        return Action::make('followUp')
            ->label('Follow-up')
            ->icon(Heroicon::OutlinedCalendarDays)
            ->visible(fn (Lead $record): bool => self::canUpdate($record) && $record->status->isOpen())
            ->fillForm(fn (Lead $record): array => ['next_follow_up_at' => $record->next_follow_up_at?->toDateString()])
            ->schema([
                DatePicker::make('next_follow_up_at')->label('Next follow-up')->native(false)->displayFormat('j M Y')->minDate(now(self::tz())->startOfDay()),
            ])
            ->action(function (Lead $record, array $data): void {
                $user = self::user();

                if ($user === null) {
                    return;
                }

                $date = filled($data['next_follow_up_at'] ?? null) ? CarbonImmutable::parse((string) $data['next_follow_up_at']) : null;
                app(UpdateLeadPipeline::class)->followUp($record, $date, $user);

                Notification::make()->success()->title($date === null ? 'Follow-up cleared' : 'Follow-up set')->send();
            });
    }

    public static function addNote(): Action
    {
        return Action::make('addNote')
            ->label('Add note')
            ->icon(Heroicon::OutlinedPencilSquare)
            ->visible(fn (Lead $record): bool => self::canUpdate($record))
            ->schema([
                Textarea::make('body')->label('Note')->required()->rows(4)->maxLength(5000),
            ])
            ->action(function (Lead $record, array $data): void {
                $user = self::user();

                if ($user === null) {
                    return;
                }

                app(UpdateLeadPipeline::class)->addNote($record, (string) $data['body'], $user);
                Notification::make()->success()->title('Note added')->send();
            });
    }

    public static function convert(): Action
    {
        return Action::make('convert')
            ->label('Convert to customer')
            ->icon(Heroicon::OutlinedUserPlus)
            ->color('success')
            ->visible(fn (Lead $record): bool => self::user()?->can('convert', $record) ?? false)
            ->modalDescription('Links an existing customer, or creates one with this email. They sign in with a one-time code; nothing is sent now.')
            ->fillForm(fn (Lead $record): array => ['email' => $record->email])
            ->schema([
                Select::make('user_id')
                    ->label('Existing customer')
                    ->placeholder('Find by email or phone, or leave empty to create')
                    ->searchable()
                    ->getSearchResultsUsing(fn (string $search): array => User::query()
                        ->where('role', 'customer')
                        ->whereNull('anonymised_at')
                        ->where(fn ($q) => $q->where('email', 'like', '%'.$search.'%')->orWhere('phone', 'like', '%'.$search.'%')->orWhere('name', 'like', '%'.$search.'%'))
                        ->limit(20)
                        ->get()
                        ->mapWithKeys(fn (User $u): array => [$u->id => trim(($u->name ?? '').' <'.$u->email.'>')])
                        ->all())
                    ->getOptionLabelUsing(fn (mixed $value): ?string => User::query()->find((int) $value)?->email)
                    ->live(),
                TextInput::make('email')
                    ->label('Email for a new customer')
                    ->email()
                    ->maxLength(190)
                    ->visible(fn (Get $get): bool => blank($get('user_id'))),
            ])
            ->action(function (Lead $record, array $data): void {
                $user = self::user();

                if ($user === null || ! $user->can('convert', $record)) {
                    return;
                }

                $existing = filled($data['user_id'] ?? null) ? User::query()->find((int) $data['user_id']) : null;

                try {
                    $customer = app(ConvertLeadToCustomer::class)->handle($record, $user, $existing, $data['email'] ?? null);
                } catch (ValidationException $e) {
                    Notification::make()->danger()->title('Not converted')->body(collect($e->errors())->flatten()->first())->send();

                    return;
                }

                Notification::make()->success()->title('Linked to '.$customer->email)->send();
            });
    }

    /** @return array<string, string> */
    public static function targets(Lead $lead): array
    {
        $options = [];

        foreach (LeadStatus::cases() as $status) {
            if ($lead->status->canTransitionTo($status)) {
                $options[$status->value] = $status->label();
            }
        }

        return $options;
    }

    /** @return array<string, string> */
    public static function interestOptions(): array
    {
        $options = [];

        foreach (LeadInterest::cases() as $interest) {
            $options[$interest->value] = $interest->label();
        }

        return $options;
    }

    /** @return array<int, string> */
    public static function teamOptions(): array
    {
        return User::query()
            ->whereIn('role', ['staff', 'admin'])
            ->orderBy('name')
            ->get()
            ->mapWithKeys(fn (User $u): array => [$u->id => $u->name ?? $u->email])
            ->all();
    }

    public static function duplicateHint(mixed $handle, mixed $phone): ?string
    {
        $matches = CreateManualLead::possibleDuplicates(is_string($handle) ? $handle : null, is_string($phone) ? $phone : null);

        if ($matches === []) {
            return null;
        }

        return 'Possible duplicate: '.collect($matches)->map(fn (Lead $lead): string => '#'.$lead->id.' '.$lead->name.' ('.$lead->status->label().')')->implode(', ');
    }

    private static function canUpdate(Lead $lead): bool
    {
        return self::user()?->can('update', $lead) ?? false;
    }

    private static function user(): ?User
    {
        $user = auth()->user();

        return $user instanceof User ? $user : null;
    }

    private static function tz(): string
    {
        return (string) config('app.display_timezone');
    }
}
