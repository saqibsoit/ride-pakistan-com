<?php

declare(strict_types=1);

use App\Enums\PrivateRequestStatus;
use App\Filament\Resources\PrivateRequests\Pages\ListPrivateRequests;
use App\Filament\Resources\PrivateRequests\Pages\ViewPrivateRequest;
use App\Filament\Resources\PrivateRequests\PrivateRequestResource;
use App\Livewire\Book\BookingFlow;
use App\Models\PrivateSessionRequest;
use App\Models\Slot;
use App\Models\User;
use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;

require_once __DIR__.'/PrivateRequestHelpers.php';

beforeEach(fn () => Notification::fake());

describe('admin panel', function (): void {
    it('lists open requests for admins and staff, not trainers', function (): void {
        $request = prRequest();

        $this->actingAs(User::factory()->trainer()->withMfa()->create())->get(PrivateRequestResource::getUrl('index', panel: 'admin'))->assertForbidden();
        $this->actingAs(User::factory()->staff()->withMfa()->create())->get(PrivateRequestResource::getUrl('index', panel: 'admin'))->assertOk()->assertSee($request->reference);

        Livewire::actingAs(User::factory()->admin()->withMfa()->create())->test(ListPrivateRequests::class)
            ->assertCanSeeTableRecords([$request])
            ->assertSee('Answer by');
    });

    it('approves from the request page, with the trainer', function (): void {
        $request = prRequest();
        $trainer = User::factory()->trainer()->create();

        Livewire::actingAs(prAdmin())->test(ViewPrivateRequest::class, ['record' => $request->id])
            ->assertActionVisible('approve')
            ->callAction('approve', ['trainer_id' => $trainer->id])
            ->assertHasNoActionErrors()
            ->assertNotified('Approved');

        expect($request->fresh()?->status)->toBe(PrivateRequestStatus::Approved)
            ->and($request->fresh()?->trainer_id)->toBe($trainer->id);
    });

    it('shows why it can’t approve and leaves the request open', function (): void {
        $request = prRequest(start: prStart(3, '10:00'));
        Slot::factory()->at(prStart(3, '11:00')->setTimezone((string) config('app.display_timezone')))->create();

        Livewire::actingAs(prAdmin())->test(ViewPrivateRequest::class, ['record' => $request->id])
            ->callAction('approve')
            ->assertNotified('Not approved');

        expect($request->fresh()?->status)->toBe(PrivateRequestStatus::Requested);
    });

    it('declines with a reason from the request page', function (): void {
        $request = prRequest();

        Livewire::actingAs(prAdmin())->test(ViewPrivateRequest::class, ['record' => $request->id])
            ->callAction('decline', ['reason' => 'Fully booked that day', 'suggested_times' => 'Sunday 08:00'])
            ->assertHasNoActionErrors();

        expect($request->fresh()?->status)->toBe(PrivateRequestStatus::Declined);
    });

    it('needs a reason to decline', function (): void {
        $request = prRequest();

        Livewire::actingAs(prAdmin())->test(ViewPrivateRequest::class, ['record' => $request->id])
            ->callAction('decline', ['reason' => ''])
            ->assertHasActionErrors(['reason' => 'required']);
    });

    it('hides approve and decline from staff', function (): void {
        $request = prRequest();

        Livewire::actingAs(User::factory()->staff()->withMfa()->create())->test(ViewPrivateRequest::class, ['record' => $request->id])
            ->assertActionHidden('approve')
            ->assertActionHidden('decline');
    });

    it('opens the approve action from the email link, after the panel sign-in', function (): void {
        $request = prRequest();
        $url = PrivateRequestResource::getUrl('view', ['record' => $request->id, 'action' => 'approve'], panel: 'admin');

        $this->get($url)->assertRedirect();
        expect($request->fresh()?->status)->toBe(PrivateRequestStatus::Requested);

        $this->actingAs(User::factory()->admin()->withMfa()->create())->get($url)->assertOk()->assertSee($request->reference);
        expect($request->fresh()?->status)->toBe(PrivateRequestStatus::Requested);
    });
});

