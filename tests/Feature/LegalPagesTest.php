<?php

declare(strict_types=1);

use App\Actions\Booking\CreateOrder;
use App\Actions\Seo\GenerateSitemap;
use App\Enums\SettingKey;
use App\Filament\Pages\ManageSettings;
use App\Models\Order;
use App\Models\Page;
use App\Models\Product;
use App\Models\Rider;
use App\Models\Slot;
use App\Models\User;
use App\Support\LegalPages;
use App\Support\Settings;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Livewire\Livewire;

/*
| The approved legal texts (decided 2026-11-11): /privacy, /terms and
| /refund-policy published from database/data/legal (a copy of docs/legal),
| the refund policy in policies.refund, the footer, the sitemap and the
| "By paying you agree to…" line at checkout.
*/

/** The publish migration, run again on demand. */
function legalMigration(): object
{
    return require database_path('migrations/2026_11_23_100000_publish_legal_pages.php');
}

/** The empty unpublished outline PageSeeder used to create. */
function legalOutline(string $slug): Page
{
    return Page::query()->updateOrCreate(['slug' => $slug], [
        'title' => 'Outline '.$slug,
        'is_published' => false,
        'is_indexable' => true,
        'body' => [['type' => 'text', 'data' => ['heading' => 'Contact', 'content' => '']]],
    ]);
}

function legalSetting(): string
{
    $settings = app(Settings::class);
    $settings->flush();

    return $settings->string(SettingKey::RefundPolicy);
}

describe('the texts', function (): void {
    it('keeps the deployed copy identical to docs/legal', function (string $slug): void {
        expect(file_get_contents(LegalPages::path($slug)))->toBe(file_get_contents(base_path('docs/legal/'.$slug.'.md')));
    })->with(LegalPages::SLUGS);

    it('turns each file into an introduction and one block per section', function (): void {
        $refund = LegalPages::content('refund-policy');

        expect($refund['title'])->toBe('Cancellation and Refund Policy')
            ->and($refund['answer'])->toStartWith('This policy applies to all bookings')
            ->and($refund['body'])->toHaveCount(9) // introduction + 8 sections
            ->and($refund['body'][0]['data']['content'])->toStartWith('Last updated: 10 November 2026')
            ->and($refund['body'][1]['data']['heading'])->toBe('1. Single and private sessions')
            ->and($refund['body'][8]['data']['content'])->toBe("WhatsApp: +92 334 4554500  \nEmail: hello@ride-pakistan.com  \nDestination Pakistan, Plot No. 68, Siddique Trade Centre, Gulberg, Lahore")
            ->and(mb_strlen($refund['meta_description']))->toBeLessThanOrEqual(160)
            ->and(LegalPages::content('privacy')['body'])->toHaveCount(11)
            ->and(LegalPages::content('terms')['body'])->toHaveCount(13);
    });

    it('stores the refund policy below its title as the setting', function (): void {
        expect(LegalPages::refundPolicySetting())->toStartWith('Last updated: 10 November 2026')
            ->and(LegalPages::refundPolicySetting())->not->toContain('# Cancellation and Refund Policy')
            ->and(LegalPages::refundPolicySetting())->toContain('## 8. Contact');
    });
});

