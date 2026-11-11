<?php

declare(strict_types=1);

use App\Actions\PrivateRequests\ApprovePrivateRequest;
use App\Actions\PrivateRequests\SubmitPrivateRequest;
use App\Enums\OrderStatus;
use App\Enums\PrivateRequestStatus;
use App\Enums\SlotStatus;
use App\Filament\Resources\PrivateRequests\PrivateRequestResource;
use App\Models\PrivateSessionRequest;
use App\Models\Product;
use App\Models\Rider;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Notification;

/*
 * Private session requests end to end (prompt 15B): a customer requests a
 * date and time on a phone, an admin approves it in the panel and the
 * customer pays by card; and both 2-hour windows run out (no answer, then
 * no payment). Every step waits for its own result (see tests/Pest.php).
 */

beforeEach(function (): void {
    Notification::fake();
});

/** A private product and a signed-up customer with a WhatsApp number and one rider. */
function journeyPrivateCustomer(string $riderName): array
{
    $product = Product::factory()->private()->create(['name' => 'Private session']);
    $user = User::factory()->create();
    Rider::factory()->for($user)->create(['name' => $riderName]);

    return [$product, $user];
}

function journeyPrivateRequest(User $user, Product $product): PrivateSessionRequest
{
    $start = CarbonImmutable::now((string) config('app.display_timezone'))->addDays(4)->setTime(10, 0)->utc();

    return app(SubmitPrivateRequest::class)->handle($user, $product, $start, [(int) $user->riders()->value('id')]);
}

it('takes a private request from the booking flow to approval and card payment', function (): void {
    [$product, $user] = journeyPrivateCustomer('Sana Iqbal');
    $date = CarbonImmutable::now((string) config('app.display_timezone'))->addDays(4)->toDateString();

    // Customer, on a phone: request a date and time.
    $this->actingAs($user);
    $page = visit(route('book', ['product' => $product->slug]))->on()->mobile()
        ->assertSee('Request another date and time');
    $page->assertNoJavaScriptErrors();
    $customer = $page->page();

    fillOnce($customer, 'Date', $date);
    fillOnce($customer, 'Start time', '10:00');
    pressOnce($customer, 'Request this time');
    waitForText($customer, 'Who’s riding?');
    $customer->getByLabel('Sana Iqbal')->check(['timeout' => E2E_WAIT_MS]);
    pressOnce($customer, 'Continue');
    waitForText($customer, 'Send request');
    pressOnce($customer, 'Send request');
    waitForText($customer, 'Request sent.');
    waitForText($customer, 'Requested: we’ll answer by');

    $request = PrivateSessionRequest::query()->sole();

    // Admin, from the email's link: approve.
    test()->actingAs(User::factory()->admin()->withMfa()->create());
    $admin = visit(PrivateRequestResource::getUrl('view', ['record' => $request->id], panel: 'admin'))->page();
    waitForText($admin, 'Private request '.$request->reference);
    pressOnce($admin, 'Approve');
    waitForText($admin, 'Approve this private session?');
    $admin->getByRole('button', ['name' => 'Approve', 'exact' => true])->last()->click(['timeout' => E2E_WAIT_MS]);
    waitForText($admin, 'The customer has been emailed.');

    $request->refresh();
    expect($request->status)->toBe(PrivateRequestStatus::Approved);

    // Customer: pay by card from the account page.
    $this->actingAs($user);
    $page = visit(route('account.private-requests'))->on()->mobile()->assertSee('Approved: pay by');
    $customer = $page->page();
    pressOnce($customer, 'Pay now', 'link', exact: false);
    waitForText($customer, 'Your private session is approved');
    payByCardInBrowser($this, $customer, $request->order);

    expect($request->refresh()->status)->toBe(PrivateRequestStatus::Paid)
        ->and($request->order?->status)->toBe(OrderStatus::Paid);

    visit(route('account.private-requests'))->on()->mobile()->assertSee('Booked')->assertNoJavaScriptErrors();
});

it('expires a request nobody answered within 2 hours', function (): void {
    [$product, $user] = journeyPrivateCustomer('Bilal Ahmed');
    $request = journeyPrivateRequest($user, $product);

    $this->actingAs($user);
    visit(route('account.private-requests'))->on()->mobile()->assertSee('Requested: we’ll answer by');

    $this->travelTo($request->answer_by->addMinute());
    $this->artisan('private-requests:expire')->assertSuccessful();

    visit(route('account.private-requests'))->on()->mobile()
        ->assertSee('Expired (no answer in time)')
        ->assertDontSee('Withdraw')
        ->assertNoJavaScriptErrors();
});

it('releases an approved slot that wasn’t paid within 2 hours', function (): void {
    [$product, $user] = journeyPrivateCustomer('Hamza Khan');
    $request = app(ApprovePrivateRequest::class)->handle(journeyPrivateRequest($user, $product), User::factory()->admin()->create());

    $this->actingAs($user);
    visit(route('account.private-requests'))->on()->mobile()->assertSee('Pay now');

    $this->travelTo($request->pay_by->addMinute());
    $this->artisan('holds:release')->assertSuccessful();

    visit(route('account.private-requests'))->on()->mobile()
        ->assertSee('Not paid in time')
        ->assertDontSee('Pay now')
        ->assertNoJavaScriptErrors();

    expect($request->refresh()->status)->toBe(PrivateRequestStatus::Lapsed)
        ->and($request->slot?->status)->toBe(SlotStatus::Cancelled);
});
