<?php

declare(strict_types=1);

use App\Actions\Slots\GenerateSlots;
use App\Enums\UserRole;
use App\Models\Booking;
use App\Models\Lead;
use App\Models\Order;
use App\Models\Payment;
use App\Models\Rider;
use App\Models\ScheduleRule;
use App\Models\Slot;
use App\Models\User;
use Carbon\CarbonImmutable;
use Spatie\Activitylog\Models\Activity;

function trainerUser(): User
{
    return User::factory()->trainer()->withMfa()->create();
}

it('is a panel role but not part of the office team', function (): void {
    $trainer = trainerUser();

    expect($trainer->role)->toBe(UserRole::Trainer)
        ->and($trainer->isTrainer())->toBeTrue()
        ->and($trainer->isPanelUser())->toBeTrue()
        ->and($trainer->isTeamMember())->toBeFalse()
        ->and($trainer->can('team-area'))->toBeTrue()
        ->and($trainer->can('check-in'))->toBeTrue()
        ->and($trainer->can('access-admin'))->toBeFalse();
});

it('requires MFA in the panel and on the run sheet, like staff', function (): void {
    $trainer = User::factory()->trainer()->create();

    $this->actingAs($trainer)->get('/test-admin')->assertRedirect();
    $this->actingAs($trainer)->get(route('team.sessions.index'))->assertRedirect('/test-admin');
});

it('cannot see payments, orders, leads or other admin data', function (): void {
    $trainer = trainerUser();
    $order = Order::factory()->create();

    expect($trainer->can('viewAny', Payment::class))->toBeFalse()
        ->and($trainer->can('viewAny', Order::class))->toBeFalse()
        ->and($trainer->can('view', $order))->toBeFalse()
        ->and($trainer->can('viewAny', Lead::class))->toBeFalse()
        ->and($trainer->can('create', Lead::class))->toBeFalse()
        ->and($trainer->can('viewAny', Slot::class))->toBeFalse()
        ->and($trainer->can('viewAny', Booking::class))->toBeFalse()
        ->and($trainer->can('viewAny', Rider::class))->toBeFalse()
        ->and($trainer->can('viewAny', User::class))->toBeFalse()
        ->and($trainer->can('viewAny', Activity::class))->toBeFalse()
        ->and($trainer->can('create', Rider::class))->toBeFalse();
});

it('is kept out of every admin area', function (string $path): void {
    $this->actingAs(trainerUser())->get('/test-admin/'.$path)->assertForbidden();
})->with([
    'activity-log', 'admin-guide', 'bookings', 'customers', 'faqs', 'horses', 'lead-board', 'leads',
    'manage-settings', 'orders', 'pages', 'posts', 'products', 'schedule-rules', 'slots', 'slots/calendar',
    'verify-transfers', 'exports', 'credit-packs',
]);

it('reaches the panel home with only its own sessions', function (): void {
    $this->actingAs(trainerUser())->get('/test-admin')
        ->assertOk()
        ->assertSee('My sessions')
        ->assertDontSee('Transfers to verify')
        ->assertDontSee('Revenue');
});

it('gets its trainer from the schedule rule on new slots', function (): void {
    $this->travelTo(CarbonImmutable::parse('2026-11-10 12:00', 'Asia/Karachi'));
    $trainer = trainerUser();
    ScheduleRule::factory()->create(['day_of_week' => 6, 'start_time' => '08:00', 'end_time' => '10:00', 'trainer_id' => $trainer->id]);

    app(GenerateSlots::class)->handle(2);

    expect(Slot::query()->count())->toBeGreaterThan(0)
        ->and(Slot::query()->whereNull('trainer_id')->count())->toBe(0);
});

it('moves inherited slots to a new rule trainer and keeps hand-picked ones', function (): void {
    $this->travelTo(CarbonImmutable::parse('2026-11-10 12:00', 'Asia/Karachi'));
    [$omar, $sara, $ali] = [trainerUser(), trainerUser(), trainerUser()];
    $rule = ScheduleRule::factory()->create(['day_of_week' => 6, 'start_time' => '08:00', 'end_time' => '10:00', 'trainer_id' => $omar->id]);
    app(GenerateSlots::class)->handle(3);

    $slots = Slot::query()->orderBy('starts_at')->limit(3)->get();
    expect($slots)->toHaveCount(3);
    $slots[1]->forceFill(['trainer_id' => $ali->id])->save();

    $past = Slot::factory()->at(CarbonImmutable::parse('2026-11-07 08:00', 'Asia/Karachi'))->create(['schedule_rule_id' => $rule->id, 'trainer_id' => $omar->id]);

    $rule->update(['trainer_id' => $sara->id]);

    expect($slots[0]->refresh()->trainer_id)->toBe($sara->id)
        ->and($slots[1]->refresh()->trainer_id)->toBe($ali->id)
        ->and($slots[2]->refresh()->trainer_id)->toBe($sara->id)
        ->and($past->refresh()->trainer_id)->toBe($omar->id);
    expect(Activity::query()->where('event', 'trainer_applied')->exists())->toBeTrue();
});
