<?php

declare(strict_types=1);

use App\Actions\Leads\CaptureLead;
use App\Actions\Leads\CreateManualLead;
use App\Enums\LeadSource;
use App\Models\User;
use App\Notifications\NewLeadReceived;
use Illuminate\Support\Facades\Notification;

it('emails every admin once for each site lead, never staff', function (): void {
    Notification::fake();

    $adminA = User::factory()->admin()->withMfa()->create();
    $adminB = User::factory()->admin()->withMfa()->create();
    $staff = User::factory()->staff()->withMfa()->create();

    $lead = app(CaptureLead::class)->handle(['name' => 'Ayesha Khan', 'phone' => '+923001234567', 'message' => 'Two of us'], [], LeadSource::PricesForm);

    Notification::assertSentToTimes($adminA, NewLeadReceived::class, 1);
    Notification::assertSentToTimes($adminB, NewLeadReceived::class, 1);
    Notification::assertNotSentTo($staff, NewLeadReceived::class);

    Notification::assertSentTo($adminA, NewLeadReceived::class, fn (NewLeadReceived $n): bool => $n->lead->is($lead));
});

it('sends the alert from the contact form', function (): void {
    Notification::fake();
    $admin = User::factory()->admin()->withMfa()->create();

    $this->post('/contact', ['name' => 'Bilal', 'phone' => '+92 300 7654321', 'message' => 'Hello'])->assertRedirect();

    Notification::assertSentToTimes($admin, NewLeadReceived::class, 1);
});

it('does not alert for leads the team enters by hand', function (): void {
    Notification::fake();
    $admin = User::factory()->admin()->withMfa()->create();

    app(CreateManualLead::class)->handle(['name' => 'Walk-in', 'phone' => '+923001112223', 'source' => LeadSource::WalkIn->value], $admin);

    Notification::assertNothingSent();
});

it('renders the alert with the lead, escaping any Markdown from the visitor', function (): void {
    $admin = User::factory()->admin()->withMfa()->create();
    $lead = app(CaptureLead::class)->handle([
        'name' => 'Sara [click](https://evil.example)',
        'email' => 'sara@example.com',
        'message' => 'Hi ![x](https://evil.example/p.gif)',
    ]);

    $mail = (new NewLeadReceived($lead))->toMail($admin);
    $html = (string) $mail->render();

    expect($mail->subject)->toStartWith('New lead: Sara')
        ->and($html)->toContain('sara@example.com')
        ->and($html)->not->toContain('href="https://evil.example"')
        ->and($html)->not->toContain('<img src="https://evil.example');
});