describe('customer', function (): void {
    it('requests a date and time from the booking flow', function (): void {
        $user = prCustomer();
        $rider = $user->riders()->sole();
        $start = prStart(4, '09:30')->setTimezone((string) config('app.display_timezone'));

        Livewire::actingAs($user)->test(BookingFlow::class)
            ->call('chooseProduct', prProduct()->slug)
            ->assertSee('Request another date and time')
            ->set('requestDate', $start->toDateString())
            ->set('requestTime', '09:30')
            ->call('chooseRequestTime')
            ->assertHasNoErrors()
            ->assertSet('step', 'riders')
            ->assertSee('Requested: '.$start->format('D j M Y').', 09:30–11:30')
            ->set('riderIds', [$rider->id])
            ->call('confirmRiders')
            ->assertSee('Send request')
            ->set('requestNote', 'First time riding')
            ->call('placeOrder')
            ->assertRedirect(route('account.private-requests'));

        $request = PrivateSessionRequest::query()->sole();

        expect($request->user_id)->toBe($user->id)
            ->and($request->note)->toBe('First time riding')
            ->and($request->rider_ids)->toBe([$rider->id]);
    });

    it('tells the customer straight away when a requested time isn’t allowed', function (): void {
        $start = prStart(4)->setTimezone((string) config('app.display_timezone'));

        Livewire::actingAs(prCustomer())->test(BookingFlow::class)
            ->call('chooseProduct', prProduct()->slug)
            ->set('requestDate', $start->toDateString())
            ->set('requestTime', '17:00')
            ->call('chooseRequestTime')
            ->assertHasErrors('requestTime')
            ->assertSet('step', 'slot');
    });

    it('keeps a guest’s request through sign-in', function (): void {
        $start = prStart(4)->setTimezone((string) config('app.display_timezone'));

        Livewire::test(BookingFlow::class)
            ->call('chooseProduct', prProduct()->slug)
            ->set('requestDate', $start->toDateString())
            ->set('requestTime', '10:00')
            ->call('chooseRequestTime')
            ->set('guestRiders', [['name' => 'Ayesha', 'age_bracket' => '', 'experience_level' => '']])
            ->call('confirmRiders')
            ->set('whatsapp', '03344554500')
            ->call('signInToContinue')
            ->assertRedirect(route('login'));

        expect(session(BookingFlow::DRAFT_KEY)['request']['time'] ?? null)->toBe('10:00');
    });

    it('shows each request’s status, pay link and withdraw on the account page', function (): void {
        $waiting = prRequest();
        $approved = prApprove(prRequest(start: prStart(5)));

        $this->actingAs($waiting->user)->get(route('account.private-requests'))
            ->assertOk()
            ->assertSee('Requested: we’ll answer by')
            ->assertSee('Withdraw');

        $this->actingAs($approved->user)->get(route('account.private-requests'))
            ->assertSee('Approved: pay by')
            ->assertSee(route('checkout.show', $approved->order?->reference));

        $this->actingAs($approved->user)->get(route('account.dashboard'))->assertSee('Private session request');
        $this->actingAs($approved->user)->get(route('checkout.show', $approved->order?->reference))->assertSee('Your private session is approved');

        $this->actingAs($waiting->user)->post(route('account.private-requests.withdraw', $waiting->reference))
            ->assertRedirect(route('account.private-requests'));
        expect($waiting->fresh()?->status)->toBe(PrivateRequestStatus::Withdrawn);

        // Not someone else's.
        $this->actingAs($waiting->user)->post(route('account.private-requests.withdraw', $approved->reference))->assertNotFound();
    });

    it('shows the spam notice after sending', function (): void {
        $request = prRequest();

        $this->actingAs($request->user)->withSession(['status' => 'private-request-sent'])->get(route('account.private-requests'))
            ->assertSee('Request sent')
            ->assertSee('Check your spam or promotions folder', false);
    });
});
