<?php

declare(strict_types=1);

use App\Models\Order;
use App\Models\Slot;
use App\Support\Stripe\StripeGateway;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Pest\Browser\Playwright\Page;
use Tests\Support\FakeStripeGateway;
use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| Test Case
|--------------------------------------------------------------------------
|
| All tests boot through Tests\TestCase, which refuses to run unless the
| database name ends with "_testing". Feature tests start from a clean
| database (migrated once per run, each test wrapped in a transaction).
|
*/

pest()->extend(TestCase::class)
    ->use(RefreshDatabase::class)
    ->in('Feature');

pest()->extend(TestCase::class)->in('Unit');

// Browser smoke tests (Pest browser plugin + Playwright Chromium).
pest()->extend(TestCase::class)
    ->use(RefreshDatabase::class)
    ->group('browser')
    ->in('Browser');

// Upper bound for browser waits (page loads, assertions), not a delay:
// each wait ends as soon as its condition holds. The test server runs in
// the test process, so a busy machine can need more than the default 5 s.
pest()->browser()->timeout(15_000);

// Real parallel processes need committed data: truncate instead of
// wrapping each test in a transaction the other processes can't see.
pest()->extend(TestCase::class)
    ->use(DatabaseTruncation::class)
    ->group('concurrency')
    ->in('Concurrency');

/*
|--------------------------------------------------------------------------
| Helpers
|--------------------------------------------------------------------------
*/

/**
 * Write a solid-colour JPEG of the given size to a (faked) disk and return its path.
 */
function putTestImage(string $disk, string $path, int $width, int $height, string $format = 'jpg'): string
{
    $image = imagecreatetruecolor($width, $height);
    imagefill($image, 0, 0, (int) imagecolorallocate($image, 139, 90, 43));

    ob_start();
    $format === 'png' ? imagepng($image) : imagejpeg($image, null, 80);
    $binary = (string) ob_get_clean();

    Storage::disk($disk)->put($path, $binary);

    return $path;
}

/**
 * Decode the page's JSON-LD @graph (one script per page).
 *
 * @return list<array<string, mixed>>
 */
function jsonLdGraph(string $html): array
{
    preg_match_all('#<script type="application/ld\+json"[^>]*>(.*?)</script>#s', $html, $matches);

    expect($matches[1])->toHaveCount(1);

    /** @var array{'@context': string, '@graph': list<array<string, mixed>>} $data */
    $data = json_decode($matches[1][0], true, flags: JSON_THROW_ON_ERROR);

    expect($data['@context'])->toBe('https://schema.org');

    return $data['@graph'];
}

/**
 * Every @type used anywhere in a JSON-LD structure.
 *
 * @param  array<mixed>  $node
 * @return list<string>
 */
function jsonLdTypes(array $node): array
{
    $types = [];

    array_walk_recursive($node, function (mixed $value, string|int $key) use (&$types): void {
        if ($key === '@type' && is_string($value)) {
            $types[] = $value;
        }
    });

    return array_values(array_unique($types));
}

/** The content of <meta name="..."> or <meta property="...">. */
function metaContent(string $html, string $name): ?string
{
    $pattern = '#<meta (?:name|property)="'.preg_quote($name, '#').'" content="([^"]*)">#';

    return preg_match($pattern, $html, $m) === 1 ? html_entity_decode($m[1]) : null;
}

/**
 * A bookable slot a few days ahead (the slot factory spreads slots over
 * years, past any credit pack's expiry). $hour is local (Asia/Karachi).
 *
 * @param  array<string, mixed>  $attributes
 */
function nearSlot(int $daysAhead = 3, int $hour = 8, array $attributes = []): Slot
{
    $local = CarbonImmutable::now((string) config('app.display_timezone'))->addDays($daysAhead)->setTime($hour, 0);

    return Slot::factory()->at($local)->create($attributes);
}

/*
|--------------------------------------------------------------------------
| End-to-end browser helpers (milestone 13)
|--------------------------------------------------------------------------
|
| The page wrapper retries every call until its timeout, which is right for
| assertions but would press a button twice on a slow server. Journeys
| therefore send each action to Playwright once (pressOnce) and then wait
| for that step's own result (waitForText). E2E_WAIT_MS is the limit before
| giving up, never a delay: each wait ends as soon as its condition holds.
|
*/

const E2E_WAIT_MS = 30_000;

/** Wait until visible text containing $text is on the page. */
function waitForText(Page $page, string $text): void
{
    $page->getByText($text)->first()->waitFor(['state' => 'visible', 'timeout' => E2E_WAIT_MS]);
}

/**
 * Click a button or link once by its accessible name. $exact = false
 * matches names that carry extra screen-reader text (e.g. "Cancel Sat 14 Nov").
 */
function pressOnce(Page $page, string $name, string $role = 'button', bool $exact = true): void
{
    $page->getByRole($role, ['name' => $name, 'exact' => $exact])->first()->click(['timeout' => E2E_WAIT_MS]);
}

/** Fill a form field once by its label. */
function fillOnce(Page $page, string $label, string $value): void
{
    $page->getByLabel($label, true)->first()->fill($value, ['timeout' => E2E_WAIT_MS]);
}

/** The in-memory Stripe bound by Tests\TestCase. */
function fakeStripe(): FakeStripeGateway
{
    $gateway = app(StripeGateway::class);
    assert($gateway instanceof FakeStripeGateway);

    return $gateway;
}

/**
 * Stripe's side of a card payment: mark the order's Checkout Session paid and
 * send the signed checkout.session.completed webhook, as Stripe does once the
 * customer has paid. Fulfilment then runs through the real webhook handler.
 */
function completeCardPayment(TestCase $test, Order $order): void
{
    $order->refresh();
    $sessionId = (string) $order->stripe_checkout_session_id;
    expect($sessionId)->not->toBe('');

    fakeStripe()->complete($sessionId);

    $payload = (string) json_encode(FakeStripeGateway::event('checkout.session.completed', [
        'id' => $sessionId,
        'object' => 'checkout.session',
        'status' => 'complete',
        'payment_status' => 'paid',
        'client_reference_id' => (string) $order->id,
        'metadata' => ['reference' => $order->reference, 'order_id' => (string) $order->id],
        'amount_total' => $order->amount_minor,
        'currency' => 'pkr',
        'payment_intent' => 'pi_test_'.$order->id,
    ]));

    $test->call('POST', '/webhooks/stripe', server: [
        'CONTENT_TYPE' => 'application/json',
        'HTTP_STRIPE_SIGNATURE' => FakeStripeGateway::sign($payload),
    ], content: $payload)->assertOk();
}

/**
 * From the checkout page: press "Pay by card", come back from (fake) Stripe to
 * the status page, let Stripe send the webhook and wait for the status page's
 * own poll to show the order paid.
 */
function payByCardInBrowser(TestCase $test, Page $page, Order $order): void
{
    fakeStripe()->returnToSuccessUrl = true;

    pressOnce($page, 'Pay by card');
    waitForText($page, 'Confirming your payment');

    completeCardPayment($test, $order);

    waitForText($page, 'Payment received. You\'re booked.');
}

/** A small real PNG (a bank app screenshot stand-in) in the testing folder. */
function transferScreenshot(): string
{
    $path = storage_path('framework/testing/transfer-proof.png');
    @mkdir(dirname($path), 0777, true);

    $image = imagecreatetruecolor(320, 640);
    imagefill($image, 0, 0, (int) imagecolorallocate($image, 240, 240, 240));
    imagepng($image, $path);

    return $path;
}
