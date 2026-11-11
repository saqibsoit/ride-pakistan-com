<?php

declare(strict_types=1);

use App\Enums\OrderStatus;
use App\Livewire\Checkout\PaymentStatus;
use App\Mail\LoginCodeMail;
use App\Models\Lead;
use App\Models\Order;
use App\Models\User;
use App\Notifications\NewLeadReceived;
use App\Notifications\OperationsAlert;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\Mail;
use Livewire\Livewire;

/*
 * Prompt 14, item 4: "Can't see it? Check your spam or promotions folder."
 * wherever the site says an email was sent (one component), and a line
 * near the top of every outgoing email asking to mark the sender as safe.
 */

const SPAM_HINT = 'Can’t see it? Check your spam or promotions folder.';

it('renders the shared hint component', function (): void {
    $html = Blade::render('<x-spam-hint class="mt-0" />');

    expect($html)->toContain(SPAM_HINT)->toContain('data-spam-hint')->toContain('mt-0');
});

it('is used by every screen that says an email was sent', function (string $view): void {
    expect(file_get_contents(resource_path('views/'.$view)))->toContain('<x-spam-hint');
})->with([
    'login code' => 'auth/verify.blade.php',
    'card payment status' => 'livewire/checkout/payment-status.blade.php',
    'bank transfer' => 'checkout/transfer.blade.php',
    'voucher payment status' => 'livewire/checkout/voucher-payment-status.blade.php',
    'voucher purchase' => 'gift-vouchers/show.blade.php',
    'voucher transfer' => 'gift-vouchers/transfer.blade.php',
    'account vouchers' => 'account/vouchers.blade.php',
    'waitlist' => 'account/waitlist.blade.php',
    'contact form' => 'public/contact.blade.php',
]);

it('shows the hint on the login code screen', function (): void {
    Mail::fake();
    $this->post('/login', ['email' => 'rider@example.com']);

    $this->get(route('login.verify'))->assertOk()->assertSee(SPAM_HINT)->assertSee('Request a new code', false);
});

it('shows the hint after a contact message and a waitlist join', function (): void {
    $this->withSession(['status' => 'lead-sent'])->get('/contact')->assertOk()->assertSee(SPAM_HINT);

    $this->actingAs(User::factory()->create())->withSession(['status' => 'waitlist-joined'])
        ->get(route('account.waitlist'))->assertOk()->assertSee(SPAM_HINT);
});

it('shows the hint on the paid booking confirmation', function (): void {
    $order = Order::factory()->status(OrderStatus::Paid)->create(['paid_at' => now()]);

    Livewire::actingAs($order->user)->test(PaymentStatus::class, ['reference' => $order->reference])
        ->assertSee("You're booked")
        ->assertSee(SPAM_HINT);
});

it('puts the "mark as not spam" line near the top of every email, HTML and plain text', function (): void {
    $admin = User::factory()->admin()->create();

    Mail::to('customer@example.test')->send(new LoginCodeMail('123456', CarbonImmutable::now()->addMinutes(10)));
    $admin->notify(new NewLeadReceived(Lead::factory()->create()));
    $admin->notifyNow(new OperationsAlert('queue', OperationsAlert::STARTED, 1, CarbonImmutable::now(), CarbonImmutable::now()));

    $messages = app('mailer')->getSymfonyTransport()->messages();
    expect($messages)->toHaveCount(3);

    foreach ($messages as $sent) {
        $email = $sent->getOriginalMessage();
        $html = (string) $email->getHtmlBody();
        $text = (string) $email->getTextBody();
        $line = 'Found this in spam or promotions? Please mark it as not spam and add '.config('mail.from.address').' to your contacts';

        expect($html)->toContain($line)
            ->and($text)->toContain($line)
            // Under the header, before the email's own content.
            ->and(strpos($html, $line))->toBeLessThan(strpos($html, '<h1') ?: PHP_INT_MAX)
            ->and(strpos($text, $line))->toBeLessThan(200 + strlen($line));
    }
});
