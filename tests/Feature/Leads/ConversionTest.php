<?php

declare(strict_types=1);

use App\Actions\Leads\ConvertLeadToCustomer;
use App\Enums\LeadStatus;
use App\Enums\OrderStatus;
use App\Enums\UserRole;
use App\Events\OrderPaid;
use App\Models\Lead;
use App\Models\Order;
use App\Models\User;
use Illuminate\Validation\ValidationException;

it('books every lead of a customer who pays, linking same-email leads', function (): void {
    $customer = User::factory()->create(['email' => 'zara@example.com']);
    $linked = Lead::factory()->create(['user_id' => $customer->id, 'status' => LeadStatus::Contacted]);
    $sameEmail = Lead::factory()->create(['email' => 'ZARA@example.com']);
    $lost = Lead::factory()->status(LeadStatus::Lost)->create(['user_id' => $customer->id]);
    $stranger = Lead::factory()->create(['email' => 'someone@example.com']);
    $order = Order::factory()->status(OrderStatus::Paid)->create(['user_id' => $customer->id]);

    event(new OrderPaid($order));

    foreach ([$linked, $sameEmail, $lost] as $lead) {
        expect($lead->fresh())
            ->status->toBe(LeadStatus::Booked)
            ->booked_order_id->toBe($order->id)
            ->user_id->toBe($customer->id)
            ->booked_at->not->toBeNull();
    }

    expect($stranger->fresh())
        ->status->toBe(LeadStatus::New)
        ->user_id->toBeNull();
});

it('leaves already booked leads alone on a later order', function (): void {
    $customer = User::factory()->create();
    $first = Order::factory()->status(OrderStatus::Paid)->create(['user_id' => $customer->id]);
    $lead = Lead::factory()->create(['user_id' => $customer->id]);

    event(new OrderPaid($first));
    event(new OrderPaid(Order::factory()->status(OrderStatus::Paid)->create(['user_id' => $customer->id])));

    expect($lead->fresh()?->booked_order_id)->toBe($first->id);
});

it('links a lead to an existing customer by email or phone', function (): void {
    $admin = User::factory()->admin()->create();
    $byEmail = User::factory()->create(['email' => 'amna@example.com']);
    $byPhone = User::factory()->create(['phone' => '+923001112233']);

    $a = Lead::factory()->create(['email' => 'Amna@Example.com']);
    $b = Lead::factory()->create(['email' => null, 'phone' => '0300 111 2233']);

    expect(app(ConvertLeadToCustomer::class)->handle($a, $admin)->id)->toBe($byEmail->id)
        ->and(app(ConvertLeadToCustomer::class)->handle($b, $admin)->id)->toBe($byPhone->id)
        ->and($a->fresh()?->user_id)->toBe($byEmail->id);
});

it('creates a customer from the lead, and needs an email to do so', function (): void {
    $admin = User::factory()->admin()->create();
    $dm = Lead::factory()->instagramDm()->create(['name' => 'Saad', 'phone' => null]);

    expect(fn () => app(ConvertLeadToCustomer::class)->handle($dm, $admin))->toThrow(ValidationException::class);

    $user = app(ConvertLeadToCustomer::class)->handle($dm, $admin, email: 'Saad@Example.com');

    expect($user->role)->toBe(UserRole::Customer)
        ->and($user->email)->toBe('saad@example.com')
        ->and($user->name)->toBe('Saad')
        ->and($dm->fresh())
        ->user_id->toBe($user->id)
        ->email->toBe('saad@example.com');

    expect(fn () => app(ConvertLeadToCustomer::class)->handle($dm->fresh() ?? $dm, $admin))->toThrow(ValidationException::class);
});

it('never links a lead to a team account', function (): void {
    $admin = User::factory()->admin()->create();
    $staff = User::factory()->staff()->create(['email' => 'staff@example.com']);
    $lead = Lead::factory()->create(['email' => 'staff@example.com']);

    expect(fn () => app(ConvertLeadToCustomer::class)->handle($lead, $admin))->toThrow(ValidationException::class)
        ->and($lead->fresh()?->user_id)->toBeNull();
});