describe('the migration', function (): void {
    it('publishes the outlines from the seed and fills the empty refund setting', function (): void {
        foreach (LegalPages::SLUGS as $slug) {
            legalOutline($slug);
        }

        legalMigration()->up();

        foreach (LegalPages::SLUGS as $slug) {
            $page = Page::query()->where('slug', $slug)->sole();

            expect($page->is_published)->toBeTrue()
                ->and($page->is_indexable)->toBeTrue()
                ->and($page->title)->toBe(LegalPages::content($slug)['title'])
                ->and($page->body)->toBe(LegalPages::content($slug)['body']);
        }

        expect(legalSetting())->toBe(LegalPages::refundPolicySetting());
    });

    it('never overwrites a page an admin already wrote, or a refund setting already set', function (): void {
        Log::spy();
        legalOutline('terms')->forceFill(['body' => [['type' => 'text', 'data' => ['content' => 'Our own terms']]]])->save();
        legalOutline('privacy');
        DB::table('settings')->insert(['key' => 'policies.refund', 'value' => json_encode('Our own refund text'), 'created_at' => now(), 'updated_at' => now()]);

        legalMigration()->up();

        expect(Page::query()->where('slug', 'terms')->sole()->body[0]['data']['content'])->toBe('Our own terms')
            ->and(Page::query()->where('slug', 'terms')->sole()->is_published)->toBeFalse()
            ->and(Page::query()->where('slug', 'privacy')->sole()->is_published)->toBeTrue()
            ->and(legalSetting())->toBe('Our own refund text');

        Log::shouldHaveReceived('warning')->withArgs(fn (string $message): bool => str_contains($message, '/terms'))->once();
    });

    it('adds a missing legal page on a seeded site, and leaves an empty database alone', function (): void {
        legalMigration()->up();

        expect(Page::query()->count())->toBe(0)
            ->and(DB::table('settings')->where('key', 'policies.refund')->exists())->toBeFalse();

        Page::factory()->create(['slug' => 'trainer']);
        legalMigration()->up();

        expect(Page::query()->whereIn('slug', LegalPages::SLUGS)->where('is_published', true)->count())->toBe(3);
    });
});

describe('on the site', function (): void {
    beforeEach(fn () => $this->seed());

    it('serves all three pages with their sections and keeps contact lines on separate lines', function (): void {
        $this->get('/privacy')->assertOk()->assertSee('Privacy Policy')->assertSee('1. What we collect')->assertSee('We do not sell your personal information.');
        $this->get('/terms')->assertOk()->assertSee('Terms and Conditions')->assertSee('11. Law and disputes');
        $this->get('/refund-policy')->assertOk()
            ->assertSee('3. Private session requests')
            ->assertSee('WhatsApp: +92 334 4554500<br', false);
    });

    it('links the three pages in the footer', function (): void {
        $this->get('/')->assertOk()
            ->assertSee('href="'.route('legal.privacy').'"', false)
            ->assertSee('href="'.route('legal.terms').'"', false)
            ->assertSee('href="'.route('legal.refund-policy').'"', false);
    });

    it('lists the three pages in the sitemap', function (): void {
        $xml = app(GenerateSitemap::class)->handle()->render();

        expect($xml)->toContain('<loc>'.route('legal.privacy').'</loc>')
            ->toContain('<loc>'.route('legal.terms').'</loc>')
            ->toContain('<loc>'.route('legal.refund-policy').'</loc>');
    });

    it('shows the full refund policy folded away on /prices, with a link to the page', function (): void {
        expect(legalSetting())->toBe(LegalPages::refundPolicySetting());

        $this->get('/prices')->assertOk()
            ->assertSee('Read the full refund policy')
            ->assertSee('<h3>1. Single and private sessions</h3>', false)
            ->assertDontSee('## 1.', false)
            ->assertSee('href="'.route('legal.refund-policy').'"', false);
    });

    it('lets an admin save the settings page with the full refund policy in it', function (): void {
        $this->actingAs(User::factory()->admin()->withMfa()->create());

        Livewire::test(ManageSettings::class)
            ->fillForm(['booking__riders_per_slot' => 5])
            ->call('save')
            ->assertHasNoFormErrors();

        expect(legalSetting())->toBe(LegalPages::refundPolicySetting())
            ->and(mb_strlen(legalSetting()))->toBeGreaterThan(2000)->toBeLessThan(ManageSettings::REFUND_POLICY_MAX);
    });
});

