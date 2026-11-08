<?php

declare(strict_types=1);

namespace App\Filament\Resources\Leads\Schemas;

use App\Filament\Resources\Customers\CustomerResource;
use App\Filament\Resources\Orders\OrderResource;
use App\Models\Lead;
use App\Models\LeadNote;
use App\Models\User;
use Filament\Infolists\Components\TextEntry;
use Filament\Infolists\Components\ViewEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Spatie\Activitylog\Models\Activity;

class LeadInfolist
{
    public static function configure(Schema $schema): Schema
    {
        $tz = (string) config('app.display_timezone');

        return $schema->components([
            Section::make('Lead')
                ->columns(3)
                ->schema([
                    TextEntry::make('name'),
                    TextEntry::make('status')->badge(),
                    TextEntry::make('lost_reason')->label('Lost because')->visible(fn (Lead $record): bool => $record->lost_reason !== null),
                    TextEntry::make('source')->badge()->color('gray'),
                    TextEntry::make('interest')->formatStateUsing(fn (Lead $record): string => $record->interest?->label() ?? '—')->placeholder('—'),
                    TextEntry::make('preferred_date')->label('Preferred date')->date('j M Y')->placeholder('—'),
                    TextEntry::make('instagram_handle')->label('Instagram')->prefix('@')->placeholder('—')
                        ->url(fn (Lead $record): ?string => $record->instagram_handle === null ? null : 'https://instagram.com/'.$record->instagram_handle, shouldOpenInNewTab: true),
                    TextEntry::make('phone')->placeholder('—')->copyable(),
                    TextEntry::make('email')->placeholder('—')->copyable(),
                    TextEntry::make('assignee.name')->label('Assignee')->placeholder('Nobody'),
                    TextEntry::make('next_follow_up_at')->label('Next follow-up')->date('j M Y')->placeholder('—')
                        ->color(fn (Lead $record): ?string => $record->isFollowUpDue() ? 'danger' : null),
                    TextEntry::make('created_at')->label('Received')->dateTime('j M Y H:i', timezone: $tz),
                    TextEntry::make('creator.name')->label('Added by')->placeholder('Site form'),
                    TextEntry::make('user.email')->label('Customer')->placeholder('Not linked')
                        ->url(fn (Lead $record): ?string => $record->user instanceof User && auth()->user()?->can('update', $record->user) ? CustomerResource::getUrl('edit', ['record' => $record->user]) : null),
                    TextEntry::make('bookedOrder.reference')->label('Booked with')->placeholder('—')
                        ->url(fn (Lead $record): ?string => $record->bookedOrder !== null && auth()->user()?->can('view', $record->bookedOrder) ? OrderResource::getUrl('view', ['record' => $record->bookedOrder]) : null),
                    TextEntry::make('message')->columnSpanFull()->placeholder('—'),
                ]),
            Section::make('Attribution')
                ->columns(3)
                ->collapsible()
                ->schema([
                    TextEntry::make('first_touch_at')->label('First visit')->dateTime('j M Y H:i', timezone: $tz)->placeholder('Not recorded'),
                    TextEntry::make('utm_source')->label('UTM source')->placeholder('—'),
                    TextEntry::make('utm_medium')->label('UTM medium')->placeholder('—'),
                    TextEntry::make('utm_campaign')->label('UTM campaign')->placeholder('—'),
                    TextEntry::make('utm_content')->label('UTM content')->placeholder('—'),
                    TextEntry::make('utm_term')->label('UTM term')->placeholder('—'),
                    TextEntry::make('fbclid')->label('Meta click id')->placeholder('—')->limit(24),
                    TextEntry::make('gclid')->label('Google click id')->placeholder('—')->limit(24),
                    TextEntry::make('consent_tracking')->label('Tracking consent')->formatStateUsing(fn (Lead $record): string => $record->consent_tracking ? 'Yes' : 'No'),
                    TextEntry::make('landing_url')->label('Landing page')->placeholder('—')->columnSpanFull(),
                    TextEntry::make('referrer')->placeholder('—')->columnSpanFull(),
                ]),
            // Everything the team did with this lead, newest first (milestone 10c).
            Section::make('Timeline')
                ->collapsible()
                ->schema([
                    ViewEntry::make('timeline')->hiddenLabel()->view('filament.leads.timeline')
                        ->state(fn (Lead $record): array => self::timeline($record)),
                ]),
        ]);
    }

    /**
     * Audit entries for the lead and its notes: status, assignment,
     * follow-up dates, notes (never their text), quick replies copied.
     *
     * @return list<array{at: string, who: string, what: string}>
     */
    public static function timeline(Lead $lead): array
    {
        $tz = (string) config('app.display_timezone');
        $noteIds = $lead->notes()->pluck('id');

        $entries = Activity::query()
            ->where(fn ($query) => $query
                ->where(fn ($q) => $q->where('subject_type', $lead->getMorphClass())->where('subject_id', $lead->id))
                ->orWhere(fn ($q) => $q->where('subject_type', (new LeadNote)->getMorphClass())->whereIn('subject_id', $noteIds)))
            ->with('causer')
            ->latest('id')
            ->limit(100)
            ->get()
            ->map(fn (Activity $activity): array => [
                'at' => $activity->created_at?->setTimezone($tz)->format('j M Y, H:i') ?? '',
                'who' => $activity->causer instanceof User ? $activity->causer->displayName() : 'System',
                'what' => $activity->subject_type === (new LeadNote)->getMorphClass() ? 'Note '.($activity->event ?? 'changed') : (string) $activity->description,
            ])
            ->all();

        return array_values($entries);
    }
}
