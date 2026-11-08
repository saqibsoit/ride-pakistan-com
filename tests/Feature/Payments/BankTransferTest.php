<?php

declare(strict_types=1);

use App\Actions\Booking\CreateOrder;
use App\Actions\Booking\ReleaseExpiredHolds;
use App\Actions\Payments\ChooseBankTransfer;
use App\Actions\Payments\CreateStripeCheckout;
use App\Actions\Settings\UpdateSettings;
use App\Enums\BankTransferStatus;
use App\Enums\OrderStatus;
use App\Enums\PaymentMethod;
use App\Enums\SettingKey;
use App\Exceptions\CheckoutUnavailable;
use App\Mail\TransferExpiredMail;
use App\Mail\TransferReceivedMail;
use App\Mail\TransferToVerifyMail;
use App\Models\BankTransfer;
use App\Models\BookingHold;
use App\Models\Order;
use App\Models\Product;
use App\Models\Rider;
use App\Models\Slot;
use App\Models\User;
use App\Support\TransferProofs;
use Carbon\CarbonImmutable;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;

/** A held order whose session starts $days from now. */
function transferOrder(int $days = 5, ?Product $product = null, bool $withSlot = true): Order
{
    $user = User::factory()->create();
    $riders = array_values(array_map('intval', Rider::factory()->count(2)->for($user)->create()->modelKeys()));
    $product ??= Product::factory()->create(['price_minor' => 1_000_000]);
    $slot = $withSlot ? Slot::factory()->at(CarbonImmutable::now()->addDays($days)->addMinutes(random_int(1, 50_000)))->create(['capacity' => 6]) : null;

    return app(CreateOrder::class)->handle($user, $product, $slot?->id, $withSlot ? $riders : []);
}

/** A real (not faked) upload, so the MIME type is sniffed from the bytes. */
function realUpload(string $bytes, string $name): UploadedFile
{
    $path = tempnam(sys_get_temp_dir(), 'proof');
    file_put_contents($path, $bytes);

    return new UploadedFile($path, $name, null, null, true);
}

function jpegBytes(int $width = 400, int $height = 300): string
{
    $image = imagecreatetruecolor($width, $height);
    imagefill($image, 0, 0, (int) imagecolorallocate($image, 30, 120, 60));
    ob_start();
    imagejpeg($image, null, 85);

    return (string) ob_get_clean();
}

/** A JPEG with an EXIF (APP1) segment carrying a marker string. */
function jpegWithExif(string $marker): string
{
    $jpeg = jpegBytes();
    $payload = "Exif\0\0".'II*'."\0\x08\0\0\0".$marker;
    $segment = "\xFF\xE1".pack('n', strlen($payload) + 2).$payload;

    return substr($jpeg, 0, 2).$segment.substr($jpeg, 2);
}

function pdfBytes(): string
{
    return "%PDF-1.4\n1 0 obj<</Type/Catalog/Pages 2 0 R>>endobj\n2 0 obj<</Type/Pages/Kids[]/Count 0>>endobj\ntrailer<</Root 1 0 R>>\n%%EOF\n";
}

function awaitingTransfer(int $days = 5): Order
{
    return app(ChooseBankTransfer::class)->handle(transferOrder($days));
}

beforeEach(function (): void {
    Storage::fake(TransferProofs::DISK);
    Mail::fake();
});

it('switches the same order to bank transfer and holds seats until the deadline', function (): void {
    $order = transferOrder();
    $reference = $order->reference;

    $this->actingAs($order->user)
        ->post(route('checkout.transfer.choose', $reference))
        ->assertRedirect(route('checkout.transfer', $reference));

    $order->refresh();

    expect($order->reference)->toBe($reference)
        ->and($order->status)->toBe(OrderStatus::AwaitingTransfer)
        ->and($order->payment_method)->toBe(PaymentMethod::BankTransfer)
        ->and($order->expires_at?->diffInHours(now(), true))->toBeGreaterThan(23.9)->toBeLessThanOrEqual(24.0)
        ->and(BookingHold::query()->where('order_id', $order->id)->sole()->expires_at->equalTo($order->expires_at))->toBeTrue();
});

it('caps the deadline at the session start', function (): void {
    $order = transferOrder();
    $slot = Slot::query()->findOrFail((int) $order->items()->value('slot_id'));
    $slot->update(['starts_at' => now()->addHours(14), 'ends_at' => now()->addHours(16)]);

    $order = app(ChooseBankTransfer::class)->handle($order);

    expect($order->expires_at?->equalTo($slot->refresh()->starts_at))->toBeTrue();
});

