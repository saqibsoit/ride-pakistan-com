<?php

declare(strict_types=1);

namespace App\Filament\Resources\QuickReplies;

use App\Actions\Leads\RenderQuickReply;
use App\Enums\QuickReplyCategory;
use App\Filament\Resources\QuickReplies\Pages\CreateQuickReply;
use App\Filament\Resources\QuickReplies\Pages\EditQuickReply;
use App\Filament\Resources\QuickReplies\Pages\ListQuickReplies;
use App\Models\QuickReply;
use BackedEnum;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Support\HtmlString;
use UnitEnum;

/**
 * Reply templates for Instagram DMs (milestone 10c). Admins write them;
 * staff copy them from the "Quick replies" page, a lead or the board.
 */
class QuickReplyResource extends Resource
{
    public const TOKEN_HELP = '{lead_name}, {booking_link}, {product_link:slug}, {price:slug}, {per_session:slug}, {validity:slug}, {schedule}, {location}, {trainer}, {cutoff}. Product slugs: single-session, private-session, 8-class-package, 16-class-package.';

    protected static ?string $model = QuickReply::class;

    protected static string|UnitEnum|null $navigationGroup = 'Leads';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedChatBubbleLeftRight;

    protected static ?string $navigationLabel = 'Reply templates';

    protected static ?string $modelLabel = 'reply template';

    protected static ?int $navigationSort = 30;

    // The "Quick replies" page (copying) owns /quick-replies.
    protected static ?string $slug = 'reply-templates';

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->columns(2)
            ->components([
                TextInput::make('title')->required()->maxLength(120),
                Select::make('category')->options(QuickReplyCategory::class)->required(),
                Textarea::make('body')->label('Message')->required()->rows(8)->maxLength(2000)->live(debounce: 500)->columnSpanFull()
                    ->helperText('Tokens are filled from live data when the reply is copied: '.self::TOKEN_HELP.' Links include the lead\'s tracking reference.'),
                Toggle::make('is_active')->label('Show on the copy lists')->default(true),
                Section::make('Preview')
                    ->description('With today\'s prices and times, for a lead called Ayesha (without the tracking reference).')
                    ->columnSpanFull()
                    ->schema([
                        Placeholder::make('preview')->hiddenLabel()
                            ->content(fn (Get $get): Htmlable => self::preview((string) $get('body'))),
                    ]),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('sort')
            ->reorderable('sort')
            ->columns([
                TextColumn::make('title')->searchable(),
                TextColumn::make('category')->badge(),
                TextColumn::make('body')->label('Message')->limit(80)->wrap(),
                IconColumn::make('is_active')->label('Shown')->boolean(),
            ])
            ->filters([
                SelectFilter::make('category')->options(QuickReplyCategory::class),
            ])
            ->recordActions([
                EditAction::make(),
                DeleteAction::make(),
            ]);
    }

    public static function preview(string $body): Htmlable
    {
        if (trim($body) === '') {
            return new HtmlString('<em>Type a message to see it filled in.</em>');
        }

        $render = app(RenderQuickReply::class);
        $unknown = $render->unknownTokens($body);
        $text = $render->handle(str_replace('{lead_name}', 'Ayesha', $body));

        $html = '<div style="white-space: pre-line">'.e($text).'</div>';

        if ($unknown !== []) {
            $html .= '<p><strong>Not filled:</strong> '.e(implode(', ', $unknown)).'. Check the spelling or the product slug.</p>';
        }

        return new HtmlString($html);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListQuickReplies::route('/'),
            'create' => CreateQuickReply::route('/create'),
            'edit' => EditQuickReply::route('/{record}/edit'),
        ];
    }
}
