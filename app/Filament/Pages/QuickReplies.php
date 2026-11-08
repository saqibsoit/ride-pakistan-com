<?php

declare(strict_types=1);

namespace App\Filament\Pages;

use App\Enums\LeadStatus;
use App\Filament\Concerns\InteractsWithQuickReplies;
use App\Filament\Resources\QuickReplies\QuickReplyResource;
use App\Models\Lead;
use App\Models\QuickReply;
use App\Models\User;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Pages\Page;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Url;
use UnitEnum;

/**
 * "Quick replies" (milestone 10c, staff and admins): every reply template
 * filled in with today's data, ready to copy into Instagram. Choosing a
 * lead adds its name and the tracked booking links, and logs each copy on
 * the lead's timeline.
 *
 * @property-read Schema $form
 */
class QuickReplies extends Page
{
    use InteractsWithQuickReplies;

    protected string $view = 'filament.pages.quick-replies';

    protected static string|UnitEnum|null $navigationGroup = 'Leads';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedClipboardDocumentList;

    protected static ?string $title = 'Quick replies';

    protected static ?int $navigationSort = 20;

    #[Url(as: 'lead')]
    public ?int $leadId = null;

    public static function canAccess(): bool
    {
        $user = auth()->user();

        return $user instanceof User && $user->can('viewAny', QuickReply::class) && $user->can('viewAny', Lead::class);
    }

    protected function getHeaderActions(): array
    {
        $user = auth()->user();

        return [
            Action::make('templates')->label('Edit templates')->icon(Heroicon::OutlinedPencilSquare)->color('gray')
                ->visible($user instanceof User && $user->can('create', QuickReply::class))
                ->url(QuickReplyResource::getUrl('index')),
        ];
    }

    public function form(Schema $schema): Schema
    {
        return $schema->components([
            Select::make('leadId')
                ->label('Lead')
                ->placeholder('No lead (plain links, not logged)')
                ->searchable()
                ->live()
                ->getSearchResultsUsing(fn (string $search): array => Lead::query()
                    ->whereIn('status', [LeadStatus::New, LeadStatus::Contacted, LeadStatus::Qualified, LeadStatus::Lost])
                    ->where(fn ($query) => $query
                        ->where('name', 'like', '%'.$search.'%')
                        ->orWhere('instagram_handle', 'like', '%'.ltrim($search, '@').'%')
                        ->orWhere('phone', 'like', '%'.$search.'%')
                        ->orWhere('email', 'like', '%'.$search.'%'))
                    ->latest('updated_at')
                    ->limit(20)
                    ->get()
                    ->mapWithKeys(fn (Lead $lead): array => [$lead->id => self::leadLabel($lead)])
                    ->all())
                ->getOptionLabelUsing(fn ($value): ?string => ($lead = Lead::query()->find((int) $value)) === null ? null : self::leadLabel($lead)),
        ]);
    }

    public function replies(): View
    {
        $lead = $this->leadId === null ? null : Lead::query()->find($this->leadId);

        return self::quickReplyList($lead);
    }

    private static function leadLabel(Lead $lead): string
    {
        return trim($lead->name.' '.($lead->contactLine() === '' ? '' : '('.$lead->contactLine().')').' · '.$lead->status->label());
    }
}