it('does not offer bank transfer when the session starts too soon', function (): void {
    $order = transferOrder();
    // Default notice is 12 hours (nobody may be watching the queue at 6 am on a weekend).
    Slot::query()->whereKey((int) $order->items()->value('slot_id'))->update(['starts_at' => now()->addHours(10), 'ends_at' => now()->addHours(12)]);

    $this->actingAs($order->user)->get(route('checkout.show', $order->reference))
        ->assertSee('starts too soon for a bank transfer');

    expect(fn () => app(ChooseBankTransfer::class)->handle($order))->toThrow(CheckoutUnavailable::class, 'at least 12 hours');

    // An admin can lower it once someone watches the queue at short notice.
    app(UpdateSettings::class)->handle([SettingKey::TransferMinNoticeHours->value => 2]);
    expect(app(ChooseBankTransfer::class)->handle($order->refresh())->status)->toBe(OrderStatus::AwaitingTransfer);
});

it('gives a package without a session the full window', function (): void {
    $order = app(ChooseBankTransfer::class)->handle(transferOrder(product: Product::factory()->package()->create(), withSlot: false));

    expect($order->status)->toBe(OrderStatus::AwaitingTransfer)
        ->and($order->expires_at?->diffInHours(now(), true))->toBeGreaterThan(23.9);
});

it('reuses the reference after a failed card attempt and closes the open Stripe session', function (): void {
    $order = transferOrder();
    app(CreateStripeCheckout::class)->handle($order);

    app(ChooseBankTransfer::class)->handle($order->refresh());

    expect($this->stripe()->expired)->toBe(['cs_test_1'])
        ->and($order->refresh()->stripe_checkout_session_id)->toBeNull()
        ->and($order->status)->toBe(OrderStatus::AwaitingTransfer);

    // Card can't be started on it any more.
    expect(fn () => app(CreateStripeCheckout::class)->handle($order))->toThrow(CheckoutUnavailable::class);
});

it('refuses bank transfer when Stripe already took the card payment', function (): void {
    $order = transferOrder();
    app(CreateStripeCheckout::class)->handle($order);
    $this->stripe()->complete('cs_test_1');

    expect(fn () => app(ChooseBankTransfer::class)->handle($order->refresh()))->toThrow(CheckoutUnavailable::class, 'received your payment')
        ->and($order->refresh()->status)->toBe(OrderStatus::PendingPayment);
});

it('shows bank details from settings, the amount, the reference and the deadline', function (): void {
    $order = awaitingTransfer();

    $this->actingAs($order->user)->get(route('checkout.transfer', $order->reference))
        ->assertOk()
        ->assertSee((string) config('settings.defaults')['bank.iban'])
        ->assertSee((string) config('settings.defaults')['bank.account_name'])
        ->assertSee('PKR 20,000')
        ->assertSee($order->reference)
        ->assertSee('(Pakistan time)')
        ->assertSee('data-deadline="'.$order->expires_at?->toIso8601String().'"', false)
        ->assertSee('Upload proof');
});

it('accepts a screenshot, re-encodes it without EXIF and moves the order to verification', function (): void {
    $order = awaitingTransfer();
    $admins = User::factory()->admin()->count(2)->create();
    User::factory()->staff()->create();

    $this->actingAs($order->user)
        ->post(route('checkout.transfer.upload', $order->reference), [
            'proof' => realUpload(jpegWithExif('SECRET-GPS-33.6844'), 'IMG_2041.jpg'),
            'transaction_ref' => 'FT2611080001',
        ])
        ->assertRedirect(route('checkout.transfer', $order->reference));

    $transfer = BankTransfer::query()->where('order_id', $order->id)->sole();
    $stored = Storage::disk(TransferProofs::DISK)->get($transfer->screenshot_path);

    expect($order->refresh()->status)->toBe(OrderStatus::AwaitingVerification)
        ->and($transfer->status)->toBe(BankTransferStatus::Submitted)
        ->and($transfer->transaction_ref)->toBe('FT2611080001')
        ->and($transfer->screenshot_path)->not->toContain('IMG_2041')
        ->and(basename($transfer->screenshot_path))->toMatch('/^[A-Za-z0-9]{40}\.(webp|jpg)$/')
        ->and($stored)->not->toContain('SECRET-GPS')
        ->and($stored)->not->toContain('Exif');

    // Seats now stay held until the session starts.
    $slot = Slot::query()->findOrFail((int) $order->items()->value('slot_id'));
    expect(BookingHold::query()->where('order_id', $order->id)->sole()->expires_at->equalTo($slot->starts_at))->toBeTrue();

    Mail::assertQueued(TransferReceivedMail::class, fn ($m) => $m->hasTo($order->user->email));
    Mail::assertQueued(TransferToVerifyMail::class, 2);
    foreach ($admins as $admin) {
        Mail::assertQueued(TransferToVerifyMail::class, fn ($m) => $m->hasTo($admin->email));
    }
});

