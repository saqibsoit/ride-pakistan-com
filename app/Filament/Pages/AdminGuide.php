<?php

declare(strict_types=1);

namespace App\Filament\Pages;

use App\Actions\Slots\SlotOverlap;
use App\Filament\Resources\Products\ProductResource;
use App\Filament\Resources\ScheduleRules\ScheduleRuleResource;
use App\Filament\Resources\Slots\SlotResource;
use App\Models\User;
use BackedEnum;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use UnitEnum;

/**
 * Read-only how-to for running the offer from the panel (milestone 10a):
 * adding a package, seasonal timings, one-off sessions, and hide versus
 * archive versus delete.
 */
class AdminGuide extends Page
{
    protected string $view = 'filament.pages.admin-guide';

    protected static string|UnitEnum|null $navigationGroup = 'System';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBookOpen;

    protected static ?string $title = 'Admin guide';

    protected static ?int $navigationSort = 110;

    public static function canAccess(): bool
    {
        $user = auth()->user();

        return $user instanceof User && $user->isTeamMember();
    }

    /**
     * @return array<string, bool|int|string|null>
     */
    protected function getViewData(): array
    {
        $user = auth()->user();
        $admin = $user instanceof User && $user->isAdmin();

        return [
            'admin' => $admin,
            'productsUrl' => $admin ? ProductResource::getUrl('index') : null,
            'newProductUrl' => $admin ? ProductResource::getUrl('create') : null,
            'rulesUrl' => $admin ? ScheduleRuleResource::getUrl('index') : null,
            'bulkUrl' => BulkSchedule::canAccess() ? BulkSchedule::getUrl() : null,
            'bufferMinutes' => app(SlotOverlap::class)->bufferMinutes(),
            'slotsUrl' => SlotResource::getUrl('index'),
            'settingsUrl' => $admin ? ManageSettings::getUrl() : null,
        ];
    }
}
