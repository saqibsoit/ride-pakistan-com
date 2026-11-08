<?php

declare(strict_types=1);

use App\Actions\Progress\RecordRiderProgress;
use App\Actions\Progress\SuggestNextStep;
use App\Enums\BookingStatus;
use App\Enums\OrderStatus;
use App\Enums\ProductType;
use App\Filament\Resources\Skills\Pages\ListSkills;
use App\Models\Booking;
use App\Models\CreditPack;
use App\Models\Order;
use App\Models\Product;
use App\Models\Rider;
use App\Models\RiderSkill;
use App\Models\Skill;
use App\Models\Slot;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Livewire\Livewire;
use Spatie\Activitylog\Models\Activity;

/** A session that started at 08:00 today (Pakistan time), run by $trainer, with one rider. */
function progressBooking(?User $trainer = null, BookingStatus $status = BookingStatus::Attended): Booking
{
    $slot = Slot::factory()->at(CarbonImmutable::parse('2026-11-14 08:00', 'Asia/Karachi'))->create(['trainer_id' => $trainer?->id]);

    return Booking::factory()->create(['slot_id' => $slot->id, 'status' => $status]);
}

/** @return list<int> */
function skillIds(string ...$keys): array
{
    return Skill::query()->whereIn('key', $keys)->pluck('id')->map(fn ($id): int => (int) $id)->values()->all();
}

beforeEach(function (): void {
    $this->travelTo(CarbonImmutable::parse('2026-11-14 10:30', 'Asia/Karachi'));
});

it('ships the default skill checklist in order', function (): void {
    expect(Skill::query()->ordered()->pluck('name')->all())->toBe([
        'Approach and lead a horse',
        'Grooming',
        'Tacking up',
        'Mounting and dismounting',
        'Walk, halt and steer',
        'Rising trot',
        'Trail riding on varied ground',
        'Canter',
    ]);
});

it('lets the session trainer tick and untick skills and write the family note', function (): void {
    $trainer = User::factory()->trainer()->withMfa()->create();
    $booking = progressBooking($trainer);

    app(RecordRiderProgress::class)->handle($booking, skillIds('grooming', 'tacking_up'), '  Lovely calm grooming today.  ', $trainer);

    expect(RiderSkill::query()->where('rider_id', $booking->rider_id)->count())->toBe(2)
        ->and(RiderSkill::query()->where('rider_id', $booking->rider_id)->value('booking_id'))->toBe($booking->id)
        ->and($booking->fresh()?->progress_note)->toBe('Lovely calm grooming today.');

    // Untick one; the other keeps its date.
    app(RecordRiderProgress::class)->handle($booking, skillIds('grooming'), null, $trainer);

    expect(RiderSkill::query()->where('rider_id', $booking->rider_id)->pluck('skill_id')->all())->toBe(skillIds('grooming'))
        ->and($booking->fresh()?->progress_note)->toBeNull();

    $log = Activity::query()->where('log_name', 'progress')->latest('id')->firstOrFail();
    expect($log->properties->get('skills_removed'))->toBe(skillIds('tacking_up'))
        ->and(json_encode($log->properties))->not->toContain('Lovely');
});

it('refuses other trainers, customers, cancelled or no-show riders and sessions that have not started', function (): void {
    $trainer = User::factory()->trainer()->withMfa()->create();
    $booking = progressBooking($trainer);

    $other = User::factory()->trainer()->withMfa()->create();
    expect(fn () => app(RecordRiderProgress::class)->handle($booking, skillIds('grooming'), null, $other))->toThrow(AuthorizationException::class);
    expect(fn () => app(RecordRiderProgress::class)->handle($booking, skillIds('grooming'), null, $booking->user))->toThrow(AuthorizationException::class);

    $noShow = Booking::factory()->create(['slot_id' => $booking->slot_id, 'status' => BookingStatus::NoShow]);
    expect(fn () => app(RecordRiderProgress::class)->handle($noShow, skillIds('grooming'), null, $trainer))->toThrow(AuthorizationException::class);

    $future = Booking::factory()->create(['slot_id' => Slot::factory()->at(CarbonImmutable::parse('2026-11-15 08:00', 'Asia/Karachi'))->create(['trainer_id' => $trainer->id])->id]);
    expect(fn () => app(RecordRiderProgress::class)->handle($future, skillIds('grooming'), null, $trainer))->toThrow(AuthorizationException::class);

    // Staff and admins work every session.
    app(RecordRiderProgress::class)->handle($booking, skillIds('canter'), null, User::factory()->staff()->withMfa()->create());
    expect(RiderSkill::query()->count())->toBe(1);
});

