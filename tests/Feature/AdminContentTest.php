<?php

declare(strict_types=1);

use App\Enums\SettingKey;
use App\Filament\Pages\ManageSettings;
use App\Filament\Resources\Products\Pages\EditProduct;
use App\Models\Faq;
use App\Models\Page;
use App\Models\Post;
use App\Models\Product;
use App\Models\ScheduleRule;
use App\Models\User;
use App\Support\Settings;
use Livewire\Livewire;
use Spatie\Activitylog\Models\Activity;

beforeEach(function (): void {
    $this->seed();
});

it('keeps content management closed to customers and staff', function (User $user): void {
    expect($user->can('viewAny', Product::class))->toBeFalse()
        ->and($user->can('update', Page::query()->firstOrFail()))->toBeFalse();

    $this->actingAs($user)->get('/test-admin/products')->assertForbidden();
})->with([
    'customer' => fn (): User => User::factory()->create(),
    'staff' => fn (): User => User::factory()->staff()->withMfa()->create(),
]);

describe('with admin access', function (): void {
    beforeEach(function (): void {
        $this->actingAs(User::factory()->admin()->withMfa()->create());
    });

    it('lists and opens every content resource', function (string $path): void {
        $this->get('/test-admin/'.$path)->assertOk();
        $this->get('/test-admin/'.$path.'/create')->assertOk();
    })->with(['pages', 'faqs', 'products', 'posts', 'schedule-rules']);

    it('opens edit forms for seeded records', function (): void {
        $this->get('/test-admin/products/'.Product::query()->firstOrFail()->getRouteKey().'/edit')->assertOk();
        $this->get('/test-admin/pages/'.Page::query()->where('slug', 'trainer')->firstOrFail()->id.'/edit')->assertOk();
        $this->get('/test-admin/posts/'.Post::query()->firstOrFail()->getRouteKey().'/edit')->assertOk();
        $this->get('/test-admin/faqs/'.Faq::query()->firstOrFail()->id.'/edit')->assertOk();
        $this->get('/test-admin/schedule-rules/'.ScheduleRule::query()->firstOrFail()->id.'/edit')->assertOk();
    });

    it('edits prices in rupees and stores paisa, with an audit entry', function (): void {
        $product = Product::query()->where('slug', 'single-session')->firstOrFail();

        Livewire::test(EditProduct::class, ['record' => $product->getRouteKey()])
            ->assertSchemaStateSet(['price_minor' => 10000])
            ->fillForm(['price_minor' => 12500])
            ->call('save')
            ->assertHasNoFormErrors();

        expect($product->refresh()->price_minor)->toBe(1_250_000);

        $log = Activity::query()->where('log_name', 'content')->where('subject_type', Product::class)->latest('id')->firstOrFail();
        expect($log->properties->get('attributes'))->toBe(['price_minor' => 1_250_000]);
    });

    it('saves settings from the settings page', function (): void {
        $this->get('/test-admin/manage-settings')->assertOk()->assertSee('Booking rules');

        Livewire::test(ManageSettings::class)
            ->fillForm(['booking__riders_per_slot' => 5, 'contact__email' => 'team@example.com'])
            ->call('save')
            ->assertHasNoFormErrors();

        $settings = app(Settings::class);
        $settings->flush();

        expect($settings->int(SettingKey::RidersPerSlot))->toBe(5)
            ->and($settings->string(SettingKey::Email))->toBe('team@example.com');
    });
});
