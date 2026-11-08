<?php

declare(strict_types=1);

use App\Enums\LeadInterest;
use App\Enums\LeadSource;
use App\Enums\LeadStatus;
use App\Models\Lead;

beforeEach(function (): void {
    $this->seed();
});

it('renders the form with attribution fields from the landing URL', function (): void {
    $this->get('/contact?utm_source=instagram&utm_campaign=winter&fbclid=abc123&interest=private', ['Referer' => 'https://instagram.com/'])
        ->assertOk()
        ->assertSee('name="utm_source" value="instagram"', false)
        ->assertSee('name="utm_campaign" value="winter"', false)
        ->assertSee('name="fbclid" value="abc123"', false)
        ->assertSee('name="referrer" value="https://instagram.com/"', false)
        ->assertSee('<option value="private" selected', false)
        ->assertSee('name="_token"', false);
});

it('stores a lead with its attribution', function (): void {
    $this->post('/contact', [
        'name' => 'Ayesha Khan',
        'phone' => '+92 300 1234567',
        'email' => 'ayesha@example.com',
        'interest' => 'package',
        'preferred_date' => now()->addWeek()->format('Y-m-d'),
        'message' => 'Two of us, beginners.',
        'utm_source' => 'instagram',
        'utm_medium' => 'paid_social',
        'utm_campaign' => 'winter',
        'fbclid' => 'abc123',
        'landing_url' => 'http://localhost/contact?utm_source=instagram',
        'referrer' => 'https://instagram.com/',
    ])->assertRedirect(route('contact').'#contact-form')
        ->assertSessionHas('status', 'lead-sent');

    $lead = Lead::query()->sole();

    expect($lead->name)->toBe('Ayesha Khan')
        ->and($lead->interest)->toBe(LeadInterest::Package)
        ->and($lead->status)->toBe(LeadStatus::New)
        ->and($lead->source)->toBe(LeadSource::ContactForm)
        ->and($lead->utm_source)->toBe('instagram')
        ->and($lead->utm_medium)->toBe('paid_social')
        ->and($lead->fbclid)->toBe('abc123')
        ->and($lead->referrer)->toBe('https://instagram.com/');

    $this->get('/contact')->assertSee('Thanks, your message is on its way.');
});

it('needs a name and a way to reply', function (): void {
    $this->post('/contact', ['message' => 'Hi'])
        ->assertSessionHasErrors(['name', 'phone', 'email']);

    $this->post('/contact', ['name' => 'Ali', 'email' => 'ali@example.com'])
        ->assertSessionHasNoErrors();

    expect(Lead::query()->count())->toBe(1);
});

it('rejects bad input', function (array $data, string $field): void {
    $this->post('/contact', [...['name' => 'Ali', 'email' => 'ali@example.com'], ...$data])
        ->assertSessionHasErrors($field);

    expect(Lead::query()->count())->toBe(0);
})->with([
    'unknown interest' => [['interest' => 'helicopter'], 'interest'],
    'past date' => [['preferred_date' => '2020-01-01'], 'preferred_date'],
    'bad phone' => [['phone' => 'call me'], 'phone'],
    'bad email' => [['email' => 'not-an-email'], 'email'],
    'long message' => [['message' => str_repeat('a', 2001)], 'message'],
]);

it('silently drops honeypot submissions', function (): void {
    $this->post('/contact', ['name' => 'Bot', 'email' => 'bot@example.com', 'website' => 'http://spam.test'])
        ->assertRedirect()
        ->assertSessionHas('status', 'lead-sent');

    expect(Lead::query()->count())->toBe(0);
});

it('rate limits submissions per IP', function (): void {
    foreach (range(1, 3) as $i) {
        $this->post('/contact', ['name' => "Rider {$i}", 'email' => "r{$i}@example.com"])->assertRedirect();
    }

    $this->post('/contact', ['name' => 'Rider 4', 'email' => 'r4@example.com'])->assertTooManyRequests();

    expect(Lead::query()->count())->toBe(3);
});

it('is never served from the page cache', function (): void {
    $this->get('/contact')->assertOk();
    $this->post('/contact', ['name' => 'Ali', 'email' => 'ali@example.com']);

    $this->get('/contact')->assertSee('Thanks, your message is on its way.');
});
