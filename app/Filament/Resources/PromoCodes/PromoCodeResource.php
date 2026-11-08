<?php

declare(strict_types=1);

namespace App\Filament\Resources\PromoCodes;

use App\Actions\Promos\ArchivePromoCode;
use App\Enums\DiscountType;
use App\Filament\Resources\PromoCodes\Pages\CreatePromoCode;
use App\Filament\Resources\PromoCodes\Pages\EditPromoCode;
use App\Filament\Resources\PromoCodes\Pages\ListPromoCodes;
use App\Models\Product;
use App\Models\PromoCode;
use App\Models\User;
use App\Support\Money;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Radio;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use UnitEnum;

/**
 * Promo codes (milestone 10e, admins only). Percentage or fixed PKR,
 * products, window, limits, minimum order, first booking only. One code
 * per order; never stacks with another promo, can combine with a gift
 * voucher. Codes are applied at checkout from Part B; until then the
 * usage columns stay at zero.
 */
class PromoCodeResource extends Resource
{
    protected static ?string $model = PromoCode::class;

    protected static string|UnitEnum|null $navigationGroup = 'Sales & promotions';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedTag;

    protected static ?string $navigationLabel = 'Promo codes';

    protected static ?string $modelLabel = 'promo code';

    protected static ?int $navigationSort = 4;

    protected static ?string $recordTitleAttribute = 'code';

    public static function form(Schema $schema): Schema
    {
        $tz = (string) config('app.display_timezone');

        return $schema
            ->columns(2)
            ->components([
                Section::make('Code')->columnSpanFull()->columns(2)->schema([
                    TextInput::make('code')->required()->maxLength(40)
                        ->helperText('What customers type, e.g. EID25. Letters, numbers, - and _; saved in capitals.')
                        ->disabled(fn (?PromoCode $record): bool => self::used($record)),
                    TextInput::make('description')->label('Internal note')->maxLength(255),
                    Radio::make('discount_type')->label('Discount')->options(DiscountType::class)->required()->live()->default(DiscountType::Percent->value)
                        ->disabled(fn (?PromoCode $record): bool => self::used($record)),
                    TextInput::make('percent')->label('Percent off')->integer()->minValue(1)->maxValue(100)->suffix('%')
                        ->visible(fn (Get $get): bool => self::type($get) === DiscountType::Percent)
                        ->required(fn (Get $get): bool => self::type($get) === DiscountType::Percent)
                        ->disabled(fn (?PromoCode $record): bool => self::used($record)),
                    TextInput::make('amount_pkr')->label('Amount off (PKR)')->integer()->minValue(1)->prefix('PKR')
                        ->visible(fn (Get $get): bool => self::type($get) === DiscountType::Fixed)
                        ->required(fn (Get $get): bool => self::type($get) === DiscountType::Fixed)
                        ->disabled(fn (?PromoCode $record): bool => self::used($record)),
                    Select::make('product_ids')->label('Products')->multiple()
                        ->options(fn (): array => Product::query()->notArchived()->ordered()->pluck('name', 'id')->all())
                        ->helperText('Leave empty for every product.')
                        ->disabled(fn (?PromoCode $record): bool => self::used($record))
                        ->columnSpanFull(),
                ]),
                Section::make('When and how often')->columnSpanFull()->columns(2)->schema([
                    DateTimePicker::make('starts_at')->label('Starts (Pakistan time)')->timezone($tz)->seconds(false),
                    DateTimePicker::make('ends_at')->label('Ends (Pakistan time)')->timezone($tz)->seconds(false),
                    TextInput::make('max_uses')->label('Total uses')->integer()->minValue(1)->helperText('Leave empty for no limit.'),
                    TextInput::make('max_uses_per_customer')->label('Uses per customer')->integer()->minValue(1)->helperText('Leave empty for no limit.'),
                    TextInput::make('min_order_pkr')->label('Minimum order (PKR)')->integer()->minValue(0)->prefix('PKR'),
                    Toggle::make('first_booking_only')->label('Only for a customer\'s first paid booking'),
                    Toggle::make('is_active')->label('Active')->default(true),
                ]),
            ]);
    }

