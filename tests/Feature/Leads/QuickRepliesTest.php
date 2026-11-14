<?php

declare(strict_types=1);

use App\Actions\Leads\LogQuickReplyCopy;
use App\Actions\Leads\RenderQuickReply;
use App\Enums\QuickReplyCategory;
use App\Filament\Pages\QuickReplies;
use App\Filament\Resources\Leads\Pages\ViewLead;
use App\Models\Lead;
use App\Models\Product;
use App\Models\QuickReply;
use App\Models\ScheduleRule;
use App\Models\User;
use App\Support\BusinessFacts;
use App\Support\LeadRef;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Spatie\Activitylog\Models\Activity;

/** Fresh renderer: BusinessFacts is per request, so drop the cached products and rules. */
function renderReply(QuickReply|string $reply, ?Lead $lead = null, bool $tracked = true): string
{
    app()->forgetScopedInstances();

    return app(RenderQuickReply::class)->handle($reply, $lead, $tracked);
}

beforeEach(function (): void {
    $this->single = Product::factory()->create(['slug' => 'single-session', 'name' => 'Single session', 'price_minor' => 1_000_000]);
    ScheduleRule::factory()->create(['day_of_week' => 6, 'start_time' => '08:00', 'end_time' => '10:00']);
});

it('fills prices, schedule and links from live data, every time', function (): void {
    $body = 'Hi {lead_name}, it is {price:single-session}, we ride {schedule}. Book: {booking_link} or {product_link:single-session}';
    $before = renderReply($body);

    expect($before)->toContain('Hi there')
        ->toContain('PKR 10,000')
        ->toContain(app(BusinessFacts::class)->scheduleText())
        ->toContain(route('book'))
        ->toContain(route('sessions.show', 'single-session'))
        ->not->toContain('{');

    // A price change shows straight away: nothing is stored in the template.
    $this->single->update(['price_minor' => 1_200_000]);
    ScheduleRule::factory()->create(['day_of_week' => 0, 'start_time' => '15:00', 'end_time' => '17:00']);

    $after = renderReply($body);
    expect($after)->toContain('PKR 12,000')->not->toContain('PKR 10,000')
        ->and($after)->toContain(app(BusinessFacts::class)->scheduleText());
});

it('keeps unknown tokens visible so a typo is noticed', function (): void {
    expect(renderReply('{price:no-such-product} {product_link:nope} {mystery}'))->toBe('{price:no-such-product} {product_link:nope} {mystery}')
        ->and(app(RenderQuickReply::class)->unknownTokens('{price:nope} {booking_link}'))->toBe(['{price:nope}']);
});

it('greets the lead by first name and signs the booking links', function (): void {
    $lead = Lead::factory()->create(['name' => 'Ayesha Khan']);

    $text = renderReply('Hi {lead_name}: {booking_link}', $lead);

    expect($text)->toBe('Hi Ayesha: '.route('book').'?ref=ig-dm&lead='.LeadRef::for($lead))
        ->and(LeadRef::resolveId(LeadRef::for($lead)))->toBe($lead->id)
        ->and(renderReply('{booking_link}', $lead, tracked: false))->toBe(route('book'));
});

it('rejects forged, tampered and unsigned lead references', function (): void {
    $lead = Lead::factory()->create();
    $other = Lead::factory()->create();
    [, $tag] = explode('-', LeadRef::for($lead));

    expect(LeadRef::resolveId((string) $lead->id))->toBeNull()
        ->and(LeadRef::resolveId($other->id.'-'.$tag))->toBeNull()
        ->and(LeadRef::resolveId($lead->id.'-aaaaaaaaaa'))->toBeNull()
        ->and(LeadRef::resolveId('x'))->toBeNull()
        ->and(LeadRef::resolveId(null))->toBeNull()
        ->and(LeadRef::resolve(LeadRef::for($lead))?->id)->toBe($lead->id);
});

it('ships eight templates that render with no unknown tokens', function (): void {
    Product::factory()->private(1_500_000)->create(['slug' => 'private-session']);
    Product::factory()->package(8, 6_000_000, 42)->create(['slug' => '8-class-package']);
    Product::factory()->package(16, 10_000_000, 70)->create(['slug' => '16-class-package']);

    $keys = DB::table('quick_replies')->whereNotNull('key')->orderBy('sort')->pluck('key')->all();

    expect($keys)->toBe(['first-reply', 'prices', 'timings', 'location', 'private-sessions', 'packages', 'bank-transfer', 'follow-up']);

    app()->forgetScopedInstances();

    foreach (QuickReply::query()->get() as $reply) {
        expect(app(RenderQuickReply::class)->unknownTokens($reply->body))->toBe([], $reply->title);
    }

    expect(QuickReply::query()->where('key', 'location')->value('body'))->toContain('Directions: {map_link}')
        ->and(QuickReply::query()->where('key', 'bank-transfer')->value('body'))->not->toContain('IBAN');
});