it('accepts PNG, WebP and PDF proofs', function (string $kind): void {
    $order = awaitingTransfer();

    $file = match ($kind) {
        'png' => (function (): UploadedFile {
            $image = imagecreatetruecolor(50, 50);
            ob_start();
            imagepng($image);

            return realUpload((string) ob_get_clean(), 'proof.png');
        })(),
        'webp' => (function (): UploadedFile {
            $image = imagecreatetruecolor(50, 50);
            ob_start();
            imagewebp($image);

            return realUpload((string) ob_get_clean(), 'proof.webp');
        })(),
        default => realUpload(pdfBytes(), 'receipt.pdf'),
    };

    $this->actingAs($order->user)
        ->post(route('checkout.transfer.upload', $order->reference), ['proof' => $file])
        ->assertSessionHasNoErrors();

    $path = BankTransfer::query()->where('order_id', $order->id)->sole()->screenshot_path;

    expect($kind === 'pdf' ? str_ends_with($path, '.pdf') : ! str_ends_with($path, '.pdf'))->toBeTrue();
})->with(['png', 'webp', 'pdf']);

it('rejects bad, oversized and disguised files', function (string $case): void {
    $order = awaitingTransfer();

    $file = match ($case) {
        // Harmless PHP: a real web-shell string gets the temp file locked by antivirus on Windows.
        'php renamed to jpg' => realUpload("<?php echo 'not an image'; ?>
", 'proof.jpg'),
        'html renamed to png' => realUpload('<html><script>alert(1)</script></html>', 'proof.png'),
        'text renamed to pdf' => realUpload('just some text, not a pdf', 'proof.pdf'),
        'jpeg header then garbage' => realUpload("\xFF\xD8\xFF\xE0\x00\x10JFIF\x00".str_repeat('<?php ?>', 50), 'proof.jpg'),
        'svg' => realUpload('<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>', 'proof.svg'),
        'oversize' => realUpload(substr(pdfBytes(), 0, 9).str_repeat('0', 5 * 1024 * 1024 + 1), 'big.pdf'),
        default => throw new LogicException($case),
    };

    $this->actingAs($order->user)
        ->from(route('checkout.transfer', $order->reference))
        ->post(route('checkout.transfer.upload', $order->reference), ['proof' => $file])
        ->assertRedirect(route('checkout.transfer', $order->reference))
        ->assertSessionHasErrors('proof');

    expect($order->refresh()->status)->toBe(OrderStatus::AwaitingTransfer)
        ->and(BankTransfer::query()->exists())->toBeFalse()
        ->and(Storage::disk(TransferProofs::DISK)->allFiles())->toBeEmpty();
})->with(['php renamed to jpg', 'html renamed to png', 'text renamed to pdf', 'jpeg header then garbage', 'svg', 'oversize']);

it('requires a file', function (): void {
    $order = awaitingTransfer();

    $this->actingAs($order->user)
        ->post(route('checkout.transfer.upload', $order->reference), ['transaction_ref' => 'X1'])
        ->assertSessionHasErrors(['proof' => 'Choose the screenshot or PDF of your transfer.']);
});

it('lets the customer replace the file until it is reviewed', function (): void {
    $order = awaitingTransfer();
    $this->actingAs($order->user);

    $this->post(route('checkout.transfer.upload', $order->reference), ['proof' => realUpload(jpegBytes(), 'a.jpg')]);
    $first = BankTransfer::query()->sole()->screenshot_path;

    $this->post(route('checkout.transfer.upload', $order->reference), ['proof' => realUpload(pdfBytes(), 'b.pdf'), 'transaction_ref' => 'NEW1'])
        ->assertSessionHasNoErrors();

    $transfer = BankTransfer::query()->sole();

    expect($transfer->screenshot_path)->not->toBe($first)
        ->and($transfer->transaction_ref)->toBe('NEW1')
        ->and(Storage::disk(TransferProofs::DISK)->exists($first))->toBeFalse()
        ->and(Storage::disk(TransferProofs::DISK)->allFiles())->toHaveCount(1);

    Mail::assertQueued(TransferToVerifyMail::class, 0); // no admins here; and only the first upload notifies
    Mail::assertQueued(TransferReceivedMail::class, 1);

    // Once reviewed, no more replacing.
    $transfer->status = BankTransferStatus::Rejected;
    $transfer->save();

    $this->post(route('checkout.transfer.upload', $order->reference), ['proof' => realUpload(jpegBytes(), 'c.jpg')])
        ->assertSessionHas('transfer_error');

    expect(Storage::disk(TransferProofs::DISK)->allFiles())->toHaveCount(1);
});

it('refuses uploads for orders that are not waiting for a transfer, and for other customers', function (): void {
    $order = transferOrder(); // still pending_payment
    $this->actingAs($order->user)
        ->post(route('checkout.transfer.upload', $order->reference), ['proof' => realUpload(jpegBytes(), 'a.jpg')])
        ->assertSessionHas('transfer_error');

    $other = awaitingTransfer();
    $this->post(route('checkout.transfer.upload', $other->reference), ['proof' => realUpload(jpegBytes(), 'a.jpg')])->assertNotFound();
    $this->get(route('checkout.transfer', $other->reference))->assertNotFound();
    $this->post(route('checkout.transfer.choose', $other->reference))->assertNotFound();

    expect(Storage::disk(TransferProofs::DISK)->allFiles())->toBeEmpty();
});

it('rate limits uploads', function (): void {
    $order = awaitingTransfer();
    $this->actingAs($order->user);

    foreach (range(1, 10) as $ignored) {
        $this->post(route('checkout.transfer.upload', $order->reference), ['proof' => realUpload(jpegBytes(20, 20), 'a.jpg')]);
    }

    $this->post(route('checkout.transfer.upload', $order->reference), ['proof' => realUpload(jpegBytes(20, 20), 'a.jpg')])
        ->assertTooManyRequests();
});

it('expires an order with no upload at the deadline and emails the customer', function (): void {
    $order = awaitingTransfer();

    $this->travelTo($order->expires_at?->subMinute());
    app(ReleaseExpiredHolds::class)->handle();
    expect($order->refresh()->status)->toBe(OrderStatus::AwaitingTransfer);

    $this->travelTo($order->expires_at?->addMinute());
    app(ReleaseExpiredHolds::class)->handle();

    expect($order->refresh()->status)->toBe(OrderStatus::Expired)
        ->and(BookingHold::query()->where('order_id', $order->id)->exists())->toBeFalse();

    Mail::assertQueued(TransferExpiredMail::class, fn ($m) => $m->hasTo($order->user->email));

    $this->actingAs($order->user)->get(route('checkout.transfer', $order->reference))->assertSee('seats were released');
});

it('keeps an uploaded transfer and its seats past the deadline until an admin decides', function (): void {
    $order = awaitingTransfer();
    $this->actingAs($order->user)->post(route('checkout.transfer.upload', $order->reference), ['proof' => realUpload(jpegBytes(), 'a.jpg')]);

    $this->travelTo($order->refresh()->expires_at?->addHours(2));
    app(ReleaseExpiredHolds::class)->handle();

    $slot = Slot::query()->findOrFail((int) $order->items()->value('slot_id'));

    expect($order->refresh()->status)->toBe(OrderStatus::AwaitingVerification)
        ->and(BookingHold::query()->where('order_id', $order->id)->exists())->toBeTrue()
        ->and($slot->seatsFree())->toBe(4);

    Mail::assertNotQueued(TransferExpiredMail::class);
});

it('never stores proofs under public/ and the storage route does not serve them', function (): void {
    $order = awaitingTransfer();
    $this->actingAs($order->user)->post(route('checkout.transfer.upload', $order->reference), ['proof' => realUpload(jpegBytes(), 'a.jpg')]);
    $path = BankTransfer::query()->sole()->screenshot_path;

    expect(config('filesystems.disks.transfers.root'))->toBe(storage_path('app/transfers'))
        ->and(config('filesystems.disks.transfers.serve'))->toBeFalse()
        ->and(config('filesystems.disks.transfers'))->not->toHaveKey('url');

    // Nothing serves the file except the signed admin route.
    expect($this->get('/storage/'.$path)->status())->toBeIn([403, 404]);
    $this->get('/transfers/'.$path)->assertNotFound();
    expect(file_exists(public_path('storage/'.$path)))->toBeFalse();
});