    public static function table(Table $table): Table
    {
        $tz = (string) config('app.display_timezone');

        return $table
            ->modifyQueryUsing(fn (Builder $query): Builder => $query->withCount('redemptions')->withSum('redemptions', 'amount_minor')->with('products'))
            ->defaultSort('id', 'desc')
            ->columns([
                TextColumn::make('code')->searchable()->copyable()->description(fn (PromoCode $record): ?string => $record->description),
                TextColumn::make('summary')->label('Discount')->state(fn (PromoCode $record): string => $record->summary()),
                TextColumn::make('status')->badge()
                    ->state(fn (PromoCode $record): string => $record->statusLabel())
                    ->color(fn (string $state): string => match ($state) {
                        'Active' => 'success',
                        'Scheduled' => 'info',
                        default => 'gray',
                    }),
                TextColumn::make('products_list')->label('Products')
                    ->state(fn (PromoCode $record): string => $record->products->isEmpty() ? 'All' : $record->products->pluck('name')->join(', '))
                    ->wrap(),
                TextColumn::make('redemptions_count')->label('Used')
                    ->formatStateUsing(fn (PromoCode $record): string => $record->redemptions_count.($record->max_uses !== null ? ' / '.$record->max_uses : ''))
                    ->sortable(),
                TextColumn::make('redemptions_sum_amount_minor')->label('Discount given')
                    ->formatStateUsing(fn (mixed $state): string => Money::format((int) $state))
                    ->placeholder(Money::format(0)),
                TextColumn::make('window')->label('Valid')
                    ->state(fn (PromoCode $record): string => ($record->starts_at?->setTimezone($tz)->format('j M Y') ?? 'Now').' – '.($record->ends_at?->setTimezone($tz)->format('j M Y') ?? 'no end')),
                TextColumn::make('rules')->label('Rules')
                    ->state(fn (PromoCode $record): string => implode(' · ', array_filter([
                        $record->max_uses_per_customer !== null ? $record->max_uses_per_customer.' per customer' : null,
                        $record->min_order_minor !== null ? 'min '.Money::format($record->min_order_minor) : null,
                        $record->first_booking_only ? 'first booking' : null,
                    ])))
                    ->placeholder('—'),
            ])
            ->filters([
                TernaryFilter::make('archived')
                    ->label('Archived')
                    ->placeholder('Hide archived')
                    ->trueLabel('Only archived')
                    ->falseLabel('With archived')
                    ->queries(
                        true: fn (Builder $query): Builder => $query->whereNotNull('archived_at'),
                        false: fn (Builder $query): Builder => $query,
                        blank: fn (Builder $query): Builder => $query->whereNull('archived_at'),
                    ),
            ])
            ->recordActions([
                Action::make('usage')
                    ->label('Usage')
                    ->icon(Heroicon::OutlinedChartBar)
                    ->modalHeading(fn (PromoCode $record): string => 'Uses of '.$record->code)
                    ->modalContent(fn (PromoCode $record): View => view('filament.promos.usage', [
                        'promo' => $record,
                        'redemptions' => $record->redemptions()->with(['order', 'user'])->latest('id')->limit(100)->get(),
                    ]))
                    ->modalSubmitAction(false)
                    ->modalCancelActionLabel('Close'),
                EditAction::make()->visible(fn (PromoCode $record): bool => ! $record->isArchived()),
                Action::make('archive')
                    ->label(fn (PromoCode $record): string => ($record->redemptions_count ?? 0) > 0 ? 'Archive' : 'Delete')
                    ->icon(Heroicon::OutlinedArchiveBox)
                    ->color('danger')
                    ->visible(fn (PromoCode $record): bool => ! $record->isArchived() && self::admin() !== null)
                    ->requiresConfirmation()
                    ->modalDescription(fn (PromoCode $record): string => ($record->redemptions_count ?? 0) > 0
                        ? 'This code has been used, so it is archived (switched off and hidden) and kept for the reports.'
                        : 'This code has never been used, so it is deleted.')
                    ->action(function (PromoCode $record): void {
                        $admin = self::admin();

                        if ($admin === null) {
                            return;
                        }

                        $deleted = app(ArchivePromoCode::class)->handle($record, $admin);
                        Notification::make()->success()->title($deleted ? 'Promo code deleted' : 'Promo code archived')->send();
                    }),
                Action::make('restore')
                    ->label('Restore')
                    ->icon(Heroicon::OutlinedArrowUturnLeft)
                    ->visible(fn (PromoCode $record): bool => $record->isArchived() && self::admin() !== null)
                    ->requiresConfirmation()
                    ->modalDescription('The code comes back switched off. Turn it on when you want it used again.')
                    ->action(function (PromoCode $record): void {
                        $admin = self::admin();

                        if ($admin !== null) {
                            app(ArchivePromoCode::class)->restore($record, $admin);
                            Notification::make()->success()->title('Promo code restored (inactive)')->send();
                        }
                    }),
            ])
            ->emptyStateHeading('No promo codes yet');
    }

    /**
     * Form state for an existing code (PKR fields and products).
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public static function fillState(PromoCode $promo, array $data): array
    {
        return [
            ...$data,
            'discount_type' => $promo->discount_type->value,
            'amount_pkr' => $promo->amount_minor === null ? null : intdiv($promo->amount_minor, Money::MINOR_PER_MAJOR),
            'min_order_pkr' => $promo->min_order_minor === null ? null : intdiv($promo->min_order_minor, Money::MINOR_PER_MAJOR),
            'product_ids' => $promo->products()->pluck('products.id')->map(fn (mixed $id): int => (int) $id)->all(),
        ];
    }

    private static function type(Get $get): DiscountType
    {
        $value = $get('discount_type');

        return $value instanceof DiscountType ? $value : (DiscountType::tryFrom((string) $value) ?? DiscountType::Percent);
    }

    private static function used(?PromoCode $record): bool
    {
        return $record !== null && $record->redemptions()->exists();
    }

    private static function admin(): ?User
    {
        $user = auth()->user();

        return $user instanceof User && $user->isAdmin() ? $user : null;
    }

    public static function getPages(): array
    {
        return [
            'index' => ListPromoCodes::route('/'),
            'create' => CreatePromoCode::route('/create'),
            'edit' => EditPromoCode::route('/{record}/edit'),
        ];
    }
}
