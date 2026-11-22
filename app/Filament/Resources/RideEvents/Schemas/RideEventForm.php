<?php

declare(strict_types=1);

namespace App\Filament\Resources\RideEvents\Schemas;

use App\Enums\BalanceDue;
use App\Enums\EventPaymentMode;
use App\Enums\EventQuestionScope;
use App\Enums\EventQuestionType;
use App\Filament\Support\ContentFields;
use Filament\Forms\Components\Builder;
use Filament\Forms\Components\Builder\Block;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\MarkdownEditor;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TagsInput;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Tabs;
use Filament\Schemas\Components\Tabs\Tab;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;
use Illuminate\Support\Str;

/**
 * The event builder form (decided 2026-11-22). Prices (tiers) and dates
 * (occurrences) are managed in the tables below it, once the event is saved.
 */
class RideEventForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Tabs::make('Event')
                    ->columnSpanFull()
                    ->tabs([
                        Tab::make('Basics')
                            ->columns(2)
                            ->schema([
                                TextInput::make('title')->required()->maxLength(120)
                                    ->live(onBlur: true)
                                    ->afterStateUpdated(fn (?string $state, Set $set, Get $get) => $get('slug') === null || $get('slug') === '' ? $set('slug', Str::slug((string) $state)) : null),
                                TextInput::make('slug')->required()->maxLength(120)->alphaDash()->unique(ignoreRecord: true)
                                    ->helperText('The page address: /events/{slug}. Changing it breaks links already shared (Instagram bio, stories).'),
                                Select::make('ride_event_category_id')->label('Category')
                                    ->relationship('category', 'name', fn ($query) => $query->where('is_active', true)->orderBy('sort')->orderBy('name'))
                                    ->createOptionForm([
                                        TextInput::make('name')->required()->maxLength(60),
                                        TextInput::make('slug')->required()->maxLength(60)->alphaDash()->unique('ride_event_categories', 'slug'),
                                    ])
                                    ->helperText('The filter chips on /events. Manage the list under Content → Event categories.'),
                                TextInput::make('location_name')->label('Location')->maxLength(120)->placeholder('Yashwala Ranch, Islamabad')
                                    ->helperText('Shown in the key facts, emails and search results. Leave empty to use the venue.'),
                                Textarea::make('summary')->label('Short description')->rows(2)->maxLength(300)->columnSpanFull()
                                    ->helperText('One or two sentences: what, where, when, for whom. Shown on cards and at the top of the page.'),
                                MarkdownEditor::make('description')->label('Long description')->columnSpanFull()
                                    ->toolbarButtons([['bold', 'italic', 'link'], ['heading'], ['bulletList', 'orderedList'], ['undo', 'redo']]),
                                Toggle::make('is_featured')->label('Featured')->helperText('Shown first, with a "Featured" tag.'),
                            ]),
                        Tab::make('Payment and booking rules')
                            ->columns(2)
                            ->schema([
                                Select::make('payment_mode')->label('Payment')->options(EventPaymentMode::class)->required()->default(EventPaymentMode::Full->value)->live(),
                                TextInput::make('deposit_percent')->label('Deposit (%)')->integer()->minValue(1)->maxValue(99)->suffix('%')
                                    ->visible(fn (Get $get): bool => self::isDeposit($get('payment_mode')))
                                    ->required(fn (Get $get): bool => self::isDeposit($get('payment_mode')))
                                    ->live(onBlur: true)
                                    ->afterStateUpdated(fn (mixed $state, Set $set) => is_numeric($state) ? $set('non_refundable_percent', (int) $state) : null)
                                    ->helperText('Charged at booking; it confirms the place. Deposits are non-refundable.'),
                                TextInput::make('non_refundable_percent')->label('Non-refundable share (%)')->integer()->minValue(0)->maxValue(100)->suffix('%')->required()->default(0)
                                    ->helperText('Kept when a customer cancels before the cutoff (usually the deposit). 0 = full refund before the cutoff. After the cutoff nothing is refunded; if we cancel, everything is.'),
                                TextInput::make('cancellation_cutoff_days')->label('Cancellation cutoff (days before)')->integer()->minValue(0)->maxValue(365)->required()->default(7),
                                Select::make('balance_due')->label('Balance due')->options(BalanceDue::class)->default(BalanceDue::OnTheDay->value)->live()
                                    ->visible(fn (Get $get): bool => self::isDeposit($get('payment_mode'))),
                                TextInput::make('balance_deadline_hours')->label('Online deadline (hours before the start)')->integer()->minValue(0)->maxValue(2000)->default(0)
                                    ->visible(fn (Get $get): bool => self::isDeposit($get('payment_mode')) && ($get('balance_due') === BalanceDue::ByDeadline->value || $get('balance_due') === BalanceDue::ByDeadline)),
                                TextInput::make('min_per_booking')->label('Fewest people per booking')->integer()->minValue(1)->maxValue(50)->required()->default(1),
                                TextInput::make('max_per_booking')->label('Most people per booking')->integer()->minValue(1)->maxValue(50)->placeholder('20')
                                    ->helperText('Never more than the seats left on a date.'),
                                DateTimePicker::make('booking_opens_at')->label('Bookings open (Pakistan time)')->seconds(false)->timezone((string) config('app.display_timezone'))
                                    ->helperText('Leave empty to open as soon as it is published.'),
                                TextInput::make('booking_closes_hours')->label('Bookings close (hours before the start)')->integer()->minValue(0)->maxValue(2000)->required()->default(24),
                                Toggle::make('waitlist_enabled')->label('Waitlist when full')->helperText('Signed-in customers can join a full date and get an offer when places free up.'),
                                TextInput::make('acknowledgement_text')->label('Required checkbox at booking')->maxLength(300)->columnSpanFull()
                                    ->placeholder('I’ll wear a cowboy hat and western wear, and bring my own riding helmet.')
                                    ->helperText('Optional. When set, customers must tick it to book; it is repeated in their emails.'),
                            ]),
                        Tab::make('Content')
                            ->schema([self::blocks()]),
                        Tab::make('Questions')
                            ->schema([
                                Repeater::make('questions')
                                    ->label('Custom questions')
                                    ->helperText('Asked at booking, for each person or once per booking. Answers are on the run sheet and in the export.')
                                    ->schema([
                                        Hidden::make('key')->default(fn (): string => (string) Str::uuid()),
                                        TextInput::make('label')->label('Question')->required()->maxLength(150),
                                        Select::make('type')->options(EventQuestionType::class)->required()->default(EventQuestionType::Text->value)->live(),
                                        TagsInput::make('options')->label('Choices')
                                            ->visible(fn (Get $get): bool => $get('type') === EventQuestionType::Select->value || $get('type') === EventQuestionType::Select)
                                            ->required(fn (Get $get): bool => $get('type') === EventQuestionType::Select->value || $get('type') === EventQuestionType::Select),
                                        Select::make('scope')->label('Ask')->options(EventQuestionScope::class)->required()->default(EventQuestionScope::Attendee->value),
                                        Toggle::make('required'),
                                    ])
                                    ->columns(2)
                                    ->reorderable()
                                    ->collapsible()
                                    ->itemLabel(fn (array $state): ?string => is_string($state['label'] ?? null) ? $state['label'] : null)
                                    ->defaultItems(0)
                                    ->maxItems(15),
                            ]),
                        Tab::make('Media and SEO')
                            ->columns(2)
                            ->schema([
                                ContentFields::image('cover_image', 'Cover image'),
                                TextInput::make('cover_image_alt')->label('Cover alt text')->maxLength(200)
                                    ->helperText('Describe the photo for people who cannot see it.'),
                                Repeater::make('gallery')
                                    ->label('Gallery')
                                    ->schema([
                                        ContentFields::image('image', 'Photo')->required(),
                                        TextInput::make('alt')->label('Alt text')->required()->maxLength(200),
                                    ])
                                    ->columns(2)
                                    ->maxItems(12)
                                    ->reorderable()
                                    ->defaultItems(0)
                                    ->columnSpanFull(),
                                TextInput::make('video_url')->label('Video link')->url()->maxLength(255)
                                    ->rule('regex:/^https:\/\//')
                                    ->helperText('An https:// link (Instagram reel, YouTube). Shown as a "Video" button.'),
                                TextInput::make('meta_title')->maxLength(60),
                                Textarea::make('meta_description')->rows(2)->maxLength(160)->columnSpanFull(),
                            ]),
                    ]),
            ]);
    }

    /** Optional, sortable content blocks; the format RideEvent::$blocks documents. */
    private static function blocks(): Builder
    {
        $list = fn (string $name, string $label): Repeater => ContentFields::list($name, $label);

        return Builder::make('blocks')
            ->label('Sections (shown in this order)')
            ->blocks([
                Block::make('programme')->label('Programme (timeline)')->icon('heroicon-o-clock')
                    ->schema([
                        Repeater::make('items')->label('Steps')
                            ->schema([
                                TextInput::make('time')->required()->maxLength(20)->placeholder('10:00'),
                                TextInput::make('activity')->required()->maxLength(150),
                            ])
                            ->columns(2)->reorderable()->defaultItems(1),
                    ]),
                Block::make('included')->label('What’s included / not included')->icon('heroicon-o-check-circle')
                    ->schema([$list('included', 'Included'), $list('not_included', 'Not included')]),
                Block::make('bring')->label('What to bring')->icon('heroicon-o-shopping-bag')
                    ->schema([$list('items', 'Items')]),
                Block::make('dress_code')->label('Dress code')->icon('heroicon-o-sparkles')
                    ->schema([
                        TextInput::make('text')->label('In one line')->maxLength(200)->placeholder('No hat, no ride.'),
                        $list('items', 'Details'),
                    ]),
                Block::make('requirements')->label('Requirements')->icon('heroicon-o-shield-check')
                    ->schema([
                        TextInput::make('level')->label('Riding level')->maxLength(150),
                        TextInput::make('age')->label('Age')->maxLength(150),
                        TextInput::make('fitness')->label('Fitness')->maxLength(150),
                    ])->columns(3),
                Block::make('faq')->label('FAQ')->icon('heroicon-o-question-mark-circle')
                    ->schema([
                        Repeater::make('items')->label('Questions')
                            ->schema([
                                TextInput::make('question')->required()->maxLength(200),
                                Textarea::make('answer')->required()->rows(3)->maxLength(1000),
                            ])
                            ->reorderable()->defaultItems(1),
                    ]),
                Block::make('location')->label('Location note')->icon('heroicon-o-map-pin')
                    ->schema([Textarea::make('text')->required()->rows(3)->maxLength(600)]),
                Block::make('photos')->label('Photos')->icon('heroicon-o-photo')
                    ->schema([
                        TextInput::make('heading')->maxLength(100),
                        Repeater::make('images')
                            ->schema([
                                ContentFields::image('image', 'Photo')->required(),
                                TextInput::make('alt')->label('Alt text')->required()->maxLength(200),
                            ])
                            ->columns(2)->reorderable()->maxItems(12)->defaultItems(1),
                    ]),
            ])
            ->blockNumbers(false)
            ->collapsible()
            ->reorderable()
            ->columnSpanFull();
    }

    private static function isDeposit(mixed $mode): bool
    {
        return $mode === EventPaymentMode::Deposit || $mode === EventPaymentMode::Deposit->value;
    }
}