it('logs which template was copied on the lead timeline, without the text', function (): void {
    $staff = User::factory()->staff()->create();
    $lead = Lead::factory()->create();
    $reply = QuickReply::query()->where('key', 'prices')->firstOrFail();

    app(LogQuickReplyCopy::class)->handle($lead, $reply, $staff, tracked: true);

    $log = Activity::query()->where('event', 'quick_reply_copied')->sole();
    expect($log->subject_id)->toBe($lead->id)
        ->and($log->causer_id)->toBe($staff->id)
        ->and($log->properties->toArray())->toBe(['quick_reply_id' => $reply->id, 'title' => 'Prices', 'tracked' => true])
        ->and($log->description)->toBe('Quick reply copied: Prices');

    $this->actingAs(User::factory()->admin()->withMfa()->create())
        ->get('/test-admin/leads/'.$lead->id)
        ->assertOk()
        ->assertSee('Timeline')
        ->assertSee('Quick reply copied: Prices');
});

it('refuses copies by trainers and customers, and of hidden templates', function (): void {
    $lead = Lead::factory()->create();
    $reply = QuickReply::factory()->create();

    expect(fn () => app(LogQuickReplyCopy::class)->handle($lead, $reply, User::factory()->trainer()->create(), true))->toThrow(AuthorizationException::class)
        ->and(fn () => app(LogQuickReplyCopy::class)->handle($lead, $reply, User::factory()->create(), true))->toThrow(AuthorizationException::class);

    $reply->update(['is_active' => false]);
    expect(fn () => app(LogQuickReplyCopy::class)->handle($lead, $reply, User::factory()->staff()->create(), true))->toThrow(AuthorizationException::class);
});

it('copies from the lead page, logging through the page', function (): void {
    $staff = User::factory()->staff()->withMfa()->create();
    $lead = Lead::factory()->create(['name' => 'Bilal Ahmed']);
    $reply = QuickReply::query()->where('key', 'first-reply')->firstOrFail();

    Livewire::actingAs($staff)->test(ViewLead::class, ['record' => $lead->getKey()])
        ->mountAction('quickReplies')
        ->assertMountedActionModalSee('Hi Bilal')
        ->assertMountedActionModalSeeHtml('lead='.LeadRef::for($lead))
        ->call('quickReplyCopied', $reply->id, $lead->id, true);

    expect(Activity::query()->where('event', 'quick_reply_copied')->where('subject_id', $lead->id)->count())->toBe(1);
});

it('has a Quick replies page for staff, with an optional lead', function (): void {
    $staff = User::factory()->staff()->withMfa()->create();
    $lead = Lead::factory()->create(['name' => 'Sana Iqbal']);

    $this->actingAs($staff)->get('/test-admin/quick-replies')->assertOk()->assertSee('No lead chosen')->assertSee('First reply');

    Livewire::actingAs($staff)->test(QuickReplies::class)
        ->set('leadId', $lead->id)
        ->assertSee('Hi Sana')
        ->call('quickReplyCopied', QuickReply::query()->firstOrFail()->id, null, false);

    expect(Activity::query()->where('event', 'quick_reply_copied')->count())->toBe(0);

    $this->actingAs(User::factory()->trainer()->withMfa()->create())->get('/test-admin/quick-replies')->assertForbidden();
});

it('lets admins manage templates and staff only read them', function (): void {
    $admin = User::factory()->admin()->withMfa()->create();
    $staff = User::factory()->staff()->withMfa()->create();
    $reply = QuickReply::factory()->create();

    expect($admin->can('create', QuickReply::class))->toBeTrue()
        ->and($admin->can('update', $reply))->toBeTrue()
        ->and($staff->can('viewAny', QuickReply::class))->toBeTrue()
        ->and($staff->can('create', QuickReply::class))->toBeFalse()
        ->and($staff->can('update', $reply))->toBeFalse()
        ->and(User::factory()->trainer()->create()->can('viewAny', QuickReply::class))->toBeFalse();

    $this->actingAs($admin)->get('/test-admin/reply-templates/create')->assertOk()->assertSee('{booking_link}');
    $this->actingAs($staff)->get('/test-admin/reply-templates/create')->assertForbidden();
    expect(QuickReplyCategory::Private->label())->toBe('Private sessions');
});