it('saves progress from the run sheet and only for the slot trainer', function (): void {
    $trainer = User::factory()->trainer()->withMfa()->create();
    $booking = progressBooking($trainer);
    $slot = $booking->slot;

    $this->actingAs($trainer)->get(route('team.sessions.show', $slot))
        ->assertOk()->assertSee('Skills '.$booking->rider->name.' can do')->assertSee('Note for the family');

    $this->actingAs($trainer)->post(route('team.sessions.progress', [$slot, $booking]), [
        'skills' => skillIds('walk_halt_steer'),
        'progress_note' => 'Steering on her own now.',
    ])->assertRedirect(route('team.sessions.show', $slot).'#rider-'.$booking->id);

    expect(RiderSkill::query()->where('rider_id', $booking->rider_id)->pluck('skill_id')->all())->toBe(skillIds('walk_halt_steer'));

    $this->actingAs(User::factory()->trainer()->withMfa()->create())
        ->post(route('team.sessions.progress', [$slot, $booking]), ['skills' => skillIds('canter')])->assertForbidden();
    $this->actingAs($booking->user)
        ->post(route('team.sessions.progress', [$slot, $booking]), ['skills' => skillIds('canter')])->assertForbidden();
    $this->actingAs($trainer)
        ->post(route('team.sessions.progress', [$slot, $booking]), ['progress_note' => str_repeat('a', 501)])->assertSessionHasErrors('progress_note');
});

it('ignores switched-off skills on the form and keeps them on riders who reached them', function (): void {
    $staff = User::factory()->staff()->withMfa()->create();
    $booking = progressBooking();
    app(RecordRiderProgress::class)->handle($booking, skillIds('canter'), null, $staff);

    Skill::query()->where('key', 'canter')->update(['is_active' => false]);

    app(RecordRiderProgress::class)->handle($booking, skillIds('grooming', 'canter'), null, $staff);

    expect(RiderSkill::query()->where('rider_id', $booking->rider_id)->count())->toBe(2);
});

it('shows customers their own riders’ progress and notes, never the internal note', function (): void {
    $trainer = User::factory()->trainer()->withMfa()->create();
    $booking = progressBooking($trainer);
    $booking->forceFill(['session_note' => 'Internal: nervous around Toofan'])->save();
    app(RecordRiderProgress::class)->handle($booking, skillIds('grooming', 'tacking_up'), 'Great start!', $trainer);

    $customer = $booking->user;
    $stranger = Rider::factory()->create(['name' => 'Someone Else']);

    $this->actingAs($customer)->get(route('account.progress'))
        ->assertOk()
        ->assertSee($booking->rider->name)
        ->assertSee('2 of 8 skills')
        ->assertSee('Great start!')
        ->assertSeeText('Grooming: done')
        ->assertSeeText('Canter: not yet')
        ->assertDontSee('Internal: nervous')
        ->assertDontSee('Someone Else');

    $this->actingAs($stranger->user)->get(route('account.progress'))
        ->assertOk()->assertDontSee($booking->rider->name)->assertDontSee('Great start!');
});

it('suggests the next step from credits, skills and the packages on sale', function (): void {
    $small = Product::factory()->create(['type' => ProductType::Package, 'sessions_count' => 8, 'name' => 'Eight pack', 'is_active' => true]);
    $large = Product::factory()->create(['type' => ProductType::Package, 'sessions_count' => 16, 'name' => 'Sixteen pack', 'is_active' => true]);
    $user = User::factory()->create();
    $suggest = app(SuggestNextStep::class);

    expect($suggest->handle($user, 1, 8)['url'] ?? null)->toBe(route('sessions.show', $small))
        ->and($suggest->handle($user, 4, 8)['url'] ?? null)->toBe(route('sessions.show', $large));

    CreditPack::factory()->create(['order_id' => Order::factory()->for($user)->status(OrderStatus::Paid), 'expires_at' => now()->addWeeks(3)]);

    expect($suggest->handle($user, 1, 8)['url'] ?? null)->toBe(route('book', ['use' => 'credits']));
});

it('lets admins manage the checklist and staff only read it', function (): void {
    $admin = User::factory()->admin()->withMfa()->create();
    $staff = User::factory()->staff()->withMfa()->create();
    $used = Skill::query()->where('key', 'grooming')->firstOrFail();
    RiderSkill::query()->create(['rider_id' => Rider::factory()->create()->id, 'skill_id' => $used->id, 'achieved_at' => now()]);
    $unused = Skill::factory()->create();

    expect($admin->can('delete', $used))->toBeFalse()
        ->and($admin->can('delete', $unused))->toBeTrue()
        ->and($staff->can('create', Skill::class))->toBeFalse()
        ->and($staff->can('viewAny', Skill::class))->toBeTrue();

    Livewire::actingAs($admin)->test(ListSkills::class)
        ->assertCanSeeTableRecords([$used, $unused])
        ->assertTableActionHidden('delete', $used)
        ->assertTableActionVisible('delete', $unused);
});