describe('at checkout', function (): void {
    function legalCheckoutOrder(): Order
    {
        $user = User::factory()->create();
        $rider = Rider::factory()->for($user)->create();
        $slot = Slot::factory()->at(CarbonImmutable::now((string) config('app.display_timezone'))->addDays(5)->setTime(8, 0))->create();

        return app(CreateOrder::class)->handle($user, Product::factory()->create(), $slot->id, [$rider->id]);
    }

    it('says paying means agreeing to the Terms and Refund Policy, with links', function (): void {
        $this->seed();
        $order = legalCheckoutOrder();

        $this->actingAs($order->user)->get(route('checkout.show', $order->reference))->assertOk()
            ->assertSee('By paying you agree to our')
            ->assertSee('href="'.route('legal.terms').'"', false)
            ->assertSee('href="'.route('legal.refund-policy').'"', false);
    });

    it('drops the links, not the sentence, while the pages are unpublished', function (): void {
        $order = legalCheckoutOrder();

        $this->actingAs($order->user)->get(route('checkout.show', $order->reference))->assertOk()
            ->assertSee('By paying you agree to our Terms and Conditions and Cancellation and Refund Policy.')
            ->assertDontSee('href="'.route('legal.terms').'"', false);
    });
});

describe('the guest checkout wording (2026-11-12)', function (): void {
    /** The page as published on 11 November: today's text with the new sentences put back to the old ones. */
    function legalOldBody(string $slug): array
    {
        $reflection = new ReflectionClass(legalGuestMigration());
        $c = $reflection->getConstants();
        $back = $slug === 'terms'
            ? [$c['DATE_NEW'] => $c['DATE_OLD'], $c['TERMS_NEW'] => $c['TERMS_OLD']]
            : [$c['DATE_NEW'] => $c['DATE_OLD'], $c['PRIVACY_NEW_2'] => $c['PRIVACY_OLD_2'], $c['PRIVACY_NEW_1'] => $c['PRIVACY_OLD_1']];

        return array_map(fn (array $block): array => ['type' => $block['type'], 'data' => [...$block['data'], 'content' => strtr($block['data']['content'], $back)]], LegalPages::content($slug)['body']);
    }

    function legalGuestMigration(): object
    {
        return require database_path('migrations/2026_11_24_100100_update_legal_pages_for_guest_checkout.php');
    }

    it('updates the published terms and privacy pages that still hold the old wording', function (): void {
        foreach (['terms', 'privacy'] as $slug) {
            Page::factory()->create(['slug' => $slug, 'is_published' => true, 'body' => legalOldBody($slug)]);
            expect(Page::query()->where('slug', $slug)->sole()->body)->not->toBe(LegalPages::content($slug)['body']);
        }

        legalGuestMigration()->up();

        foreach (['terms', 'privacy'] as $slug) {
            expect(Page::query()->where('slug', $slug)->sole()->body)->toBe(LegalPages::content($slug)['body']);
        }

        expect(json_encode(Page::query()->where('slug', 'terms')->sole()->body))->toContain('book as a guest');
    });

    it('leaves an edited page alone with a warning, and the new wording without one', function (): void {
        Log::spy();
        Page::factory()->create(['slug' => 'terms', 'is_published' => true, 'body' => [['type' => 'text', 'data' => ['content' => 'Our own terms']]]]);
        Page::factory()->create(['slug' => 'privacy', 'is_published' => true, 'body' => LegalPages::content('privacy')['body']]);

        legalGuestMigration()->up();

        expect(Page::query()->where('slug', 'terms')->sole()->body[0]['data']['content'])->toBe('Our own terms')
            ->and(Page::query()->where('slug', 'privacy')->sole()->body)->toBe(LegalPages::content('privacy')['body']);

        Log::shouldHaveReceived('warning')->withArgs(fn (string $message): bool => str_contains($message, '/terms'))->once();
        Log::shouldNotHaveReceived('warning', [Mockery::on(fn (string $message): bool => str_contains($message, '/privacy'))]);
    });
});
