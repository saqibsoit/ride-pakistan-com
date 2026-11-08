<?php

declare(strict_types=1);

namespace App\Filament\Pages;

use App\Enums\LeadSource;
use App\Enums\LeadStatus;
use App\Filament\Concerns\InteractsWithQuickReplies;
use App\Filament\Resources\Leads\LeadActions;
use App\Filament\Resources\Leads\LeadResource;
use App\Models\Lead;
use App\Models\User;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\Textarea;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Collection;
use Livewire\Attributes\Url;
use UnitEnum;

/**
 * Lead board (PLAN milestone 9): new → contacted → qualified → booked,
 * plus lost. Cards move with the "Move to" menu (keyboard and single
 * pointer, WCAG 2.5.7) or by dragging. Both go through ChangeLeadStatus;
 * moving to Lost asks for a reason. Each column shows its newest 50.
 */
class LeadBoard extends Page
{
    use InteractsWithQuickReplies;

    public const PER_COLUMN = 50;

    protected string $view = 'filament.pages.lead-board';

    protected static string|UnitEnum|null $navigationGroup = 'Leads';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedViewColumns;

    protected static ?string $title = 'Lead board';

    protected static ?int $navigationSort = 5;

    #[Url]
    public ?string $source = null;

    #[Url]
    public ?string $assignee = null;

    public static function canAccess(): bool
    {
        $user = auth()->user();

        return $user instanceof User && $user->can('viewAny', Lead::class);
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('list')->label('List')->icon(Heroicon::OutlinedListBullet)->color('gray')->url(LeadResource::getUrl()),
            LeadActions::add(),
        ];
    }

    /** Called from the card menu and on drop. */
    public function moveLead(int $leadId, string $status): void
    {
        if ($status === LeadStatus::Lost->value) {
            $this->mountAction('markLost', ['lead' => $leadId]);

            return;
        }

        $lead = Lead::query()->find($leadId);

        if ($lead !== null) {
            LeadActions::move($lead, $status);
        }
    }

    public function markLostAction(): Action
    {
        return Action::make('markLost')
            ->label('Mark as lost')
            ->modalHeading(fn (array $arguments): string => 'Mark '.(Lead::query()->find((int) ($arguments['lead'] ?? 0))->name ?? 'lead').' as lost')
            ->schema([
                Textarea::make('reason')->label('Why was it lost?')->required()->rows(2)->maxLength(500),
            ])
            ->action(function (array $data, array $arguments): void {
                $lead = Lead::query()->find((int) ($arguments['lead'] ?? 0));

                if ($lead !== null) {
                    LeadActions::move($lead, LeadStatus::Lost->value, (string) $data['reason']);
                }
            });
    }

    /**
     * @return array<string, array{status: LeadStatus, total: int, leads: Collection<int, Lead>}>
     */
    public function columns(): array
    {
        $columns = [];

        foreach (LeadStatus::cases() as $status) {
            $query = Lead::query()
                ->where('status', $status)
                ->when(LeadSource::tryFrom((string) $this->source), fn ($q, LeadSource $source) => $q->where('source', $source))
                ->when($this->assignee === 'none', fn ($q) => $q->whereNull('assigned_to'))
                ->when(ctype_digit((string) $this->assignee), fn ($q) => $q->where('assigned_to', (int) $this->assignee));

            $columns[$status->value] = [
                'status' => $status,
                'total' => (clone $query)->count(),
                'leads' => $query->with('assignee')->latest('updated_at')->limit(self::PER_COLUMN)->get(),
            ];
        }

        return $columns;
    }

    /** @return array<string, string> */
    public function sourceOptions(): array
    {
        return LeadSource::options();
    }

    /** @return array<int, string> */
    public function teamOptions(): array
    {
        return LeadActions::teamOptions();
    }
}
